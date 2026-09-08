<?php
/**
 * Compact structured memories of what the administration has done.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Appends and reads the memory log.
 *
 * Memories are short structured records, never prose paragraphs: they drive the History
 * screen today and give a future AI layer a cheap, factual context window. The engine fills
 * `id`, `turn` and `date`; content authors supply `type`, `action`, `target`, `result` and
 * `tags`.
 */
final class MemorySystem
{
    /** Hard cap on stored memories; the oldest are dropped first. */
    const MAX_ENTRIES = 500;

    /** Where the log lives in the state. */
    const MEMORIES_PATH = 'memories';

    /** Prefix for generated memory ids (`m-<turn>-<n>`). */
    const ID_PREFIX = 'm-';

    /** Allowed memory types (spec section 6). */
    const TYPES = [
        'economy',
        'domestic',
        'foreign_policy',
        'security',
        'congress',
        'crisis',
        'disaster',
        'technology',
    ];

    /** Type used when a partial omits one. */
    const DEFAULT_TYPE = 'domestic';

    /** Keys a stored memory always has, in order. */
    const FIELDS = ['id', 'turn', 'date', 'type', 'action', 'target', 'result', 'tags'];

    /**
     * Append a memory to the state.
     *
     * @param GameState $state   State to mutate.
     * @param array     $partial Authored fields: `type`, `action`, `target`, `result`, `tags`.
     *
     * @return array The stored record, including the generated `id`, `turn` and `date`.
     *
     * @throws EngineException `invalid_content` when `type` is present but not a known type.
     */
    public static function record(GameState $state, array $partial): array
    {
        $type = isset($partial['type']) ? (string) $partial['type'] : self::DEFAULT_TYPE;

        if (!self::isValidType($type)) {
            throw new EngineException(
                EngineException::INVALID_CONTENT,
                sprintf(
                    'Unknown memory type "%s"; expected one of: %s.',
                    $type,
                    implode(', ', self::TYPES)
                )
            );
        }

        $turn = $state->turn();

        $record = [
            'id'     => self::nextId($state, $turn),
            'turn'   => $turn,
            'date'   => self::monthKey($state->date()),
            'type'   => $type,
            'action' => isset($partial['action']) ? (string) $partial['action'] : '',
            'target' => isset($partial['target']) ? (string) $partial['target'] : null,
            'result' => isset($partial['result']) ? (string) $partial['result'] : '',
            'tags'   => self::normalizeTags($partial),
        ];

        $memories   = self::all($state);
        $memories[] = $record;

        $overflow = count($memories) - self::MAX_ENTRIES;

        if ($overflow > 0) {
            $memories = array_slice($memories, $overflow);
        }

        $state->set(self::MEMORIES_PATH, array_values($memories));

        return $record;
    }

    /**
     * The whole log, oldest first.
     *
     * @param GameState $state State to read.
     *
     * @return array<int, array>
     */
    public static function all(GameState $state): array
    {
        $memories = $state->get(self::MEMORIES_PATH, []);

        return is_array($memories) ? array_values($memories) : [];
    }

    /**
     * The most recent memories, oldest first within the window.
     *
     * @param GameState $state State to read.
     * @param int       $count How many to return; values below 1 return an empty list.
     *
     * @return array<int, array>
     */
    public static function recent(GameState $state, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $memories = self::all($state);

        if (count($memories) <= $count) {
            return $memories;
        }

        return array_values(array_slice($memories, -$count));
    }

    /**
     * Whether a type string is one of the allowed memory types.
     *
     * Content loading uses this so a bad type fails at load time, not mid-decision.
     *
     * @param string $type Candidate type.
     *
     * @return bool
     */
    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Next unused `m-<turn>-<n>` id for the current turn.
     *
     * @param GameState $state State to read.
     * @param int       $turn  Current turn.
     *
     * @return string
     */
    private static function nextId(GameState $state, int $turn): string
    {
        $prefix   = self::ID_PREFIX . $turn . '-';
        $existing = [];

        foreach (self::all($state) as $memory) {
            if (isset($memory['id']) && is_string($memory['id'])) {
                $existing[$memory['id']] = true;
            }
        }

        $index = 1;

        while (isset($existing[$prefix . $index])) {
            $index++;
        }

        return $prefix . $index;
    }

    /**
     * Reduce an authored date to its `YYYY-MM` key.
     *
     * String slicing on purpose: the engine may not call PHP's date functions.
     *
     * @param string $isoDate ISO date such as `2001-03-20`.
     *
     * @return string
     */
    private static function monthKey(string $isoDate): string
    {
        return strlen($isoDate) >= 7 ? substr($isoDate, 0, 7) : $isoDate;
    }

    /**
     * Normalise the authored tag list into a list of non-empty strings.
     *
     * @param array $partial Authored memory fields.
     *
     * @return array<int, string>
     */
    private static function normalizeTags(array $partial): array
    {
        if (!isset($partial['tags']) || !is_array($partial['tags'])) {
            return [];
        }

        $tags = [];

        foreach ($partial['tags'] as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }

            $tag = (string) $tag;

            if ('' === $tag) {
                continue;
            }

            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }
}
