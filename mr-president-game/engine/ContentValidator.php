<?php
/**
 * Required-key validation for JSON game content.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * Checks content documents against the schemas in spec section 7.
 *
 * Content is authored by hand, so it is validated once at load time and never again: every
 * failure names the file and the offending key, and everything downstream may assume the
 * required keys exist. Optional keys are checked only when present.
 */
final class ContentValidator
{
    /** Ids are kebab-case. */
    const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * State keys are snake_case or kebab-case.
     *
     * Country and advisor ids are also dot-path segments (`countries.european_allies.trust`,
     * `cabinet_assessment.chief_of_staff`) and the spec fixes them in snake_case (sections
     * 2.1 and 7.3), so they are validated against this looser pattern rather than
     * `ID_PATTERN`.
     */
    const KEY_PATTERN = '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/';

    /** Dates are plain ISO calendar dates. */
    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** Keys every scenario document must have. */
    const SCENARIO_REQUIRED = ['id', 'title', 'start_date', 'initial_state'];

    /** Blocks every scenario's `initial_state` must have. */
    const SCENARIO_STATE_REQUIRED = ['public', 'hidden', 'countries'];

    /** Keys every event document must have. */
    const EVENT_REQUIRED = ['id', 'title', 'category', 'summary', 'briefing_text', 'weight', 'choices'];

    /** Keys every choice must have. */
    const CHOICE_REQUIRED = ['id', 'label', 'outcome_text'];

    /** Keys every advisor must have. */
    const ADVISOR_REQUIRED = ['id', 'name', 'office'];

    /** Keys every country must have. */
    const COUNTRY_REQUIRED = ['id', 'name', 'baseline'];

    /** Keys every outlet must have. */
    const OUTLET_REQUIRED = ['id', 'name', 'templates'];

    /** Inclusive severity range for events. */
    const SEVERITY_MIN = 1;
    const SEVERITY_MAX = 5;

    /**
     * Validate a scenario document.
     *
     * @param array  $data Decoded document.
     * @param string $file Relative file path, for error messages.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    public static function scenario(array $data, string $file): void
    {
        self::requireKeys($data, self::SCENARIO_REQUIRED, $file, '');
        self::requireId($data['id'], $file, 'id');
        self::requireText($data['title'], $file, 'title');

        if (!is_string($data['start_date']) || 1 !== preg_match(self::DATE_PATTERN, $data['start_date'])) {
            throw EngineException::invalidContent($file, 'start_date', 'expected an ISO date such as 2001-01-20');
        }

        if (!is_array($data['initial_state'])) {
            throw EngineException::invalidContent($file, 'initial_state', 'expected an object');
        }

        foreach (self::SCENARIO_STATE_REQUIRED as $block) {
            if (!isset($data['initial_state'][$block]) || !is_array($data['initial_state'][$block])) {
                throw EngineException::invalidContent($file, 'initial_state.' . $block, 'expected an object');
            }
        }

        if (isset($data['event_pool']) && null !== $data['event_pool'] && !is_array($data['event_pool'])) {
            throw EngineException::invalidContent($file, 'event_pool', 'expected a list of event ids or null');
        }

        if (isset($data['opening_event']) && null !== $data['opening_event']) {
            self::requireId($data['opening_event'], $file, 'opening_event');
        }

        if (isset($data['quiet_month_weight']) && !is_numeric($data['quiet_month_weight'])) {
            throw EngineException::invalidContent($file, 'quiet_month_weight', 'expected a number');
        }
    }

    /**
     * Validate an event document, including its choices and follow-ups.
     *
     * @param array  $data Decoded document.
     * @param string $file Relative file path, for error messages.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    public static function event(array $data, string $file): void
    {
        self::requireKeys($data, self::EVENT_REQUIRED, $file, '');
        self::requireId($data['id'], $file, 'id');
        self::requireText($data['title'], $file, 'title');
        self::requireText($data['category'], $file, 'category');
        self::requireText($data['summary'], $file, 'summary');
        self::requireText($data['briefing_text'], $file, 'briefing_text');

        if (!is_numeric($data['weight']) || (float) $data['weight'] <= 0.0) {
            throw EngineException::invalidContent($file, 'weight', 'expected a number greater than zero');
        }

        if (isset($data['severity'])) {
            $severity = is_numeric($data['severity']) ? (int) $data['severity'] : 0;

            if ($severity < self::SEVERITY_MIN || $severity > self::SEVERITY_MAX) {
                throw EngineException::invalidContent(
                    $file,
                    'severity',
                    sprintf('expected %d..%d', self::SEVERITY_MIN, self::SEVERITY_MAX)
                );
            }
        }

        if (!is_array($data['choices']) || [] === $data['choices']) {
            throw EngineException::invalidContent($file, 'choices', 'expected at least one choice');
        }

        $seen = [];

        foreach (array_values($data['choices']) as $index => $choice) {
            $context = 'choices[' . $index . ']';

            if (!is_array($choice)) {
                throw EngineException::invalidContent($file, $context, 'expected an object');
            }

            self::choice($choice, $file, $context);

            if (isset($seen[$choice['id']])) {
                throw EngineException::invalidContent($file, $context . '.id', 'duplicate choice id');
            }

            $seen[$choice['id']] = true;
        }

        if (isset($data['followup_events'])) {
            self::followups($data['followup_events'], $file);
        }
    }

    /**
     * Validate the advisors document.
     *
     * @param array  $data Decoded document.
     * @param string $file Relative file path, for error messages.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    public static function advisors(array $data, string $file): void
    {
        if (!isset($data['sets']) || !is_array($data['sets']) || [] === $data['sets']) {
            throw EngineException::invalidContent($file, 'sets', 'expected at least one advisor set');
        }

        foreach ($data['sets'] as $key => $advisors) {
            $context = 'sets.' . $key;

            if (!is_array($advisors) || [] === $advisors) {
                throw EngineException::invalidContent($file, $context, 'expected a non-empty list of advisors');
            }

            foreach (array_values($advisors) as $index => $advisor) {
                $advisorContext = $context . '[' . $index . ']';

                if (!is_array($advisor)) {
                    throw EngineException::invalidContent($file, $advisorContext, 'expected an object');
                }

                self::requireKeys($advisor, self::ADVISOR_REQUIRED, $file, $advisorContext);
                self::requireKey($advisor['id'], $file, $advisorContext . '.id');
                self::requireText($advisor['name'], $file, $advisorContext . '.name');
                self::requireText($advisor['office'], $file, $advisorContext . '.office');
            }
        }
    }

    /**
     * Validate the countries document.
     *
     * @param array  $data Decoded document.
     * @param string $file Relative file path, for error messages.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    public static function countries(array $data, string $file): void
    {
        if (!isset($data['countries']) || !is_array($data['countries']) || [] === $data['countries']) {
            throw EngineException::invalidContent($file, 'countries', 'expected a non-empty list');
        }

        foreach (array_values($data['countries']) as $index => $country) {
            $context = 'countries[' . $index . ']';

            if (!is_array($country)) {
                throw EngineException::invalidContent($file, $context, 'expected an object');
            }

            self::requireKeys($country, self::COUNTRY_REQUIRED, $file, $context);
            self::requireKey($country['id'], $file, $context . '.id');
            self::requireText($country['name'], $file, $context . '.name');

            if (!is_array($country['baseline']) || [] === $country['baseline']) {
                throw EngineException::invalidContent($file, $context . '.baseline', 'expected an object of measures');
            }

            foreach ($country['baseline'] as $measure => $value) {
                if (!is_numeric($value)) {
                    throw EngineException::invalidContent(
                        $file,
                        $context . '.baseline.' . $measure,
                        'expected a number'
                    );
                }
            }
        }
    }

    /**
     * Validate the media outlets document.
     *
     * @param array  $data Decoded document.
     * @param string $file Relative file path, for error messages.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    public static function outlets(array $data, string $file): void
    {
        if (!isset($data['outlets']) || !is_array($data['outlets']) || [] === $data['outlets']) {
            throw EngineException::invalidContent($file, 'outlets', 'expected a non-empty list');
        }

        foreach (array_values($data['outlets']) as $index => $outlet) {
            $context = 'outlets[' . $index . ']';

            if (!is_array($outlet)) {
                throw EngineException::invalidContent($file, $context, 'expected an object');
            }

            self::requireKeys($outlet, self::OUTLET_REQUIRED, $file, $context);
            self::requireId($outlet['id'], $file, $context . '.id');
            self::requireText($outlet['name'], $file, $context . '.name');

            if (!is_array($outlet['templates']) || [] === $outlet['templates']) {
                throw EngineException::invalidContent($file, $context . '.templates', 'expected an object of lists');
            }

            foreach ($outlet['templates'] as $category => $templates) {
                if (!is_array($templates) || [] === $templates) {
                    throw EngineException::invalidContent(
                        $file,
                        $context . '.templates.' . $category,
                        'expected a non-empty list of headline templates'
                    );
                }
            }
        }
    }

    /**
     * Validate one choice.
     *
     * @param array  $choice  Choice object.
     * @param string $file    Relative file path.
     * @param string $context Path to the choice inside the document.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    private static function choice(array $choice, string $file, string $context): void
    {
        self::requireKeys($choice, self::CHOICE_REQUIRED, $file, $context);
        self::requireId($choice['id'], $file, $context . '.id');
        self::requireText($choice['label'], $file, $context . '.label');
        self::requireText($choice['outcome_text'], $file, $context . '.outcome_text');

        foreach (['effects', 'hidden_effects', 'flags', 'counters'] as $key) {
            if (isset($choice[$key]) && !is_array($choice[$key])) {
                throw EngineException::invalidContent($file, $context . '.' . $key, 'expected an object');
            }
        }

        if (isset($choice['memory'])) {
            self::memory($choice['memory'], $file, $context . '.memory');
        }

        if (isset($choice['delayed'])) {
            if (!is_array($choice['delayed'])) {
                throw EngineException::invalidContent($file, $context . '.delayed', 'expected a list');
            }

            foreach (array_values($choice['delayed']) as $index => $delayed) {
                $delayedContext = $context . '.delayed[' . $index . ']';

                if (!is_array($delayed)) {
                    throw EngineException::invalidContent($file, $delayedContext, 'expected an object');
                }

                if (isset($delayed['trigger_event'])) {
                    self::requireId($delayed['trigger_event'], $file, $delayedContext . '.trigger_event');
                }

                if (isset($delayed['memory'])) {
                    self::memory($delayed['memory'], $file, $delayedContext . '.memory');
                }
            }
        }
    }

    /**
     * Validate a `followup_events` block.
     *
     * @param mixed  $followups Raw value.
     * @param string $file      Relative file path.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    private static function followups($followups, string $file): void
    {
        if (!is_array($followups)) {
            throw EngineException::invalidContent($file, 'followup_events', 'expected a list');
        }

        foreach (array_values($followups) as $index => $followup) {
            $context = 'followup_events[' . $index . ']';

            if (!is_array($followup) || !isset($followup['event_id'])) {
                throw EngineException::invalidContent($file, $context, 'expected an object with "event_id"');
            }

            self::requireId($followup['event_id'], $file, $context . '.event_id');
        }
    }

    /**
     * Validate an authored memory partial.
     *
     * @param mixed  $memory  Raw value.
     * @param string $file    Relative file path.
     * @param string $context Path to the memory inside the document.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    private static function memory($memory, string $file, string $context): void
    {
        if (!is_array($memory)) {
            throw EngineException::invalidContent($file, $context, 'expected an object');
        }

        if (isset($memory['type']) && !MemorySystem::isValidType((string) $memory['type'])) {
            throw EngineException::invalidContent(
                $file,
                $context . '.type',
                'expected one of: ' . implode(', ', MemorySystem::TYPES)
            );
        }

        self::requireText(isset($memory['action']) ? $memory['action'] : null, $file, $context . '.action');
    }

    /**
     * Assert that every required key is present.
     *
     * @param array  $data    Document or fragment.
     * @param array  $keys    Required keys.
     * @param string $file    Relative file path.
     * @param string $context Path to the fragment, or an empty string for the document root.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    private static function requireKeys(array $data, array $keys, string $file, string $context): void
    {
        $prefix = '' === $context ? '' : $context . '.';

        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                throw EngineException::invalidContent($file, $prefix . $key, 'required key is missing');
            }
        }
    }

    /**
     * Assert that a value is a kebab-case id.
     *
     * @param mixed  $value Raw value.
     * @param string $file  Relative file path.
     * @param string $key   Key path, for the error message.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    private static function requireId($value, string $file, string $key): void
    {
        if (!is_string($value) || 1 !== preg_match(self::ID_PATTERN, $value)) {
            throw EngineException::invalidContent($file, $key, 'expected a kebab-case id, e.g. "energy-price-spike"');
        }
    }

    /**
     * Assert that a value is a snake_case or kebab-case state key.
     *
     * Used for the ids that double as dot-path segments: countries and advisors.
     *
     * @param mixed  $value Raw value.
     * @param string $file  Relative file path.
     * @param string $key   Key path, for the error message.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    private static function requireKey($value, string $file, string $key): void
    {
        if (!is_string($value) || 1 !== preg_match(self::KEY_PATTERN, $value)) {
            throw EngineException::invalidContent(
                $file,
                $key,
                'expected a snake_case or kebab-case id, e.g. "european_allies"'
            );
        }
    }

    /**
     * Assert that a value is a non-empty string.
     *
     * @param mixed  $value Raw value.
     * @param string $file  Relative file path.
     * @param string $key   Key path, for the error message.
     *
     * @return void
     *
     * @throws EngineException `invalid_content`
     */
    private static function requireText($value, string $file, string $key): void
    {
        if (!is_string($value) || '' === trim($value)) {
            throw EngineException::invalidContent($file, $key, 'expected a non-empty string');
        }
    }
}
