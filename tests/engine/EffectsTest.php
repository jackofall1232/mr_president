<?php
/**
 * Effects: add, clamp, set, booleans, counters, labels, visibility.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\Effects;
use MrPresident\Engine\GameState;
use MrPresident\Tests\Assert;

$state = static function (): GameState {
    return GameState::fromArray([
        'seed'      => 1,
        'public'    => ['approval' => 50.0, 'gdp_growth' => 1.0, 'crisis_level' => 10.0],
        'hidden'    => ['credibility' => 55.0],
        'countries' => ['china' => ['relationship' => 10.0, 'trust' => 35.0]],
    ]);
};

return [
    'numeric effects add and report deltas' => static function (Assert $t) use ($state): void {
        $s      = $state();
        $deltas = Effects::apply($s, ['public.approval' => 2, 'countries.china.trust' => -5]);
        $t->close(52.0, (float) $s->get('public.approval'));
        $t->close(30.0, (float) $s->get('countries.china.trust'));
        $t->count(2, $deltas);
        $t->same('public.approval', $deltas[0]['path']);
        $t->close(2.0, (float) $deltas[0]['delta']);
        $t->same('+2', $deltas[0]['display']);
    },
    'values are clamped to bounds' => static function (Assert $t) use ($state): void {
        $s = $state();
        Effects::apply($s, ['public.approval' => 80, 'countries.china.relationship' => -500, 'hidden.credibility' => -100]);
        $t->close(100.0, (float) $s->get('public.approval'));
        $t->close(-100.0, (float) $s->get('countries.china.relationship'));
        $t->close(0.0, (float) $s->get('hidden.credibility'));
    },
    'clamped no-op emits no delta row' => static function (Assert $t) use ($state): void {
        $s = $state();
        $s->set('public.approval', 100.0);
        $t->count(0, Effects::apply($s, ['public.approval' => 5]));
    },
    'string with = prefix sets, booleans set flags' => static function (Assert $t) use ($state): void {
        $s = $state();
        Effects::apply($s, ['public.crisis_level' => '=40', 'flags.export_controls' => true, 'flags.posture' => '=forward']);
        $t->close(40.0, (float) $s->get('public.crisis_level'));
        $t->same(true, $s->get('flags.export_controls'));
        $t->same('forward', $s->get('flags.posture'));
    },
    'counters add and never go below zero' => static function (Assert $t) use ($state): void {
        $s = $state();
        Effects::apply($s, ['counters.sanctions_used' => 1]);
        Effects::apply($s, ['counters.sanctions_used' => 1]);
        $t->same(2, (int) $s->get('counters.sanctions_used'));
        Effects::apply($s, ['counters.sanctions_used' => -10]);
        $t->true((float) $s->get('counters.sanctions_used') >= 0.0, 'counter went negative');
    },
    'labels and visibility' => static function (Assert $t): void {
        $t->same('Approval', Effects::label('public.approval'));
        $t->same('GDP Growth', Effects::label('public.gdp_growth'));
        $t->true(false !== strpos(Effects::label('countries.china.trust'), 'Trust'), 'country label mentions the field');
        $t->true(Effects::isVisible('public.approval'));
        $t->true(Effects::isVisible('countries.china.trust'));
        $t->false(Effects::isVisible('hidden.credibility'));
        $t->false(Effects::isVisible('flags.x'));
    },
    'visibleOnly filters hidden rows' => static function (Assert $t) use ($state): void {
        $s      = $state();
        $deltas = Effects::apply($s, ['public.approval' => 1, 'hidden.credibility' => 3]);
        $t->count(2, $deltas);
        $t->count(1, Effects::visibleOnly($deltas));
    },
];
