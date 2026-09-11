<?php
/**
 * Reading and decoding of on-disk JSON content.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * The engine's only filesystem access, used exclusively by `ContentRepository`.
 *
 * Keeping the three filesystem calls behind one tiny class makes the "the engine does no
 * I/O except loading content" rule (spec section 0.1) checkable by reading a single file,
 * and gives every decode failure the same shaped `invalid_content` error.
 */
final class JsonFile
{
    /** Extension every content file uses. */
    const EXTENSION = '.json';

    /**
     * Read and decode a JSON document.
     *
     * @param string $path     Absolute path to read.
     * @param string $relative Path relative to the data directory, for error messages.
     *
     * @return array Decoded document.
     *
     * @throws EngineException `invalid_content` when the file is unreadable or does not
     *                         decode to an object or list.
     */
    public static function read(string $path, string $relative): array
    {
        $raw = self::exists($path) ? file_get_contents($path) : false;

        if (false === $raw) {
            throw EngineException::invalidContent($relative, '', 'file is missing or unreadable');
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw EngineException::invalidContent(
                $relative,
                '',
                'file is not valid JSON (' . json_last_error_msg() . ')'
            );
        }

        return $decoded;
    }

    /**
     * Whether a path exists and is readable.
     *
     * @param string $path Absolute path.
     *
     * @return bool
     */
    public static function exists(string $path): bool
    {
        return is_file($path) && is_readable($path);
    }

    /**
     * List the JSON files in a directory, sorted for a deterministic load order.
     *
     * @param string $directory Absolute directory path.
     *
     * @return array<int, string> Absolute file paths.
     */
    public static function listIn(string $directory): array
    {
        $matches = glob(rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . '*' . self::EXTENSION);

        if (false === $matches) {
            return [];
        }

        sort($matches);

        return $matches;
    }
}
