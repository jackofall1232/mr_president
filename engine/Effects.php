<?php
/**
 * Path-based effect application with uniform clamping and labelling.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * The one sanctioned way to change gameplay numbers on a `GameState`.
 *
 * An effects object is a flat map of dot path to operation:
 *
 * - number            -> add, then clamp to `BOUNDS` (`"public.approval": -2`)
 * - boolean           -> set (`"flags.export_controls": true`)
 * - string with `=`   -> set (`"flags.posture": "=forward"`); a numeric literal after the
 *                        `=` is a numeric set, still clamped (`"public.crisis_level": "=40"`)
 * - any other string  -> set verbatim
 * - array or null     -> set verbatim
 *
 * `apply()` returns a delta row for every numeric change, visible or hidden. Callers that
 * only want player-facing rows filter with `isVisible()`; that is how
 * `effects` / `hidden_effects` stay one code path with two presentations.
 */
final class Effects
{
    /**
     * Inclusive bounds per path, `[min, max]`; `null` means unbounded on that side.
     *
     * Wildcard patterns use `*` for one whole segment. The most specific match wins
     * (fewest wildcards), so `countries.*.relationship` beats `countries.*.*` regardless of
     * declaration order.
     */
    const BOUNDS = [
        'public.approval'           => [0.0, 100.0],
        'public.gdp_growth'         => [-10.0, 10.0],
        'public.inflation'          => [-2.0, 20.0],
        'public.unemployment'       => [2.0, 25.0],
        'public.deficit'            => [-500.0, 2000.0],
        'public.national_debt'      => [0.0, null],
        'public.global_influence'   => [0.0, 100.0],
        'public.allied_confidence'  => [0.0, 100.0],
        'public.domestic_stability' => [0.0, 100.0],
        'public.congress_support'   => [0.0, 100.0],
        'public.crisis_level'       => [0.0, 100.0],
        'hidden.*'                  => [0.0, 100.0],
        'countries.*.relationship'  => [-100.0, 100.0],
        'countries.*.*'             => [0.0, 100.0],
        'counters.*'                => [0.0, null],
    ];

    /** Prefixes whose values are shown to the player. */
    const VISIBLE_PREFIXES = ['public.', 'countries.'];

    /** Decimals kept on stored values, to stop float drift from leaking into saves. */
    const PRECISION = 6;

    /** Deltas smaller than this are treated as no change at all. */
    const EPSILON = 0.000001;

    /** Prefix marking a "set" rather than an "add". */
    const SET_PREFIX = '=';

    /** Human labels for fixed paths. */
    const LABELS = [
        'public.approval'           => 'Approval',
        'public.gdp_growth'         => 'GDP Growth',
        'public.inflation'          => 'Inflation',
        'public.unemployment'       => 'Unemployment',
        'public.deficit'            => 'Deficit',
        'public.national_debt'      => 'National Debt',
        'public.global_influence'   => 'Global Influence',
        'public.allied_confidence'  => 'Allied Confidence',
        'public.domestic_stability' => 'Domestic Stability',
        'public.congress_support'   => 'Congressional Support',
        'public.crisis_level'       => 'Crisis Level',
        'hidden.credibility'        => 'Credibility',
        'hidden.war_fatigue'        => 'War Fatigue',
        'hidden.institutional_trust' => 'Institutional Trust',
        'hidden.political_capital'  => 'Political Capital',
        'hidden.rival_risk_tolerance' => 'Rival Risk Tolerance',
        'hidden.allied_reliability' => 'Allied Reliability',
        'hidden.intelligence_confidence' => 'Intelligence Confidence',
        'hidden.recession_pressure' => 'Recession Pressure',
        'hidden.escalation_pressure' => 'Escalation Pressure',
        'hidden.trade_retaliation_risk' => 'Trade Retaliation Risk',
        'hidden.media_goodwill'     => 'Media Goodwill',
    ];

    /** Human labels for the per-country measures. */
    const COUNTRY_FIELD_LABELS = [
        'relationship'     => 'Relationship',
        'trust'            => 'Trust',
        'trade_dependency' => 'Trade Dependency',
        'military_tension' => 'Military Tension',
        'cooperation'      => 'Cooperation',
    ];

    /** Separator between the country name and the measure in a label. */
    const LABEL_SEPARATOR = ' · ';

    /** Words that stay upper-case when a path is humanised. */
    const ACRONYMS = ['gdp' => 'GDP', 'nsa' => 'NSA', 'un' => 'UN', 'ai' => 'AI'];

    /**
     * Apply an effects object to a state.
     *
     * @param GameState $state   State to mutate.
     * @param array     $effects Map of dot path to operation.
     *
     * @return array<int, array> Delta rows `['path', 'label', 'delta', 'display']` for every
     *                           numeric change, in authoring order. Non-numeric operations
     *                           and no-op numeric changes produce no row.
     */
    public static function apply(GameState $state, array $effects): array
    {
        $deltas = [];

        foreach ($effects as $path => $operation) {
            $path = (string) $path;

            if ('' === $path) {
                continue;
            }

            $row = self::applyOne($state, $path, $operation);

            if (null !== $row) {
                $deltas[] = $row;
            }
        }

        return $deltas;
    }

    /**
     * Human label for a dot path.
     *
     * Unknown paths are humanised: `countries.rival_state_a.trust` becomes
     * `Rival State A · Trust`, `flags.export_controls` becomes `Export Controls`.
     *
     * @param string $path Dot path.
     *
     * @return string
     */
    public static function label(string $path): string
    {
        if (isset(self::LABELS[$path])) {
            return self::LABELS[$path];
        }

        $segments = explode('.', $path);

        if (3 === count($segments) && 'countries' === $segments[0]) {
            $field = isset(self::COUNTRY_FIELD_LABELS[$segments[2]])
                ? self::COUNTRY_FIELD_LABELS[$segments[2]]
                : self::humanize($segments[2]);

            return self::humanize($segments[1]) . self::LABEL_SEPARATOR . $field;
        }

        return self::humanize(end($segments));
    }

    /**
     * Whether a path is shown to the player outside developer mode.
     *
     * @param string $path Dot path.
     *
     * @return bool
     */
    public static function isVisible(string $path): bool
    {
        foreach (self::VISIBLE_PREFIXES as $prefix) {
            if (0 === strncmp($path, $prefix, strlen($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only the player-facing rows of a delta list.
     *
     * @param array $deltas Rows from `apply()`.
     *
     * @return array<int, array>
     */
    public static function visibleOnly(array $deltas): array
    {
        $visible = [];

        foreach ($deltas as $row) {
            if (isset($row['path']) && self::isVisible((string) $row['path'])) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /**
     * Clamp a value to the bounds registered for a path.
     *
     * @param string $path  Dot path.
     * @param float  $value Raw value.
     *
     * @return float
     */
    public static function clamp(string $path, float $value): float
    {
        $bounds = self::boundsFor($path);

        if (null !== $bounds) {
            if (null !== $bounds[0] && $value < $bounds[0]) {
                $value = (float) $bounds[0];
            }

            if (null !== $bounds[1] && $value > $bounds[1]) {
                $value = (float) $bounds[1];
            }
        }

        return round($value, self::PRECISION);
    }

    /**
     * Bounds registered for a path, or null when the path is unbounded.
     *
     * @param string $path Dot path.
     *
     * @return array|null `[min|null, max|null]`
     */
    public static function boundsFor(string $path): ?array
    {
        if (isset(self::BOUNDS[$path])) {
            return self::BOUNDS[$path];
        }

        $segments  = explode('.', $path);
        $bestScore = null;
        $best      = null;

        foreach (self::BOUNDS as $pattern => $bounds) {
            $patternSegments = explode('.', (string) $pattern);

            if (count($patternSegments) !== count($segments)) {
                continue;
            }

            $score   = 0;
            $matches = true;

            foreach ($patternSegments as $index => $patternSegment) {
                if ('*' === $patternSegment) {
                    $score++;

                    continue;
                }

                if ($patternSegment !== $segments[$index]) {
                    $matches = false;

                    break;
                }
            }

            if ($matches && (null === $bestScore || $score < $bestScore)) {
                $bestScore = $score;
                $best      = $bounds;
            }
        }

        return $best;
    }

    /**
     * Format a delta for display, e.g. `+1`, `-2.5`.
     *
     * @param float $delta Signed change.
     *
     * @return string
     */
    public static function formatDelta(float $delta): string
    {
        $rounded = round($delta, 1);

        if (abs($rounded - round($rounded)) < self::EPSILON) {
            return sprintf('%+d', (int) round($rounded));
        }

        return sprintf('%+.1f', $rounded);
    }

    /**
     * Apply a single path/operation pair.
     *
     * @param GameState $state     State to mutate.
     * @param string    $path      Dot path.
     * @param mixed     $operation Operation value.
     *
     * @return array|null Delta row for numeric changes, null otherwise.
     */
    private static function applyOne(GameState $state, string $path, $operation): ?array
    {
        if (is_bool($operation) || is_array($operation) || null === $operation) {
            $state->set($path, $operation);

            return null;
        }

        if (is_string($operation)) {
            return self::applyString($state, $path, $operation);
        }

        if (is_int($operation) || is_float($operation)) {
            return self::applyNumber($state, $path, (float) $operation, false);
        }

        return null;
    }

    /**
     * Apply a string operation: `=` prefixed sets, everything else a verbatim set.
     *
     * @param GameState $state     State to mutate.
     * @param string    $path      Dot path.
     * @param string    $operation Operation value.
     *
     * @return array|null
     */
    private static function applyString(GameState $state, string $path, string $operation): ?array
    {
        if ('' === $operation || self::SET_PREFIX !== $operation[0]) {
            $state->set($path, $operation);

            return null;
        }

        $literal = substr($operation, 1);

        if (is_numeric($literal)) {
            return self::applyNumber($state, $path, (float) $literal, true);
        }

        if ('true' === $literal || 'false' === $literal) {
            $state->set($path, 'true' === $literal);

            return null;
        }

        $state->set($path, $literal);

        return null;
    }

    /**
     * Add to (or set) a numeric path and clamp the result.
     *
     * @param GameState $state    State to mutate.
     * @param string    $path     Dot path.
     * @param float     $value    Amount to add, or the new value when $isSet.
     * @param bool      $isSet    True for a numeric set, false for an add.
     *
     * @return array|null Delta row, or null when the value did not move.
     */
    private static function applyNumber(GameState $state, string $path, float $value, bool $isSet): ?array
    {
        $current = $state->get($path, 0);
        $old     = is_numeric($current) ? (float) $current : 0.0;
        $new     = self::clamp($path, $isSet ? $value : $old + $value);

        $state->set($path, $new);

        $delta = round($new - $old, self::PRECISION);

        if (abs($delta) < self::EPSILON) {
            return null;
        }

        return [
            'path'    => $path,
            'label'   => self::label($path),
            'delta'   => $delta,
            'display' => self::formatDelta($delta),
        ];
    }

    /**
     * Turn a snake/kebab identifier into title case, keeping known acronyms upper-case.
     *
     * @param string $identifier Raw identifier.
     *
     * @return string
     */
    private static function humanize(string $identifier): string
    {
        $words  = preg_split('/[_\-\s]+/', $identifier);
        $result = [];

        foreach ((array) $words as $word) {
            if ('' === $word) {
                continue;
            }

            $lower = strtolower($word);

            if (isset(self::ACRONYMS[$lower])) {
                $result[] = self::ACRONYMS[$lower];

                continue;
            }

            if (1 === strlen($word)) {
                $result[] = strtoupper($word);

                continue;
            }

            $result[] = ucfirst($lower);
        }

        return implode(' ', $result);
    }
}
