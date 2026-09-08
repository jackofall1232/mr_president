<?php
/**
 * Fictional press coverage assembled from authored headlines and outlet templates.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Turns a decision into 1-3 headlines and keeps the running media log.
 *
 * Coverage is assembled in two passes. Headlines the content author wrote for the chosen
 * option come first, in the order they were written, because they are the ones that carry
 * the specific detail. The remaining slots are filled from `data/media/outlets.json`
 * templates for the event's category, drawn with the state RNG from outlets that have not
 * spoken yet — so the same decision under the same seed always produces the same front
 * pages, and no outlet is quoted twice about one decision.
 *
 * Templates use three placeholders: `{event_short}`, `{president}` and `{choice}`. A
 * template whose placeholders cannot all be filled is never selected, which is how the
 * ambient turn-report headline (no decision, so no `{choice}`) stays grammatical.
 */
final class MediaSystem
{
    /** Where the running log lives in the state. */
    const MEDIA_LOG_PATH = 'media_log';

    /** Entries kept in the log; the oldest are dropped. */
    const MEDIA_LOG_MAX = 40;

    /** Headlines produced for one decision, at most. */
    const MAX_HEADLINES = 3;

    /** Template list used when an outlet has nothing for the event's category. */
    const DEFAULT_TEMPLATE_KEY = 'default';

    /** Chance that a turn report carries an ambient headline. */
    const AMBIENT_CHANCE = 0.55;

    /** Source recorded for the ambient turn-report headline. */
    const AMBIENT_SOURCE = 'ambient';

    /** Placeholder for the short form of the event, e.g. `the energy price spike`. */
    const PLACEHOLDER_EVENT = '{event_short}';

    /** Placeholder for the player's name. */
    const PLACEHOLDER_PRESIDENT = '{president}';

    /** Placeholder for the chosen option's label. */
    const PLACEHOLDER_CHOICE = '{choice}';

    /** Optional event key overriding the derived short form. */
    const SHORT_LABEL_KEY = 'short_label';

    /** Article prefixed to a short form derived from an event id. */
    const SHORT_LABEL_ARTICLE = 'the ';

    /** Short form used when an event carries no id at all. */
    const SHORT_LABEL_FALLBACK = 'the situation';

    /** Words left lower-case inside a derived short form. */
    const MINOR_WORDS = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];

    /** Title used when the state carries no president name. */
    const DEFAULT_PRESIDENT = 'The President';

    /**
     * Content, for the outlet roster and their templates.
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
     * Coverage of one decision.
     *
     * @param GameState $state  State to read; its RNG is consumed.
     * @param array     $event  Event document.
     * @param array     $choice Chosen option.
     *
     * @return array<int, array> `['outlet_id', 'outlet', 'title']`, 1-3 entries.
     */
    public function headlinesFor(GameState $state, array $event, array $choice): array
    {
        $headlines = [];
        $used      = [];

        $authored = isset($choice['headlines']) && is_array($choice['headlines']) ? $choice['headlines'] : [];

        foreach ($authored as $entry) {
            if (!is_array($entry) || !isset($entry['title']) || !is_string($entry['title'])) {
                continue;
            }

            $outletId = isset($entry['outlet_id']) ? (string) $entry['outlet_id'] : '';
            $title    = $this->substitute((string) $entry['title'], $state, $event, $choice);

            if (null === $title || isset($used[$outletId])) {
                continue;
            }

            $used[$outletId] = true;
            $headlines[]     = $this->headline($outletId, $title);

            if (count($headlines) >= self::MAX_HEADLINES) {
                return $headlines;
            }
        }

        while (count($headlines) < self::MAX_HEADLINES) {
            $templated = $this->templatedHeadline($state, $event, $choice, $used);

            if (null === $templated) {
                break;
            }

            $used[$templated['outlet_id']] = true;
            $headlines[]                   = $templated;
        }

        return $headlines;
    }

    /**
     * The optional single headline that colours a quiet turn report.
     *
     * Consumes one RNG draw for the coin flip whatever the outcome, so adding or removing
     * ambient coverage never shifts the rest of the stream.
     *
     * @param GameState  $state State to read; its RNG is consumed.
     * @param array|null $event Newly presented event, when there is one.
     *
     * @return array|null `['outlet_id', 'outlet', 'title']` or null.
     */
    public function ambientHeadline(GameState $state, ?array $event): ?array
    {
        if (!$state->rng()->chance(self::AMBIENT_CHANCE)) {
            return null;
        }

        if (null === $event || [] === $event) {
            return null;
        }

        return $this->templatedHeadline($state, $event, null, []);
    }

    /**
     * Complete an authored headline with its outlet name and placeholder substitutions.
     *
     * @param GameState  $state    State to read.
     * @param array      $authored `['outlet_id', 'title']` as written in content.
     * @param array|null $event    Event the headline is about, when known.
     * @param array|null $choice   Choice the headline is about, when known.
     *
     * @return array|null `['outlet_id', 'outlet', 'title']`, or null when unusable.
     */
    public function resolveAuthored(GameState $state, array $authored, ?array $event, ?array $choice): ?array
    {
        if (!isset($authored['title']) || !is_string($authored['title'])) {
            return null;
        }

        $title = $this->substitute($authored['title'], $state, $event, $choice);

        if (null === $title) {
            return null;
        }

        return $this->headline(isset($authored['outlet_id']) ? (string) $authored['outlet_id'] : '', $title);
    }

    /**
     * Append headlines to the running media log.
     *
     * @param GameState $state     State to mutate.
     * @param array     $headlines Headlines from `headlinesFor()` or `ambientHeadline()`.
     * @param string    $source    Provenance, e.g. `foreign-missile-test:sanctions`.
     *
     * @return array<int, array> The log after the append, oldest first.
     */
    public function append(GameState $state, array $headlines, string $source): array
    {
        $log = $state->get(self::MEDIA_LOG_PATH, []);

        if (!is_array($log)) {
            $log = [];
        }

        $log = array_values($log);

        foreach ($headlines as $headline) {
            if (!is_array($headline) || !isset($headline['title'])) {
                continue;
            }

            $log[] = [
                'turn'      => $state->turn(),
                'date'      => $state->date(),
                'outlet_id' => isset($headline['outlet_id']) ? (string) $headline['outlet_id'] : '',
                'outlet'    => isset($headline['outlet']) ? (string) $headline['outlet'] : '',
                'title'     => (string) $headline['title'],
                'source'    => $source,
            ];
        }

        $overflow = count($log) - self::MEDIA_LOG_MAX;

        if ($overflow > 0) {
            $log = array_slice($log, $overflow);
        }

        $log = array_values($log);
        $state->set(self::MEDIA_LOG_PATH, $log);

        return $log;
    }

    /**
     * The most recent headlines, oldest first within the window.
     *
     * @param GameState $state State to read.
     * @param int       $count How many to return.
     *
     * @return array<int, array>
     */
    public static function recent(GameState $state, int $count): array
    {
        $log = $state->get(self::MEDIA_LOG_PATH, []);

        if (!is_array($log) || $count < 1) {
            return [];
        }

        $log = array_values($log);

        return count($log) <= $count ? $log : array_values(array_slice($log, -$count));
    }

    /**
     * The short, in-sentence form of an event.
     *
     * Derived from the event id — `energy-price-spike` becomes `the Energy Price Spike` —
     * because event titles are full headlines and read badly inside another headline. An
     * event may override the derivation with a `short_label` key.
     *
     * @param array $event Event document.
     *
     * @return string
     */
    public static function eventShort(array $event): string
    {
        if (isset($event[self::SHORT_LABEL_KEY]) && is_string($event[self::SHORT_LABEL_KEY])
            && '' !== trim($event[self::SHORT_LABEL_KEY])) {
            return trim($event[self::SHORT_LABEL_KEY]);
        }

        if (!isset($event['id']) || !is_string($event['id']) || '' === $event['id']) {
            return self::SHORT_LABEL_FALLBACK;
        }

        $words  = explode('-', $event['id']);
        $titled = [];

        foreach ($words as $word) {
            if ('' === $word) {
                continue;
            }

            $titled[] = in_array($word, self::MINOR_WORDS, true) ? $word : ucfirst($word);
        }

        return self::SHORT_LABEL_ARTICLE . implode(' ', $titled);
    }

    /**
     * Draw one templated headline from an outlet that has not spoken yet.
     *
     * Consumes two RNG draws when a usable outlet exists, and none when the roster is
     * exhausted.
     *
     * @param GameState  $state  State to read; its RNG is consumed.
     * @param array      $event  Event document.
     * @param array|null $choice Chosen option, when there is one.
     * @param array      $used   Outlet ids already quoted, as a lookup map.
     *
     * @return array|null `['outlet_id', 'outlet', 'title']` or null.
     */
    private function templatedHeadline(GameState $state, array $event, ?array $choice, array $used): ?array
    {
        $category  = isset($event['category']) ? (string) $event['category'] : self::DEFAULT_TEMPLATE_KEY;
        $available = [];

        foreach ($this->content->outlets() as $outletId => $outlet) {
            if (isset($used[(string) $outletId])) {
                continue;
            }

            $templates = $this->usableTemplates($outlet, $category, $state, $event, $choice);

            if ([] === $templates) {
                continue;
            }

            $available[] = ['id' => (string) $outletId, 'outlet' => $outlet, 'templates' => $templates];
        }

        if ([] === $available) {
            return null;
        }

        $picked    = $available[$state->rng()->range(0, count($available) - 1)];
        $templates = $picked['templates'];
        $title     = $templates[$state->rng()->range(0, count($templates) - 1)];

        return [
            'outlet_id' => $picked['id'],
            'outlet'    => isset($picked['outlet']['name']) ? (string) $picked['outlet']['name'] : $picked['id'],
            'title'     => $title,
        ];
    }

    /**
     * Every template of an outlet that can be fully substituted right now.
     *
     * @param array      $outlet   Outlet document.
     * @param string     $category Event category.
     * @param GameState  $state    State to read.
     * @param array      $event    Event document.
     * @param array|null $choice   Chosen option, when there is one.
     *
     * @return array<int, string> Substituted, ready-to-print titles.
     */
    private function usableTemplates(
        array $outlet,
        string $category,
        GameState $state,
        array $event,
        ?array $choice
    ): array {
        $templates = isset($outlet['templates']) && is_array($outlet['templates']) ? $outlet['templates'] : [];

        if (isset($templates[$category]) && is_array($templates[$category])) {
            $candidates = $templates[$category];
        } elseif (isset($templates[self::DEFAULT_TEMPLATE_KEY]) && is_array($templates[self::DEFAULT_TEMPLATE_KEY])) {
            $candidates = $templates[self::DEFAULT_TEMPLATE_KEY];
        } else {
            return [];
        }

        $usable = [];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $title = $this->substitute($candidate, $state, $event, $choice);

            if (null !== $title) {
                $usable[] = $title;
            }
        }

        return $usable;
    }

    /**
     * Fill a template's placeholders.
     *
     * @param string     $template Raw template.
     * @param GameState  $state    State to read.
     * @param array|null $event    Event document, when known.
     * @param array|null $choice   Chosen option, when known.
     *
     * @return string|null The finished title, or null when a placeholder cannot be filled.
     */
    private function substitute(string $template, GameState $state, ?array $event, ?array $choice): ?string
    {
        $replacements = [
            self::PLACEHOLDER_PRESIDENT => self::presidentName($state),
            self::PLACEHOLDER_EVENT     => null === $event || [] === $event ? null : self::eventShort($event),
            self::PLACEHOLDER_CHOICE    => null === $choice || !isset($choice['label'])
                ? null
                : (string) $choice['label'],
        ];

        $title = $template;

        foreach ($replacements as $placeholder => $value) {
            if (false === strpos($title, $placeholder)) {
                continue;
            }

            if (null === $value || '' === $value) {
                return null;
            }

            $title = str_replace($placeholder, $value, $title);
        }

        return ucfirst(trim($title));
    }

    /**
     * Build a headline row, resolving the outlet's display name.
     *
     * An unknown outlet id is humanised rather than rejected: a typo in one headline must
     * not break a decision that has already been applied to the state.
     *
     * @param string $outletId Outlet id as authored.
     * @param string $title    Finished title.
     *
     * @return array `['outlet_id', 'outlet', 'title']`
     */
    private function headline(string $outletId, string $title): array
    {
        $outlets = $this->content->outlets();
        $name    = isset($outlets[$outletId]['name'])
            ? (string) $outlets[$outletId]['name']
            : ucwords(str_replace('-', ' ', $outletId));

        return [
            'outlet_id' => $outletId,
            'outlet'    => $name,
            'title'     => $title,
        ];
    }

    /**
     * The player's name, or a neutral stand-in.
     *
     * @param GameState $state State to read.
     *
     * @return string
     */
    private static function presidentName(GameState $state): string
    {
        $name = $state->get('president_name', '');

        return is_string($name) && '' !== trim($name) ? trim($name) : self::DEFAULT_PRESIDENT;
    }
}
