<?php
/**
 * Monthly drift of escalation pressure, the crisis level and the security drivers.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Crises cool, but only as fast as the world lets them.
 *
 * `hidden.escalation_pressure` is the memory of recent confrontation. It decays toward the
 * *ambient* level implied by military tension around the world, so a spike from a decision
 * bleeds off over months rather than turns, and a permanently tense map keeps a floor under
 * it.
 *
 * `public.crisis_level` is derived rather than driven: it tracks escalation pressure,
 * average military tension and war fatigue, and moves faster than they do because it is the
 * headline the public sees. Two hidden drivers close the loop: rival risk tolerance rises
 * as American credibility falls, and intelligence confidence reverts to its own baseline.
 */
final class SecuritySystem extends DriftSystem
{
    /** RNG draws consumed per tick; see `DriftSystem`. */
    const RNG_DRAWS = 1;

    /** Military tension treated as background noise rather than a threat. */
    const TENSION_FLOOR = 15.0;

    /** Escalation pressure implied per point of tension above the floor. */
    const TENSION_TO_ESCALATION = 0.5;

    /** Fraction of the escalation gap closed each month. */
    const ESCALATION_ADJUST_RATE = 0.08;

    /** Half-width of the monthly escalation noise. */
    const ESCALATION_NOISE = 0.25;

    /** Weight of escalation pressure in the crisis-level target. */
    const CRISIS_ESCALATION_WEIGHT = 0.45;

    /** Weight of average military tension in the crisis-level target. */
    const CRISIS_TENSION_WEIGHT = 0.35;

    /** Weight of war fatigue in the crisis-level target. */
    const CRISIS_FATIGUE_WEIGHT = 0.2;

    /** Fraction of the crisis gap closed each month; higher, because it is a headline. */
    const CRISIS_ADJUST_RATE = 0.25;

    /** Rival risk tolerance when American credibility sits at its pivot. */
    const RIVAL_BASE = 45.0;

    /** Credibility level at which rivals take the base amount of risk. */
    const RIVAL_CREDIBILITY_PIVOT = 55.0;

    /** Risk-tolerance points per point of credibility below the pivot. */
    const RIVAL_CREDIBILITY_COEFF = 0.35;

    /** Fraction of the rival risk-tolerance gap closed each month. */
    const RIVAL_ADJUST_RATE = 0.05;

    /** Where intelligence confidence settles when nothing disturbs it. */
    const INTELLIGENCE_BASE = 55.0;

    /** Fraction of the intelligence-confidence gap closed each month. */
    const INTELLIGENCE_ADJUST_RATE = 0.04;

    /** Default military tension when no country carries the measure. */
    const DEFAULT_TENSION = 20.0;

    /** Escalation change worth a sentence in the brief. */
    const NOTE_ESCALATION_DELTA = 0.8;

    /** Crisis-level change worth a sentence in the brief. */
    const NOTE_CRISIS_DELTA = 0.8;

    /**
     * Apply one month of security drift.
     *
     * @param GameState $state State to mutate.
     *
     * @return array<int, string> Observations for the turn report.
     */
    public function tick(GameState $state): array
    {
        $escalation   = self::number($state, 'hidden.escalation_pressure');
        $crisis       = self::number($state, 'public.crisis_level');
        $fatigue      = self::number($state, 'hidden.war_fatigue');
        $credibility  = self::number($state, 'hidden.credibility', self::RIVAL_CREDIBILITY_PIVOT);
        $rivalRisk    = self::number($state, 'hidden.rival_risk_tolerance', self::RIVAL_BASE);
        $intelligence = self::number($state, 'hidden.intelligence_confidence', self::INTELLIGENCE_BASE);
        $tension      = self::averageCountryMeasure($state, 'military_tension', self::DEFAULT_TENSION);

        $escalationTarget = max(0.0, ($tension - self::TENSION_FLOOR) * self::TENSION_TO_ESCALATION);
        $escalationDelta  = self::toward($escalation, $escalationTarget, self::ESCALATION_ADJUST_RATE)
            + self::jitter($state, self::ESCALATION_NOISE);

        $crisisTarget = ((($escalation + $escalationDelta) * self::CRISIS_ESCALATION_WEIGHT)
            + ($tension * self::CRISIS_TENSION_WEIGHT)
            + ($fatigue * self::CRISIS_FATIGUE_WEIGHT));
        $crisisDelta  = self::toward($crisis, $crisisTarget, self::CRISIS_ADJUST_RATE);

        $rivalTarget = self::RIVAL_BASE
            + ((self::RIVAL_CREDIBILITY_PIVOT - $credibility) * self::RIVAL_CREDIBILITY_COEFF);

        Effects::apply(
            $state,
            [
                'hidden.escalation_pressure'     => $escalationDelta,
                'public.crisis_level'            => $crisisDelta,
                'hidden.rival_risk_tolerance'    => self::toward($rivalRisk, $rivalTarget, self::RIVAL_ADJUST_RATE),
                'hidden.intelligence_confidence' => self::toward(
                    $intelligence,
                    self::INTELLIGENCE_BASE,
                    self::INTELLIGENCE_ADJUST_RATE
                ),
            ]
        );

        return self::limit(self::notes($escalationDelta, $crisisDelta));
    }

    /**
     * Turn this month's moves into plain-English observations.
     *
     * @param float $escalationDelta Change in escalation pressure.
     * @param float $crisisDelta     Change in the crisis level.
     *
     * @return array<int, string>
     */
    private static function notes(float $escalationDelta, float $crisisDelta): array
    {
        $notes = [];

        if (self::notable($crisisDelta, self::NOTE_CRISIS_DELTA)) {
            $notes[] = $crisisDelta > 0.0
                ? 'The Situation Room raised its standing assessment of the threat picture.'
                : 'The Situation Room lowered its standing assessment of the threat picture.';
        }

        if (self::notable($escalationDelta, self::NOTE_ESCALATION_DELTA)) {
            $notes[] = $escalationDelta > 0.0
                ? 'Military activity abroad kept the escalation watch open.'
                : 'Deployments abroad returned to their normal rotation.';
        }

        return $notes;
    }
}
