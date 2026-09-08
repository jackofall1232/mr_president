<?php
/**
 * Minimal autoloader for `MrPresident\Engine`, for tests that run without WordPress.
 *
 * Mirrors the engine half of the plugin's autoloader (spec section 1):
 * `MrPresident\Engine\Foo` maps to `engine/Foo.php`, with sub-namespaces as sub-folders.
 *
 * @package MrPresident\Engine\Tests
 */

declare(strict_types=1);

namespace MrPresident\Engine\Tests;

/** Absolute path to the engine folder, with a trailing separator. */
const ENGINE_DIR = __DIR__ . '/../../mr-president-game/engine/';

/** Namespace prefix the autoloader answers for. */
const ENGINE_PREFIX = 'MrPresident\\Engine\\';

spl_autoload_register(
    static function (string $class): void {
        if (0 !== strncmp($class, ENGINE_PREFIX, strlen(ENGINE_PREFIX))) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen(ENGINE_PREFIX)));
        $path     = ENGINE_DIR . $relative . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
);
