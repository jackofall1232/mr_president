<?php
/**
 * EventEngine: eligibility, cooldown, occurrences, exclusivity, forced events.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\EventEngine;
use MrPresident\Engine\GameEngine;
use MrPresident\Tests\Assert;

$content = new ContentRepository(__DIR__ . '/../../mr-president-game/data');
$engine  = new GameEngine($content);

$fresh = static function () use ($engine) {
    return $engine->newGame('new-administration', 'Test', 7);
};

return [
    'opening event is forced on turn 1' => static function (Assert $t) use ($fresh, $content): void {
        $s = $fresh();
        $t->same($content->scenario('new-administration')['opening_event'], $s->get('active_event.event_id'));
        $t->same(1, $s->get('active_event.turn_presented'));
        $t->contains($s->get('active_event.event_id'), $s->get('seen_events'));
    },
    'eligibility report covers every event with reasons' => static function (Assert $t) use ($fresh, $engine, $content): void {
        $s      = $fresh();
        $report = $engine->eligibilityReport($s);
        $ids    = array_column($report, 'event_id');
        foreach (array_keys($content->events()) as $id) {
            $t->contains($id, $ids, 'every event appears in the report');
        }
        $t->contains(EventEngine::QUIET_KEY, $ids, 'the quiet-month pseudo-entry is reported too');
        foreach ($report as $row) {
            $t->hasKey('event_id', $row);
            $t->hasKey('eligible', $row);
            $t->hasKey('weight', $row);
            $t->hasKey('reasons', $row);
        }
    },
    'cooldown blocks re-presentation' => static function (Assert $t) use ($fresh, $engine): void {
        $s     = $fresh();
        $id    = (string) $s->get('active_event.event_id');
        $event = $engine->content()->event($id);
        $t->true((int) $s->get('cooldowns.' . $id) > 1, 'cooldown recorded');
        $s->set('active_event', null);
        $t->false($engine->events()->isEligible($s, $event, null), 'on cooldown');
        $s->set('cooldowns.' . $id, 1);
        $s->set('seen_events', []);
        $t->true($engine->events()->isEligible($s, $event, null), 'eligible once cooldown expired and occurrences reset');
    },
    'max_occurrences is respected' => static function (Assert $t) use ($fresh, $engine): void {
        $s     = $fresh();
        $id    = (string) $s->get('active_event.event_id');
        $event = $engine->content()->event($id);
        $event['max_occurrences'] = 1;
        $s->set('active_event', null);
        $s->set('cooldowns', []);
        $t->false($engine->events()->isEligible($s, $event, null), 'already seen once');
    },
    'exclusive_with blocks the partner event' => static function (Assert $t) use ($fresh, $engine): void {
        $s     = $fresh();
        $event = $engine->content()->event('foreign-missile-test');
        $event['exclusive_with'] = ['economic-slowdown'];
        $event['start_conditions'] = [];
        $s->set('cooldowns', []);
        $t->false($engine->events()->isEligible($s, $event, 'economic-slowdown'), 'blocked by previous event');
        $t->true($engine->events()->isEligible($s, $event, null), 'allowed otherwise');
    },
    'forced event wins even when not otherwise eligible' => static function (Assert $t) use ($fresh, $engine): void {
        $s = $fresh();
        $s->set('active_event', null);
        $s->set('turn', 2);
        $picked = $engine->events()->selectNext($s, 'trade-retaliation', 'dq-test');
        $t->same('trade-retaliation', $picked['id']);
        $t->same('trade-retaliation', $s->get('active_event.event_id'));
        $t->same('dq-test', $s->get('active_event.forced_by'));
    },
];
