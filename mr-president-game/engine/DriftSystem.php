<?php
/**
 * Shared behaviour for the monthly drift systems.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Base class for the five systems that run every turn advance.
 *
 * A drift system is deliberately dumb: it reads the state, works out where each indicator
 * *should* be sitting given the hidden drivers, and moves it a fraction of the way there.
 * Nothing jumps, everything is explainable in one sentence, and all tuning lives in class
 * constants so a designer can change the feel of the game without reading any logic.
 *
 * Three rules every subclass keeps:
 *
 * 1. **Fixed RNG budget.** Each system declares `RNG_DRAWS` and consumes exactly that many
 *    draws per tick, whatever the state looks like. The turn pipeline's draw sequence is
 *    part of the save format (spec section 3), so a branch that skipped a draw would make
 *    two runs of the same seed diverge.
 * 2. **All writes go through `Effects::apply()`**, so clamping and rounding are uniform and
 *    a drift can never push an indicator outside `Effects::BOUNDS`.
 * 3. **Notes are observations, not decisions.** `tick()` returns short strings for the turn
 *    report; the caller decides how many survive.
 */
abstract class DriftSystem
{
    /** Midpoint of every 0..100 gauge. */
    const NEUTRAL = 50.0;

    /** Most notes one system contributes to a single turn report. */
    const MAX_NOTES = 2;

    /**
     * Apply one month of drift.
     *
     * @param GameState $state State to mutate.
     *
     * @return array<int, string> Short observations for the turn report.
     */
    abstract public function tick(GameState $state): array;

    /**
     * The step that moves `$current` a fraction of the way to `$target`.
     *
     * @param float $current Current value.
     * @param float $target  Where the value is being pulled to.
     * @param float $rate    Fraction of the gap closed this month, 0..1.
     *
     * @return float Signed change to add.
     */
    protected static function toward(float $current, float $target, float $rate): float
    {
        return ($target - $current) * $rate;
    }

    /**
     * A symmetric random nudge in `[-amplitude, +amplitude]`.
     *
     * Consumes exactly one RNG draw, always.
     *
     * @param GameState $state     State whose generator is used.
     * @param float     $amplitude Half-width of the range.
     *
     * @return float
     */
    protected static function jitter(GameState $state, float $amplitude): float
    {
        return ($state->rng()->nextFloat() * 2.0 - 1.0) * $amplitude;
    }

    /**
     * Read a numeric dot path.
     *
     * @param GameState $state    State to read.
     * @param string    $path     Dot path.
     * @param float     $fallback Used when the path is missing or non-numeric.
     *
     * @return float
     */
    protected static function number(GameState $state, string $path, float $fallback = 0.0): float
    {
        $value = $state->get($path, $fallback);

        return is_numeric($value) ? (float) $value : $fallback;
    }

    /**
     * Average one measure across every country in the state.
     *
     * @param GameState $state    State to read.
     * @param string    $measure  Measure key, e.g. `military_tension`.
     * @param float     $fallback Returned when no country carries the measure.
     *
     * @return float
     */
    protected static function averageCountryMeasure(GameState $state, string $measure, float $fallback): float
    {
        $countries = $state->get('countries', []);

        if (!is_array($countries) || [] === $countries) {
            return $fallback;
        }

        $total = 0.0;
        $count = 0;

        foreach ($countries as $country) {
            if (!is_array($country) || !isset($country[$measure]) || !is_numeric($country[$measure])) {
                continue;
            }

            $total += (float) $country[$measure];
            $count++;
        }

        return 0 === $count ? $fallback : $total / $count;
    }

    /**
     * Whether a change is large enough to be worth a sentence in the brief.
     *
     * @param float $delta     Signed change.
     * @param float $threshold Minimum magnitude.
     *
     * @return bool
     */
    protected static function notable(float $delta, float $threshold): bool
    {
        return abs($delta) >= $threshold;
    }

    /**
     * Trim a note list to this system's share of the turn report.
     *
     * @param array<int, string> $notes Candidate notes, most interesting first.
     *
     * @return array<int, string>
     */
    protected static function limit(array $notes): array
    {
        return array_slice(array_values($notes), 0, static::MAX_NOTES);
    }
}
