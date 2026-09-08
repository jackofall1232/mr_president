<?php
/**
 * Dependency-free test runner for Mr. President.
 *
 *     php tests/run.php            # everything
 *     php tests/run.php --quick    # skip the two long smoke scripts
 *
 * Runs, in order:
 *   1. static guards — `php -l` on every plugin PHP file, engine purity (no WordPress,
 *      globals, clock or unseeded randomness inside engine/), PHP 7.4 syntax compliance
 *      (no PHP-8-only constructs anywhere in the plugin), JSON validity of every data file;
 *   2. every `tests/engine/*Test.php` and `tests/wp/*Test.php`, each of which returns an
 *      array of `name => callable(Assert $t)`;
 *   3. the two smoke scripts as subprocesses.
 *
 * Exit code is non-zero when anything fails. No PHPUnit, no Composer, nothing to install.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

namespace MrPresident\Tests;

const ROOT       = __DIR__ . '/..';
const PLUGIN_DIR = ROOT . '/mr-president-game';
const ENGINE_DIR = PLUGIN_DIR . '/engine';
const DATA_DIR   = PLUGIN_DIR . '/data';

require_once __DIR__ . '/engine/bootstrap-engine.php';
require_once __DIR__ . '/Assert.php';

$quick   = in_array('--quick', $argv, true);
$passed  = 0;
$failed  = 0;
$failures = [];

/**
 * Record one PASS/FAIL line.
 */
$report = static function (bool $ok, string $name, string $detail = '') use (&$passed, &$failed, &$failures): void {
    if ($ok) {
        $passed++;
        echo "PASS  {$name}\n";
        return;
    }
    $failed++;
    $failures[] = $name . ('' === $detail ? '' : " — {$detail}");
    echo "FAIL  {$name}" . ('' === $detail ? '' : "\n      {$detail}") . "\n";
};

/**
 * Recursively list files under a directory matching a suffix.
 *
 * @return string[]
 */
function listFiles(string $dir, string $suffix): array
{
    $out = [];
    $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (substr($file->getPathname(), -strlen($suffix)) === $suffix) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

echo "== Static guards ==\n";

// 1a. php -l on every plugin PHP file.
$lintErrors = [];
foreach (listFiles(PLUGIN_DIR, '.php') as $file) {
    $output = [];
    $code   = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    if (0 !== $code) {
        $lintErrors[] = implode(' ', $output);
    }
}
$report(empty($lintErrors), 'php -l on every plugin file', implode('; ', $lintErrors));

// 1b. Engine purity: no WordPress, superglobals, clock, or unseeded randomness in engine/.
$purityPattern = '/(?<![\w>\$])(get_option|add_action|add_filter|apply_filters|do_action|wp_[a-z_]+|esc_[a-z_]+|__|_e|sanitize_[a-z_]+|current_user_can|mt_rand|rand|random_int|shuffle|array_rand|time|microtime|date|hrtime|uniqid)\s*\(|\$wpdb|\$_(GET|POST|REQUEST|SERVER|COOKIE|SESSION)\b|\bglobal\s+\$/';
$purityHits = [];
foreach (listFiles(ENGINE_DIR, '.php') as $file) {
    foreach (file($file) as $n => $line) {
        $stripped = preg_replace('#//.*$#', '', $line);
        if (preg_match('#^\s*(\*|/\*)#', $line) || preg_match('/\bfunction\s+\w+\s*\(/', $line)) {
            continue; // docblock line or a method declaration (e.g. GameState::date())
        }
        if (preg_match($purityPattern, (string) $stripped)) {
            $purityHits[] = basename($file) . ':' . ($n + 1) . ' ' . trim($line);
        }
    }
}
$report(empty($purityHits), 'engine/ is pure (no WordPress, superglobals, clock, unseeded RNG)', implode('; ', array_slice($purityHits, 0, 5)));

// 1c. PHP 7.4 compliance: PHP-8-only syntax is forbidden everywhere in the plugin.
$php8Patterns = [
    'match expression'          => '/(?<![\w$>])match\s*\(/',
    'readonly'                  => '/\breadonly\s+(public|private|protected|int|string|array|bool|float|\$)/',
    'mixed return/param type'   => '/(\)\s*:\s*mixed\b|\(\s*mixed\s+\$|,\s*mixed\s+\$)/',
    'never return type'         => '/\)\s*:\s*never\b/',
    'nullsafe operator'         => '/\?->/',
    'enum declaration'          => '/^\s*enum\s+\w+/m',
    'str_contains/starts/ends'  => '/\b(str_contains|str_starts_with|str_ends_with)\s*\(/',
    'union type in signature'   => '/(\(|,)\s*\??(int|string|array|bool|float|iterable|object|self|null)\|[\w|]+\s+\$|\)\s*:\s*\??(int|string|array|bool|float|iterable|object|self|null)\|/',
    'constructor promotion'     => '/__construct\s*\([^)]*\b(public|private|protected)\s+/s',
    'named arguments'           => '/\w+\(\s*\w+:\s*[^:]/',
    'attributes'                => '/^\s*#\[\w+/m',
];
$php8Hits = [];
foreach (listFiles(PLUGIN_DIR, '.php') as $file) {
    $source = (string) file_get_contents($file);
    $code   = preg_replace('#/\*.*?\*/#s', '', $source);   // strip block comments
    $code   = preg_replace('#(^|\s)//.*$#m', '', (string) $code); // strip line comments
    foreach ($php8Patterns as $label => $pattern) {
        if ('named arguments' === $label) {
            continue; // too many false positives against array keys; covered by the 7.4 CI job
        }
        if (preg_match($pattern, (string) $code)) {
            $php8Hits[] = basename($file) . ': ' . $label;
        }
    }
}
$report(empty($php8Hits), 'no PHP-8-only syntax in the plugin', implode('; ', $php8Hits));

// 1d. JSON validity of every content file.
$jsonErrors = [];
foreach (listFiles(DATA_DIR, '.json') as $file) {
    json_decode((string) file_get_contents($file), true);
    if (JSON_ERROR_NONE !== json_last_error()) {
        $jsonErrors[] = basename($file) . ': ' . json_last_error_msg();
    }
}
$report(empty($jsonErrors), 'every data/ JSON file parses', implode('; ', $jsonErrors));

echo "\n== Test files ==\n";

$testFiles = array_merge(listFiles(__DIR__ . '/engine', 'Test.php'), listFiles(__DIR__ . '/wp', 'Test.php'));
foreach ($testFiles as $file) {
    $suite = basename($file, '.php');
    $tests = require $file;
    if (!is_array($tests)) {
        $report(false, $suite, 'test file must return an array of name => callable');
        continue;
    }
    foreach ($tests as $name => $callable) {
        $t = new Assert();
        try {
            $callable($t);
            $report(true, "{$suite}::{$name}");
        } catch (\Throwable $e) {
            $report(false, "{$suite}::{$name}", get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
}

if (!$quick) {
    echo "\n== Smoke scripts ==\n";
    foreach (['engine/smoke-core.php', 'engine/smoke-systems.php'] as $script) {
        $output = [];
        $code   = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/' . $script) . ' 2>&1', $output, $code);
        $report(0 === $code, $script, 0 === $code ? '' : implode("\n      ", array_slice($output, -8)));
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
exit(0);
