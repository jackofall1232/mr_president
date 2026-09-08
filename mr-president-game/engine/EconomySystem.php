<?php
/**
 * Monthly drift of the economic indicators.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Growth, unemployment, inflation, the deficit and the debt.
 *
 * The model is a small feedback loop, not a macroeconomic simulation:
 *
 * - `hidden.recession_pressure` sets where growth is heading;
 * - growth relative to potential sets where unemployment is heading (Okun's rule of thumb);
 * - growth relative to potential also sets where inflation is heading (demand pressure);
 * - growth and unemployment feed back into recession pressure, closing the loop;
 * - the deficit widens when the economy runs below potential, and the debt accumulates a
 *   twelfth of the annual deficit each month.
 *
 * With the shipped scenario the loop settles near 1.7% growth, 5.4% unemployment and a
 * recession pressure in the mid-thirties: a soft economy that never spirals on its own, so
 * every dramatic move in the numbers is traceable to a decision the player made.
 */
final class EconomySystem extends DriftSystem
{
    /** RNG draws consumed per tick; see `DriftSystem`. */
    const RNG_DRAWS = 2;

    /** Trend growth rate the economy is capable of, annualised %. */
    const POTENTIAL_GROWTH = 2.5;

    /** Growth with zero recession pressure, annualised %. */
    const GROWTH_BASE = 3.0;

    /** Points of growth lost per point of recession pressure. */
    const GROWTH_PRESSURE_COEFF = 0.04;

    /** Fraction of the growth gap closed each month. */
    const GROWTH_ADJUST_RATE = 0.18;

    /** Half-width of the monthly growth surprise. */
    const GROWTH_NOISE = 0.06;

    /** Unemployment rate consistent with growth at potential, %. */
    const NATURAL_UNEMPLOYMENT = 5.0;

    /** Okun coefficient: unemployment points per point of growth shortfall. */
    const OKUN_COEFF = 0.45;

    /** Fraction of the unemployment gap closed each month. */
    const UNEMPLOYMENT_ADJUST_RATE = 0.14;

    /** Half-width of the monthly payroll surprise. */
    const UNEMPLOYMENT_NOISE = 0.04;

    /** Inflation consistent with growth at potential, %. */
    const INFLATION_BASE = 2.0;

    /** Inflation points per point of growth above potential. */
    const INFLATION_GROWTH_COEFF = 0.3;

    /** Fraction of the inflation gap closed each month. */
    const INFLATION_ADJUST_RATE = 0.12;

    /** Recession pressure with the economy exactly at potential. */
    const PRESSURE_BASE = 25.0;

    /** Pressure points per point of growth shortfall. */
    const PRESSURE_GROWTH_COEFF = 8.0;

    /** Pressure points per point of excess unemployment. */
    const PRESSURE_UNEMPLOYMENT_COEFF = 5.0;

    /** Fraction of the recession-pressure gap closed each month. */
    const PRESSURE_ADJUST_RATE = 0.08;

    /** Deficit the budget returns to in a healthy economy, $bn per year. */
    const STRUCTURAL_DEFICIT = 90.0;

    /** Deficit widening, $bn per year, per point of growth shortfall. */
    const DEFICIT_CYCLE_COEFF = 3.0;

    /** Fraction of the gap to the structural deficit closed each month. */
    const DEFICIT_ADJUST_RATE = 0.03;

    /** Months the annual deficit is spread over when it is added to the debt. */
    const MONTHS_PER_YEAR = 12.0;

    /** Growth change worth a sentence in the brief. */
    const NOTE_GROWTH_DELTA = 0.08;

    /** Unemployment change worth a sentence in the brief. */
    const NOTE_UNEMPLOYMENT_DELTA = 0.06;

    /** Inflation change worth a sentence in the brief. */
    const NOTE_INFLATION_DELTA = 0.08;

    /**
     * Apply one month of economic drift.
     *
     * @param GameState $state State to mutate.
     *
     * @return array<int, string> Observations for the turn report.
     */
    public function tick(GameState $state): array
    {
        $growth       = self::number($state, 'public.gdp_growth');
        $unemployment = self::number($state, 'public.unemployment', self::NATURAL_UNEMPLOYMENT);
        $inflation    = self::number($state, 'public.inflation', self::INFLATION_BASE);
        $deficit      = self::number($state, 'public.deficit');
        $pressure     = self::number($state, 'hidden.recession_pressure');

        $growthTarget = self::GROWTH_BASE - ($pressure * self::GROWTH_PRESSURE_COEFF);
        $growthDelta  = self::toward($growth, $growthTarget, self::GROWTH_ADJUST_RATE)
            + self::jitter($state, self::GROWTH_NOISE);

        $projectedGrowth   = $growth + $growthDelta;
        $shortfall         = self::POTENTIAL_GROWTH - $projectedGrowth;
        $unemploymentTarget = self::NATURAL_UNEMPLOYMENT + ($shortfall * self::OKUN_COEFF);
        $unemploymentDelta = self::toward($unemployment, $unemploymentTarget, self::UNEMPLOYMENT_ADJUST_RATE)
            + self::jitter($state, self::UNEMPLOYMENT_NOISE);

        $inflationTarget = self::INFLATION_BASE - ($shortfall * self::INFLATION_GROWTH_COEFF);
        $inflationDelta  = self::toward($inflation, $inflationTarget, self::INFLATION_ADJUST_RATE);

        $pressureTarget = self::PRESSURE_BASE
            + ($shortfall * self::PRESSURE_GROWTH_COEFF)
            + ((($unemployment + $unemploymentDelta) - self::NATURAL_UNEMPLOYMENT) * self::PRESSURE_UNEMPLOYMENT_COEFF);
        $pressureDelta  = self::toward($pressure, $pressureTarget, self::PRESSURE_ADJUST_RATE);

        $deficitDelta = ($shortfall * self::DEFICIT_CYCLE_COEFF)
            + self::toward($deficit, self::STRUCTURAL_DEFICIT, self::DEFICIT_ADJUST_RATE);
        $debtDelta    = ($deficit + $deficitDelta) / self::MONTHS_PER_YEAR;

        Effects::apply(
            $state,
            [
                'public.gdp_growth'         => $growthDelta,
                'public.unemployment'       => $unemploymentDelta,
                'public.inflation'          => $inflationDelta,
                'public.deficit'            => $deficitDelta,
                'public.national_debt'      => $debtDelta,
                'hidden.recession_pressure' => $pressureDelta,
            ]
        );

        return self::limit(self::notes($growthDelta, $unemploymentDelta, $inflationDelta));
    }

    /**
     * Turn this month's moves into plain-English observations.
     *
     * @param float $growthDelta       Change in GDP growth.
     * @param float $unemploymentDelta Change in unemployment.
     * @param float $inflationDelta    Change in inflation.
     *
     * @return array<int, string>
     */
    private static function notes(float $growthDelta, float $unemploymentDelta, float $inflationDelta): array
    {
        $notes = [];

        if (self::notable($growthDelta, self::NOTE_GROWTH_DELTA)) {
            $notes[] = $growthDelta < 0.0
                ? 'Consumer demand softened further and new orders came in light.'
                : 'Output firmed as new orders and freight volumes picked up.';
        }

        if (self::notable($unemploymentDelta, self::NOTE_UNEMPLOYMENT_DELTA)) {
            $notes[] = $unemploymentDelta > 0.0
                ? 'Hiring slowed and jobless claims edged higher.'
                : 'Payrolls improved across services and construction.';
        }

        if (self::notable($inflationDelta, self::NOTE_INFLATION_DELTA)) {
            $notes[] = $inflationDelta > 0.0
                ? 'Price pressures built through the month.'
                : 'Price pressures eased as demand cooled.';
        }

        return $notes;
    }
}
