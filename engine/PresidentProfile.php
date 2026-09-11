<?php
declare(strict_types=1);
namespace MrPresident\Engine;

/** Validated player identity; alignment is descriptive, never a correctness score. */
final class PresidentProfile
{
    const ALIGNMENTS = ['Progressive', 'Center-left', 'Centrist', 'Center-right', 'Conservative', 'Independent'];
    const PRIORITIES = ['Economy', 'National security', 'Healthcare', 'Energy', 'Foreign policy', 'Immigration', 'Infrastructure', 'Education', 'Technology', 'Environment', 'Fiscal policy'];
    const STATES = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];

    public static function validate(array $profile): array
    {
        if ([] === $profile) {
            return [];
        }
        $age = $profile['age'] ?? null;
        $state = $profile['home_state'] ?? null;
        $alignment = $profile['alignment'] ?? null;
        $priorities = $profile['priorities'] ?? null;
        if (!is_int($age) || $age < 35 || $age > 100 || !in_array($state, self::STATES, true)
            || !in_array($alignment, self::ALIGNMENTS, true) || !is_array($priorities) || count($priorities) !== 3) {
            throw new EngineException('invalid_profile', 'Choose an age from 35 to 100, a home state, an alignment and three priorities.');
        }
        foreach ($priorities as $priority) {
            if (!is_string($priority) || !in_array($priority, self::PRIORITIES, true)) {
                throw new EngineException('invalid_profile', 'Unknown political priority.');
            }
        }
        if (count(array_unique($priorities)) !== 3) {
            throw new EngineException('invalid_profile', 'Choose three different priorities.');
        }
        return ['age' => $age, 'home_state' => $state, 'alignment' => $alignment, 'priorities' => array_values($priorities)];
    }
}
