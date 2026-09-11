<?php
/**
 * Event eligibility, weighting and selection.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Decides which card lands on the Resolute desk this month.
 *
 * Selection is a two-stage filter. Eligibility is a hard gate — scenario pool,
 * `start_conditions`, cooldown, `max_occurrences`, mutual exclusivity — and everything that
 * survives it competes on weight, where `weight_modifiers` multiply the base weight when
 * their own conditions hold. A pseudo-entry (`__quiet__`, weighted by the scenario's
 * `quiet_month_weight`) competes alongside the real events, which is how a month with no
 * card happens at a rate the scenario author controls.
 *
 * A delayed consequence may force a specific event (`trigger_event`). A forced event skips
 * the pool, its start conditions, its cooldown and exclusivity — but not `max_occurrences`,
 * which is a hard content cap rather than a timing rule. If the cap blocks it, selection
 * falls back to the ordinary weighted pick, so a forced trigger can never leave the player
 * with nothing.
 *
 * `eligibilityReport()` exposes the same machinery, per event and with reasons, for the
 * developer panel (spec section 8.4).
 */
final class EventEngine
{
    /** Key of the "no event this month" pseudo-entry in the weight map. */
    const QUIET_KEY = '__quiet__';

    /** Quiet-month weight used when the scenario names none. */
    const DEFAULT_QUIET_WEIGHT = 5.0;

    /** Cooldown used when an event names none. */
    const DEFAULT_COOLDOWN = 0;

    /** Where the current card lives in the state. */
    const ACTIVE_EVENT_PATH = 'active_event';

    /** Where the cooldown map lives in the state. */
    const COOLDOWNS_PATH = 'cooldowns';

    /** Where the list of presented event ids lives in the state. */
    const SEEN_EVENTS_PATH = 'seen_events';

    /** Whether a forced event still respects the event's `max_occurrences` cap. */
    const FORCED_RESPECTS_MAX_OCCURRENCES = true;

    /** Reason codes used by `eligibilityReport()`. */
    const REASON_NOT_IN_POOL = 'not in the scenario event pool';
    const REASON_CONDITIONS = 'start conditions not met';
    const REASON_COOLDOWN = 'on cooldown until turn %d';
    const REASON_MAX_OCCURRENCES = 'presented %d of %d allowed times';
    const REASON_EXCLUSIVE = 'mutually exclusive with "%s"';
    const REASON_MODIFIER = 'weight modifier x%s applied';

    /**
     * Content, for the event and scenario documents.
     *
     * @var ContentRepository
     */
    private ContentRepository $content;

    /**
     * @param ContentRepository $content Loaded content repository.
     */
    public function __construct(ContentRepository $content)
    {
        $this->content = $content;
    }

    /**
     * Choose and present the next event, or leave the desk clear.
     *
     * Mutates `active_event`, `cooldowns` and `seen_events`.
     *
     * @param GameState   $state          State to mutate.
     * @param string|null $forcedEventId  Event a delayed consequence is forcing, if any.
     * @param string|null $forcedBy       Id of the consequence doing the forcing.
     *
     * @return array|null The presented event document, or null for a quiet month.
     *
     * @throws EngineException `invalid_content` when content is malformed.
     */
    public function selectNext(GameState $state, ?string $forcedEventId = null, ?string $forcedBy = null): ?array
    {
        $previousId = $this->activeEventId($state);
        $forced     = $this->forcedEvent($state, $forcedEventId);

        if (null !== $forced) {
            return $this->present($state, $forced, $forcedBy);
        }

        $weights = [];

        foreach ($this->candidates($state) as $eventId => $event) {
            if (!$this->isEligible($state, $event, $previousId)) {
                continue;
            }

            $weights[$eventId] = $this->weightFor($state, $event);
        }

        $weights[self::QUIET_KEY] = $this->quietWeight($state);

        $pick = $state->rng()->weightedPick($weights);

        if (null === $pick || self::QUIET_KEY === $pick) {
            $state->set(self::ACTIVE_EVENT_PATH, null);

            return null;
        }

        return $this->present($state, $this->content->event($pick), null);
    }

    /**
     * Put an event on the desk, and record that it has been presented.
     *
     * @param GameState   $state    State to mutate.
     * @param array       $event    Event document.
     * @param string|null $forcedBy Consequence id that forced it, or null.
     *
     * @return array The presented event document.
     */
    public function present(GameState $state, array $event, ?string $forcedBy): array
    {
        $eventId = (string) $event['id'];
        $turn    = $state->turn();

        $state->set(
            self::ACTIVE_EVENT_PATH,
            [
                'event_id'           => $eventId,
                'turn_presented'     => $turn,
                'expires_turn'       => self::expiryTurn($event, $turn),
                'resolved_choice_id' => null,
                'forced_by'          => $forcedBy,
            ]
        );

        $cooldown = isset($event['cooldown']) && is_numeric($event['cooldown'])
            ? (int) $event['cooldown']
            : self::DEFAULT_COOLDOWN;

        $state->set(self::COOLDOWNS_PATH . '.' . $eventId, $turn + $cooldown);
        $state->push(self::SEEN_EVENTS_PATH, $eventId);

        return $event;
    }

    /**
     * Queue an event's `followup_events` block, which fires when the event is resolved.
     *
     * @param GameState $state  State to mutate.
     * @param array     $event  Event document.
     * @param string    $source Provenance, e.g. `major-cyberattack:export-controls`.
     *
     * @return array<int, string> Ids of the queued consequences.
     *
     * @throws EngineException `invalid_content` when an entry is malformed.
     */
    public function enqueueFollowups(GameState $state, array $event, string $source): array
    {
        if (!isset($event['followup_events']) || !is_array($event['followup_events'])) {
            return [];
        }

        return DelayedConsequenceQueue::enqueueFollowups($state, $event['followup_events'], $source);
    }

    /**
     * Every event with its current eligibility, weight and the reasons behind both.
     *
     * Pure read: nothing is mutated and no RNG is consumed, so the developer panel can call
     * it on every render.
     *
     * @param GameState $state State to inspect.
     *
     * @return array<int, array> `['event_id', 'eligible', 'weight', 'reasons']`, plus a
     *                           final row for the quiet-month pseudo-entry.
     */
    public function eligibilityReport(GameState $state): array
    {
        $previousId = $this->activeEventId($state);
        $pool       = $this->poolIds($state);
        $report     = [];

        foreach ($this->content->events() as $eventId => $event) {
            $reasons  = [];
            $eventId  = (string) $eventId;
            $eligible = true;

            if (null !== $pool && !isset($pool[$eventId])) {
                $eligible  = false;
                $reasons[] = self::REASON_NOT_IN_POOL;
            }

            if (!$this->conditionsHold($state, $event)) {
                $eligible  = false;
                $reasons[] = self::REASON_CONDITIONS;
            }

            $readyTurn = $this->cooldownUntil($state, $eventId);

            if ($readyTurn > $state->turn()) {
                $eligible  = false;
                $reasons[] = sprintf(self::REASON_COOLDOWN, $readyTurn);
            }

            $maximum = self::maxOccurrences($event);
            $seen    = $this->occurrences($state, $eventId);

            if (null !== $maximum && $seen >= $maximum) {
                $eligible  = false;
                $reasons[] = sprintf(self::REASON_MAX_OCCURRENCES, $seen, $maximum);
            }

            $blocker = self::exclusivityBlocker($this->content->events(), $event, $previousId);

            if (null !== $blocker) {
                $eligible  = false;
                $reasons[] = sprintf(self::REASON_EXCLUSIVE, $blocker);
            }

            foreach ($this->modifierMultipliers($state, $event) as $multiplier) {
                $reasons[] = sprintf(self::REASON_MODIFIER, rtrim(rtrim(number_format($multiplier, 2), '0'), '.'));
            }

            $report[] = [
                'event_id' => $eventId,
                'eligible' => $eligible,
                'weight'   => $eligible ? $this->weightFor($state, $event) : 0.0,
                'reasons'  => $reasons,
            ];
        }

        $report[] = [
            'event_id' => self::QUIET_KEY,
            'eligible' => true,
            'weight'   => $this->quietWeight($state),
            'reasons'  => ['scenario quiet_month_weight'],
        ];

        return $report;
    }

    /**
     * Whether an event may be presented right now.
     *
     * @param GameState   $state      State to read.
     * @param array       $event      Event document.
     * @param string|null $previousId Id of the current or just-resolved event.
     *
     * @return bool
     */
    public function isEligible(GameState $state, array $event, ?string $previousId): bool
    {
        $eventId = (string) $event['id'];
        $pool    = $this->poolIds($state);

        if (null !== $pool && !isset($pool[$eventId])) {
            return false;
        }

        if ($this->cooldownUntil($state, $eventId) > $state->turn()) {
            return false;
        }

        if (!$this->withinOccurrenceCap($state, $event)) {
            return false;
        }

        if (null !== self::exclusivityBlocker($this->content->events(), $event, $previousId)) {
            return false;
        }

        return $this->conditionsHold($state, $event);
    }

    /**
     * Base weight times every matching weight modifier.
     *
     * @param GameState $state State to read.
     * @param array     $event Event document.
     *
     * @return float Always greater than zero for a valid event.
     */
    public function weightFor(GameState $state, array $event): float
    {
        $weight = isset($event['weight']) && is_numeric($event['weight']) ? (float) $event['weight'] : 0.0;

        foreach ($this->modifierMultipliers($state, $event) as $multiplier) {
            $weight *= $multiplier;
        }

        return $weight;
    }

    /**
     * The event a trigger is forcing, when it may actually be presented.
     *
     * @param GameState   $state         State to read.
     * @param string|null $forcedEventId Forced event id, or null.
     *
     * @return array|null Event document, or null when there is nothing to force.
     *
     * @throws EngineException `unknown_event` when the id is not in content.
     */
    private function forcedEvent(GameState $state, ?string $forcedEventId): ?array
    {
        if (null === $forcedEventId || '' === $forcedEventId) {
            return null;
        }

        $event = $this->content->event($forcedEventId);

        if (self::FORCED_RESPECTS_MAX_OCCURRENCES && !$this->withinOccurrenceCap($state, $event)) {
            return null;
        }

        return $event;
    }

    /**
     * The events this scenario may draw from, keyed by id.
     *
     * @param GameState $state State to read.
     *
     * @return array<string, array>
     */
    private function candidates(GameState $state): array
    {
        $events = $this->content->events();
        $pool   = $this->poolIds($state);

        if (null === $pool) {
            return $events;
        }

        $candidates = [];

        foreach ($events as $eventId => $event) {
            if (isset($pool[(string) $eventId])) {
                $candidates[(string) $eventId] = $event;
            }
        }

        return $candidates;
    }

    /**
     * The scenario's event pool as a lookup map, or null when it is unrestricted.
     *
     * @param GameState $state State to read.
     *
     * @return array<string, bool>|null
     */
    private function poolIds(GameState $state): ?array
    {
        $scenario = $this->scenario($state);

        if (null === $scenario || !isset($scenario['event_pool']) || !is_array($scenario['event_pool'])) {
            return null;
        }

        $pool = [];

        foreach ($scenario['event_pool'] as $eventId) {
            $pool[(string) $eventId] = true;
        }

        return $pool;
    }

    /**
     * The scenario document for this run, when it can be resolved.
     *
     * @param GameState $state State to read.
     *
     * @return array|null
     */
    private function scenario(GameState $state): ?array
    {
        $scenarioId = $state->get('scenario_id', '');

        if (!is_string($scenarioId) || '' === $scenarioId) {
            return null;
        }

        return $this->content->scenario($scenarioId);
    }

    /**
     * The pseudo-weight of a month with no event.
     *
     * @param GameState $state State to read.
     *
     * @return float
     */
    private function quietWeight(GameState $state): float
    {
        $scenario = $this->scenario($state);

        if (null === $scenario || !isset($scenario['quiet_month_weight'])
            || !is_numeric($scenario['quiet_month_weight'])) {
            return self::DEFAULT_QUIET_WEIGHT;
        }

        return max(0.0, (float) $scenario['quiet_month_weight']);
    }

    /**
     * Whether an event's `start_conditions` hold.
     *
     * @param GameState $state State to read.
     * @param array     $event Event document.
     *
     * @return bool
     *
     * @throws EngineException `invalid_content` when a clause is malformed.
     */
    private function conditionsHold(GameState $state, array $event): bool
    {
        if (!isset($event['start_conditions']) || !is_array($event['start_conditions'])) {
            return true;
        }

        return Conditions::evaluate($state, $event['start_conditions']);
    }

    /**
     * Every matching weight modifier's multiplier, in authoring order.
     *
     * @param GameState $state State to read.
     * @param array     $event Event document.
     *
     * @return array<int, float>
     */
    private function modifierMultipliers(GameState $state, array $event): array
    {
        if (!isset($event['weight_modifiers']) || !is_array($event['weight_modifiers'])) {
            return [];
        }

        $multipliers = [];

        foreach ($event['weight_modifiers'] as $modifier) {
            if (!is_array($modifier) || !isset($modifier['multiply']) || !is_numeric($modifier['multiply'])) {
                continue;
            }

            $conditions = isset($modifier['conditions']) && is_array($modifier['conditions'])
                ? $modifier['conditions']
                : [];

            if (Conditions::evaluate($state, $conditions)) {
                $multipliers[] = (float) $modifier['multiply'];
            }
        }

        return $multipliers;
    }

    /**
     * First turn an event is eligible again.
     *
     * @param GameState $state   State to read.
     * @param string    $eventId Event id.
     *
     * @return int
     */
    private function cooldownUntil(GameState $state, string $eventId): int
    {
        $until = $state->get(self::COOLDOWNS_PATH . '.' . $eventId, 0);

        return is_numeric($until) ? (int) $until : 0;
    }

    /**
     * How many times an event has been presented.
     *
     * @param GameState $state   State to read.
     * @param string    $eventId Event id.
     *
     * @return int
     */
    private function occurrences(GameState $state, string $eventId): int
    {
        $seen = $state->get(self::SEEN_EVENTS_PATH, []);

        if (!is_array($seen)) {
            return 0;
        }

        $count = 0;

        foreach ($seen as $id) {
            if ($eventId === (string) $id) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether an event is still under its `max_occurrences` cap.
     *
     * @param GameState $state State to read.
     * @param array     $event Event document.
     *
     * @return bool
     */
    private function withinOccurrenceCap(GameState $state, array $event): bool
    {
        $maximum = self::maxOccurrences($event);

        if (null === $maximum) {
            return true;
        }

        return $this->occurrences($state, (string) $event['id']) < $maximum;
    }

    /**
     * The id of the event currently on the desk, resolved or not.
     *
     * @param GameState $state State to read.
     *
     * @return string|null
     */
    private function activeEventId(GameState $state): ?string
    {
        $eventId = $state->get(self::ACTIVE_EVENT_PATH . '.event_id', null);

        return is_string($eventId) && '' !== $eventId ? $eventId : null;
    }

    /**
     * The event blocking a candidate through mutual exclusivity, if any.
     *
     * The check runs both ways: it is enough for either event to name the other.
     *
     * @param array       $events     Every event document, keyed by id.
     * @param array       $candidate  Candidate event document.
     * @param string|null $previousId Id of the current or just-resolved event.
     *
     * @return string|null The blocking event id.
     */
    private static function exclusivityBlocker(array $events, array $candidate, ?string $previousId): ?string
    {
        if (null === $previousId || $previousId === (string) $candidate['id']) {
            return null;
        }

        if (in_array($previousId, self::exclusiveIds($candidate), true)) {
            return $previousId;
        }

        if (isset($events[$previousId]) && is_array($events[$previousId])
            && in_array((string) $candidate['id'], self::exclusiveIds($events[$previousId]), true)) {
            return $previousId;
        }

        return null;
    }

    /**
     * An event's `exclusive_with` list, normalised to strings.
     *
     * @param array $event Event document.
     *
     * @return array<int, string>
     */
    private static function exclusiveIds(array $event): array
    {
        if (!isset($event['exclusive_with']) || !is_array($event['exclusive_with'])) {
            return [];
        }

        $ids = [];

        foreach ($event['exclusive_with'] as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * An event's occurrence cap, or null when it is unlimited.
     *
     * @param array $event Event document.
     *
     * @return int|null
     */
    private static function maxOccurrences(array $event): ?int
    {
        if (!isset($event['max_occurrences']) || !is_numeric($event['max_occurrences'])) {
            return null;
        }

        return max(0, (int) $event['max_occurrences']);
    }

    /**
     * The turn an unresolved card would lapse on, or null when it never does.
     *
     * @param array $event Event document.
     * @param int   $turn  Turn the card is presented on.
     *
     * @return int|null
     */
    private static function expiryTurn(array $event, int $turn): ?int
    {
        if (!isset($event['expiry']) || !is_numeric($event['expiry'])) {
            return null;
        }

        return $turn + (int) $event['expiry'];
    }
}
