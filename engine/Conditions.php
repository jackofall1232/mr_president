<?php
/**
 * Declarative condition evaluator used by events, weights and consequences.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Evaluates the small condition DSL authored in JSON content.
 *
 * A clause list is ANDed; an empty list is true. Supported clauses:
 *
 * ```jsonc
 * { "path": "hidden.recession_pressure", "op": ">=", "value": 40 }
 * { "flag": "export_controls" }        { "not_flag": "export_controls" }
 * { "min_turn": 3 }                    { "max_turn": 12 }
 * { "seen_event": "economic-slowdown" }{ "not_seen_event": "economic-slowdown" }
 * { "counter_min": ["sanctions_used", 2] }
 * { "any": [ clause, ... ] }           { "all": [ clause, ... ] }
 * { "not": clause }
 * ```
 *
 * Unknown clause keys throw `invalid_content` rather than quietly evaluating to false: a
 * typo in content must fail loudly in the test run instead of silently disabling an event.
 */
final class Conditions
{
    /** Comparison operators accepted by a `path` clause. */
    const OPERATORS = ['==', '!=', '<', '<=', '>', '>='];

    /** Default operator when a `path` clause omits `op`. */
    const DEFAULT_OPERATOR = '==';

    /** Clause keys recognised by the evaluator. */
    const CLAUSE_KEYS = [
        'path',
        'flag',
        'not_flag',
        'min_turn',
        'max_turn',
        'seen_event',
        'not_seen_event',
        'counter_min',
        'any',
        'all',
        'not',
    ];

    /** Where flags live in the state. */
    const FLAGS_PREFIX = 'flags.';

    /** Where counters live in the state. */
    const COUNTERS_PREFIX = 'counters.';

    /** Where the list of already-presented event ids lives. */
    const SEEN_EVENTS_PATH = 'seen_events';

    /** Tolerance for float equality. */
    const EPSILON = 0.000001;

    /**
     * Evaluate a clause list (ANDed).
     *
     * A single clause object may be passed instead of a list; it is treated as a one-clause
     * list so content can write either form.
     *
     * @param GameState $state   State to read.
     * @param array     $clauses Clause list, or one clause.
     *
     * @return bool True when every clause holds. An empty list is true.
     *
     * @throws EngineException `invalid_content` when a clause is malformed.
     */
    public static function evaluate(GameState $state, array $clauses): bool
    {
        if ([] === $clauses) {
            return true;
        }

        foreach (self::normalize($clauses) as $clause) {
            if (!self::clause($state, $clause)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a clause list as an OR group.
     *
     * @param GameState $state   State to read.
     * @param array     $clauses Clause list.
     *
     * @return bool True when at least one clause holds. An empty list is false.
     */
    public static function evaluateAny(GameState $state, array $clauses): bool
    {
        foreach (self::normalize($clauses) as $clause) {
            if (self::clause($state, $clause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a clause list or a single clause into a list of clauses.
     *
     * @param array $clauses Clause list, or one clause.
     *
     * @return array<int, array>
     *
     * @throws EngineException `invalid_content` when an entry is not an array.
     */
    private static function normalize(array $clauses): array
    {
        if (self::isClauseObject($clauses)) {
            return [$clauses];
        }

        $list = [];

        foreach ($clauses as $clause) {
            if (!is_array($clause)) {
                throw new EngineException(
                    EngineException::INVALID_CONTENT,
                    'Condition clause must be an object, ' . gettype($clause) . ' given.'
                );
            }

            $list[] = $clause;
        }

        return $list;
    }

    /**
     * Whether an array looks like one clause rather than a list of clauses.
     *
     * @param array $candidate Array to inspect.
     *
     * @return bool
     */
    private static function isClauseObject(array $candidate): bool
    {
        foreach (self::CLAUSE_KEYS as $key) {
            if (array_key_exists($key, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate one clause.
     *
     * @param GameState $state  State to read.
     * @param array     $clause Clause object.
     *
     * @return bool
     *
     * @throws EngineException `invalid_content` for unknown or malformed clauses.
     */
    private static function clause(GameState $state, array $clause): bool
    {
        if (array_key_exists('any', $clause)) {
            return self::evaluateAny($state, (array) $clause['any']);
        }

        if (array_key_exists('all', $clause)) {
            return self::evaluate($state, (array) $clause['all']);
        }

        if (array_key_exists('not', $clause)) {
            $inner = $clause['not'];

            if (!is_array($inner)) {
                throw new EngineException(
                    EngineException::INVALID_CONTENT,
                    'Condition clause "not" must contain a clause object or list.'
                );
            }

            return !self::evaluate($state, $inner);
        }

        if (array_key_exists('path', $clause)) {
            return self::comparePath($state, $clause);
        }

        if (array_key_exists('flag', $clause)) {
            return self::flagIsTruthy($state, (string) $clause['flag']);
        }

        if (array_key_exists('not_flag', $clause)) {
            return !self::flagIsTruthy($state, (string) $clause['not_flag']);
        }

        if (array_key_exists('min_turn', $clause)) {
            return $state->turn() >= (int) $clause['min_turn'];
        }

        if (array_key_exists('max_turn', $clause)) {
            return $state->turn() <= (int) $clause['max_turn'];
        }

        if (array_key_exists('seen_event', $clause)) {
            return self::hasSeen($state, (string) $clause['seen_event']);
        }

        if (array_key_exists('not_seen_event', $clause)) {
            return !self::hasSeen($state, (string) $clause['not_seen_event']);
        }

        if (array_key_exists('counter_min', $clause)) {
            return self::counterAtLeast($state, $clause['counter_min']);
        }

        throw new EngineException(
            EngineException::INVALID_CONTENT,
            'Unknown condition clause: ' . implode(', ', array_keys($clause))
        );
    }

    /**
     * Evaluate a `path` / `op` / `value` clause.
     *
     * @param GameState $state  State to read.
     * @param array     $clause Clause object.
     *
     * @return bool
     *
     * @throws EngineException `invalid_content` for an unsupported operator.
     */
    private static function comparePath(GameState $state, array $clause): bool
    {
        $operator = isset($clause['op']) ? (string) $clause['op'] : self::DEFAULT_OPERATOR;

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new EngineException(
                EngineException::INVALID_CONTENT,
                'Unsupported condition operator: ' . $operator
            );
        }

        $left  = $state->get((string) $clause['path'], null);
        $right = array_key_exists('value', $clause) ? $clause['value'] : null;

        return self::compare($left, $operator, $right);
    }

    /**
     * Compare two values, numerically when both sides look numeric.
     *
     * A missing or non-numeric path reads as 0 when compared against a number, so
     * `{ "path": "counters.x", "op": ">=", "value": 1 }` works before `x` exists.
     * When neither side is numeric the comparison is a string comparison and a missing
     * path reads as the empty string.
     *
     * @param mixed  $left     Left-hand value.
     * @param string $operator One of `OPERATORS`.
     * @param mixed  $right    Right-hand value.
     *
     * @return bool
     */
    private static function compare($left, string $operator, $right): bool
    {
        if (is_bool($left) || is_bool($right)) {
            $leftBool  = (bool) $left;
            $rightBool = (bool) $right;

            if ('==' === $operator) {
                return $leftBool === $rightBool;
            }

            if ('!=' === $operator) {
                return $leftBool !== $rightBool;
            }
        }

        if (is_numeric($right)) {
            $leftNumber = is_numeric($left) ? (float) $left : 0.0;

            return self::compareNumbers($leftNumber, $operator, (float) $right);
        }

        $leftString  = is_scalar($left) ? (string) $left : '';
        $rightString = is_scalar($right) ? (string) $right : '';

        switch ($operator) {
            case '==':
                return $leftString === $rightString;
            case '!=':
                return $leftString !== $rightString;
            case '<':
                return strcmp($leftString, $rightString) < 0;
            case '<=':
                return strcmp($leftString, $rightString) <= 0;
            case '>':
                return strcmp($leftString, $rightString) > 0;
            default:
                return strcmp($leftString, $rightString) >= 0;
        }
    }

    /**
     * Numeric comparison with a float tolerance on equality.
     *
     * @param float  $left     Left-hand number.
     * @param string $operator One of `OPERATORS`.
     * @param float  $right    Right-hand number.
     *
     * @return bool
     */
    private static function compareNumbers(float $left, string $operator, float $right): bool
    {
        $equal = abs($left - $right) < self::EPSILON;

        switch ($operator) {
            case '==':
                return $equal;
            case '!=':
                return !$equal;
            case '<':
                return !$equal && $left < $right;
            case '<=':
                return $equal || $left < $right;
            case '>':
                return !$equal && $left > $right;
            default:
                return $equal || $left > $right;
        }
    }

    /**
     * Whether a flag is set to a truthy value.
     *
     * @param GameState $state State to read.
     * @param string    $flag  Flag name (without the `flags.` prefix).
     *
     * @return bool
     */
    private static function flagIsTruthy(GameState $state, string $flag): bool
    {
        $value = $state->get(self::FLAGS_PREFIX . $flag, false);

        if (is_string($value)) {
            return '' !== $value && '0' !== $value && 'false' !== $value;
        }

        return (bool) $value;
    }

    /**
     * Whether an event id has ever been presented.
     *
     * @param GameState $state   State to read.
     * @param string    $eventId Event id.
     *
     * @return bool
     */
    private static function hasSeen(GameState $state, string $eventId): bool
    {
        $seen = $state->get(self::SEEN_EVENTS_PATH, []);

        return is_array($seen) && in_array($eventId, $seen, true);
    }

    /**
     * Evaluate a `counter_min` clause: `["counter_name", minimum]`.
     *
     * @param GameState $state    State to read.
     * @param mixed     $argument Clause argument.
     *
     * @return bool
     *
     * @throws EngineException `invalid_content` when the argument is malformed.
     */
    private static function counterAtLeast(GameState $state, $argument): bool
    {
        if (!is_array($argument) || !isset($argument[0], $argument[1])) {
            throw new EngineException(
                EngineException::INVALID_CONTENT,
                'Condition clause "counter_min" expects ["counter_name", minimum].'
            );
        }

        $current = $state->get(self::COUNTERS_PREFIX . (string) $argument[0], 0);
        $current = is_numeric($current) ? (float) $current : 0.0;

        return $current >= (float) $argument[1] - self::EPSILON;
    }
}
