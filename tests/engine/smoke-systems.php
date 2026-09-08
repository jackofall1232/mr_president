<?php
/**
 * Self-check for the engine systems against the shipped content.
 *
 *     php tests/engine/smoke-systems.php
 *
 * Plays eight months of "A New Administration" under a fixed seed — new game, decide,
 * advance, repeat — printing a compact trace, and then plays exactly the same eight months
 * a second time while serialising the state to JSON and rebuilding it after *every* step.
 * The two runs must end with a byte-identical `toArray()`.
 *
 * That is the whole point of the exercise: the RNG position, the delayed queue, the
 * cooldowns and the media log all travel inside the state document, so a player who closes
 * the tab mid-term and comes back must get the run they left, not a re-roll of it.
 *
 * Including this file does nothing: the run only starts when it is the entry script, so a
 * test runner that sweeps `tests/engine/` cannot be terminated by it.
 *
 * @package MrPresident\Engine\Tests
 */

declare(strict_types=1);

namespace MrPresident\Engine\Tests;

use MrPresident\Engine\AdvisorSystem;
use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\EngineException;
use MrPresident\Engine\EventEngine;
use MrPresident\Engine\GameEngine;
use MrPresident\Engine\GameState;
use MrPresident\Engine\MediaSystem;
use MrPresident\Engine\TurnEngine;

require_once __DIR__ . '/bootstrap-engine.php';

/**
 * A scripted eight-month run, played twice.
 */
final class SmokeSystems
{
    /** Content directory of the shipped plugin. */
    const DATA_DIR = __DIR__ . '/../../mr-president-game/data';

    /** Scenario under test. */
    const SCENARIO = 'new-administration';

    /** Player name used for `{president}` substitutions. */
    const PRESIDENT = 'Test President';

    /** Fixed seed; the whole check is meaningless without one. */
    const SEED = 42;

    /** Months played. */
    const TURNS = 8;

    /** The choice index is `turn % CHOICE_CYCLE`, so the run exercises every option. */
    const CHOICE_CYCLE = 4;

    /** Advisors the default set must contain. */
    const EXPECTED_ADVISORS = 6;

    /** Keys the briefing's structured summary must carry. */
    const SUMMARY_KEYS = ['month_label', 'headline_count', 'active_event_title', 'top_deltas'];

    /**
     * Labels of the checks that failed.
     *
     * @var array<int, string>
     */
    private static array $failures = [];

    /** How many checks ran. */
    private static int $checks = 0;

    /**
     * Play the run twice, compare, and report.
     *
     * @return int Process exit code.
     */
    public static function run(): int
    {
        self::$failures = [];
        self::$checks   = 0;

        $straight = self::play(false);
        $reloaded = self::play(true);
        $repeat   = self::play(false);

        self::printTrace($straight['trace']);

        self::ok(
            $straight['final'] === $reloaded['final'],
            'save/load round-trip after every step produces an identical final state'
        );
        self::ok(
            $straight['trace'] === $reloaded['trace'],
            'save/load round-trip produces an identical step-by-step trace'
        );
        self::ok(
            $straight['final'] === $repeat['final'],
            'replaying the same seed produces an identical final state'
        );

        self::checkShape($straight);
        self::checkBriefing();
        self::checkEligibility();
        self::checkGuards();

        if ([] === self::$failures) {
            echo 'engine systems smoke: ' . self::$checks . " checks, all passed\n";

            return 0;
        }

        echo 'engine systems smoke: ' . count(self::$failures) . ' of ' . self::$checks . " checks FAILED\n";

        foreach (self::$failures as $failure) {
            echo '  - ' . $failure . "\n";
        }

        return 1;
    }

    /**
     * Play the scripted run.
     *
     * @param bool $reload Whether to serialise and rebuild the state after every step.
     *
     * @return array `['trace' => array<int, array>, 'final' => string, 'state' => GameState]`
     */
    private static function play(bool $reload): array
    {
        $engine = self::engine();
        $state  = $engine->newGame(self::SCENARIO, self::PRESIDENT, self::SEED);
        $state  = $reload ? self::roundTrip($state) : $state;
        $trace  = [];

        for ($step = 0; $step < self::TURNS; $step++) {
            $row = [
                'turn'     => $state->turn(),
                'date'     => $state->date(),
                'event'    => self::activeEventId($state),
                'choice'   => null,
                'visible'  => 0,
                'hidden'   => 0,
                'delayed'  => 0,
                'approval' => self::approval($state),
            ];

            if (null !== $row['event']) {
                $choiceId = self::choiceFor($engine, $row['event'], $state->turn());
                $outcome  = $engine->applyDecision($state, $choiceId);
                $state    = $reload ? self::roundTrip($state) : $state;

                $row['choice']  = $choiceId;
                $row['visible'] = count($outcome['visible_deltas']);
                $row['hidden']  = (int) $outcome['hidden_change_count'];
                $row['delayed'] = (int) $outcome['delayed_count'];
            }

            $report = $engine->advanceTurn($state);
            $state  = $reload ? self::roundTrip($state) : $state;

            $row['notes']     = count($report['system_notes']);
            $row['fired']     = count($report['fired_consequences']);
            $row['headlines'] = count($report['headlines']);
            $trace[]          = $row;
        }

        return [
            'trace' => $trace,
            'final' => self::encode($state->toArray()),
            'state' => $state,
        ];
    }

    /**
     * Structural checks on the finished run.
     *
     * @param array $run Result of `play()`.
     *
     * @return void
     */
    private static function checkShape(array $run): void
    {
        $state = $run['state'];
        $data  = $state->toArray();

        self::ok(self::TURNS + 1 === $data['turn'], 'eight advances land on turn ' . (self::TURNS + 1));
        self::ok(1 === $data['term'], 'the run stays inside the first term');
        self::ok('2001-09-20' === $data['date'], 'the calendar reaches 2001-09-20 (got ' . $data['date'] . ')');
        self::ok(
            false === $data['flags']['election_year'],
            'the election-year flag is off in the first year of a term'
        );

        $decisions = 0;

        foreach ($run['trace'] as $row) {
            if (null !== $row['choice']) {
                $decisions++;
            }
        }

        self::ok($decisions > 0, 'the run took at least one decision');
        self::ok(count($data['event_log']) === $decisions, 'every decision is recorded in the event log');
        self::ok(count($data['memories']) >= $decisions, 'every decision left at least one memory');
        self::ok(
            count($data['media_log']) <= MediaSystem::MEDIA_LOG_MAX,
            'the media log stays within its cap'
        );
        self::ok([] !== $data['media_log'], 'the media log is not empty');

        $presented = $decisions + (null === $data['active_event'] ? 0 : 1);

        self::ok(
            count($data['seen_events']) === $presented,
            'seen_events holds one entry per presentation (expected ' . $presented
            . ', got ' . count($data['seen_events']) . ')'
        );
        self::ok(
            self::quietMonths($run['trace']) < self::TURNS,
            'the scenario does not produce eight quiet months in a row'
        );

        foreach ($data['cooldowns'] as $eventId => $until) {
            self::ok(is_int($until), 'cooldown for "' . $eventId . '" is an integer turn');
        }

        self::ok(is_array($data['delayed_queue']), 'the delayed queue survives as a list');
        self::ok(null === $data['last_outcome'], 'the outcome is cleared by the advance that follows it');
        self::ok(
            self::SEED === $data['seed'] && $data['rng']['state'] !== self::SEED,
            'the seed is preserved while the RNG position has moved'
        );
    }

    /**
     * Checks on `buildBriefing()`.
     *
     * @return void
     */
    private static function checkBriefing(): void
    {
        $engine   = self::engine();
        $state    = $engine->newGame(self::SCENARIO, self::PRESIDENT, self::SEED);
        $briefing = $engine->buildBriefing($state);

        self::ok('January 2001' === $briefing['month_label'], 'the briefing labels the inauguration month');
        self::ok(1 === $briefing['turn'] && 1 === $briefing['term'], 'the briefing reports turn 1 of term 1');
        self::ok(is_array($briefing['summary']), 'the briefing summary is structured, not prose');

        foreach (self::SUMMARY_KEYS as $key) {
            self::ok(array_key_exists($key, $briefing['summary']), 'the summary carries "' . $key . '"');
        }

        self::ok(
            'Growth Stalls Ahead of Forecast' === $briefing['summary']['active_event_title'],
            'the summary names the opening event'
        );
        self::ok(null !== $briefing['event'], 'the opening event is on the desk from turn 1');
        self::ok(
            self::EXPECTED_ADVISORS === count($briefing['cabinet']),
            'the cabinet has ' . self::EXPECTED_ADVISORS . ' advisors'
        );

        foreach ($briefing['cabinet'] as $advisor) {
            self::ok(
                '' !== $advisor['position'] && AdvisorSystem::DEFAULT_POSITION !== $advisor['position'],
                'advisor "' . $advisor['advisor_id'] . '" has an authored position on the opening event'
            );
        }

        $choice = $briefing['event']['choices'][0];

        foreach (['effects', 'hidden_effects', 'delayed', 'memory', 'headlines', 'outcome_text'] as $secret) {
            self::ok(!isset($choice[$secret]), 'the event card hides choice "' . $secret . '" from the client');
        }

        self::ok(isset($choice['id'], $choice['label']), 'the event card keeps the id and label of each choice');

        $engine->applyDecision($state, (string) $choice['id']);
        $decided = $engine->buildBriefing($state);

        self::ok(
            (string) $choice['id'] === $decided['event']['resolved_choice_id'],
            'the card reports the resolved choice after a decision'
        );
    }

    /**
     * Checks on the developer-panel eligibility report.
     *
     * @return void
     */
    private static function checkEligibility(): void
    {
        $engine = self::engine();
        $state  = $engine->newGame(self::SCENARIO, self::PRESIDENT, self::SEED);
        $report = $engine->eligibilityReport($state);
        $rows   = [];

        foreach ($report as $row) {
            $rows[$row['event_id']] = $row;
        }

        self::ok(
            count($rows) === count($engine->content()->events()) + 1,
            'the eligibility report covers every event plus the quiet-month entry'
        );
        self::ok(isset($rows[EventEngine::QUIET_KEY]), 'the report includes the quiet-month pseudo-entry');
        self::ok(
            false === $rows['economic-slowdown']['eligible'],
            'the opening event is on cooldown immediately after being presented'
        );
        self::ok(
            [] !== $rows['economic-slowdown']['reasons'],
            'an ineligible event explains itself'
        );
        self::ok(
            false === $rows['trade-retaliation']['eligible'],
            'an event gated on an unset flag is ineligible'
        );
        self::ok(
            $rows['foreign-missile-test']['weight'] > 0.0
            || false === $rows['foreign-missile-test']['eligible'],
            'an eligible event carries a positive weight'
        );
    }

    /**
     * Checks on the error codes the plugin layer maps to HTTP statuses.
     *
     * @return void
     */
    private static function checkGuards(): void
    {
        $engine = self::engine();
        $state  = $engine->newGame(self::SCENARIO, self::PRESIDENT, self::SEED);

        self::ok(
            self::throwsCode(
                static function () use ($engine, $state): void {
                    $engine->advanceTurn($state);
                },
                EngineException::DECISION_REQUIRED
            ),
            'advancing with an unresolved card throws decision_required'
        );

        self::ok(
            self::throwsCode(
                static function () use ($engine, $state): void {
                    $engine->applyDecision($state, 'no-such-choice');
                },
                EngineException::UNKNOWN_CHOICE
            ),
            'an unknown choice id throws unknown_choice'
        );

        $engine->applyDecision($state, 'stimulus-package');

        self::ok(
            self::throwsCode(
                static function () use ($engine, $state): void {
                    $engine->applyDecision($state, 'fiscal-restraint');
                },
                EngineException::EVENT_ALREADY_RESOLVED
            ),
            'deciding twice throws event_already_resolved'
        );

        self::ok(
            self::throwsCode(
                static function () use ($engine): void {
                    $engine->newGame('no-such-scenario', self::PRESIDENT, self::SEED);
                },
                EngineException::UNKNOWN_SCENARIO
            ),
            'an unknown scenario throws unknown_scenario'
        );

        self::ok(
            'February 2001' === TurnEngine::monthLabel(TurnEngine::addMonth('2001-01-20')),
            'the calendar advances one month'
        );
        self::ok('2002-01-31' === TurnEngine::addMonth('2001-12-31'), 'the calendar rolls over the year end');
        self::ok('2001-02-28' === TurnEngine::addMonth('2001-01-31'), 'a short month clamps the day');
        self::ok('2004-02-29' === TurnEngine::addMonth('2004-01-31'), 'February gains a day in a leap year');
    }

    /**
     * Print the trace of a run.
     *
     * @param array<int, array> $trace Rows from `play()`.
     *
     * @return void
     */
    private static function printTrace(array $trace): void
    {
        echo "turn  date        event                        choice                  appr   vis hid dly  notes fired heads\n";

        foreach ($trace as $row) {
            printf(
                "%-5d %-11s %-28s %-23s %5.1f  %3d %3d %3d  %5d %5d %5d\n",
                $row['turn'],
                $row['date'],
                null === $row['event'] ? '(quiet month)' : $row['event'],
                null === $row['choice'] ? '-' : $row['choice'],
                $row['approval'],
                $row['visible'],
                $row['hidden'],
                $row['delayed'],
                $row['notes'],
                $row['fired'],
                $row['headlines']
            );
        }

        echo "\n";
    }

    /**
     * A fresh engine over the shipped content.
     *
     * @return GameEngine
     */
    private static function engine(): GameEngine
    {
        return new GameEngine(new ContentRepository(self::DATA_DIR));
    }

    /**
     * Serialise a state to JSON and rebuild it, exactly as the save layer does.
     *
     * @param GameState $state State to round-trip.
     *
     * @return GameState
     */
    private static function roundTrip(GameState $state): GameState
    {
        $decoded = json_decode(self::encode($state->toArray()), true);

        return GameState::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * Encode a state document the way the save layer does.
     *
     * @param array $data State document.
     *
     * @return string
     */
    private static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $json ? '' : $json;
    }

    /**
     * The choice this script picks for a turn.
     *
     * @param GameEngine $engine  Engine, for the content.
     * @param string     $eventId Active event id.
     * @param int        $turn    Current turn.
     *
     * @return string
     */
    private static function choiceFor(GameEngine $engine, string $eventId, int $turn): string
    {
        $choices = $engine->content()->event($eventId)['choices'];
        $index   = ($turn % self::CHOICE_CYCLE) % count($choices);

        return (string) $choices[$index]['id'];
    }

    /**
     * The active event id, if any.
     *
     * @param GameState $state State to read.
     *
     * @return string|null
     */
    private static function activeEventId(GameState $state): ?string
    {
        $eventId = $state->get(EventEngine::ACTIVE_EVENT_PATH . '.event_id', null);

        return is_string($eventId) ? $eventId : null;
    }

    /**
     * Current approval, for the trace.
     *
     * @param GameState $state State to read.
     *
     * @return float
     */
    private static function approval(GameState $state): float
    {
        $approval = $state->get('public.approval', 0);

        return is_numeric($approval) ? (float) $approval : 0.0;
    }

    /**
     * How many months of a trace had no event.
     *
     * @param array<int, array> $trace Trace rows.
     *
     * @return int
     */
    private static function quietMonths(array $trace): int
    {
        $quiet = 0;

        foreach ($trace as $row) {
            if (null === $row['event']) {
                $quiet++;
            }
        }

        return $quiet;
    }

    /**
     * Record one check.
     *
     * @param bool   $condition Whether the check passed.
     * @param string $label     What was being checked.
     *
     * @return void
     */
    private static function ok(bool $condition, string $label): void
    {
        self::$checks++;

        if (!$condition) {
            self::$failures[] = $label;
        }
    }

    /**
     * Whether a callable throws an `EngineException` carrying a given code.
     *
     * @param callable $callable Code that should throw.
     * @param string   $code     Expected engine code.
     *
     * @return bool
     */
    private static function throwsCode(callable $callable, string $code): bool
    {
        try {
            $callable();
        } catch (EngineException $exception) {
            return $code === $exception->code();
        }

        return false;
    }
}

$entryScript = get_included_files();

if (__FILE__ === reset($entryScript)) {
    exit(SmokeSystems::run());
}
