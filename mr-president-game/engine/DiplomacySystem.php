<?php
/**
 * Monthly drift of bilateral relations and the standing of the United States abroad.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Relations revert; reputations do not.
 *
 * Every measure the player can move on a country (`relationship`, `trust`,
 * `trade_dependency`, `military_tension`, `cooperation`) is pulled back toward the baseline
 * authored in `data/countries/countries.json`. A single dramatic gesture therefore fades
 * over roughly a year unless it is repeated, which is what makes sustained policy feel
 * different from a one-off.
 *
 * Two aggregate indicators sit on top of that: allied confidence tracks how the ally blocs
 * are actually doing (plus the hidden `allied_reliability`), and global influence tracks
 * allied confidence, credibility and how calm the world is.
 *
 * This is the only drift system that reads content, because the baselines it reverts to are
 * authored data rather than engine constants.
 */
final class DiplomacySystem extends DriftSystem
{
    /** RNG draws consumed per tick; see `DriftSystem`. */
    const RNG_DRAWS = 1;

    /** Fraction of the gap to a country's baseline closed each month. */
    const BASELINE_PULL_RATE = 0.06;

    /** `kind` marking a bloc of allies in `countries.json`. */
    const ALLY_KIND = 'ally_bloc';

    /** Measure preferred when judging how an ally bloc is doing. */
    const ALLY_PRIMARY_MEASURE = 'cooperation';

    /** Measure used when a bloc has no `cooperation` value. */
    const ALLY_FALLBACK_MEASURE = 'relationship';

    /** Weight of observed ally cooperation in the allied-confidence target. */
    const ALLIED_COOPERATION_WEIGHT = 0.6;

    /** Weight of hidden allied reliability in the allied-confidence target. */
    const ALLIED_RELIABILITY_WEIGHT = 0.4;

    /** Fraction of the allied-confidence gap closed each month. */
    const ALLIED_CONFIDENCE_ADJUST_RATE = 0.08;

    /** Fraction of the allied-reliability gap closed each month. */
    const ALLIED_RELIABILITY_ADJUST_RATE = 0.05;

    /** Weight of allied confidence in the global-influence target. */
    const INFLUENCE_ALLIED_WEIGHT = 0.5;

    /** Weight of credibility in the global-influence target. */
    const INFLUENCE_CREDIBILITY_WEIGHT = 0.3;

    /** Weight of world calm (100 - crisis level) in the global-influence target. */
    const INFLUENCE_CALM_WEIGHT = 0.2;

    /** Fraction of the global-influence gap closed each month. */
    const INFLUENCE_ADJUST_RATE = 0.06;

    /** Half-width of the monthly influence noise. */
    const INFLUENCE_NOISE = 0.25;

    /** Highest possible value of a 0..100 gauge, used to invert the crisis level. */
    const GAUGE_MAX = 100.0;

    /** Relationship movement worth a sentence in the brief. */
    const NOTE_RELATION_DELTA = 0.8;

    /** Allied-confidence movement worth a sentence in the brief. */
    const NOTE_ALLIED_DELTA = 0.45;

    /**
     * Content, for the country baselines and the `kind` of each country.
     *
     * @var ContentRepository
     */
    private ContentRepository $content;

    /**
     * @param ContentRepository $content Loaded content repository.
     */
    public function __construct(ContentRepository $content)
    {
        $this->content = $content;
    }

    /**
     * Apply one month of diplomatic drift.
     *
     * @param GameState $state State to mutate.
     *
     * @return array<int, string> Observations for the turn report.
     */
    public function tick(GameState $state): array
    {
        $catalogue = $this->content->countries();
        $reversion = $this->reversionEffects($state, $catalogue);

        $confidence  = self::number($state, 'public.allied_confidence');
        $reliability = self::number($state, 'hidden.allied_reliability');
        $credibility = self::number($state, 'hidden.credibility');
        $influence   = self::number($state, 'public.global_influence');
        $crisis      = self::number($state, 'public.crisis_level');

        $allyStanding    = $this->allyStanding($state, $catalogue, $confidence);
        $confidenceTarget = ($allyStanding * self::ALLIED_COOPERATION_WEIGHT)
            + ($reliability * self::ALLIED_RELIABILITY_WEIGHT);
        $confidenceDelta  = self::toward($confidence, $confidenceTarget, self::ALLIED_CONFIDENCE_ADJUST_RATE);

        $influenceTarget = ((($confidence + $confidenceDelta) * self::INFLUENCE_ALLIED_WEIGHT)
            + ($credibility * self::INFLUENCE_CREDIBILITY_WEIGHT)
            + ((self::GAUGE_MAX - $crisis) * self::INFLUENCE_CALM_WEIGHT));
        $influenceDelta  = self::toward($influence, $influenceTarget, self::INFLUENCE_ADJUST_RATE)
            + self::jitter($state, self::INFLUENCE_NOISE);

        $effects = $reversion;

        $effects['public.allied_confidence']  = $confidenceDelta;
        $effects['public.global_influence']   = $influenceDelta;
        $effects['hidden.allied_reliability'] = self::toward(
            $reliability,
            $confidence + $confidenceDelta,
            self::ALLIED_RELIABILITY_ADJUST_RATE
        );

        $applied = Effects::apply($state, $effects);

        return self::limit(self::notes($applied, $confidenceDelta, $catalogue));
    }

    /**
     * Build the effects map that pulls every country measure toward its baseline.
     *
     * @param GameState $state     State to read.
     * @param array     $catalogue Countries from content, keyed by id.
     *
     * @return array<string, float> Effects map of dot path to signed change.
     */
    private function reversionEffects(GameState $state, array $catalogue): array
    {
        $effects  = [];
        $current  = $state->get('countries', []);

        if (!is_array($current)) {
            return $effects;
        }

        foreach ($current as $countryId => $measures) {
            if (!is_array($measures) || !isset($catalogue[(string) $countryId]['baseline'])) {
                continue;
            }

            $baseline = $catalogue[(string) $countryId]['baseline'];

            if (!is_array($baseline)) {
                continue;
            }

            foreach ($measures as $measure => $value) {
                if (!is_numeric($value) || !isset($baseline[$measure]) || !is_numeric($baseline[$measure])) {
                    continue;
                }

                $effects['countries.' . $countryId . '.' . $measure] = self::toward(
                    (float) $value,
                    (float) $baseline[$measure],
                    self::BASELINE_PULL_RATE
                );
            }
        }

        return $effects;
    }

    /**
     * How the ally blocs are actually doing, averaged.
     *
     * @param GameState $state     State to read.
     * @param array     $catalogue Countries from content, keyed by id.
     * @param float     $fallback  Used when the scenario has no ally bloc.
     *
     * @return float
     */
    private function allyStanding(GameState $state, array $catalogue, float $fallback): float
    {
        $total = 0.0;
        $count = 0;

        foreach ($catalogue as $countryId => $country) {
            if (!isset($country['kind']) || self::ALLY_KIND !== $country['kind']) {
                continue;
            }

            $path  = 'countries.' . $countryId . '.';
            $value = $state->has($path . self::ALLY_PRIMARY_MEASURE)
                ? self::number($state, $path . self::ALLY_PRIMARY_MEASURE, $fallback)
                : self::number($state, $path . self::ALLY_FALLBACK_MEASURE, $fallback);

            $total += $value;
            $count++;
        }

        return 0 === $count ? $fallback : $total / $count;
    }

    /**
     * Turn this month's moves into plain-English observations.
     *
     * @param array $applied         Delta rows returned by `Effects::apply()`.
     * @param float $confidenceDelta Change in allied confidence.
     * @param array $catalogue       Countries from content, keyed by id.
     *
     * @return array<int, string>
     */
    private static function notes(array $applied, float $confidenceDelta, array $catalogue): array
    {
        $notes   = [];
        $largest = null;

        foreach ($applied as $row) {
            $path = isset($row['path']) ? (string) $row['path'] : '';

            if (1 !== preg_match('/^countries\.([^.]+)\.relationship$/', $path, $matches)) {
                continue;
            }

            if (null === $largest || abs((float) $row['delta']) > abs((float) $largest['delta'])) {
                $largest        = $row;
                $largest['who'] = $matches[1];
            }
        }

        if (null !== $largest && self::notable((float) $largest['delta'], self::NOTE_RELATION_DELTA)) {
            $name    = isset($catalogue[$largest['who']]['name'])
                ? (string) $catalogue[$largest['who']]['name']
                : ucwords(str_replace('_', ' ', (string) $largest['who']));
            $notes[] = (float) $largest['delta'] < 0.0
                ? sprintf('Working-level contacts with %s cooled back toward routine.', $name)
                : sprintf('Working-level contacts with %s warmed back toward routine.', $name);
        }

        if (self::notable($confidenceDelta, self::NOTE_ALLIED_DELTA)) {
            $notes[] = $confidenceDelta < 0.0
                ? 'Allied capitals asked for clarification more often than usual.'
                : 'Allied capitals reported a steadier reading of American intentions.';
        }

        return $notes;
    }
}
