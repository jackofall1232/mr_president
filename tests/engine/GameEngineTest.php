<?php
/**
 * GameEngine: the full loop, determinism across save/reload, every choice applies.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\Conditions;
use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\GameEngine;
use MrPresident\Engine\GameState;
use MrPresident\Tests\Assert;

const MRP_TEST_TURNS = 10;

$content = new ContentRepository(__DIR__ . '/../../mr-president-game/data');
$engine  = new GameEngine($content);

/**
 * Serialize through JSON exactly the way the save store does.
 */
$reload = static function (GameState $s): GameState {
    return GameState::fromArray(json_decode((string) json_encode($s->toArray()), true));
};

/**
 * Play N turns choosing choice index (turn % 4); optionally reload after every step.
 */
$play = static function (int $seed, bool $reloadEveryStep) use ($engine, $reload): array {
    $s = $engine->newGame('new-administration', 'Determinism Test', $seed);
    for ($turn = 1; $turn <= MRP_TEST_TURNS; $turn++) {
        if ($reloadEveryStep) {
            $s = $reload($s);
        }
        $event = $engine->activeEvent($s);
        if (null !== $event) {
            $choices = $event['choices'];
            $engine->applyDecision($s, $choices[$turn % count($choices)]['id']);
            if ($reloadEveryStep) {
                $s = $reload($s);
            }
        }
        $engine->advanceTurn($s);
    }
    return $s->toArray();
};

return [
    'new game starts on Inauguration Day with the scenario values' => static function (Assert $t) use ($engine): void {
        $s = $engine->newGame('new-administration', 'Alexandra Reyes', 42);
        $t->same('2001-01-20', $s->date());
        $t->same(1, $s->turn());
        $t->same('Alexandra Reyes', $s->get('president_name'));
        $t->same(42, $s->get('seed'));
        $t->true(is_array($s->get('turn_report')), 'initial turn report built');
        $t->true(null !== $s->get('active_event'), 'opening event present');
        foreach (['approval', 'gdp_growth', 'inflation', 'unemployment', 'deficit', 'national_debt', 'global_influence', 'allied_confidence', 'domestic_stability', 'congress_support', 'crisis_level'] as $key) {
            $t->hasKey($key, $s->get('public'), 'public indicator');
        }
        foreach (['credibility', 'war_fatigue', 'institutional_trust', 'political_capital', 'rival_risk_tolerance', 'allied_reliability', 'intelligence_confidence', 'recession_pressure', 'escalation_pressure'] as $key) {
            $t->hasKey($key, $s->get('hidden'), 'hidden variable');
        }
    },
    'unknown scenario and choices throw typed errors' => static function (Assert $t) use ($engine): void {
        $t->throws(static function () use ($engine): void {
            $engine->newGame('no-such-scenario', 'X', 1);
        }, 'unknown_scenario');
        $s = $engine->newGame('new-administration', 'X', 1);
        $t->throws(static function () use ($engine, $s): void {
            $engine->applyDecision($s, 'not-a-choice');
        }, 'unknown_choice');
        $t->throws(static function () use ($engine, $s): void {
            $engine->advanceTurn($s);
        }, 'decision_required');
    },
    'a decision changes state, records memory, queues consequences and resolves the event' => static function (Assert $t) use ($engine): void {
        $s       = $engine->newGame('new-administration', 'X', 3);
        $event   = $engine->activeEvent($s);
        $outcome = $engine->applyDecision($s, $event['choices'][0]['id']);
        $t->same($event['id'], $outcome['event_id']);
        $t->hasKey('visible_deltas', $outcome);
        $t->hasKey('hidden_change_count', $outcome);
        $t->hasKey('headlines', $outcome);
        $t->true(count($outcome['headlines']) >= 1 && count($outcome['headlines']) <= 3, '1-3 headlines');
        $t->count(1, $s->get('memories'));
        $t->same($event['choices'][0]['id'], $s->get('active_event.resolved_choice_id'));
        $t->throws(static function () use ($engine, $s, $event): void {
            $engine->applyDecision($s, $event['choices'][1]['id']);
        }, 'event_already_resolved');
    },
    'advance moves one month and builds a turn report' => static function (Assert $t) use ($engine): void {
        $s = $engine->newGame('new-administration', 'X', 3);
        $engine->applyDecision($s, $engine->activeEvent($s)['choices'][0]['id']);
        $report = $engine->advanceTurn($s);
        $t->same(2, $s->turn());
        $t->same('2001-02-20', $s->date());
        $t->same('February 2001', $report['month_label']);
        $t->hasKey('indicator_deltas', $report);
        $t->hasKey('fired_consequences', $report);
        $t->same(null, $s->get('last_outcome'), 'last outcome cleared on advance');
    },
    'twelve advances cross a year boundary correctly' => static function (Assert $t) use ($engine): void {
        $s = $engine->newGame('new-administration', 'X', 8);
        for ($i = 0; $i < 12; $i++) {
            $event = $engine->activeEvent($s);
            if (null !== $event) {
                $engine->applyDecision($s, $event['choices'][0]['id']);
            }
            $engine->advanceTurn($s);
        }
        $t->same('2002-01-20', $s->date());
        $t->same(13, $s->turn());
    },
    // Compared in serialized form: that is what the save store persists, and a JSON round trip
    // legitimately turns 10.0 into 10 without changing any outcome.
    'save/reload after every step reproduces a straight run (seed 42)' => static function (Assert $t) use ($play): void {
        $t->same(json_encode($play(42, false)), json_encode($play(42, true)));
    },
    'save/reload after every step reproduces a straight run (seed 9001)' => static function (Assert $t) use ($play): void {
        $t->same(json_encode($play(9001, false)), json_encode($play(9001, true)));
    },
    'different seeds produce different runs' => static function (Assert $t) use ($play): void {
        $t->notSame(json_encode($play(1, false)), json_encode($play(2, false)));
    },
    'every choice of every event applies from the start state' => static function (Assert $t) use ($engine, $content): void {
        foreach ($content->events() as $id => $event) {
            foreach ($event['choices'] as $choice) {
                $s = $engine->newGame('new-administration', 'X', 5);
                $s->set('active_event', ['event_id' => $id, 'turn_presented' => 1, 'expires_turn' => null, 'resolved_choice_id' => null, 'forced_by' => null]);
                $outcome = $engine->applyDecision($s, $choice['id']);
                $t->same($choice['id'], $outcome['choice_id'], "{$id}/{$choice['id']}");
                $engine->advanceTurn($s);
            }
        }
    },
    'every event start condition is satisfiable' => static function (Assert $t) use ($engine, $content): void {
        $satisfy = static function (GameState $s, array $clauses) use (&$satisfy): void {
            foreach ($clauses as $clause) {
                if (isset($clause['path'])) {
                    $s->set($clause['path'], $clause['value']);
                } elseif (isset($clause['flag'])) {
                    $s->set('flags.' . $clause['flag'], true);
                } elseif (isset($clause['not_flag'])) {
                    $s->set('flags.' . $clause['not_flag'], false);
                } elseif (isset($clause['min_turn'])) {
                    $s->set('turn', max($s->turn(), (int) $clause['min_turn']));
                } elseif (isset($clause['seen_event'])) {
                    $s->push('seen_events', $clause['seen_event']);
                } elseif (isset($clause['counter_min'])) {
                    $s->set('counters.' . $clause['counter_min'][0], $clause['counter_min'][1]);
                } elseif (isset($clause['any'])) {
                    $satisfy($s, [$clause['any'][0]]);
                } elseif (isset($clause['all'])) {
                    $satisfy($s, $clause['all']);
                }
            }
        };
        foreach ($content->events() as $id => $event) {
            $s = $engine->newGame('new-administration', 'X', 5);
            $s->set('active_event', null);
            $s->set('cooldowns', []);
            $s->set('seen_events', []);
            $satisfy($s, $event['start_conditions']);
            $t->true(Conditions::evaluate($s, $event['start_conditions']), "{$id} conditions satisfiable");
            $t->true($engine->events()->isEligible($s, $event, null), "{$id} eligible once conditions hold");
        }
    },
];
