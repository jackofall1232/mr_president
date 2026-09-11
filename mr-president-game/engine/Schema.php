<?php
/**
 * Game-state schema version and forward migrations.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Owns `GAME_STATE_SCHEMA_VERSION` and the upgrade path between versions.
 *
 * Every persisted state document carries a `schema_version`. `GameState::fromArray()` runs
 * `migrate()` before anything else, so the rest of the engine only ever sees the current
 * shape.
 *
 * Adding a version:
 *   1. bump `VERSION`;
 *   2. add a `private static function migrateToN(array $data): array` that upgrades a
 *      document from version N-1 to N;
 *   3. register it in `steps()`.
 * Steps are applied in ascending order and must be pure array transforms.
 */
final class Schema
{
    /** Current game-state schema version. */
    const VERSION = 2;

    /** Key holding the version inside a state document. */
    const VERSION_KEY = 'schema_version';

    /**
     * Migrate a state document up to `VERSION`.
     *
     * A document with no `schema_version` is assumed to already be current; that is the case
     * for freshly built states that have not been persisted yet.
     *
     * @param array $data Raw state document.
     *
     * @return array Migrated document with `schema_version` set to `VERSION`.
     *
     * @throws EngineException `invalid_content` when the document comes from a newer schema
     *                         than this build knows how to read.
     */
    public static function migrate(array $data): array
    {
        $from = isset($data[self::VERSION_KEY]) && is_numeric($data[self::VERSION_KEY])
            ? (int) $data[self::VERSION_KEY]
            : self::VERSION;

        if ($from > self::VERSION) {
            throw new EngineException(
                EngineException::INVALID_CONTENT,
                sprintf(
                    'Saved game uses schema version %d but this build understands at most %d.',
                    $from,
                    self::VERSION
                )
            );
        }

        if ($from < 1) {
            $from = 1;
        }

        $steps = self::steps();

        for ($target = $from + 1; $target <= self::VERSION; $target++) {
            if (!isset($steps[$target])) {
                throw new EngineException(
                    EngineException::INVALID_CONTENT,
                    sprintf('No migration registered for game-state schema version %d.', $target)
                );
            }

            $data = call_user_func($steps[$target], $data);
        }

        $data[self::VERSION_KEY] = self::VERSION;

        return $data;
    }

    /**
     * Whether a document is already at the current version.
     *
     * @param array $data Raw state document.
     *
     * @return bool
     */
    public static function isCurrent(array $data): bool
    {
        return isset($data[self::VERSION_KEY]) && self::VERSION === (int) $data[self::VERSION_KEY];
    }

    /**
     * Registered migration steps, keyed by the version they produce.
     *
     * Version 1 is the initial schema, so there is nothing to register yet.
     *
     * @return array<int, callable> `[target_version => callable(array): array]`
     */
    private static function steps(): array
    {
        return [2 => [self::class, 'migrateTo2']];
    }

    private static function migrateTo2(array $data): array
    {
        $data['campaign'] = CampaignSystem::DEFAULTS;
        // Existing saves past election night retain their earned playing time.
        if ((int) ($data['turn'] ?? 1) > 47) {
            $data['campaign']['reelected'] = true;
        }
        $data['president_profile'] = [];
        return $data;
    }
}
