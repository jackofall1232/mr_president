<?php
/**
 * Shape and normalisation rules for one delayed consequence.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Translates between the authoring form and the runtime form of a delayed consequence.
 *
 * Content authors write `delay_turns` / `window_turns` relative to the turn a decision was
 * taken; the queue stores absolute `due_turn` / `expires_turn`. Keeping that translation -
 * and the defaulting of every optional field - in one place means `DelayedConsequenceQueue`
 * only ever deals with fully-populated items and never has to guess.
 *
 * Items are plain arrays, not objects, so they serialise straight into `GameState`.
 */
final class DelayedConsequenceItem
{
    /** Evaluate once at `due_turn`. */
    const MODE_DUE = 'due';

    /** Re-check every turn from `due_turn` until `expires_turn`. */
    const MODE_CONDITIONAL = 'conditional';

    /** Keep a conditional item whose conditions failed. */
    const ON_FAIL_KEEP = 'keep';

    /** Drop a conditional item whose conditions failed. */
    const ON_FAIL_DROP = 'drop';

    /** Delay applied when an authored item omits `delay_turns`. */
    const DEFAULT_DELAY_TURNS = 1;

    /** Probability applied when an authored item omits `chance`. */
    const DEFAULT_CHANCE = 1.0;

    /** Label used when an authored item omits one and forces no event. */
    const DEFAULT_LABEL = 'Delayed consequence';

    /** Prefix for the label generated from a forced event id. */
    const FOLLOWUP_LABEL_PREFIX = 'Follow-up: ';

    /** Runtime keys every stored item carries. */
    const FIELDS = [
        'id',
        'label',
        'source',
        'created_turn',
        'due_turn',
        'expires_turn',
        'mode',
        'chance',
        'conditions',
        'effects',
        'hidden_effects',
        'trigger_event',
        'memory',
        'headline',
        'on_fail',
    ];

    /**
     * Build a runtime item from the authoring form.
     *
     * @param array  $authoring Authored fields (see spec section 5.3).
     * @param string $source    Provenance, e.g. `foreign-missile-test:sanctions`.
     * @param string $id        Id assigned by the queue.
     * @param int    $turn      Turn the item is created on.
     *
     * @return array Runtime item.
     *
     * @throws EngineException `invalid_content` when `trigger_event` is present but not a
     *                         non-empty string.
     */
    public static function fromAuthoring(array $authoring, string $source, string $id, int $turn): array
    {
        $trigger = self::readTrigger($authoring, $source);
        $due     = self::resolveDueTurn($authoring, $turn);

        return [
            'id'             => $id,
            'label'          => isset($authoring['label'])
                ? (string) $authoring['label']
                : self::defaultLabel($trigger),
            'source'         => $source,
            'created_turn'   => $turn,
            'due_turn'       => $due,
            'expires_turn'   => self::resolveExpiryTurn($authoring, $due),
            'mode'           => self::readMode($authoring),
            'chance'         => self::readChance($authoring),
            'conditions'     => self::readArray($authoring, 'conditions'),
            'effects'        => self::readArray($authoring, 'effects'),
            'hidden_effects' => self::readArray($authoring, 'hidden_effects'),
            'trigger_event'  => $trigger,
            'memory'         => self::readObject($authoring, 'memory'),
            'headline'       => self::readObject($authoring, 'headline'),
            'on_fail'        => self::readOnFail($authoring),
        ];
    }

    /**
     * Fill a stored item with every runtime key.
     *
     * Defensive rather than strict: a hand-edited or older save is normalised instead of
     * rejected, because a malformed queue entry must never break a run in progress.
     *
     * @param array $raw Stored item.
     *
     * @return array Runtime item.
     */
    public static function hydrate(array $raw): array
    {
        $due = isset($raw['due_turn']) && is_numeric($raw['due_turn']) ? (int) $raw['due_turn'] : 0;

        return [
            'id'             => isset($raw['id']) ? (string) $raw['id'] : '',
            'label'          => isset($raw['label']) ? (string) $raw['label'] : self::DEFAULT_LABEL,
            'source'         => isset($raw['source']) ? (string) $raw['source'] : '',
            'created_turn'   => isset($raw['created_turn']) && is_numeric($raw['created_turn'])
                ? (int) $raw['created_turn']
                : $due,
            'due_turn'       => $due,
            'expires_turn'   => isset($raw['expires_turn']) && is_numeric($raw['expires_turn'])
                ? (int) $raw['expires_turn']
                : null,
            'mode'           => self::readMode($raw),
            'chance'         => self::readChance($raw),
            'conditions'     => self::readArray($raw, 'conditions'),
            'effects'        => self::readArray($raw, 'effects'),
            'hidden_effects' => self::readArray($raw, 'hidden_effects'),
            'trigger_event'  => isset($raw['trigger_event']) && is_string($raw['trigger_event'])
                && '' !== $raw['trigger_event']
                    ? $raw['trigger_event']
                    : null,
            'memory'         => self::readObject($raw, 'memory'),
            'headline'       => self::readObject($raw, 'headline'),
            'on_fail'        => self::readOnFail($raw),
        ];
    }

    /**
     * Strip an already-fired item down to its unhonoured forced event.
     *
     * Effects, memory and headline have been applied, so the copy carries none of them; the
     * expiry is cleared so the forced event cannot be lost while it waits its turn.
     *
     * @param array $item    Runtime item that already fired.
     * @param int   $dueTurn Turn the trigger should be honoured on.
     *
     * @return array Runtime item carrying only the trigger.
     */
    public static function triggerOnly(array $item, int $dueTurn): array
    {
        return [
            'id'             => $item['id'],
            'label'          => $item['label'],
            'source'         => $item['source'],
            'created_turn'   => $item['created_turn'],
            'due_turn'       => $dueTurn,
            'expires_turn'   => null,
            'mode'           => self::MODE_DUE,
            'chance'         => self::DEFAULT_CHANCE,
            'conditions'     => [],
            'effects'        => [],
            'hidden_effects' => [],
            'trigger_event'  => $item['trigger_event'],
            'memory'         => null,
            'headline'       => null,
            'on_fail'        => self::ON_FAIL_KEEP,
        ];
    }

    /**
     * Readable label for an item that did not author one.
     *
     * @param string|null $triggerEvent Forced event id, when there is one.
     *
     * @return string
     */
    public static function defaultLabel(?string $triggerEvent): string
    {
        if (null === $triggerEvent || '' === $triggerEvent) {
            return self::DEFAULT_LABEL;
        }

        return self::FOLLOWUP_LABEL_PREFIX . ucwords(str_replace('-', ' ', $triggerEvent));
    }

    /**
     * Absolute turn the item becomes due on.
     *
     * `due_turn` wins when present (re-queued items already carry one), otherwise
     * `delay_turns` is added to the creation turn.
     *
     * @param array $authoring Authored fields.
     * @param int   $turn      Creation turn.
     *
     * @return int
     */
    private static function resolveDueTurn(array $authoring, int $turn): int
    {
        if (isset($authoring['due_turn']) && is_numeric($authoring['due_turn'])) {
            return (int) $authoring['due_turn'];
        }

        $delay = isset($authoring['delay_turns']) && is_numeric($authoring['delay_turns'])
            ? max(0, (int) $authoring['delay_turns'])
            : self::DEFAULT_DELAY_TURNS;

        return $turn + $delay;
    }

    /**
     * Absolute turn the item stops being eligible after, or null for never.
     *
     * @param array $authoring Authored fields.
     * @param int   $dueTurn   Resolved due turn.
     *
     * @return int|null
     */
    private static function resolveExpiryTurn(array $authoring, int $dueTurn): ?int
    {
        if (isset($authoring['window_turns']) && is_numeric($authoring['window_turns'])) {
            return $dueTurn + max(0, (int) $authoring['window_turns']);
        }

        if (isset($authoring['expires_turn']) && is_numeric($authoring['expires_turn'])) {
            return (int) $authoring['expires_turn'];
        }

        return null;
    }

    /**
     * Validate and read `trigger_event`.
     *
     * @param array  $authoring Authored fields.
     * @param string $source    Provenance, used in the error message.
     *
     * @return string|null
     *
     * @throws EngineException `invalid_content` when present but not a non-empty string.
     */
    private static function readTrigger(array $authoring, string $source): ?string
    {
        if (!isset($authoring['trigger_event']) || null === $authoring['trigger_event']) {
            return null;
        }

        if (!is_string($authoring['trigger_event']) || '' === $authoring['trigger_event']) {
            throw new EngineException(
                EngineException::INVALID_CONTENT,
                'Delayed consequence "trigger_event" must be a non-empty event id (source: ' . $source . ').'
            );
        }

        return $authoring['trigger_event'];
    }

    /**
     * Read the mode, defaulting to `due`.
     *
     * @param array $item Authored or stored item.
     *
     * @return string
     */
    private static function readMode(array $item): string
    {
        $mode = isset($item['mode']) && is_string($item['mode']) ? $item['mode'] : self::MODE_DUE;

        return self::MODE_CONDITIONAL === $mode ? self::MODE_CONDITIONAL : self::MODE_DUE;
    }

    /**
     * Read the failure policy, defaulting to `keep`.
     *
     * @param array $item Authored or stored item.
     *
     * @return string
     */
    private static function readOnFail(array $item): string
    {
        $onFail = isset($item['on_fail']) && is_string($item['on_fail']) ? $item['on_fail'] : self::ON_FAIL_KEEP;

        return self::ON_FAIL_DROP === $onFail ? self::ON_FAIL_DROP : self::ON_FAIL_KEEP;
    }

    /**
     * Read the chance, clamped into 0..1.
     *
     * @param array $item Authored or stored item.
     *
     * @return float
     */
    private static function readChance(array $item): float
    {
        if (!isset($item['chance']) || !is_numeric($item['chance'])) {
            return self::DEFAULT_CHANCE;
        }

        $chance = (float) $item['chance'];

        if ($chance < 0.0) {
            return 0.0;
        }

        return $chance > 1.0 ? 1.0 : $chance;
    }

    /**
     * Read an array field, defaulting to an empty array.
     *
     * @param array  $item Item.
     * @param string $key  Field name.
     *
     * @return array
     */
    private static function readArray(array $item, string $key): array
    {
        return isset($item[$key]) && is_array($item[$key]) ? $item[$key] : [];
    }

    /**
     * Read an optional object field, defaulting to null.
     *
     * @param array  $item Item.
     * @param string $key  Field name.
     *
     * @return array|null
     */
    private static function readObject(array $item, string $key): ?array
    {
        if (!isset($item[$key]) || !is_array($item[$key]) || [] === $item[$key]) {
            return null;
        }

        return $item[$key];
    }
}
