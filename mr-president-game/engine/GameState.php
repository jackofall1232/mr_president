<?php
/**
 * Authoritative, serializable game state.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * The single source of truth for a run in progress.
 *
 * The state is one nested array plus a live `Random`. Everything is reached through dot
 * paths (`public.approval`, `countries.china.trust`, `flags.export_controls`) so effects and
 * conditions can be authored as data instead of code.
 *
 * The class deliberately does no clamping, no validation of gameplay values and no content
 * lookups: `Effects` owns bounds, `Conditions` owns predicates, `ContentRepository` owns
 * content. That keeps `toArray()` a faithful, lossless snapshot.
 */
final class GameState
{
    /** Separator for dot paths. */
    const PATH_SEPARATOR = '.';

    /**
     * Canonical skeleton for schema version 1.
     *
     * Container keys only - gameplay values come from the scenario, never from here, so
     * tuning lives in exactly one place (`data/scenarios/*.json`).
     */
    const DEFAULTS = [
        'schema_version'     => Schema::VERSION,
        'game_uuid'          => null,
        'president_name'     => '',
        'scenario_id'        => '',
        'seed'               => 0,
        'rng'                => null,
        'turn'               => 1,
        'term'               => 1,
        'campaign'           => CampaignSystem::DEFAULTS,
        'president_profile'  => [],
        'date'               => '',
        'public'             => [],
        'hidden'             => [],
        'countries'          => [],
        'flags'              => [],
        'counters'           => [],
        'active_event'       => null,
        'event_log'          => [],
        'cooldowns'          => [],
        'seen_events'        => [],
        'delayed_queue'      => [],
        'memories'           => [],
        'media_log'          => [],
        'last_outcome'       => null,
        'turn_report'        => null,
        'indicator_snapshot' => [],
    ];

    /**
     * Nested state document, minus the RNG (which lives in `$rng`).
     *
     * @var array
     */
    private array $data;

    /**
     * Live generator; its state is written back into the document by `toArray()`.
     *
     * @var Random
     */
    private Random $rng;

    /**
     * Use `fromArray()`; construction always goes through migration.
     *
     * @param array  $data Migrated, defaults-filled document.
     * @param Random $rng  Generator restored from the document.
     */
    private function __construct(array $data, Random $rng)
    {
        $this->data = $data;
        $this->rng  = $rng;
    }

    /**
     * Build a state from a persisted or freshly assembled document.
     *
     * Runs `Schema::migrate()` first, then fills any missing top-level container with its
     * canonical empty value, so callers may pass a partial document (a new game only needs
     * `seed`, `date`, `scenario_id` and the initial `public`/`hidden`/`countries` blocks).
     *
     * @param array $data Raw state document.
     *
     * @return GameState
     *
     * @throws EngineException `invalid_content` when the document is from a newer schema.
     */
    public static function fromArray(array $data): GameState
    {
        $data = Schema::migrate($data);

        foreach (self::DEFAULTS as $key => $default) {
            $missing = !array_key_exists($key, $data);

            if ($missing || (null === $data[$key] && is_array($default))) {
                $data[$key] = $default;
            }
        }

        $data['turn'] = is_numeric($data['turn']) ? (int) $data['turn'] : 1;
        $data['term'] = is_numeric($data['term']) ? (int) $data['term'] : 1;
        $data['seed'] = is_numeric($data['seed']) ? (int) $data['seed'] : 0;
        $data['date'] = is_string($data['date']) ? $data['date'] : '';

        $rngData = is_array($data['rng']) ? $data['rng'] : ['state' => $data['seed']];
        $rng     = Random::fromArray($rngData);

        unset($data['rng']);

        return new self($data, $rng);
    }

    /**
     * The canonical skeleton, ready to be filled by a scenario.
     *
     * @return array
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Snapshot the whole state, including the current RNG position.
     *
     * @return array Round-trips through `fromArray()` without loss.
     */
    public function toArray(): array
    {
        $data                      = $this->data;
        $data[Schema::VERSION_KEY] = Schema::VERSION;
        $data['rng']               = $this->rng->toArray();

        return $data;
    }

    /**
     * Read a dot path.
     *
     * @param string $path    Dot path, e.g. `countries.china.trust`.
     * @param mixed  $default Returned when the path does not exist.
     *
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        $segments = self::split($path);

        if ([] === $segments) {
            return $default;
        }

        $cursor = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Write a dot path, creating intermediate arrays as needed.
     *
     * No clamping and no type coercion - `Effects::apply()` is the only sanctioned way to
     * change gameplay numbers.
     *
     * @param string $path  Dot path.
     * @param mixed  $value New value.
     *
     * @return void
     */
    public function set(string $path, $value): void
    {
        $segments = self::split($path);

        if ([] === $segments) {
            return;
        }

        $leaf   = array_pop($segments);
        $cursor = &$this->data;

        foreach ($segments as $segment) {
            if (!array_key_exists($segment, $cursor) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$leaf] = $value;
        unset($cursor);
    }

    /**
     * Whether a dot path exists (a null value still counts as present).
     *
     * @param string $path Dot path.
     *
     * @return bool
     */
    public function has(string $path): bool
    {
        $segments = self::split($path);

        if ([] === $segments) {
            return false;
        }

        $cursor = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * Append to the list stored at a dot path, creating it when missing.
     *
     * @param string $path  Dot path to a list.
     * @param mixed  $value Value to append.
     *
     * @return void
     */
    public function push(string $path, $value): void
    {
        $list = $this->get($path, []);

        if (!is_array($list)) {
            $list = [];
        }

        $list[] = $value;

        $this->set($path, array_values($list));
    }

    /**
     * Remove a dot path if it exists.
     *
     * @param string $path Dot path.
     *
     * @return void
     */
    public function delete(string $path): void
    {
        $segments = self::split($path);

        if ([] === $segments) {
            return;
        }

        $leaf   = array_pop($segments);
        $cursor = &$this->data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                unset($cursor);

                return;
            }

            $cursor = &$cursor[$segment];
        }

        unset($cursor[$leaf]);
        unset($cursor);
    }

    /**
     * Current turn number; turn 1 is the inauguration month.
     *
     * @return int
     */
    public function turn(): int
    {
        return (int) $this->data['turn'];
    }

    /**
     * Current in-game ISO date (`YYYY-MM-DD`).
     *
     * @return string
     */
    public function date(): string
    {
        return (string) $this->data['date'];
    }

    /**
     * Current term number.
     *
     * @return int
     */
    public function term(): int
    {
        return (int) $this->data['term'];
    }

    /**
     * The live generator. Its position is persisted by `toArray()`.
     *
     * @return Random
     */
    public function rng(): Random
    {
        return $this->rng;
    }

    /**
     * Split a dot path into segments, dropping empty ones.
     *
     * @param string $path Dot path.
     *
     * @return array<int, string>
     */
    private static function split(string $path): array
    {
        if ('' === $path) {
            return [];
        }

        $segments = explode(self::PATH_SEPARATOR, $path);
        $clean    = [];

        foreach ($segments as $segment) {
            if ('' === $segment) {
                continue;
            }

            $clean[] = $segment;
        }

        return $clean;
    }
}
