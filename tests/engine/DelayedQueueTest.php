<?php
/**
 * DelayedConsequenceQueue: due, conditional, chance, triggers, expiry.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\DelayedConsequenceQueue as Queue;
use MrPresident\Engine\GameState;
use MrPresident\Tests\Assert;

$state = static function (int $turn = 3): GameState {
    return GameState::fromArray([
        'seed'   => 11,
        'turn'   => $turn,
        'date'   => '2001-03-20',
        'public' => ['approval' => 50.0],
        'hidden' => ['trade_retaliation_risk' => 10.0],
    ]);
};

$advance = static function (GameState $s): void {
    $s->set('turn', $s->turn() + 1);
};

return [
    'due item fires at due_turn with visible deltas' => static function (Assert $t) use ($state, $advance): void {
        $s  = $state();
        $id = Queue::enqueue($s, ['label' => 'x', 'delay_turns' => 2, 'effects' => ['public.approval' => -3], 'hidden_effects' => ['hidden.trade_retaliation_risk' => 5]], 'test');
        $t->true('' !== $id);
        $advance($s);
        $t->count(0, Queue::tick($s), 'must not fire early');
        $advance($s);
        $fired = Queue::tick($s);
        $t->count(1, $fired);
        $t->close(47.0, (float) $s->get('public.approval'));
        $t->close(15.0, (float) $s->get('hidden.trade_retaliation_risk'));
        $t->count(1, $fired[0]['visible_deltas'], 'hidden effect must not appear as visible');
        $t->count(0, $s->get('delayed_queue'), 'fired item removed');
    },
    'due item whose conditions fail is dropped' => static function (Assert $t) use ($state, $advance): void {
        $s = $state();
        Queue::enqueue($s, ['delay_turns' => 1, 'conditions' => [['flag' => 'never']], 'effects' => ['public.approval' => 5]], 'test');
        $advance($s);
        $t->count(0, Queue::tick($s));
        $t->close(50.0, (float) $s->get('public.approval'));
        $t->count(0, $s->get('delayed_queue'));
    },
    'conditional item waits, then fires when the flag appears' => static function (Assert $t) use ($state, $advance): void {
        $s = $state();
        Queue::enqueue($s, ['delay_turns' => 1, 'window_turns' => 5, 'mode' => 'conditional', 'conditions' => [['flag' => 'go']], 'effects' => ['public.approval' => 5]], 'test');
        $advance($s);
        $t->count(0, Queue::tick($s));
        $t->count(1, $s->get('delayed_queue'), 'kept while waiting');
        $s->set('flags.go', true);
        $advance($s);
        $t->count(1, Queue::tick($s));
        $t->close(55.0, (float) $s->get('public.approval'));
    },
    'conditional item expires' => static function (Assert $t) use ($state, $advance): void {
        $s = $state();
        Queue::enqueue($s, ['delay_turns' => 1, 'window_turns' => 1, 'mode' => 'conditional', 'conditions' => [['flag' => 'go']]], 'test');
        for ($i = 0; $i < 4; $i++) {
            $advance($s);
            Queue::tick($s);
        }
        $t->count(0, $s->get('delayed_queue'), 'expired item removed');
    },
    'chance 0 never fires, chance 1 always fires' => static function (Assert $t) use ($state, $advance): void {
        $s = $state();
        Queue::enqueue($s, ['delay_turns' => 1, 'chance' => 0.0, 'effects' => ['public.approval' => 5]], 'test');
        Queue::enqueue($s, ['delay_turns' => 1, 'chance' => 1.0, 'effects' => ['public.approval' => 1]], 'test');
        $advance($s);
        $fired = Queue::tick($s);
        $t->count(1, $fired);
        $t->close(51.0, (float) $s->get('public.approval'));
    },
    'only one trigger_event is honored per turn' => static function (Assert $t) use ($state, $advance): void {
        $s = $state();
        Queue::enqueue($s, ['delay_turns' => 1, 'trigger_event' => 'trade-retaliation'], 'test');
        Queue::enqueue($s, ['delay_turns' => 1, 'trigger_event' => 'cyber-attribution-fallout'], 'test');
        $advance($s);
        $fired = Queue::tick($s);
        $t->same('trade-retaliation', Queue::forcedEvent($fired));
        $t->count(1, $s->get('delayed_queue'), 'second trigger re-queued');
        $advance($s);
        $t->same('cyber-attribution-fallout', Queue::forcedEvent(Queue::tick($s)));
    },
    'queue survives serialization' => static function (Assert $t) use ($state, $advance): void {
        $s = $state();
        Queue::enqueue($s, ['delay_turns' => 2, 'effects' => ['public.approval' => -1]], 'test');
        $copy = GameState::fromArray(json_decode((string) json_encode($s->toArray()), true));
        $advance($copy);
        $advance($copy);
        $t->count(1, Queue::tick($copy));
        $t->close(49.0, (float) $copy->get('public.approval'));
    },
];
