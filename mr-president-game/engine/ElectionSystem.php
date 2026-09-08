<?php
/**
 * Term bookkeeping.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Keeps `term` and `flags.election_year` honest; nothing else in 0.1.0.
 *
 * A term is 48 turns — four years of one turn per month, starting with the inauguration
 * month. The last four months of a term are the election window: the flag is *set* rather
 * than merely implied so that content and `CongressSystem` can read it like any other flag,
 * and it is explicitly set to `false` outside the window so `{ "flag": "election_year" }`
 * and `{ "not_flag": "election_year" }` are both meaningful from turn 1.
 *
 * Elections themselves — campaigning, results, a second-term reset — are deliberately out
 * of scope for 0.1.0; this class is the seam they will hang off.
 */
final class ElectionSystem
{
    /** RNG draws consumed per tick. Term arithmetic is not a gamble. */
    const RNG_DRAWS = 0;

    /** Turns in one presidential term. */
    const TURNS_PER_TERM = 48;

    /** First turn *within a term* that counts as election season. */
    const ELECTION_WINDOW_START = 45;

    /** Flag set while the administration is inside the election window. */
    const ELECTION_YEAR_FLAG = 'flags.election_year';

    /** Where the term number lives in the state. */
    const TERM_PATH = 'term';

    /**
     * Update term bookkeeping for the turn the state is now on.
     *
     * @param GameState $state State to mutate. Its `turn` must already have been advanced.
     *
     * @return array<int, string> Observations for the turn report.
     */
    public function tick(GameState $state): array
    {
        $turn         = max(1, $state->turn());
        $previousTerm = $state->term();
        $term         = self::termForTurn($turn);
        $turnInTerm   = self::turnWithinTerm($turn);
        $wasElection  = (bool) $state->get(self::ELECTION_YEAR_FLAG, false);
        $isElection   = $turnInTerm >= self::ELECTION_WINDOW_START;

        $state->set(self::TERM_PATH, $term);
        $state->set(self::ELECTION_YEAR_FLAG, $isElection);

        $notes = [];

        if ($term !== $previousTerm) {
            $notes[] = sprintf('A new term begins; this is month %d of term %d.', $turnInTerm, $term);
        }

        if ($isElection && !$wasElection) {
            $notes[] = 'The election calendar now shapes every schedule in Washington.';
        }

        return $notes;
    }

    /**
     * Which term a turn belongs to.
     *
     * @param int $turn Turn number, 1-based.
     *
     * @return int Term number, 1-based.
     */
    public static function termForTurn(int $turn): int
    {
        return (int) floor(($turn - 1) / self::TURNS_PER_TERM) + 1;
    }

    /**
     * Position of a turn inside its own term.
     *
     * @param int $turn Turn number, 1-based.
     *
     * @return int 1..TURNS_PER_TERM.
     */
    public static function turnWithinTerm(int $turn): int
    {
        return (($turn - 1) % self::TURNS_PER_TERM) + 1;
    }
}
