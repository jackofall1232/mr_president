<?php
/**
 * The engine facade the plugin talks to.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Four verbs and a read: start a game, decide, advance, brief.
 *
 * Everything the WordPress layer is allowed to do to a run goes through one of these
 * methods, and every one of them is a deterministic function of `(GameState, content, RNG
 * state)`. Nothing here knows about HTTP, users, the database or the clock; the plugin
 * loads a state, calls a method, and persists `toArray()`.
 *
 * `buildBriefing()` deliberately returns *facts*, never prose. Its `summary` key is a
 * structured array — month, how much coverage there was, what is on the desk, the three
 * biggest indicator moves — which `Template_AI_Provider` (spec section 8.7) renders into a
 * sentence. That is the seam that lets a real language model take over the writing later
 * without any engine change, and it is why nothing under `includes/ai/` ever needs a
 * `GameState`.
 */
final class GameEngine
{
    /** Indicator moves carried in the briefing summary. */
    const SUMMARY_TOP_DELTAS = 3;

    /** Headlines carried in a briefing. */
    const BRIEFING_MEDIA_COUNT = 5;

    /** Memories carried in a briefing. */
    const BRIEFING_MEMORY_COUNT = 5;

    /** Note that opens the very first turn report of a run. */
    const INAUGURATION_NOTE = 'The administration takes office; the transition is over.';

    /** Choice keys that never leave the engine — the player sees consequences after choosing. */
    const PRIVATE_CHOICE_KEYS = ['effects', 'hidden_effects', 'flags', 'counters', 'delayed', 'memory', 'headlines', 'outcome_text'];

    /** Event keys carried onto the card the client renders. */
    const CARD_KEYS = ['id', 'title', 'flash_label', 'category', 'severity', 'summary', 'briefing_text', 'location', 'tags'];

    /**
     * Loaded content.
     *
     * @var ContentRepository
     */
    private ContentRepository $content;

    /**
     * Event eligibility and selection.
     *
     * @var EventEngine
     */
    private EventEngine $events;

    /**
     * Headlines and the media log.
     *
     * @var MediaSystem
     */
    private MediaSystem $media;

    /**
     * The cabinet.
     *
     * @var AdvisorSystem
     */
    private AdvisorSystem $advisors;

    /**
     * Decision application.
     *
     * @var DecisionEngine
     */
    private DecisionEngine $decisions;

    /**
     * The month advance.
     *
     * @var TurnEngine
     */
    private TurnEngine $turns;

    /**
     * @param ContentRepository $content Loaded content repository.
     */
    public function __construct(ContentRepository $content)
    {
        $this->content   = $content;
        $this->events    = new EventEngine($content);
        $this->media     = new MediaSystem($content);
        $this->advisors  = new AdvisorSystem($content);
        $this->decisions = new DecisionEngine($content, $this->events, $this->media);
        $this->turns     = new TurnEngine($content, $this->events, $this->media);
    }

    /**
     * Start a run on turn 1, with the scenario's opening event already on the desk.
     *
     * @param string $scenarioId    Scenario id.
     * @param string $presidentName Player-supplied name; the plugin sanitises it.
     * @param int    $seed          RNG seed; never changes for the life of the run.
     *
     * @return GameState A fresh, playable state.
     *
     * @throws EngineException `unknown_scenario`, `unknown_event` or `invalid_content`.
     */
    public function newGame(string $scenarioId, string $presidentName, int $seed): GameState
    {
        $scenario = $this->content->scenario($scenarioId);
        $initial  = $scenario['initial_state'];

        $data = GameState::defaults();

        $data['president_name'] = $presidentName;
        $data['scenario_id']    = (string) $scenario['id'];
        $data['seed']           = $seed;
        $data['rng']            = ['state' => $seed];
        $data['date']           = (string) $scenario['start_date'];
        $data['public']         = self::block($initial, 'public');
        $data['hidden']         = self::block($initial, 'hidden');
        $data['countries']      = self::block($initial, 'countries');
        $data['flags']          = self::block($initial, 'flags');
        $data['counters']       = self::block($initial, 'counters');

        $state = GameState::fromArray($data);

        $state->set(TurnEngine::SNAPSHOT_PATH, $data['public']);

        $opening = $this->openingEvent($state, $scenario);
        $ambient = $this->media->ambientHeadline($state, $opening);
        $notes   = [self::INAUGURATION_NOTE];

        if (null !== $ambient) {
            $this->media->append($state, [$ambient], MediaSystem::AMBIENT_SOURCE);
        }

        $state->set(
            TurnEngine::TURN_REPORT_PATH,
            [
                'turn'               => $state->turn(),
                'date'               => $state->date(),
                'month_label'        => TurnEngine::monthLabel($state->date()),
                'indicator_deltas'   => [],
                'system_notes'       => $notes,
                'fired_consequences' => [],
                'headlines'          => null === $ambient ? [] : [$ambient],
                'active_event_id'    => null === $opening ? null : (string) $opening['id'],
            ]
        );

        return $state;
    }

    /**
     * Apply the player's decision on the active event.
     *
     * @param GameState $state    State to mutate.
     * @param string    $choiceId Choice id from the client.
     *
     * @return array Outcome (spec section 4.3).
     *
     * @throws EngineException `no_active_event`, `event_already_resolved`, `unknown_choice`.
     */
    public function applyDecision(GameState $state, string $choiceId): array
    {
        return $this->decisions->apply($state, $choiceId);
    }

    /**
     * Advance the game by one month.
     *
     * @param GameState $state State to mutate.
     *
     * @return array Turn report (spec section 4.4).
     *
     * @throws EngineException `decision_required`.
     */
    public function advanceTurn(GameState $state): array
    {
        return $this->turns->advance($state);
    }

    /**
     * Everything the daily brief needs, as facts.
     *
     * Pure read: no mutation, no RNG.
     *
     * @param GameState $state State to inspect.
     *
     * @return array Briefing (spec section 4.5).
     *
     * @throws EngineException `unknown_event` when the active card is not in content.
     */
    public function buildBriefing(GameState $state): array
    {
        $event      = $this->activeEvent($state);
        $resolvedId = self::resolvedChoiceId($state);
        $report     = $state->get(TurnEngine::TURN_REPORT_PATH, null);
        $report     = is_array($report) ? $report : null;

        return [
            'date'            => $state->date(),
            'month_label'     => TurnEngine::monthLabel($state->date()),
            'turn'            => $state->turn(),
            'term'            => $state->term(),
            'summary'         => self::summaryFacts($state, $event, $report),
            'indicators'      => $state->get('public', []),
            'turn_report'     => $report,
            'event'           => null === $event ? null : $this->card($state, $event, $resolvedId),
            'cabinet'         => $this->advisors->positionsFor($state, null === $event ? [] : $event, $resolvedId),
            'media'           => MediaSystem::recent($state, self::BRIEFING_MEDIA_COUNT),
            'memories_recent' => MemorySystem::recent($state, self::BRIEFING_MEMORY_COUNT),
        ];
    }

    /**
     * Every event with its eligibility, weight and reasons, for the developer panel.
     *
     * @param GameState $state State to inspect.
     *
     * @return array<int, array>
     */
    public function eligibilityReport(GameState $state): array
    {
        return $this->events->eligibilityReport($state);
    }

    /**
     * The loaded content.
     *
     * @return ContentRepository
     */
    public function content(): ContentRepository
    {
        return $this->content;
    }

    /**
     * The event engine.
     *
     * @return EventEngine
     */
    public function events(): EventEngine
    {
        return $this->events;
    }

    /**
     * The cabinet.
     *
     * @return AdvisorSystem
     */
    public function advisors(): AdvisorSystem
    {
        return $this->advisors;
    }

    /**
     * The media system.
     *
     * @return MediaSystem
     */
    public function media(): MediaSystem
    {
        return $this->media;
    }

    /**
     * The event document currently on the desk, if any.
     *
     * @param GameState $state State to read.
     *
     * @return array|null
     *
     * @throws EngineException `unknown_event`.
     */
    public function activeEvent(GameState $state): ?array
    {
        $eventId = $state->get(EventEngine::ACTIVE_EVENT_PATH . '.event_id', null);

        if (!is_string($eventId) || '' === $eventId) {
            return null;
        }

        return $this->content->event($eventId);
    }

    /**
     * Put the scenario's opening event on the desk, or draw one.
     *
     * @param GameState $state    State to mutate.
     * @param array     $scenario Scenario document.
     *
     * @return array|null The presented event, or null for a quiet first month.
     *
     * @throws EngineException `unknown_event`.
     */
    private function openingEvent(GameState $state, array $scenario): ?array
    {
        $openingId = isset($scenario['opening_event']) && is_string($scenario['opening_event'])
            && '' !== $scenario['opening_event']
                ? $scenario['opening_event']
                : null;

        if (null === $openingId) {
            return $this->events->selectNext($state);
        }

        return $this->events->present($state, $this->content->event($openingId), null);
    }

    /**
     * The client-safe event card.
     *
     * Choice internals — effects, hidden effects, flags, counters, delayed consequences,
     * the authored memory, the headlines and the outcome text — never leave the engine on a
     * card: the player learns the consequences by choosing, not by reading the payload.
     *
     * @param GameState   $state      State to read.
     * @param array       $event      Event document.
     * @param string|null $resolvedId Resolved choice id, when the card is decided.
     *
     * @return array
     */
    private function card(GameState $state, array $event, ?string $resolvedId): array
    {
        $card = [];

        foreach (self::CARD_KEYS as $key) {
            $card[$key] = isset($event[$key]) ? $event[$key] : null;
        }

        $card['choices']            = self::publicChoices($event);
        $card['cabinet']            = $this->advisors->positionsFor($state, $event, $resolvedId);
        $card['resolved_choice_id'] = $resolvedId;
        $card['turn_presented']     = $state->get(EventEngine::ACTIVE_EVENT_PATH . '.turn_presented', null);
        $card['forced_by']          = $state->get(EventEngine::ACTIVE_EVENT_PATH . '.forced_by', null);

        return $card;
    }

    /**
     * The choices, stripped of everything the client must not see.
     *
     * @param array $event Event document.
     *
     * @return array<int, array>
     */
    private static function publicChoices(array $event): array
    {
        if (!isset($event['choices']) || !is_array($event['choices'])) {
            return [];
        }

        $choices = [];

        foreach ($event['choices'] as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            foreach (self::PRIVATE_CHOICE_KEYS as $key) {
                unset($choice[$key]);
            }

            $choices[] = $choice;
        }

        return $choices;
    }

    /**
     * The structured facts the AI layer turns into the brief's opening paragraph.
     *
     * @param GameState  $state  State to read.
     * @param array|null $event  Active event document, or null.
     * @param array|null $report Current turn report, or null.
     *
     * @return array `['month_label', 'headline_count', 'active_event_title', 'top_deltas']`
     */
    private static function summaryFacts(GameState $state, ?array $event, ?array $report): array
    {
        $headlines = null !== $report && isset($report['headlines']) && is_array($report['headlines'])
            ? count($report['headlines'])
            : 0;

        $deltas = null !== $report && isset($report['indicator_deltas']) && is_array($report['indicator_deltas'])
            ? $report['indicator_deltas']
            : [];

        return [
            'month_label'        => TurnEngine::monthLabel($state->date()),
            'headline_count'     => $headlines,
            'active_event_title' => null === $event || !isset($event['title']) ? null : (string) $event['title'],
            'top_deltas'         => self::topDeltas($deltas),
        ];
    }

    /**
     * The largest indicator moves of the month, biggest first.
     *
     * @param array $deltas Delta rows from the turn report.
     *
     * @return array<int, array>
     */
    private static function topDeltas(array $deltas): array
    {
        $rows = array_values($deltas);

        usort(
            $rows,
            static function (array $left, array $right): int {
                $a = isset($left['delta']) ? abs((float) $left['delta']) : 0.0;
                $b = isset($right['delta']) ? abs((float) $right['delta']) : 0.0;

                if ($a === $b) {
                    return strcmp(
                        isset($left['path']) ? (string) $left['path'] : '',
                        isset($right['path']) ? (string) $right['path'] : ''
                    );
                }

                return $a < $b ? 1 : -1;
            }
        );

        return array_slice($rows, 0, self::SUMMARY_TOP_DELTAS);
    }

    /**
     * The resolved choice id of the active card, if any.
     *
     * @param GameState $state State to read.
     *
     * @return string|null
     */
    private static function resolvedChoiceId(GameState $state): ?string
    {
        $choiceId = $state->get(EventEngine::ACTIVE_EVENT_PATH . '.resolved_choice_id', null);

        return is_string($choiceId) && '' !== $choiceId ? $choiceId : null;
    }

    /**
     * One block of a scenario's `initial_state`, defaulted to an empty object.
     *
     * @param array  $initial Scenario `initial_state`.
     * @param string $key     Block name.
     *
     * @return array
     */
    private static function block(array $initial, string $key): array
    {
        return isset($initial[$key]) && is_array($initial[$key]) ? $initial[$key] : [];
    }
}
