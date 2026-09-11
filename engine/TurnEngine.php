<?php
/**
 * The month advance.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Runs one turn of the simulation, in an order that never changes.
 *
 * The sequence below is the spec's (section 4.2) and is load-bearing twice over: it decides
 * what a consequence can still influence before the next card is drawn, and it fixes the
 * order in which the RNG is consumed, which is what makes a reloaded save replay a run
 * exactly.
 *
 * 1. refuse to move while a card is unresolved;
 * 2. snapshot the indicators and clear the previous outcome;
 * 3. advance the turn counter and the calendar by one month;
 * 4. term bookkeeping (`ElectionSystem`);
 * 5. the five drift systems, in a fixed order, each with a fixed RNG budget;
 * 6. the delayed consequence queue — which may force next month's event;
 * 7. event selection;
 * 8. build the turn report.
 *
 * Calendar arithmetic is done on the integers in the ISO date rather than with PHP's date
 * functions, so the engine has no clock, no timezone dependency and nothing to mock.
 */
final class TurnEngine
{
    /** Notes carried into one turn report, at most. */
    const MAX_SYSTEM_NOTES = 5;

    /** Where the pre-advance copy of the indicators lives. */
    const SNAPSHOT_PATH = 'indicator_snapshot';

    /** Where the finished report lives. */
    const TURN_REPORT_PATH = 'turn_report';

    /** Month names for `monthLabel()`, index 1-12. */
    const MONTH_NAMES = [
        1  => 'January',
        2  => 'February',
        3  => 'March',
        4  => 'April',
        5  => 'May',
        6  => 'June',
        7  => 'July',
        8  => 'August',
        9  => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];

    /** Days in each month, index 1-12; February is corrected for leap years. */
    const MONTH_LENGTHS = [
        1  => 31,
        2  => 28,
        3  => 31,
        4  => 30,
        5  => 31,
        6  => 30,
        7  => 31,
        8  => 31,
        9  => 30,
        10 => 31,
        11 => 30,
        12 => 31,
    ];

    /** Months in a year. */
    const MONTHS_PER_YEAR = 12;

    /**
     * Event selection.
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
     * Term bookkeeping.
     *
     * @var ElectionSystem
     */
    private ElectionSystem $elections;

    /**
     * The five drift systems, in the order they tick.
     *
     * @var array<int, DriftSystem>
     */
    private array $drift;

    /**
     * @param ContentRepository $content Loaded content repository; the diplomacy system
     *                                   reads country baselines from it.
     * @param EventEngine       $events  Event engine.
     * @param MediaSystem       $media   Media system.
     */
    public function __construct(ContentRepository $content, EventEngine $events, MediaSystem $media)
    {
        $this->events    = $events;
        $this->media     = $media;
        $this->elections = new ElectionSystem();
        $this->drift     = [
            new EconomySystem(),
            new DomesticSystem(),
            new DiplomacySystem($content),
            new CongressSystem(),
            new SecuritySystem(),
        ];
    }

    /**
     * Advance the game by one month.
     *
     * @param GameState $state State to mutate.
     *
     * @return array The turn report (spec section 4.4), also stored on the state.
     *
     * @throws EngineException `decision_required` when the current card is unresolved,
     *                         or `invalid_content` when content is malformed.
     */
    public function advance(GameState $state): array
    {
        CampaignSystem::requireActive($state);
        self::requireResolvedEvent($state);

        $state->set(self::SNAPSHOT_PATH, self::indicators($state));
        $state->set(DecisionEngine::LAST_OUTCOME_PATH, null);

        $state->set('turn', $state->turn() + 1);
        $state->set('date', self::addMonth($state->date()));

        $notes = [$this->elections->tick($state)];
        array_unshift($notes, CampaignSystem::tick($state));
        if ('active' !== $state->get('campaign.status')) {
            // The outgoing administration does not begin the next numbered term.
            return $this->buildReport($state, [$notes[0]], [], null);
        }

        foreach ($this->drift as $system) {
            $notes[] = $system->tick($state);
        }

        $fired  = DelayedConsequenceQueue::tick($state);
        $forced = DelayedConsequenceQueue::forcedEvent($fired);
        $event  = $this->events->selectNext($state, $forced, self::forcingItemId($fired));

        return $this->buildReport($state, $notes, $fired, $event);
    }

    /**
     * A month name and year for an ISO date, e.g. `April 2001`.
     *
     * @param string $isoDate ISO date, `YYYY-MM-DD`.
     *
     * @return string The date verbatim when it cannot be parsed.
     */
    public static function monthLabel(string $isoDate): string
    {
        $parts = self::parseDate($isoDate);

        if (null === $parts) {
            return $isoDate;
        }

        return self::MONTH_NAMES[$parts[1]] . ' ' . $parts[0];
    }

    /**
     * Add one calendar month to an ISO date, keeping the day where possible.
     *
     * @param string $isoDate ISO date, `YYYY-MM-DD`.
     *
     * @return string The date verbatim when it cannot be parsed.
     */
    public static function addMonth(string $isoDate): string
    {
        $parts = self::parseDate($isoDate);

        if (null === $parts) {
            return $isoDate;
        }

        list($year, $month, $day) = $parts;

        $month++;

        if ($month > self::MONTHS_PER_YEAR) {
            $month = 1;
            $year++;
        }

        $length = self::daysInMonth($year, $month);

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $length));
    }

    /**
     * Build the turn report and store it on the state.
     *
     * @param GameState  $state State to mutate.
     * @param array      $notes Note lists, one per system, in tick order.
     * @param array      $fired Fired consequence records from the queue.
     * @param array|null $event Newly presented event document, or null.
     *
     * @return array The report.
     */
    private function buildReport(GameState $state, array $notes, array $fired, ?array $event): array
    {
        $consequences = [];
        $headlines    = [];

        foreach ($fired as $record) {
            $headline = null;

            if (isset($record['headline']) && is_array($record['headline'])) {
                $headline = $this->media->resolveAuthored($state, $record['headline'], $event, null);
            }

            if (null !== $headline) {
                $headlines[] = $headline;
            }

            $consequences[] = [
                'id'             => isset($record['id']) ? (string) $record['id'] : '',
                'label'          => isset($record['label']) ? (string) $record['label'] : '',
                'headline'       => $headline,
                'visible_deltas' => isset($record['visible_deltas']) && is_array($record['visible_deltas'])
                    ? $record['visible_deltas']
                    : [],
            ];
        }

        $ambient = $this->media->ambientHeadline($state, $event);

        if (null !== $ambient) {
            $headlines[] = $ambient;
        }

        if ([] !== $headlines) {
            $this->media->append($state, $headlines, self::reportSource($fired, $event));
        }

        $report = [
            'turn'              => $state->turn(),
            'date'              => $state->date(),
            'month_label'       => self::monthLabel($state->date()),
            'indicator_deltas'  => self::indicatorDeltas($state),
            'system_notes'      => self::selectNotes($notes),
            'fired_consequences' => $consequences,
            'headlines'         => $headlines,
            'active_event_id'   => null === $event ? null : (string) $event['id'],
        ];

        $state->set(self::TURN_REPORT_PATH, $report);

        return $report;
    }

    /**
     * Deltas between the indicators now and the snapshot taken before the advance.
     *
     * @param GameState $state State to read.
     *
     * @return array<int, array> `['path', 'label', 'delta', 'display']`, non-zero only.
     */
    private static function indicatorDeltas(GameState $state): array
    {
        $before = $state->get(self::SNAPSHOT_PATH, []);
        $after  = self::indicators($state);
        $rows   = [];

        if (!is_array($before)) {
            return $rows;
        }

        foreach ($after as $key => $value) {
            if (!is_numeric($value) || !isset($before[$key]) || !is_numeric($before[$key])) {
                continue;
            }

            $delta = round((float) $value - (float) $before[$key], Effects::PRECISION);

            if (abs($delta) < Effects::EPSILON) {
                continue;
            }

            $path   = 'public.' . $key;
            $rows[] = [
                'path'    => $path,
                'label'   => Effects::label($path),
                'delta'   => $delta,
                'display' => Effects::formatDelta($delta),
            ];
        }

        return $rows;
    }

    /**
     * The public indicator block.
     *
     * @param GameState $state State to read.
     *
     * @return array<string, mixed>
     */
    private static function indicators(GameState $state): array
    {
        $public = $state->get('public', []);

        return is_array($public) ? $public : [];
    }

    /**
     * Choose which system notes make the brief.
     *
     * Round-robin across the systems rather than first-come, so a talkative economy cannot
     * crowd out the one sentence the Situation Room had to offer.
     *
     * @param array<int, array<int, string>> $notes Note lists in tick order.
     *
     * @return array<int, string>
     */
    private static function selectNotes(array $notes): array
    {
        $selected = [];
        $depth    = 0;
        $deepest  = 0;

        foreach ($notes as $list) {
            $deepest = max($deepest, count($list));
        }

        while ($depth < $deepest && count($selected) < self::MAX_SYSTEM_NOTES) {
            foreach ($notes as $list) {
                if (!isset($list[$depth])) {
                    continue;
                }

                $selected[] = (string) $list[$depth];

                if (count($selected) >= self::MAX_SYSTEM_NOTES) {
                    break;
                }
            }

            $depth++;
        }

        return $selected;
    }

    /**
     * The id of the consequence whose forced event is being honoured this turn.
     *
     * @param array $fired Fired records from the queue.
     *
     * @return string|null
     */
    private static function forcingItemId(array $fired): ?string
    {
        foreach ($fired as $record) {
            if (isset($record['trigger_event']) && is_string($record['trigger_event'])) {
                return isset($record['id']) ? (string) $record['id'] : null;
            }
        }

        return null;
    }

    /**
     * Provenance recorded against the headlines a turn advance produces.
     *
     * @param array      $fired Fired records from the queue.
     * @param array|null $event Newly presented event, or null.
     *
     * @return string
     */
    private static function reportSource(array $fired, ?array $event): string
    {
        if ([] !== $fired && isset($fired[0]['id'])) {
            return (string) $fired[0]['id'];
        }

        return null === $event ? MediaSystem::AMBIENT_SOURCE : (string) $event['id'];
    }

    /**
     * Refuse to advance while a card is waiting for a decision.
     *
     * @param GameState $state State to read.
     *
     * @return void
     *
     * @throws EngineException `decision_required`.
     */
    private static function requireResolvedEvent(GameState $state): void
    {
        $active = $state->get(DecisionEngine::ACTIVE_EVENT_PATH, null);

        if (!is_array($active) || !isset($active['event_id'])) {
            return;
        }

        if (isset($active['resolved_choice_id']) && null !== $active['resolved_choice_id']) {
            return;
        }

        throw new EngineException(
            EngineException::DECISION_REQUIRED,
            sprintf('Event "%s" is still awaiting a decision.', (string) $active['event_id'])
        );
    }

    /**
     * Split an ISO date into `[year, month, day]`.
     *
     * @param string $isoDate ISO date, `YYYY-MM-DD`.
     *
     * @return array|null Null when the string is not a valid calendar date.
     */
    private static function parseDate(string $isoDate): ?array
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $matches)) {
            return null;
        }

        $year  = (int) $matches[1];
        $month = (int) $matches[2];
        $day   = (int) $matches[3];

        if ($month < 1 || $month > self::MONTHS_PER_YEAR || $day < 1 || $day > self::daysInMonth($year, $month)) {
            return null;
        }

        return [$year, $month, $day];
    }

    /**
     * Days in a calendar month, leap years included.
     *
     * @param int $year  Four-digit year.
     * @param int $month Month, 1-12.
     *
     * @return int
     */
    private static function daysInMonth(int $year, int $month): int
    {
        if (2 === $month && self::isLeapYear($year)) {
            return 29;
        }

        return isset(self::MONTH_LENGTHS[$month]) ? self::MONTH_LENGTHS[$month] : 30;
    }

    /**
     * Whether a year is a leap year in the proleptic Gregorian calendar.
     *
     * @param int $year Four-digit year.
     *
     * @return bool
     */
    private static function isLeapYear(int $year): bool
    {
        if (0 !== $year % 4) {
            return false;
        }

        return 0 !== $year % 100 || 0 === $year % 400;
    }
}
