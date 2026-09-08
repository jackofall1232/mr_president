<?php
/**
 * Seeded, serializable pseudo-random number generator.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * 32-bit xorshift PRNG.
 *
 * Every step is integer math masked to 32 bits (`& 0xFFFFFFFF`), so a given state produces
 * the same sequence on any 64-bit PHP build and can be reproduced verbatim by a future
 * JavaScript port. The whole generator is one integer, which makes it trivially
 * serializable: `toArray()` / `fromArray()` round-trip it inside `GameState`.
 *
 * Consumption order is part of the engine protocol: the turn pipeline draws in a fixed
 * order (spec section 4.2) so that save -> reload -> continue reproduces the same run.
 */
final class Random
{
    /** 32-bit mask applied after every shift. */
    const MASK = 0xFFFFFFFF;

    /** Number of distinct 32-bit values; used to normalise floats. */
    const SPAN = 4294967296.0;

    /**
     * Substituted for a zero state.
     *
     * xorshift is a fixed point at zero, so the seed 0 (and any seed whose low 32 bits are
     * zero) is remapped to this constant - the 32-bit golden-ratio odd constant.
     */
    const ZERO_SEED_REPLACEMENT = 0x9E3779B9;

    /** Shift triad for xorshift32 (Marsaglia). */
    const SHIFT_A = 13;
    const SHIFT_B = 17;
    const SHIFT_C = 5;

    /**
     * Current 32-bit state, always in 1..2^32-1.
     *
     * @var int
     */
    private int $state;

    /**
     * @param int $seed Any integer; only the low 32 bits are used.
     */
    public function __construct(int $seed)
    {
        $this->state = self::normalize($seed);
    }

    /**
     * Restore a generator from its serialized form.
     *
     * @param array $data `['state' => int]`. A missing or zero state is normalised the same
     *                    way the constructor normalises a zero seed.
     *
     * @return Random
     */
    public static function fromArray(array $data): Random
    {
        $state = isset($data['state']) && is_numeric($data['state']) ? (int) $data['state'] : 0;

        return new self($state);
    }

    /**
     * Serialize the generator.
     *
     * @return array `['state' => int]`
     */
    public function toArray(): array
    {
        return ['state' => $this->state];
    }

    /**
     * Draw the next raw value.
     *
     * @return int In 1..2^32-1.
     */
    public function nextInt(): int
    {
        $x = $this->state;
        $x ^= ($x << self::SHIFT_A) & self::MASK;
        $x &= self::MASK;
        $x ^= $x >> self::SHIFT_B;
        $x ^= ($x << self::SHIFT_C) & self::MASK;
        $x &= self::MASK;

        $this->state = $x;

        return $x;
    }

    /**
     * Draw a float in [0, 1).
     *
     * Derived from `nextInt() - 1` over 2^32, so the result can reach 0.0 and can never
     * reach 1.0.
     *
     * @return float
     */
    public function nextFloat(): float
    {
        return ((float) ($this->nextInt() - 1)) / self::SPAN;
    }

    /**
     * Draw an integer in an inclusive range.
     *
     * Consumes exactly one value. Bounds given in the wrong order are swapped rather than
     * rejected, so callers cannot accidentally desynchronise the stream.
     *
     * @param int $min Lower bound (inclusive).
     * @param int $max Upper bound (inclusive).
     *
     * @return int
     */
    public function range(int $min, int $max): int
    {
        if ($min > $max) {
            $swap = $min;
            $min  = $max;
            $max  = $swap;
        }

        $span = $max - $min + 1;

        return $min + (int) floor($this->nextFloat() * $span);
    }

    /**
     * Roll a probability.
     *
     * Always consumes exactly one value, including for p <= 0 and p >= 1, so that changing a
     * probability in content never shifts the rest of the stream.
     *
     * @param float $probability Chance of returning true, 0..1.
     *
     * @return bool
     */
    public function chance(float $probability): bool
    {
        return $this->nextFloat() < $probability;
    }

    /**
     * Pick one key from a weight map.
     *
     * Non-positive and non-numeric weights are ignored. Returns null - without consuming a
     * value - when nothing is pickable, so an empty pool costs nothing in stream position.
     *
     * @param array $weights `['id' => weight, ...]` with weights > 0.
     *
     * @return string|null The chosen key, or null when the map is empty or all-zero.
     */
    public function weightedPick(array $weights): ?string
    {
        $total    = 0.0;
        $eligible = [];

        foreach ($weights as $key => $weight) {
            if (!is_numeric($weight)) {
                continue;
            }

            $weight = (float) $weight;

            if ($weight <= 0.0) {
                continue;
            }

            $eligible[(string) $key] = $weight;
            $total                  += $weight;
        }

        if ($total <= 0.0) {
            return null;
        }

        $roll       = $this->nextFloat() * $total;
        $cumulative = 0.0;
        $last       = null;

        foreach ($eligible as $key => $weight) {
            $cumulative += $weight;
            $last        = $key;

            if ($roll < $cumulative) {
                return $key;
            }
        }

        // Only reachable through floating point rounding at the very top of the range.
        return $last;
    }

    /**
     * Normalise a seed or restored state into a non-zero 32-bit integer.
     *
     * @param int $seed Raw seed.
     *
     * @return int
     */
    private static function normalize(int $seed): int
    {
        $state = $seed & self::MASK;

        return 0 === $state ? self::ZERO_SEED_REPLACEMENT : $state;
    }
}
