<?php
/**
 * Conditions DSL matrix.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\Conditions;
use MrPresident\Engine\GameState;
use MrPresident\Tests\Assert;

$state = static function (): GameState {
    return GameState::fromArray([
        'seed'        => 1,
        'turn'        => 5,
        'public'      => ['approval' => 50.0],
        'hidden'      => ['recession_pressure' => 40.0],
        'flags'       => ['export_controls' => true, 'off' => false],
        'counters'    => ['sanctions_used' => 2],
        'seen_events' => ['economic-slowdown'],
    ]);
};

return [
    'empty clause list is true' => static function (Assert $t) use ($state): void {
        $t->true(Conditions::evaluate($state(), []));
    },
    'path comparisons' => static function (Assert $t) use ($state): void {
        $s = $state();
        $t->true(Conditions::evaluate($s, [['path' => 'hidden.recession_pressure', 'op' => '>=', 'value' => 40]]));
        $t->false(Conditions::evaluate($s, [['path' => 'hidden.recession_pressure', 'op' => '>', 'value' => 40]]));
        $t->true(Conditions::evaluate($s, [['path' => 'public.approval', 'op' => '==', 'value' => 50]]));
        $t->true(Conditions::evaluate($s, [['path' => 'public.approval', 'op' => '!=', 'value' => 51]]));
        $t->true(Conditions::evaluate($s, [['path' => 'public.approval', 'op' => '<', 'value' => 51]]));
        $t->true(Conditions::evaluate($s, [['path' => 'public.approval', 'op' => '<=', 'value' => 50]]));
    },
    'flags, not_flag, counters' => static function (Assert $t) use ($state): void {
        $s = $state();
        $t->true(Conditions::evaluate($s, [['flag' => 'export_controls']]));
        $t->false(Conditions::evaluate($s, [['flag' => 'off']]));
        $t->false(Conditions::evaluate($s, [['flag' => 'never_set']]));
        $t->true(Conditions::evaluate($s, [['not_flag' => 'never_set']]));
        $t->false(Conditions::evaluate($s, [['not_flag' => 'export_controls']]));
        $t->true(Conditions::evaluate($s, [['counter_min' => ['sanctions_used', 2]]]));
        $t->false(Conditions::evaluate($s, [['counter_min' => ['sanctions_used', 3]]]));
    },
    'turn windows and seen events' => static function (Assert $t) use ($state): void {
        $s = $state();
        $t->true(Conditions::evaluate($s, [['min_turn' => 5], ['max_turn' => 5]]));
        $t->false(Conditions::evaluate($s, [['min_turn' => 6]]));
        $t->false(Conditions::evaluate($s, [['max_turn' => 4]]));
        $t->true(Conditions::evaluate($s, [['seen_event' => 'economic-slowdown']]));
        $t->true(Conditions::evaluate($s, [['not_seen_event' => 'foreign-missile-test']]));
        $t->false(Conditions::evaluate($s, [['not_seen_event' => 'economic-slowdown']]));
    },
    'any / not groups and AND across clauses' => static function (Assert $t) use ($state): void {
        $s = $state();
        $t->true(Conditions::evaluate($s, [['any' => [['flag' => 'off'], ['flag' => 'export_controls']]]]));
        $t->false(Conditions::evaluate($s, [['any' => [['flag' => 'off'], ['flag' => 'nope']]]]));
        $t->true(Conditions::evaluate($s, [['not' => ['flag' => 'off']]]));
        $t->false(Conditions::evaluate($s, [['flag' => 'export_controls'], ['flag' => 'off']]));
    },
    'unknown clause key fails loudly' => static function (Assert $t) use ($state): void {
        $s = $state();
        $t->throws(static function () use ($s): void {
            Conditions::evaluate($s, [['flagg' => 'typo']]);
        }, 'invalid_content');
    },
];
