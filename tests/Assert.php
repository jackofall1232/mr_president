<?php
/**
 * Tiny assertion helper for the dependency-free runner.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

namespace MrPresident\Tests;

/**
 * Thrown by every failed assertion.
 */
final class AssertionFailed extends \RuntimeException
{
}

/**
 * The `$t` object handed to every test closure.
 */
final class Assert
{
    public function true($value, string $message = 'expected true'): void
    {
        if (true !== $value) {
            throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    public function false($value, string $message = 'expected false'): void
    {
        if (false !== $value) {
            throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    public function same($expected, $actual, string $message = 'values differ'): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf('%s: expected %s, got %s', $message, $this->dump($expected), $this->dump($actual)));
        }
    }

    public function notSame($unexpected, $actual, string $message = 'values should differ'): void
    {
        if ($unexpected === $actual) {
            throw new AssertionFailed($message . ': both ' . $this->dump($actual));
        }
    }

    public function close(float $expected, float $actual, float $epsilon = 0.000001, string $message = 'floats differ'): void
    {
        if (abs($expected - $actual) > $epsilon) {
            throw new AssertionFailed(sprintf('%s: expected %s, got %s', $message, $expected, $actual));
        }
    }

    public function count(int $expected, $countable, string $message = 'count differs'): void
    {
        $this->same($expected, count($countable), $message);
    }

    public function hasKey(string $key, array $array, string $message = 'missing key'): void
    {
        if (!array_key_exists($key, $array)) {
            throw new AssertionFailed($message . ": '{$key}' not in [" . implode(', ', array_keys($array)) . ']');
        }
    }

    public function notHasKey(string $key, array $array, string $message = 'unexpected key'): void
    {
        if (array_key_exists($key, $array)) {
            throw new AssertionFailed($message . ": '{$key}' present");
        }
    }

    public function contains($needle, array $haystack, string $message = 'value not found'): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new AssertionFailed($message . ': ' . $this->dump($needle));
        }
    }

    /**
     * Assert that a callable throws an EngineException (or any Throwable) with the given code.
     */
    public function throws(callable $fn, ?string $engineCode = null, string $message = 'expected an exception'): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (null !== $engineCode) {
                $actual = method_exists($e, 'code') ? $e->code() : (string) $e->getCode();
                $this->same($engineCode, $actual, 'exception code');
            }
            return;
        }
        throw new AssertionFailed($message);
    }

    private function dump($value): string
    {
        $json = json_encode($value);
        $json = false === $json ? var_export($value, true) : $json;
        return strlen($json) > 200 ? substr($json, 0, 200) . '…' : $json;
    }
}
