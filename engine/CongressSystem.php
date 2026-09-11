<?php
/**
 * Monthly drift of congressional support and political capital.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * The Hill follows the country, with a lag and a discount.
 *
 * Congressional support is pulled toward a blend of approval, political capital and
 * institutional trust: members read the same polls the White House does, but they price in
 * how much capital the administration still has to spend and how much they trust the
 * process. In an election year the whole target drops, because votes get harder to find
 * when everyone is campaigning.
 *
 * Political capital in turn follows approval and the Hill's own mood, which is what makes a
 * long unpopular stretch expensive: capital falls, support falls with it, and the next
 * legislative event starts from a worse position.
 */
final class CongressSystem extends DriftSystem
{
    /** RNG draws consumed per tick; see `DriftSystem`. */
    const RNG_DRAWS = 1;

    /** Weight of public approval in the congressional-support target. */
    const SUPPORT_APPROVAL_WEIGHT = 0.5;

    /** Weight of political capital in the congressional-support target. */
    const SUPPORT_CAPITAL_WEIGHT = 0.3;

    /** Weight of institutional trust in the congressional-support target. */
    const SUPPORT_TRUST_WEIGHT = 0.2;

    /** Support points lost while the election-year flag is set. */
    const ELECTION_YEAR_PENALTY = 5.0;

    /** Fraction of the congressional-support gap closed each month. */
    const SUPPORT_ADJUST_RATE = 0.1;

    /** Half-width of the monthly whip-count noise. */
    const SUPPORT_NOISE = 0.3;

    /** Weight of approval in the political-capital target. */
    const CAPITAL_APPROVAL_WEIGHT = 0.7;

    /** Weight of congressional support in the political-capital target. */
    const CAPITAL_SUPPORT_WEIGHT = 0.3;

    /** Fraction of the political-capital gap closed each month. */
    const CAPITAL_ADJUST_RATE = 0.06;

    /** Flag ElectionSystem sets during the last months of a term. */
    const ELECTION_YEAR_FLAG = 'flags.election_year';

    /** Support change worth a sentence in the brief. */
    const NOTE_SUPPORT_DELTA = 0.5;

    /**
     * Apply one month of legislative drift.
     *
     * @param GameState $state State to mutate.
     *
     * @return array<int, string> Observations for the turn report.
     */
    public function tick(GameState $state): array
    {
        $support  = self::number($state, 'public.congress_support');
        $approval = self::number($state, 'public.approval');
        $capital  = self::number($state, 'hidden.political_capital');
        $trust    = self::number($state, 'hidden.institutional_trust');

        $supportTarget = ($approval * self::SUPPORT_APPROVAL_WEIGHT)
            + ($capital * self::SUPPORT_CAPITAL_WEIGHT)
            + ($trust * self::SUPPORT_TRUST_WEIGHT);

        if (self::isElectionYear($state)) {
            $supportTarget -= self::ELECTION_YEAR_PENALTY;
        }

        $supportDelta = self::toward($support, $supportTarget, self::SUPPORT_ADJUST_RATE)
            + self::jitter($state, self::SUPPORT_NOISE);

        $capitalTarget = ($approval * self::CAPITAL_APPROVAL_WEIGHT)
            + ((($support + $supportDelta)) * self::CAPITAL_SUPPORT_WEIGHT);
        $capitalDelta  = self::toward($capital, $capitalTarget, self::CAPITAL_ADJUST_RATE);

        Effects::apply(
            $state,
            [
                'public.congress_support'   => $supportDelta,
                'hidden.political_capital'  => $capitalDelta,
            ]
        );

        return self::limit(self::notes($supportDelta, self::isElectionYear($state)));
    }

    /**
     * Whether the election-year flag is currently set.
     *
     * @param GameState $state State to read.
     *
     * @return bool
     */
    private static function isElectionYear(GameState $state): bool
    {
        return (bool) $state->get(self::ELECTION_YEAR_FLAG, false);
    }

    /**
     * Turn this month's moves into plain-English observations.
     *
     * @param float $supportDelta  Change in congressional support.
     * @param bool  $isElectionYear Whether the election-year flag is set.
     *
     * @return array<int, string>
     */
    private static function notes(float $supportDelta, bool $isElectionYear): array
    {
        $notes = [];

        if (self::notable($supportDelta, self::NOTE_SUPPORT_DELTA)) {
            $notes[] = $supportDelta < 0.0
                ? 'The whip count on the administration\'s agenda tightened.'
                : 'Leadership found a few more votes for the administration\'s agenda.';
        }

        if ($isElectionYear) {
            $notes[] = 'Campaign season kept members away from difficult votes.';
        }

        return $notes;
    }
}
