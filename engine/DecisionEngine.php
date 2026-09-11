<?php
/**
 * Application of a player decision to the game state.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * The one place a choice turns into consequences.
 *
 * The browser sends nothing but a `choice_id`; everything else — which event that choice
 * belongs to, what it costs, what it promises for later — is read from content here. The
 * order of operations is fixed, because it is also the order in which the RNG is consumed
 * (only the media pass draws) and therefore part of the save format:
 *
 * 1. validate the active event and the choice;
 * 2. apply `effects` and `hidden_effects` in one pass, so visible and hidden changes share
 *    one code path and differ only in how they are reported;
 * 3. set `flags` literally and add `counters`;
 * 4. queue `choices[].delayed`, then the event's `followup_events`;
 * 5. record the memory;
 * 6. assemble headlines and append them to the media log;
 * 7. mark the card resolved, append to `event_log`, store `last_outcome`.
 *
 * Flags are *set* to whatever the author wrote (`true`, a string, a number) while counters
 * *add* — that asymmetry is the spec's (section 5.1) and it is what makes
 * `{ "counter_min": ["sanctions_used", 2] }` meaningful.
 */
final class DecisionEngine
{
    /** Where the current card lives in the state. */
    const ACTIVE_EVENT_PATH = 'active_event';

    /** Where the decision history lives in the state. */
    const EVENT_LOG_PATH = 'event_log';

    /** Where the most recent outcome lives in the state. */
    const LAST_OUTCOME_PATH = 'last_outcome';

    /** Prefix for flag dot paths. */
    const FLAGS_PREFIX = 'flags.';

    /** Prefix for counter dot paths. */
    const COUNTERS_PREFIX = 'counters.';

    /** Separator between event id and choice id in a provenance string. */
    const SOURCE_SEPARATOR = ':';

    /**
     * Content, for the event and choice documents.
     *
     * @var ContentRepository
     */
    private ContentRepository $content;

    /**
     * Event engine, for queuing the event's follow-ups.
     *
     * @var EventEngine
     */
    private EventEngine $events;

    /**
     * Media system, for the headlines a decision generates.
     *
     * @var MediaSystem
     */
    private MediaSystem $media;

    /**
     * @param ContentRepository $content Loaded content repository.
     * @param EventEngine       $events  Event engine.
     * @param MediaSystem       $media   Media system.
     */
    public function __construct(ContentRepository $content, EventEngine $events, MediaSystem $media)
    {
        $this->content = $content;
        $this->events  = $events;
        $this->media   = $media;
    }

    /**
     * Apply a decision and return its outcome.
     *
     * @param GameState $state    State to mutate.
     * @param string    $choiceId Choice the player picked.
     *
     * @return array Outcome (spec section 4.3), also stored as `last_outcome`.
     *
     * @throws EngineException `no_active_event`, `event_already_resolved`, `unknown_choice`,
     *                         `unknown_event` or `invalid_content`.
     */
    public function apply(GameState $state, string $choiceId): array
    {
        $active = $this->requireUnresolvedEvent($state);
        $event  = $this->content->event((string) $active['event_id']);
        $choice = self::requireChoice($event, $choiceId);
        $source = (string) $event['id'] . self::SOURCE_SEPARATOR . $choiceId;

        $deltas  = Effects::apply($state, self::effectsOf($choice, 'effects'));
        $deltas  = array_merge($deltas, Effects::apply($state, self::effectsOf($choice, 'hidden_effects')));
        $visible = Effects::visibleOnly($deltas);

        self::applyFlags($state, $choice);
        self::applyCounters($state, $choice);

        $queued = $this->queueConsequences($state, $event, $choice, $source);
        $memory = self::recordMemory($state, $choice);

        $headlines = $this->media->headlinesFor($state, $event, $choice);
        $this->media->append($state, $headlines, $source);

        $state->set(self::ACTIVE_EVENT_PATH . '.resolved_choice_id', $choiceId);
        $state->push(
            self::EVENT_LOG_PATH,
            [
                'event_id'  => (string) $event['id'],
                'turn'      => $state->turn(),
                'date'      => $state->date(),
                'choice_id' => $choiceId,
            ]
        );

        $outcome = [
            'event_id'            => (string) $event['id'],
            'choice_id'           => $choiceId,
            'choice_label'        => isset($choice['label']) ? (string) $choice['label'] : $choiceId,
            'outcome_text'        => isset($choice['outcome_text']) ? (string) $choice['outcome_text'] : '',
            'visible_deltas'      => $visible,
            'hidden_change_count' => count($deltas) - count($visible),
            'headlines'           => $headlines,
            'memory'              => $memory,
            'delayed_count'       => $queued,
        ];

        $state->set(self::LAST_OUTCOME_PATH, $outcome);

        return $outcome;
    }

    /**
     * The active event, guaranteed to exist and to be awaiting a decision.
     *
     * @param GameState $state State to read.
     *
     * @return array The `active_event` block.
     *
     * @throws EngineException `no_active_event` or `event_already_resolved`.
     */
    private function requireUnresolvedEvent(GameState $state): array
    {
        $active = $state->get(self::ACTIVE_EVENT_PATH, null);

        if (!is_array($active) || !isset($active['event_id'])) {
            throw new EngineException(
                EngineException::NO_ACTIVE_EVENT,
                'No event is awaiting a decision.'
            );
        }

        if (isset($active['resolved_choice_id']) && null !== $active['resolved_choice_id']) {
            throw new EngineException(
                EngineException::EVENT_ALREADY_RESOLVED,
                sprintf(
                    'Event "%s" was already resolved with choice "%s".',
                    (string) $active['event_id'],
                    (string) $active['resolved_choice_id']
                )
            );
        }

        return $active;
    }

    /**
     * Queue everything this decision promises for later.
     *
     * The choice's own `delayed` entries are queued first, then the event's
     * `followup_events`, so ids stay in a predictable order.
     *
     * @param GameState $state  State to mutate.
     * @param array     $event  Event document.
     * @param array     $choice Chosen option.
     * @param string    $source Provenance string.
     *
     * @return int How many consequences were queued.
     *
     * @throws EngineException `invalid_content` when an entry is malformed.
     */
    private function queueConsequences(GameState $state, array $event, array $choice, string $source): int
    {
        $queued = 0;

        if (isset($choice['delayed']) && is_array($choice['delayed'])) {
            foreach ($choice['delayed'] as $item) {
                if (!is_array($item)) {
                    throw new EngineException(
                        EngineException::INVALID_CONTENT,
                        'Each "delayed" entry must be an object (source: ' . $source . ').'
                    );
                }

                DelayedConsequenceQueue::enqueue($state, $item, $source);
                $queued++;
            }
        }

        return $queued + count($this->events->enqueueFollowups($state, $event, $source));
    }

    /**
     * Set the choice's flags literally, whatever their type.
     *
     * @param GameState $state  State to mutate.
     * @param array     $choice Chosen option.
     *
     * @return void
     */
    private static function applyFlags(GameState $state, array $choice): void
    {
        if (!isset($choice['flags']) || !is_array($choice['flags'])) {
            return;
        }

        foreach ($choice['flags'] as $flag => $value) {
            $flag = (string) $flag;

            if ('' === $flag) {
                continue;
            }

            $state->set(self::FLAGS_PREFIX . $flag, $value);
        }
    }

    /**
     * Add the choice's counters, floored at zero by `Effects::BOUNDS`.
     *
     * @param GameState $state  State to mutate.
     * @param array     $choice Chosen option.
     *
     * @return void
     */
    private static function applyCounters(GameState $state, array $choice): void
    {
        if (!isset($choice['counters']) || !is_array($choice['counters'])) {
            return;
        }

        $effects = [];

        foreach ($choice['counters'] as $counter => $value) {
            $counter = (string) $counter;

            if ('' === $counter || !is_numeric($value)) {
                continue;
            }

            $effects[self::COUNTERS_PREFIX . $counter] = $value;
        }

        Effects::apply($state, $effects);
    }

    /**
     * Append the choice's memory, when it authored one.
     *
     * @param GameState $state  State to mutate.
     * @param array     $choice Chosen option.
     *
     * @return array|null The stored record, or null when the choice has no memory.
     *
     * @throws EngineException `invalid_content` on an unknown memory type.
     */
    private static function recordMemory(GameState $state, array $choice): ?array
    {
        if (!isset($choice['memory']) || !is_array($choice['memory']) || [] === $choice['memory']) {
            return null;
        }

        return MemorySystem::record($state, $choice['memory']);
    }

    /**
     * One of a choice's effect maps.
     *
     * @param array  $choice Chosen option.
     * @param string $key    `effects` or `hidden_effects`.
     *
     * @return array
     */
    private static function effectsOf(array $choice, string $key): array
    {
        return isset($choice[$key]) && is_array($choice[$key]) ? $choice[$key] : [];
    }

    /**
     * The chosen option, or a hard failure.
     *
     * @param array  $event    Event document.
     * @param string $choiceId Choice id from the client.
     *
     * @return array
     *
     * @throws EngineException `unknown_choice`.
     */
    private static function requireChoice(array $event, string $choiceId): array
    {
        if (isset($event['choices']) && is_array($event['choices'])) {
            foreach ($event['choices'] as $choice) {
                if (is_array($choice) && isset($choice['id']) && $choiceId === (string) $choice['id']) {
                    return $choice;
                }
            }
        }

        throw new EngineException(
            EngineException::UNKNOWN_CHOICE,
            sprintf('Event "%s" has no choice "%s".', (string) $event['id'], $choiceId)
        );
    }
}
