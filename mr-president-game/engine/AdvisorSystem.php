<?php
/**
 * The cabinet roster and what each advisor says about the event on the desk.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Six advisors, one position each, resolved from the most specific source available.
 *
 * Positions are looked up in this order, first hit wins:
 *
 * 1. `choices[<resolved>].advisor_positions[<advisor>]` — what this advisor says about the
 *    option the President actually took. Only consulted once a choice is resolved.
 * 2. `cabinet_assessment[<advisor>]` — the advisor's read of the event itself.
 * 3. `advisors[].fallback_positions[<event category>]` — the advisor's standing view on
 *    this kind of problem, from `advisors.json`.
 * 4. `advisors[].fallback_positions.default` — their standing view on anything.
 * 5. `DEFAULT_POSITION` — a neutral line, so the Cabinet tab is never blank.
 *
 * Every level is optional in content, which is what lets an event author write two sharp
 * lines for the two advisors who matter and let the roster fill in the rest.
 */
final class AdvisorSystem
{
    /** RNG draws consumed. Advice is deterministic. */
    const RNG_DRAWS = 0;

    /** Advisor set used when a scenario names none. */
    const DEFAULT_SET = 'default';

    /** Key inside `fallback_positions` used when the category has no entry. */
    const FALLBACK_KEY = 'default';

    /** Scenario key naming the advisor set. */
    const SCENARIO_SET_KEY = 'advisor_set';

    /** Event key holding the per-advisor read of the event. */
    const ASSESSMENT_KEY = 'cabinet_assessment';

    /** Choice key holding the per-advisor read of that option. */
    const CHOICE_POSITIONS_KEY = 'advisor_positions';

    /** Advisor key holding their standing views by category. */
    const FALLBACK_POSITIONS_KEY = 'fallback_positions';

    /** Last-resort line when content offers nothing at all. */
    const DEFAULT_POSITION = 'No formal position, Mr. President. I would want a fuller read before I gave you one.';

    /**
     * Content, for the advisor roster.
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
     * The advisors serving in this run, in authored order.
     *
     * @param GameState $state State to read (for the scenario id).
     *
     * @return array<int, array> Advisor documents.
     *
     * @throws EngineException `invalid_content` when the named set does not exist.
     */
    public function roster(GameState $state): array
    {
        return $this->content->advisorSet($this->setKey($state));
    }

    /**
     * Cabinet positions on an event, one row per advisor.
     *
     * @param GameState   $state    State to read.
     * @param array       $event    Event document; may be empty for a quiet month.
     * @param string|null $choiceId Resolved choice id, when the event has been decided.
     *
     * @return array<int, array> `['advisor_id', 'name', 'office', 'position']`.
     */
    public function positionsFor(GameState $state, array $event, ?string $choiceId): array
    {
        $category   = isset($event['category']) ? (string) $event['category'] : '';
        $assessment = isset($event[self::ASSESSMENT_KEY]) && is_array($event[self::ASSESSMENT_KEY])
            ? $event[self::ASSESSMENT_KEY]
            : [];
        $choice     = self::findChoice($event, $choiceId);
        $chosen     = null !== $choice && isset($choice[self::CHOICE_POSITIONS_KEY])
            && is_array($choice[self::CHOICE_POSITIONS_KEY])
                ? $choice[self::CHOICE_POSITIONS_KEY]
                : [];

        $positions = [];

        foreach ($this->roster($state) as $advisor) {
            if (!is_array($advisor) || !isset($advisor['id'])) {
                continue;
            }

            $advisorId = (string) $advisor['id'];

            $positions[] = [
                'advisor_id' => $advisorId,
                'name'       => isset($advisor['name']) ? (string) $advisor['name'] : $advisorId,
                'office'     => isset($advisor['office']) ? (string) $advisor['office'] : '',
                'position'   => self::position($advisor, $advisorId, $chosen, $assessment, $category),
            ];
        }

        return $positions;
    }

    /**
     * The roster as lightweight rows, for screens that need names but no advice.
     *
     * @param GameState $state State to read.
     *
     * @return array<int, array> `['advisor_id', 'name', 'office', 'specialty', 'portrait']`.
     */
    public function directory(GameState $state): array
    {
        $rows = [];

        foreach ($this->roster($state) as $advisor) {
            if (!is_array($advisor) || !isset($advisor['id'])) {
                continue;
            }

            $rows[] = [
                'advisor_id' => (string) $advisor['id'],
                'name'       => isset($advisor['name']) ? (string) $advisor['name'] : (string) $advisor['id'],
                'office'     => isset($advisor['office']) ? (string) $advisor['office'] : '',
                'specialty'  => isset($advisor['specialty']) ? (string) $advisor['specialty'] : '',
                'portrait'   => isset($advisor['portrait']) && is_string($advisor['portrait'])
                    ? $advisor['portrait']
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Resolve one advisor's line through the precedence chain.
     *
     * @param array  $advisor    Advisor document.
     * @param string $advisorId  Advisor id.
     * @param array  $chosen     `advisor_positions` of the resolved choice.
     * @param array  $assessment `cabinet_assessment` of the event.
     * @param string $category   Event category.
     *
     * @return string
     */
    private static function position(
        array $advisor,
        string $advisorId,
        array $chosen,
        array $assessment,
        string $category
    ): string {
        $sources = [
            isset($chosen[$advisorId]) ? $chosen[$advisorId] : null,
            isset($assessment[$advisorId]) ? $assessment[$advisorId] : null,
        ];

        $fallbacks = isset($advisor[self::FALLBACK_POSITIONS_KEY])
            && is_array($advisor[self::FALLBACK_POSITIONS_KEY])
                ? $advisor[self::FALLBACK_POSITIONS_KEY]
                : [];

        if ('' !== $category && isset($fallbacks[$category])) {
            $sources[] = $fallbacks[$category];
        }

        if (isset($fallbacks[self::FALLBACK_KEY])) {
            $sources[] = $fallbacks[self::FALLBACK_KEY];
        }

        foreach ($sources as $source) {
            if (is_string($source) && '' !== trim($source)) {
                return trim($source);
            }
        }

        return self::DEFAULT_POSITION;
    }

    /**
     * Find a choice inside an event document.
     *
     * @param array       $event    Event document.
     * @param string|null $choiceId Choice id, or null.
     *
     * @return array|null
     */
    private static function findChoice(array $event, ?string $choiceId): ?array
    {
        if (null === $choiceId || '' === $choiceId || !isset($event['choices']) || !is_array($event['choices'])) {
            return null;
        }

        foreach ($event['choices'] as $choice) {
            if (is_array($choice) && isset($choice['id']) && $choiceId === (string) $choice['id']) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * The advisor set key named by the current scenario.
     *
     * @param GameState $state State to read.
     *
     * @return string
     */
    private function setKey(GameState $state): string
    {
        $scenarioId = $state->get('scenario_id', '');

        if (!is_string($scenarioId) || '' === $scenarioId) {
            return self::DEFAULT_SET;
        }

        $scenario = $this->content->scenario($scenarioId);

        return isset($scenario[self::SCENARIO_SET_KEY]) && is_string($scenario[self::SCENARIO_SET_KEY])
            && '' !== $scenario[self::SCENARIO_SET_KEY]
                ? $scenario[self::SCENARIO_SET_KEY]
                : self::DEFAULT_SET;
    }
}
