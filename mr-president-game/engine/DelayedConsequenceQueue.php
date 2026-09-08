<?php
/**
 * Queue of delayed and conditional consequences.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Stores the promises a decision makes about the future and cashes them in later.
 *
 * `tick()` runs once per turn advance, after the drift systems and before event selection,
 * so a consequence can both move indicators and force next turn's event. Item shape and
 * defaulting live in `DelayedConsequenceItem`.
 *
 * Two modes:
 * - `due`         evaluated once when the turn reaches `due_turn`; a failed condition or a
 *                 failed chance roll drops the item.
 * - `conditional` re-checked every turn from `due_turn` until `expires_turn`; `on_fail`
 *                 decides whether a failed condition keeps or drops it, and a failed chance
 *                 roll always keeps it for another look next turn.
 *
 * At most `MAX_TRIGGERS_PER_TURN` forced events are honoured per turn. Extra ones still
 * apply their effects, then re-queue themselves for next turn carrying only the trigger.
 */
final class DelayedConsequenceQueue
{
    /** Where the queue lives in the state. */
    const QUEUE_PATH = 'delayed_queue';

    /** Prefix for generated ids (`dq-<turn>-<n>`). */
    const ID_PREFIX = 'dq-';

    /** Forced events honoured per turn advance. */
    const MAX_TRIGGERS_PER_TURN = 1;

    /** Delay used when an unhonoured trigger is re-queued. */
    const REQUEUE_DELAY_TURNS = 1;

    /** Evaluate once at `due_turn`. */
    const MODE_DUE = DelayedConsequenceItem::MODE_DUE;

    /** Re-check every turn from `due_turn` until `expires_turn`. */
    const MODE_CONDITIONAL = DelayedConsequenceItem::MODE_CONDITIONAL;

    /** Keep a conditional item whose conditions failed. */
    const ON_FAIL_KEEP = DelayedConsequenceItem::ON_FAIL_KEEP;

    /** Drop a conditional item whose conditions failed. */
    const ON_FAIL_DROP = DelayedConsequenceItem::ON_FAIL_DROP;

    /**
     * Queue one consequence from its authoring form.
     *
     * @param GameState $state         State to mutate.
     * @param array     $authoringItem Authored item: `label`, `delay_turns`, `window_turns`,
     *                                 `mode`, `chance`, `conditions`, `effects`,
     *                                 `hidden_effects`, `trigger_event`, `memory`,
     *                                 `headline`, `on_fail`.
     * @param string    $source        Provenance, e.g. `foreign-missile-test:sanctions`.
     *
     * @return string The generated item id.
     *
     * @throws EngineException `invalid_content` when `trigger_event` is malformed.
     */
    public static function enqueue(GameState $state, array $authoringItem, string $source): string
    {
        $turn = $state->turn();
        $id   = self::nextId($state, $turn);

        $state->push(
            self::QUEUE_PATH,
            DelayedConsequenceItem::fromAuthoring($authoringItem, $source, $id, $turn)
        );

        return $id;
    }

    /**
     * Queue every entry of an event's `followup_events` block.
     *
     * @param GameState $state     State to mutate.
     * @param array     $followups `followup_events` entries.
     * @param string    $source    Provenance for all of them.
     *
     * @return array<int, string> The generated ids, in authoring order.
     *
     * @throws EngineException `invalid_content` when an entry has no `event_id`.
     */
    public static function enqueueFollowups(GameState $state, array $followups, string $source): array
    {
        $ids = [];

        foreach ($followups as $followup) {
            if (!is_array($followup)) {
                throw new EngineException(
                    EngineException::INVALID_CONTENT,
                    'Each "followup_events" entry must be an object (source: ' . $source . ').'
                );
            }

            $ids[] = self::enqueue($state, self::fromFollowup($followup), $source);
        }

        return $ids;
    }

    /**
     * Fire everything that is due, and rewrite the queue.
     *
     * @param GameState $state State to mutate. Its turn must already have been advanced.
     *
     * @return array<int, array> Fired records, in queue order:
     *                           `['id', 'label', 'source', 'created_turn', 'due_turn',
     *                             'mode', 'trigger_event', 'headline', 'memory',
     *                             'visible_deltas', 'hidden_change_count']`.
     *                           `trigger_event` is non-null only on the single record whose
     *                           forced event the caller should honour this turn.
     */
    public static function tick(GameState $state): array
    {
        $queue = $state->get(self::QUEUE_PATH, []);

        if (!is_array($queue)) {
            $queue = [];
        }

        $turn      = $state->turn();
        $remaining = [];
        $fired     = [];
        $triggers  = 0;

        foreach ($queue as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $item = DelayedConsequenceItem::hydrate($raw);

            if ($turn < $item['due_turn']) {
                $remaining[] = $item;

                continue;
            }

            if (self::hasExpired($item, $turn)) {
                continue;
            }

            if (!Conditions::evaluate($state, $item['conditions'])) {
                if (self::keepsAfterFailure($item)) {
                    $remaining[] = $item;
                }

                continue;
            }

            if (!$state->rng()->chance($item['chance'])) {
                if (self::MODE_CONDITIONAL === $item['mode']) {
                    $remaining[] = $item;
                }

                continue;
            }

            $trigger = null;

            if (null !== $item['trigger_event']) {
                if ($triggers < self::MAX_TRIGGERS_PER_TURN) {
                    $trigger = $item['trigger_event'];
                    $triggers++;
                } else {
                    $remaining[] = DelayedConsequenceItem::triggerOnly(
                        $item,
                        $turn + self::REQUEUE_DELAY_TURNS
                    );
                }
            }

            $fired[] = self::fire($state, $item, $trigger);
        }

        $state->set(self::QUEUE_PATH, array_values($remaining));

        return $fired;
    }

    /**
     * The forced event id from a set of fired records, if any.
     *
     * @param array $fired Records returned by `tick()`.
     *
     * @return string|null
     */
    public static function forcedEvent(array $fired): ?string
    {
        foreach ($fired as $record) {
            if (isset($record['trigger_event']) && is_string($record['trigger_event'])) {
                return $record['trigger_event'];
            }
        }

        return null;
    }

    /**
     * Convert an event's `followup_events` entry into the authoring form.
     *
     * @param array $followup `['event_id', 'delay_turns', 'chance', 'conditions', 'label']`.
     *
     * @return array Authoring item ready for `enqueue()`.
     *
     * @throws EngineException `invalid_content` when `event_id` is missing.
     */
    public static function fromFollowup(array $followup): array
    {
        if (!isset($followup['event_id']) || !is_string($followup['event_id']) || '' === $followup['event_id']) {
            throw new EngineException(
                EngineException::INVALID_CONTENT,
                'A "followup_events" entry needs a non-empty "event_id".'
            );
        }

        $item = [
            'label'         => isset($followup['label'])
                ? (string) $followup['label']
                : DelayedConsequenceItem::defaultLabel($followup['event_id']),
            'trigger_event' => $followup['event_id'],
            'conditions'    => isset($followup['conditions']) && is_array($followup['conditions'])
                ? $followup['conditions']
                : [],
        ];

        foreach (['delay_turns', 'window_turns', 'chance', 'mode', 'on_fail'] as $key) {
            if (isset($followup[$key])) {
                $item[$key] = $followup[$key];
            }
        }

        return $item;
    }

    /**
     * Apply a fired item and build its record.
     *
     * @param GameState   $state   State to mutate.
     * @param array       $item    Runtime item.
     * @param string|null $trigger Forced event id when this item's trigger was honoured.
     *
     * @return array Fired record.
     */
    private static function fire(GameState $state, array $item, ?string $trigger): array
    {
        $deltas = array_merge(
            Effects::apply($state, $item['effects']),
            Effects::apply($state, $item['hidden_effects'])
        );

        $visible = Effects::visibleOnly($deltas);
        $memory  = null;

        if (null !== $item['memory']) {
            $memory = MemorySystem::record($state, $item['memory']);
        }

        return [
            'id'                  => $item['id'],
            'label'               => $item['label'],
            'source'              => $item['source'],
            'created_turn'        => $item['created_turn'],
            'due_turn'            => $item['due_turn'],
            'mode'                => $item['mode'],
            'trigger_event'       => $trigger,
            'headline'            => $item['headline'],
            'memory'              => $memory,
            'visible_deltas'      => $visible,
            'hidden_change_count' => count($deltas) - count($visible),
        ];
    }

    /**
     * Whether an item is past its window.
     *
     * @param array $item Runtime item.
     * @param int   $turn Current turn.
     *
     * @return bool
     */
    private static function hasExpired(array $item, int $turn): bool
    {
        return null !== $item['expires_turn'] && $turn > $item['expires_turn'];
    }

    /**
     * Whether an item survives its conditions failing.
     *
     * @param array $item Runtime item.
     *
     * @return bool
     */
    private static function keepsAfterFailure(array $item): bool
    {
        return self::MODE_CONDITIONAL === $item['mode'] && self::ON_FAIL_KEEP === $item['on_fail'];
    }

    /**
     * Next unused `dq-<turn>-<n>` id.
     *
     * @param GameState $state State to read.
     * @param int       $turn  Current turn.
     *
     * @return string
     */
    private static function nextId(GameState $state, int $turn): string
    {
        $prefix = self::ID_PREFIX . $turn . '-';
        $taken  = [];
        $queue  = $state->get(self::QUEUE_PATH, []);

        if (is_array($queue)) {
            foreach ($queue as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                    $taken[$item['id']] = true;
                }
            }
        }

        $index = 1;

        while (isset($taken[$prefix . $index])) {
            $index++;
        }

        return $prefix . $index;
    }
}
