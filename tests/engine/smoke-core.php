<?php
/**
 * Self-check for the engine core primitives.
 *
 * Runs without WordPress, without content files and without the rest of the engine:
 *
 *     php tests/engine/smoke-core.php
 *
 * It exercises Random determinism and serialization, GameState dot paths and round-trips,
 * Effects clamping and labelling, the Conditions matrix, MemorySystem and the delayed
 * consequence queue against a hand-built state. The full suite in `tests/run.php`
 * supersedes it; this script stays useful because it needs nothing but the engine files it
 * checks.
 *
 * Including this file does nothing: the run only starts when it is the entry script, so a
 * test runner that sweeps `tests/engine/` cannot be terminated by it.
 *
 * @package MrPresident\Engine\Tests
 */

declare(strict_types=1);

namespace MrPresident\Engine\Tests;

use MrPresident\Engine\Conditions;
use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\DelayedConsequenceQueue;
use MrPresident\Engine\Effects;
use MrPresident\Engine\EngineException;
use MrPresident\Engine\GameState;
use MrPresident\Engine\MemorySystem;
use MrPresident\Engine\Random;
use MrPresident\Engine\Schema;

require_once __DIR__ . '/bootstrap-engine.php';

/**
 * A flat list of assertions over the engine core primitives.
 */
final class SmokeCore
{
    /** Seed used by every hand-built state. */
    const SEED = 20010120;

    /** Tolerance when comparing floats. */
    const EPSILON = 0.000001;

    /**
     * Labels of the checks that failed.
     *
     * @var array<int, string>
     */
    private static array $failures = [];

    /** How many checks ran. */
    private static int $checks = 0;

    /**
     * Run every section and print a report.
     *
     * @return int Process exit code.
     */
    public static function run(): int
    {
        self::$failures = [];
        self::$checks   = 0;

        self::checkRandom();
        self::checkGameState();
        self::checkEffects();
        self::checkConditions();
        self::checkMemory();
        self::checkQueue();
        self::checkContentRepository();

        if ([] === self::$failures) {
            echo 'engine core smoke: ' . self::$checks . " checks, all passed\n";

            return 0;
        }

        echo 'engine core smoke: ' . count(self::$failures) . ' of ' . self::$checks . " checks FAILED\n";

        foreach (self::$failures as $failure) {
            echo '  FAIL  ' . $failure . "\n";
        }

        return 1;
    }

    // -----------------------------------------------------------------------------------
    // Random
    // -----------------------------------------------------------------------------------

    /**
     * Determinism, range and serialization of the PRNG.
     *
     * @return void
     */
    private static function checkRandom(): void
    {
        $left  = new Random(12345);
        $right = new Random(12345);

        $sequenceA = [];
        $sequenceB = [];

        for ($i = 0; $i < 25; $i++) {
            $sequenceA[] = $left->nextInt();
            $sequenceB[] = $right->nextInt();
        }

        self::ok($sequenceA === $sequenceB, 'Random: same seed produces the same sequence');
        self::ok(count(array_unique($sequenceA)) === count($sequenceA), 'Random: 25 draws are distinct');

        $inRange = true;

        foreach ($sequenceA as $value) {
            if ($value < 1 || $value > 0xFFFFFFFF) {
                $inRange = false;
            }
        }

        self::ok($inRange, 'Random: nextInt stays inside 1..2^32-1');
        self::ok(
            (new Random(0))->toArray() === (new Random(Random::ZERO_SEED_REPLACEMENT))->toArray(),
            'Random: seed 0 is remapped to a non-zero state'
        );

        $mid = new Random(777);
        $mid->nextInt();
        $mid->nextInt();

        $resumed   = Random::fromArray($mid->toArray());
        $expected  = [];
        $continued = [];

        for ($i = 0; $i < 5; $i++) {
            $expected[]  = $mid->nextInt();
            $continued[] = $resumed->nextInt();
        }

        self::ok($expected === $continued, 'Random: serialize/restore resumes mid-sequence');

        $floats  = new Random(99);
        $bounded = true;

        for ($i = 0; $i < 200; $i++) {
            $value = $floats->nextFloat();

            if ($value < 0.0 || $value >= 1.0) {
                $bounded = false;
            }
        }

        self::ok($bounded, 'Random: nextFloat stays in [0,1)');

        $ranged   = new Random(4242);
        $inBounds = true;

        for ($i = 0; $i < 200; $i++) {
            $value = $ranged->range(3, 7);

            if ($value < 3 || $value > 7) {
                $inBounds = false;
            }
        }

        self::ok($inBounds, 'Random: range is inclusive on both ends');
        self::same(5, (new Random(11))->range(5, 5), 'Random: a one-value range always returns it');
        self::ok(true === (new Random(11))->chance(1.0), 'Random: chance(1.0) is always true');
        self::ok(false === (new Random(11))->chance(0.0), 'Random: chance(0.0) is always false');

        $picker = new Random(5150);

        self::ok(null === $picker->weightedPick([]), 'Random: weightedPick returns null for an empty pool');
        self::ok(
            null === $picker->weightedPick(['a' => 0, 'b' => -3]),
            'Random: weightedPick returns null when every weight is zero or less'
        );
        self::same('only', $picker->weightedPick(['only' => 4]), 'Random: weightedPick returns the sole candidate');

        $picksA = [];
        $picksB = [];
        $one    = new Random(31337);
        $two    = new Random(31337);

        for ($i = 0; $i < 40; $i++) {
            $picksA[] = $one->weightedPick(['low' => 1, 'high' => 9]);
            $picksB[] = $two->weightedPick(['low' => 1, 'high' => 9]);
        }

        self::ok($picksA === $picksB, 'Random: weightedPick is deterministic for a given seed');
        self::same(0, count(array_diff($picksA, ['low', 'high'])), 'Random: weightedPick only returns supplied keys');
    }

    // -----------------------------------------------------------------------------------
    // GameState and Schema
    // -----------------------------------------------------------------------------------

    /**
     * Dot paths, defaults and lossless round-trips.
     *
     * @return void
     */
    private static function checkGameState(): void
    {
        $state = self::state();

        self::same(1, Schema::VERSION, 'Schema: version 1 is current');
        self::same(1, $state->turn(), 'GameState: turn reader');
        self::same(1, $state->term(), 'GameState: term reader');
        self::same('2001-01-20', $state->date(), 'GameState: date reader');
        self::same(52.0, $state->get('public.approval'), 'GameState: dot-path read');
        self::same(35.0, $state->get('countries.china.trust'), 'GameState: nested dot-path read');
        self::same('fallback', $state->get('countries.nowhere.trust', 'fallback'), 'GameState: default for a missing path');
        self::ok(true === $state->has('public.approval'), 'GameState: has() finds an existing path');
        self::ok(false === $state->has('public.nothing'), 'GameState: has() rejects a missing path');

        $state->set('flags.export_controls', true);
        self::ok(true === $state->get('flags.export_controls'), 'GameState: dot-path write');

        $state->set('deep.nested.value', 7);
        self::same(7, $state->get('deep.nested.value'), 'GameState: write creates intermediate arrays');

        $state->push('event_log', ['event_id' => 'test-event', 'turn' => 1]);
        self::same(1, count($state->get('event_log')), 'GameState: push appends to a list');

        $state->delete('deep.nested.value');
        self::ok(false === $state->has('deep.nested.value'), 'GameState: delete removes a path');

        $snapshot = $state->toArray();

        self::same(Schema::VERSION, $snapshot['schema_version'], 'GameState: toArray stamps the schema version');
        self::ok(isset($snapshot['rng']['state']), 'GameState: toArray persists the RNG state');
        self::ok(array_key_exists('memories', $snapshot), 'GameState: defaults fill missing containers');

        $state->rng()->nextInt();
        $state->rng()->nextInt();

        $afterDraws = $state->toArray();

        self::ok($afterDraws['rng']['state'] !== $snapshot['rng']['state'], 'GameState: the RNG position travels with the state');

        $reloaded = GameState::fromArray($afterDraws);

        self::same($state->rng()->nextInt(), $reloaded->rng()->nextInt(), 'GameState: reload continues the same RNG stream');
        self::ok(GameState::fromArray($afterDraws)->toArray() == $afterDraws, 'GameState: toArray/fromArray round-trips losslessly');

        $migrated = GameState::fromArray(['seed' => 42]);

        self::same(Schema::VERSION, $migrated->toArray()['schema_version'], 'Schema: a versionless document is stamped');
        self::same(1, $migrated->turn(), 'GameState: a partial document still starts on turn 1');

        $threw = self::throwsCode(
            static function (): void {
                GameState::fromArray(['schema_version' => Schema::VERSION + 1]);
            },
            EngineException::INVALID_CONTENT
        );

        self::ok($threw, 'Schema: a future schema version is refused');
    }

    // -----------------------------------------------------------------------------------
    // Effects
    // -----------------------------------------------------------------------------------

    /**
     * Operations, clamping, delta rows and labels.
     *
     * @return void
     */
    private static function checkEffects(): void
    {
        $state  = self::state();
        $deltas = Effects::apply(
            $state,
            [
                'public.approval'                      => -2,
                'countries.rival_state_a.relationship' => -6,
                'hidden.credibility'                   => 3,
                'flags.sanctions_rival_a'              => true,
                'flags.posture'                        => '=forward',
                'public.crisis_level'                  => '=40',
                'counters.sanctions_used'              => 1,
            ]
        );

        self::same(50.0, $state->get('public.approval'), 'Effects: a numeric value adds');
        self::same(-46.0, $state->get('countries.rival_state_a.relationship'), 'Effects: country relationship adds');
        self::same(58.0, $state->get('hidden.credibility'), 'Effects: a hidden value adds');
        self::ok(true === $state->get('flags.sanctions_rival_a'), 'Effects: a boolean sets a flag');
        self::same('forward', $state->get('flags.posture'), 'Effects: "=" prefixed text sets verbatim');
        self::same(40.0, $state->get('public.crisis_level'), 'Effects: "=" prefixed number sets numerically');
        self::same(1.0, $state->get('counters.sanctions_used'), 'Effects: counters add');
        self::same(5, count($deltas), 'Effects: only numeric changes produce delta rows');
        self::same(3, count(Effects::visibleOnly($deltas)), 'Effects: hidden paths and counters are not visible deltas');
        self::same('public.approval', $deltas[0]['path'], 'Effects: delta rows keep authoring order');
        self::same('-2', $deltas[0]['display'], 'Effects: whole deltas display without decimals');

        $clamped = self::state();

        Effects::apply($clamped, ['public.approval' => 500]);
        self::same(100.0, $clamped->get('public.approval'), 'Effects: clamps to the upper bound');

        Effects::apply($clamped, ['public.approval' => -500]);
        self::same(0.0, $clamped->get('public.approval'), 'Effects: clamps to the lower bound');

        Effects::apply($clamped, ['hidden.credibility' => 900]);
        self::same(100.0, $clamped->get('hidden.credibility'), 'Effects: the hidden.* wildcard bound applies');

        Effects::apply($clamped, ['countries.china.relationship' => -400]);
        self::same(-100.0, $clamped->get('countries.china.relationship'), 'Effects: relationship floors at -100');

        Effects::apply($clamped, ['countries.china.trust' => -400]);
        self::same(0.0, $clamped->get('countries.china.trust'), 'Effects: other country measures floor at 0');

        Effects::apply($clamped, ['counters.sanctions_used' => -5]);
        self::same(0.0, $clamped->get('counters.sanctions_used'), 'Effects: counters never go below zero');

        Effects::apply($clamped, ['public.national_debt' => 99999]);
        self::same(105699.0, $clamped->get('public.national_debt'), 'Effects: national debt has no upper bound');

        self::same(0, count(Effects::apply($clamped, ['public.approval' => -3])), 'Effects: a clamped no-op produces no row');

        $fractional = Effects::apply(self::state(), ['public.gdp_growth' => -0.5]);

        self::same('-0.5', $fractional[0]['display'], 'Effects: fractional deltas keep one decimal');
        self::same(-0.5, $fractional[0]['delta'], 'Effects: the raw delta is unrounded');

        self::same('Approval', Effects::label('public.approval'), 'Effects: known label');
        self::same('GDP Growth', Effects::label('public.gdp_growth'), 'Effects: acronyms stay upper-case');
        self::same('China · Trust', Effects::label('countries.china.trust'), 'Effects: country label');
        self::same(
            'Rival State A · Military Tension',
            Effects::label('countries.rival_state_a.military_tension'),
            'Effects: humanised country label'
        );
        self::same('Export Controls', Effects::label('flags.export_controls'), 'Effects: fallback label');
        self::ok(true === Effects::isVisible('public.approval'), 'Effects: public paths are visible');
        self::ok(true === Effects::isVisible('countries.china.trust'), 'Effects: country paths are visible');
        self::ok(false === Effects::isVisible('hidden.credibility'), 'Effects: hidden paths are not visible');
        self::ok(false === Effects::isVisible('flags.posture'), 'Effects: flags are not visible');
        self::ok(false === Effects::isVisible('counters.sanctions_used'), 'Effects: counters are not visible');
    }

    // -----------------------------------------------------------------------------------
    // Conditions
    // -----------------------------------------------------------------------------------

    /**
     * Every clause type, plus nesting and the failure mode for bad content.
     *
     * @return void
     */
    private static function checkConditions(): void
    {
        $state = self::state();
        $state->set('flags.export_controls', true);
        $state->set('counters.sanctions_used', 2);
        $state->set('turn', 6);

        self::ok(true === Conditions::evaluate($state, []), 'Conditions: an empty list is true');
        self::ok(
            true === Conditions::evaluate($state, [['path' => 'hidden.recession_pressure', 'op' => '>=', 'value' => 30]]),
            'Conditions: >= on a hidden path'
        );
        self::ok(
            false === Conditions::evaluate($state, [['path' => 'hidden.recession_pressure', 'op' => '>', 'value' => 30]]),
            'Conditions: > is strict'
        );
        self::ok(
            true === Conditions::evaluate($state, [['path' => 'public.approval', 'op' => '==', 'value' => 52]]),
            'Conditions: == compares numerically'
        );
        self::ok(
            true === Conditions::evaluate($state, [['path' => 'public.approval', 'op' => '!=', 'value' => 51]]),
            'Conditions: != compares numerically'
        );
        self::ok(
            true === Conditions::evaluate($state, [['path' => 'public.approval', 'op' => '<=', 'value' => 52]]),
            'Conditions: <= includes equality'
        );
        self::ok(
            true === Conditions::evaluate($state, [['path' => 'countries.china.trust', 'op' => '<', 'value' => 40]]),
            'Conditions: < on a country path'
        );
        self::ok(
            false === Conditions::evaluate($state, [['path' => 'public.missing', 'op' => '>', 'value' => 0]]),
            'Conditions: a missing numeric path reads as zero'
        );
        self::ok(
            true === Conditions::evaluate($state, [['path' => 'flags.export_controls', 'op' => '==', 'value' => true]]),
            'Conditions: booleans compare as booleans'
        );
        self::ok(true === Conditions::evaluate($state, [['flag' => 'export_controls']]), 'Conditions: flag clause');
        self::ok(false === Conditions::evaluate($state, [['flag' => 'never_set']]), 'Conditions: an unset flag is false');
        self::ok(true === Conditions::evaluate($state, [['not_flag' => 'never_set']]), 'Conditions: not_flag clause');
        self::ok(true === Conditions::evaluate($state, [['min_turn' => 3]]), 'Conditions: min_turn');
        self::ok(false === Conditions::evaluate($state, [['min_turn' => 9]]), 'Conditions: min_turn rejects early turns');
        self::ok(true === Conditions::evaluate($state, [['max_turn' => 12]]), 'Conditions: max_turn');
        self::ok(false === Conditions::evaluate($state, [['max_turn' => 4]]), 'Conditions: max_turn rejects late turns');
        self::ok(true === Conditions::evaluate($state, [['seen_event' => 'economic-slowdown']]), 'Conditions: seen_event');
        self::ok(
            true === Conditions::evaluate($state, [['not_seen_event' => 'foreign-missile-test']]),
            'Conditions: not_seen_event'
        );
        self::ok(
            true === Conditions::evaluate($state, [['counter_min' => ['sanctions_used', 2]]]),
            'Conditions: counter_min at the boundary'
        );
        self::ok(
            false === Conditions::evaluate($state, [['counter_min' => ['sanctions_used', 3]]]),
            'Conditions: counter_min below the boundary'
        );
        self::ok(
            false === Conditions::evaluate($state, [['counter_min' => ['never_used', 1]]]),
            'Conditions: counter_min on a missing counter'
        );
        self::ok(
            false === Conditions::evaluate($state, [['flag' => 'export_controls'], ['min_turn' => 99]]),
            'Conditions: clauses are ANDed'
        );
        self::ok(
            true === Conditions::evaluate($state, [['any' => [['min_turn' => 99], ['flag' => 'export_controls']]]]),
            'Conditions: any is an OR group'
        );
        self::ok(false === Conditions::evaluate($state, [['any' => []]]), 'Conditions: an empty any group is false');
        self::ok(true === Conditions::evaluate($state, [['not' => ['flag' => 'never_set']]]), 'Conditions: not inverts a clause');
        self::ok(
            true === Conditions::evaluate(
                $state,
                [['any' => [['not' => ['flag' => 'export_controls']], ['all' => [['min_turn' => 3], ['max_turn' => 12]]]]]]
            ),
            'Conditions: nested any/not/all'
        );
        self::ok(true === Conditions::evaluate($state, ['flag' => 'export_controls']), 'Conditions: a bare clause object works');

        $threw = self::throwsCode(
            static function () use ($state): void {
                Conditions::evaluate($state, [['nonsense' => 1]]);
            },
            EngineException::INVALID_CONTENT
        );

        self::ok($threw, 'Conditions: an unknown clause throws invalid_content');

        $threw = self::throwsCode(
            static function () use ($state): void {
                Conditions::evaluate($state, [['path' => 'public.approval', 'op' => '<>', 'value' => 1]]);
            },
            EngineException::INVALID_CONTENT
        );

        self::ok($threw, 'Conditions: an unsupported operator throws invalid_content');
    }

    // -----------------------------------------------------------------------------------
    // MemorySystem
    // -----------------------------------------------------------------------------------

    /**
     * Record shape, id sequencing and the type guard.
     *
     * @return void
     */
    private static function checkMemory(): void
    {
        $state = self::state();
        $state->set('turn', 3);
        $state->set('date', '2001-03-20');

        $memory = MemorySystem::record(
            $state,
            [
                'type'   => 'foreign_policy',
                'action' => 'imposed sanctions after missile test',
                'target' => 'rival_state_a',
                'result' => 'allies supportive; rival defiant',
                'tags'   => ['sanctions', 'credibility', 'sanctions'],
            ]
        );

        self::same('m-3-1', $memory['id'], 'MemorySystem: ids are m-<turn>-<n>');
        self::same('2001-03', $memory['date'], 'MemorySystem: date is the YYYY-MM key');
        self::same(3, $memory['turn'], 'MemorySystem: turn comes from the state');
        self::same('rival_state_a', $memory['target'], 'MemorySystem: target is kept');
        self::same(2, count($memory['tags']), 'MemorySystem: duplicate tags are dropped');

        $second = MemorySystem::record($state, ['type' => 'domestic', 'action' => 'signed the budget']);

        self::same('m-3-2', $second['id'], 'MemorySystem: ids increment inside a turn');
        self::ok(null === $second['target'], 'MemorySystem: an absent target is null');
        self::same(2, count(MemorySystem::all($state)), 'MemorySystem: records accumulate');
        self::same(1, count(MemorySystem::recent($state, 1)), 'MemorySystem: recent honours the window');
        self::same('m-3-2', MemorySystem::recent($state, 1)[0]['id'], 'MemorySystem: recent returns the newest');
        self::same(0, count(MemorySystem::recent($state, 0)), 'MemorySystem: a zero window is empty');
        self::same(500, MemorySystem::MAX_ENTRIES, 'MemorySystem: the cap is 500 entries');

        $threw = self::throwsCode(
            static function () use ($state): void {
                MemorySystem::record($state, ['type' => 'not-a-type', 'action' => 'x']);
            },
            EngineException::INVALID_CONTENT
        );

        self::ok($threw, 'MemorySystem: an unknown type throws invalid_content');
    }

    // -----------------------------------------------------------------------------------
    // DelayedConsequenceQueue
    // -----------------------------------------------------------------------------------

    /**
     * Due and conditional firing, chance, expiry, triggers and follow-ups.
     *
     * @return void
     */
    private static function checkQueue(): void
    {
        $state = self::state();

        $id = DelayedConsequenceQueue::enqueue(
            $state,
            [
                'label'          => 'Trade retaliation after export controls',
                'delay_turns'    => 2,
                'effects'        => ['public.approval' => -3],
                'hidden_effects' => ['hidden.trade_retaliation_risk' => 10],
                'headline'       => ['outlet_id' => 'world-desk', 'title' => 'Tariffs answered in kind'],
                'memory'         => ['type' => 'economy', 'action' => 'absorbed retaliatory tariffs'],
            ],
            'foreign-missile-test:sanctions'
        );

        self::same('dq-1-1', $id, 'Queue: ids are dq-<turn>-<n>');
        self::same(1, count($state->get('delayed_queue')), 'Queue: enqueue stores the item');
        self::same(3, $state->get('delayed_queue')[0]['due_turn'], 'Queue: delay_turns resolves to an absolute due turn');
        self::ok(null === $state->get('delayed_queue')[0]['expires_turn'], 'Queue: no window means no expiry');

        $state->set('turn', 2);
        self::same(0, count(DelayedConsequenceQueue::tick($state)), 'Queue: nothing fires before the due turn');
        self::same(1, count($state->get('delayed_queue')), 'Queue: an undue item stays queued');

        $state->set('turn', 3);
        $state->set('date', '2001-03-20');
        $fired = DelayedConsequenceQueue::tick($state);

        self::same(1, count($fired), 'Queue: a due item fires');
        self::same(49.0, $state->get('public.approval'), 'Queue: fired effects are applied');
        self::same(20.0, $state->get('hidden.trade_retaliation_risk'), 'Queue: hidden effects are applied');
        self::same(1, count($fired[0]['visible_deltas']), 'Queue: only visible deltas are reported');
        self::same(1, $fired[0]['hidden_change_count'], 'Queue: hidden changes are counted, not listed');
        self::same('Tariffs answered in kind', $fired[0]['headline']['title'], 'Queue: the headline is passed through');
        self::same('m-3-1', $fired[0]['memory']['id'], 'Queue: the memory is recorded and returned');
        self::ok(null === $fired[0]['trigger_event'], 'Queue: an item without a trigger reports none');
        self::same(0, count($state->get('delayed_queue')), 'Queue: a fired item leaves the queue');

        // A due item whose conditions fail is dropped.
        $state = self::state();
        DelayedConsequenceQueue::enqueue(
            $state,
            [
                'label'       => 'Never',
                'delay_turns' => 1,
                'conditions'  => [['flag' => 'never_set']],
                'effects'     => ['public.approval' => 5],
            ],
            'test:due-conditions'
        );
        $state->set('turn', 2);

        self::same(0, count(DelayedConsequenceQueue::tick($state)), 'Queue: a due item with failing conditions does not fire');
        self::same(0, count($state->get('delayed_queue')), 'Queue: a due item with failing conditions is dropped');
        self::same(52.0, $state->get('public.approval'), 'Queue: a dropped item applies nothing');

        // A conditional item waits for its conditions, then fires exactly once.
        $state = self::state();
        DelayedConsequenceQueue::enqueue(
            $state,
            [
                'label'        => 'Recession bites',
                'delay_turns'  => 1,
                'window_turns' => 6,
                'mode'         => DelayedConsequenceQueue::MODE_CONDITIONAL,
                'conditions'   => [['flag' => 'recession_declared']],
                'effects'      => ['public.approval' => -4],
            ],
            'test:conditional'
        );

        self::same(8, $state->get('delayed_queue')[0]['expires_turn'], 'Queue: window_turns resolves to an absolute expiry');

        $state->set('turn', 2);
        DelayedConsequenceQueue::tick($state);
        self::same(1, count($state->get('delayed_queue')), 'Queue: a conditional item survives a failed check');

        $state->set('flags.recession_declared', true);
        $state->set('turn', 3);
        $fired = DelayedConsequenceQueue::tick($state);

        self::same(1, count($fired), 'Queue: a conditional item fires once its conditions hold');
        self::same(48.0, $state->get('public.approval'), 'Queue: conditional effects are applied');
        self::same(0, count($state->get('delayed_queue')), 'Queue: a conditional item fires only once');

        // on_fail=drop discards a conditional item at its first failed check.
        $state = self::state();
        DelayedConsequenceQueue::enqueue(
            $state,
            [
                'label'       => 'One shot',
                'delay_turns' => 1,
                'mode'        => DelayedConsequenceQueue::MODE_CONDITIONAL,
                'on_fail'     => DelayedConsequenceQueue::ON_FAIL_DROP,
                'conditions'  => [['flag' => 'never_set']],
            ],
            'test:on-fail-drop'
        );
        $state->set('turn', 2);
        DelayedConsequenceQueue::tick($state);

        self::same(0, count($state->get('delayed_queue')), 'Queue: on_fail=drop discards a failed conditional item');

        // A zero chance drops a due item without applying anything.
        $state = self::state();
        DelayedConsequenceQueue::enqueue(
            $state,
            ['label' => 'Coin flip', 'delay_turns' => 1, 'chance' => 0.0, 'effects' => ['public.approval' => 10]],
            'test:chance'
        );
        $state->set('turn', 2);

        self::same(0, count(DelayedConsequenceQueue::tick($state)), 'Queue: a failed chance roll does not fire');
        self::same(0, count($state->get('delayed_queue')), 'Queue: a failed chance roll drops a due item');
        self::same(52.0, $state->get('public.approval'), 'Queue: a failed chance roll applies nothing');

        // An expired item is dropped untouched.
        $state = self::state();
        DelayedConsequenceQueue::enqueue(
            $state,
            [
                'label'        => 'Too late',
                'delay_turns'  => 1,
                'window_turns' => 1,
                'mode'         => DelayedConsequenceQueue::MODE_CONDITIONAL,
                'conditions'   => [['flag' => 'never_set']],
                'effects'      => ['public.approval' => 10],
            ],
            'test:expiry'
        );
        $state->set('turn', 5);

        self::same(0, count(DelayedConsequenceQueue::tick($state)), 'Queue: an expired item does not fire');
        self::same(0, count($state->get('delayed_queue')), 'Queue: an expired item is dropped');
        self::same(52.0, $state->get('public.approval'), 'Queue: an expired item applies nothing');

        // Only one forced event per turn; the rest re-queue carrying just the trigger.
        $state = self::state();
        DelayedConsequenceQueue::enqueue(
            $state,
            [
                'label'         => 'First trigger',
                'delay_turns'   => 1,
                'trigger_event' => 'trade-retaliation',
                'effects'       => ['public.approval' => -1],
            ],
            'test:trigger-a'
        );
        DelayedConsequenceQueue::enqueue(
            $state,
            [
                'label'         => 'Second trigger',
                'delay_turns'   => 1,
                'trigger_event' => 'allied-summit',
                'effects'       => ['public.approval' => -1],
            ],
            'test:trigger-b'
        );
        $state->set('turn', 2);
        $fired = DelayedConsequenceQueue::tick($state);

        self::same(2, count($fired), 'Queue: every due item fires even when its trigger cannot be honoured');
        self::same('trade-retaliation', $fired[0]['trigger_event'], 'Queue: the first trigger is honoured');
        self::ok(null === $fired[1]['trigger_event'], 'Queue: the second trigger is not honoured this turn');
        self::same('trade-retaliation', DelayedConsequenceQueue::forcedEvent($fired), 'Queue: forcedEvent finds the trigger');
        self::same(50.0, $state->get('public.approval'), 'Queue: both items applied their effects');
        self::same(1, count($state->get('delayed_queue')), 'Queue: the unhonoured trigger is re-queued');

        $requeued = $state->get('delayed_queue')[0];

        self::same('allied-summit', $requeued['trigger_event'], 'Queue: the re-queued item keeps its trigger');
        self::same(3, $requeued['due_turn'], 'Queue: the re-queued item is due next turn');
        self::same(0, count($requeued['effects']), 'Queue: the re-queued item carries no effects');

        $state->set('turn', 3);
        $fired = DelayedConsequenceQueue::tick($state);

        self::same('allied-summit', $fired[0]['trigger_event'], 'Queue: the deferred trigger is honoured next turn');
        self::same(50.0, $state->get('public.approval'), 'Queue: the deferred trigger does not re-apply effects');

        // followup_events sugar, and a save/reload round trip of the queue.
        $state = self::state();
        $ids   = DelayedConsequenceQueue::enqueueFollowups(
            $state,
            [
                [
                    'event_id'    => 'trade-retaliation',
                    'delay_turns' => 4,
                    'chance'      => 0.5,
                    'conditions'  => [['flag' => 'sanctions_rival_a']],
                ],
            ],
            'foreign-missile-test:sanctions'
        );

        self::same(1, count($ids), 'Queue: enqueueFollowups queues one item per entry');

        $queued = $state->get('delayed_queue')[0];

        self::same('trade-retaliation', $queued['trigger_event'], 'Queue: a follow-up becomes a trigger item');
        self::same(5, $queued['due_turn'], 'Queue: a follow-up honours delay_turns');
        self::same(0.5, $queued['chance'], 'Queue: a follow-up honours chance');
        self::same('Follow-up: Trade Retaliation', $queued['label'], 'Queue: a follow-up gets a readable default label');

        $reloaded = GameState::fromArray($state->toArray());

        self::same(
            $queued['due_turn'],
            $reloaded->get('delayed_queue')[0]['due_turn'],
            'Queue: the queue survives a state round trip'
        );

        $threw = self::throwsCode(
            static function () use ($state): void {
                DelayedConsequenceQueue::enqueue($state, ['trigger_event' => ''], 'test:bad-trigger');
            },
            EngineException::INVALID_CONTENT
        );

        self::ok($threw, 'Queue: an empty trigger_event throws invalid_content');
    }

    // -----------------------------------------------------------------------------------
    // ContentRepository (error paths only; no fixtures needed)
    // -----------------------------------------------------------------------------------

    /**
     * The codes a missing data directory must produce.
     *
     * @return void
     */
    private static function checkContentRepository(): void
    {
        $repository = new ContentRepository(__DIR__ . '/no-such-data-dir');

        self::ok(
            self::throwsCode(
                static function () use ($repository): void {
                    $repository->scenario('new-administration');
                },
                EngineException::UNKNOWN_SCENARIO
            ),
            'ContentRepository: a missing scenario throws unknown_scenario'
        );

        self::ok(
            self::throwsCode(
                static function () use ($repository): void {
                    $repository->event('foreign-missile-test');
                },
                EngineException::UNKNOWN_EVENT
            ),
            'ContentRepository: a missing event throws unknown_event'
        );

        self::ok(
            self::throwsCode(
                static function () use ($repository): void {
                    $repository->countries();
                },
                EngineException::INVALID_CONTENT
            ),
            'ContentRepository: an unreadable document throws invalid_content'
        );

        self::same([], $repository->events(), 'ContentRepository: an empty events directory loads as an empty map');
        self::ok(false === $repository->hasEvent('anything'), 'ContentRepository: hasEvent never throws');
    }

    // -----------------------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------------------

    /**
     * A small but complete state to test against.
     *
     * @return GameState
     */
    private static function state(): GameState
    {
        return GameState::fromArray(
            [
                'seed'        => self::SEED,
                'scenario_id' => 'test-scenario',
                'turn'        => 1,
                'term'        => 1,
                'date'        => '2001-01-20',
                'public'      => [
                    'approval'           => 52.0,
                    'gdp_growth'         => 1.8,
                    'crisis_level'       => 15.0,
                    'allied_confidence'  => 58.0,
                    'domestic_stability' => 62.0,
                    'national_debt'      => 5700.0,
                ],
                'hidden'      => [
                    'credibility'            => 55.0,
                    'recession_pressure'     => 30.0,
                    'trade_retaliation_risk' => 10.0,
                ],
                'countries'   => [
                    'china'         => [
                        'relationship'     => 10.0,
                        'trust'            => 35.0,
                        'military_tension' => 30.0,
                    ],
                    'rival_state_a' => [
                        'relationship'     => -40.0,
                        'trust'            => 10.0,
                        'military_tension' => 60.0,
                    ],
                ],
                'seen_events' => ['economic-slowdown'],
            ]
        );
    }

    /**
     * Record one assertion.
     *
     * @param bool   $condition Condition that must hold.
     * @param string $label     What is being checked.
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
     * Record one equality assertion, comparing floats with a tolerance.
     *
     * @param mixed  $expected Expected value.
     * @param mixed  $actual   Actual value.
     * @param string $label    What is being checked.
     *
     * @return void
     */
    private static function same($expected, $actual, string $label): void
    {
        if (is_float($expected) || is_float($actual)) {
            self::ok(
                is_numeric($actual) && abs((float) $expected - (float) $actual) < self::EPSILON,
                $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
            );

            return;
        }

        self::ok(
            $expected === $actual,
            $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
        );
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
    exit(SmokeCore::run());
}
