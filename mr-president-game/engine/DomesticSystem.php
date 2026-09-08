<?php
/**
 * Monthly drift of approval, stability and the domestic hidden drivers.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * How the country feels about the administration, month by month.
 *
 * Approval is never a free-floating number: it decays toward a *base* that the economy, the
 * crisis level and media goodwill decide between them. A president with a soft economy and
 * a running crisis has a base in the forties, so an event-driven bump fades; a president
 * with growth and calm has a base in the high fifties, so the same bump sticks.
 *
 * Domestic stability follows the same shape with a slower rate, and three hidden drivers
 * revert quietly to their own baselines: institutional trust, media goodwill and war
 * fatigue (which also feeds off escalation pressure).
 */
final class DomesticSystem extends DriftSystem
{
    /** RNG draws consumed per tick; see `DriftSystem`. */
    const RNG_DRAWS = 1;

    /** Approval an administration holds with a neutral economy and no crisis. */
    const APPROVAL_BASE = 50.0;

    /** Approval points per point of growth above potential. */
    const APPROVAL_GROWTH_COEFF = 2.5;

    /** Approval points lost per point of unemployment above the natural rate. */
    const APPROVAL_UNEMPLOYMENT_COEFF = 2.0;

    /** Approval points lost per point of crisis level. */
    const APPROVAL_CRISIS_COEFF = 0.12;

    /** Approval points per point of media goodwill away from neutral. */
    const APPROVAL_MEDIA_COEFF = 0.06;

    /** Fraction of the approval gap closed each month. */
    const APPROVAL_ADJUST_RATE = 0.1;

    /** Half-width of the monthly polling noise. */
    const APPROVAL_NOISE = 0.35;

    /** Domestic stability in a calm, fully employed country. */
    const STABILITY_BASE = 68.0;

    /** Stability points lost per point of crisis level. */
    const STABILITY_CRISIS_COEFF = 0.18;

    /** Stability points lost per point of unemployment above the natural rate. */
    const STABILITY_UNEMPLOYMENT_COEFF = 1.5;

    /** Stability points gained per point of approval above neutral. */
    const STABILITY_APPROVAL_COEFF = 0.1;

    /** Fraction of the stability gap closed each month. */
    const STABILITY_ADJUST_RATE = 0.08;

    /** Where institutional trust settles when nothing disturbs it. */
    const INSTITUTIONAL_TRUST_BASE = 55.0;

    /** Fraction of the institutional-trust gap closed each month. */
    const INSTITUTIONAL_TRUST_ADJUST_RATE = 0.03;

    /** Where media goodwill settles when nothing disturbs it. */
    const MEDIA_GOODWILL_BASE = 50.0;

    /** Fraction of the media-goodwill gap closed each month. */
    const MEDIA_GOODWILL_ADJUST_RATE = 0.05;

    /** Fraction of standing war fatigue that fades each month. */
    const WAR_FATIGUE_DECAY_RATE = 0.05;

    /** Escalation pressure above which the public starts to tire. */
    const WAR_FATIGUE_ESCALATION_THRESHOLD = 40.0;

    /** War-fatigue points per point of escalation above the threshold. */
    const WAR_FATIGUE_ESCALATION_COEFF = 0.03;

    /** Unemployment rate treated as full employment. */
    const NATURAL_UNEMPLOYMENT = EconomySystem::NATURAL_UNEMPLOYMENT;

    /** Approval change worth a sentence in the brief. */
    const NOTE_APPROVAL_DELTA = 0.5;

    /** Stability change worth a sentence in the brief. */
    const NOTE_STABILITY_DELTA = 0.5;

    /**
     * Apply one month of domestic drift.
     *
     * @param GameState $state State to mutate.
     *
     * @return array<int, string> Observations for the turn report.
     */
    public function tick(GameState $state): array
    {
        $approval     = self::number($state, 'public.approval', self::APPROVAL_BASE);
        $stability    = self::number($state, 'public.domestic_stability', self::STABILITY_BASE);
        $growth       = self::number($state, 'public.gdp_growth');
        $unemployment = self::number($state, 'public.unemployment', self::NATURAL_UNEMPLOYMENT);
        $crisis       = self::number($state, 'public.crisis_level');
        $goodwill     = self::number($state, 'hidden.media_goodwill', self::MEDIA_GOODWILL_BASE);
        $trust        = self::number($state, 'hidden.institutional_trust', self::INSTITUTIONAL_TRUST_BASE);
        $fatigue      = self::number($state, 'hidden.war_fatigue');
        $escalation   = self::number($state, 'hidden.escalation_pressure');

        $excessUnemployment = $unemployment - self::NATURAL_UNEMPLOYMENT;

        $approvalBase = self::APPROVAL_BASE
            + (($growth - EconomySystem::POTENTIAL_GROWTH) * self::APPROVAL_GROWTH_COEFF)
            - ($excessUnemployment * self::APPROVAL_UNEMPLOYMENT_COEFF)
            - ($crisis * self::APPROVAL_CRISIS_COEFF)
            + (($goodwill - self::NEUTRAL) * self::APPROVAL_MEDIA_COEFF);

        $approvalDelta = self::toward($approval, $approvalBase, self::APPROVAL_ADJUST_RATE)
            + self::jitter($state, self::APPROVAL_NOISE);

        $stabilityBase = self::STABILITY_BASE
            - ($crisis * self::STABILITY_CRISIS_COEFF)
            - ($excessUnemployment * self::STABILITY_UNEMPLOYMENT_COEFF)
            + ((($approval + $approvalDelta) - self::NEUTRAL) * self::STABILITY_APPROVAL_COEFF);

        $stabilityDelta = self::toward($stability, $stabilityBase, self::STABILITY_ADJUST_RATE);

        $fatigueTarget = max(
            0.0,
            ($escalation - self::WAR_FATIGUE_ESCALATION_THRESHOLD) * self::WAR_FATIGUE_ESCALATION_COEFF
        );
        $fatigueDelta  = $fatigueTarget - ($fatigue * self::WAR_FATIGUE_DECAY_RATE);

        Effects::apply(
            $state,
            [
                'public.approval'            => $approvalDelta,
                'public.domestic_stability'  => $stabilityDelta,
                'hidden.institutional_trust' => self::toward(
                    $trust,
                    self::INSTITUTIONAL_TRUST_BASE,
                    self::INSTITUTIONAL_TRUST_ADJUST_RATE
                ),
                'hidden.media_goodwill'      => self::toward(
                    $goodwill,
                    self::MEDIA_GOODWILL_BASE,
                    self::MEDIA_GOODWILL_ADJUST_RATE
                ),
                'hidden.war_fatigue'         => $fatigueDelta,
            ]
        );

        return self::limit(self::notes($approvalDelta, $stabilityDelta));
    }

    /**
     * Turn this month's moves into plain-English observations.
     *
     * @param float $approvalDelta  Change in approval.
     * @param float $stabilityDelta Change in domestic stability.
     *
     * @return array<int, string>
     */
    private static function notes(float $approvalDelta, float $stabilityDelta): array
    {
        $notes = [];

        if (self::notable($approvalDelta, self::NOTE_APPROVAL_DELTA)) {
            $notes[] = $approvalDelta < 0.0
                ? 'National polling drifted against the administration.'
                : 'National polling moved in the administration\'s favour.';
        }

        if (self::notable($stabilityDelta, self::NOTE_STABILITY_DELTA)) {
            $notes[] = $stabilityDelta < 0.0
                ? 'Governors reported a harder public mood in several states.'
                : 'The domestic picture steadied in most regions.';
        }

        return $notes;
    }
}
