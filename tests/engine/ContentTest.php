<?php
/**
 * Content integrity: every reference resolves, every path is known, every choice trades off.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\ContentRepository;
use MrPresident\Tests\Assert;

$content = new ContentRepository(__DIR__ . '/../../mr-president-game/data');

const MRP_PUBLIC_KEYS  = ['approval', 'gdp_growth', 'inflation', 'unemployment', 'deficit', 'national_debt', 'global_influence', 'allied_confidence', 'domestic_stability', 'congress_support', 'crisis_level'];
const MRP_HIDDEN_KEYS  = ['credibility', 'war_fatigue', 'institutional_trust', 'political_capital', 'rival_risk_tolerance', 'allied_reliability', 'intelligence_confidence', 'recession_pressure', 'escalation_pressure', 'trade_retaliation_risk', 'media_goodwill'];
const MRP_COUNTRY_KEYS = ['relationship', 'trust', 'trade_dependency', 'military_tension', 'cooperation'];
const MRP_ADVISOR_IDS  = ['state', 'defense', 'treasury', 'nsa', 'chief_of_staff', 'intelligence'];

/**
 * Validate one effects path against the spec §2.1 keys.
 */
$checkPath = static function (Assert $t, string $path, array $countries, string $where): void {
    $parts = explode('.', $path);
    switch ($parts[0]) {
        case 'public':
            $t->contains($parts[1] ?? '', MRP_PUBLIC_KEYS, "{$where}: unknown public key in {$path}");
            break;
        case 'hidden':
            $t->contains($parts[1] ?? '', MRP_HIDDEN_KEYS, "{$where}: unknown hidden key in {$path}");
            break;
        case 'countries':
            $t->contains($parts[1] ?? '', $countries, "{$where}: unknown country in {$path}");
            $t->contains($parts[2] ?? '', MRP_COUNTRY_KEYS, "{$where}: unknown country field in {$path}");
            break;
        case 'flags':
        case 'counters':
            $t->true(isset($parts[1]) && '' !== $parts[1], "{$where}: bare {$path}");
            break;
        default:
            throw new \MrPresident\Tests\AssertionFailed("{$where}: unsupported path root in {$path}");
    }
};

return [
    'scenario loads with every state key and a valid pool' => static function (Assert $t) use ($content): void {
        $scenario = $content->scenario('new-administration');
        $t->same('2001-01-20', $scenario['start_date']);
        foreach (MRP_PUBLIC_KEYS as $k) {
            $t->hasKey($k, $scenario['initial_state']['public'], 'scenario public');
        }
        foreach (MRP_HIDDEN_KEYS as $k) {
            $t->hasKey($k, $scenario['initial_state']['hidden'], 'scenario hidden');
        }
        foreach ($scenario['event_pool'] as $id) {
            $t->true($content->hasEvent($id), "pool event {$id} exists");
        }
        $t->true($content->hasEvent($scenario['opening_event']), 'opening event exists');
        $t->true(count($scenario['event_pool']) >= 6, 'at least six playable events');
    },
    'six advisors with the fixed ids' => static function (Assert $t) use ($content): void {
        $ids = array_map(static function (array $a): string {
            return $a['id'];
        }, $content->advisorSet('default'));
        sort($ids);
        $expected = MRP_ADVISOR_IDS;
        sort($expected);
        $t->same($expected, $ids);
        foreach ($content->advisorSet('default') as $advisor) {
            foreach (['name', 'office', 'specialty', 'risk_tolerance', 'policy_tendencies', 'confidence_style', 'fallback_positions'] as $k) {
                $t->hasKey($k, $advisor, "advisor {$advisor['id']}");
            }
            $t->hasKey('default', $advisor['fallback_positions'], "advisor {$advisor['id']} fallback");
        }
    },
    'countries match the scenario baselines' => static function (Assert $t) use ($content): void {
        $scenario  = $content->scenario('new-administration');
        $countries = $content->countries();
        foreach ($scenario['initial_state']['countries'] as $id => $values) {
            $t->hasKey($id, $countries, 'country defined');
            $t->hasKey('name', $countries[$id]);
            $t->hasKey('baseline', $countries[$id]);
        }
    },
    'every event references only known ids and paths, and every choice trades off' => static function (Assert $t) use ($content, $checkPath): void {
        $countries = array_keys($content->countries());
        $outlets   = array_keys($content->outlets());
        foreach ($content->events() as $id => $event) {
            $where = "event {$id}";
            $t->true(count($event['choices']) >= 3, "{$where}: at least three choices");
            foreach (array_keys($event['cabinet_assessment'] ?? []) as $advisorId) {
                $t->contains($advisorId, MRP_ADVISOR_IDS, "{$where}: cabinet_assessment advisor");
            }
            foreach ($event['exclusive_with'] ?? [] as $other) {
                $t->true($content->hasEvent($other), "{$where}: exclusive_with {$other}");
            }
            foreach ($event['followup_events'] ?? [] as $f) {
                $t->true($content->hasEvent($f['event_id']), "{$where}: followup {$f['event_id']}");
            }
            foreach ($event['choices'] as $choice) {
                $cw       = "{$where}/{$choice['id']}";
                $negative = false;
                foreach (['effects', 'hidden_effects'] as $bucket) {
                    foreach ($choice[$bucket] ?? [] as $path => $value) {
                        $checkPath($t, (string) $path, $countries, $cw);
                    }
                }
                // A cost is any numeric decrease outside relationship-with-rivals, or an increase in crisis/tension/pressure.
                foreach (['effects', 'hidden_effects'] as $bucket) {
                    foreach ($choice[$bucket] ?? [] as $path => $value) {
                        if (!is_numeric($value)) {
                            continue;
                        }
                        $isBad = preg_match('/(crisis_level|military_tension|escalation_pressure|recession_pressure|war_fatigue|trade_retaliation_risk|deficit|national_debt|inflation|unemployment)$/', (string) $path);
                        if (($isBad && $value > 0) || (!$isBad && $value < 0)) {
                            $negative = true;
                        }
                    }
                }
                $t->true($negative, "{$cw}: choice has no cost at all");
                foreach ($choice['advisor_positions'] ?? [] as $advisorId => $text) {
                    $t->contains($advisorId, MRP_ADVISOR_IDS, "{$cw}: advisor_positions advisor");
                }
                foreach ($choice['headlines'] ?? [] as $h) {
                    $t->contains($h['outlet_id'], $outlets, "{$cw}: headline outlet");
                }
                foreach ($choice['delayed'] ?? [] as $i => $item) {
                    foreach (['effects', 'hidden_effects'] as $bucket) {
                        foreach ($item[$bucket] ?? [] as $path => $value) {
                            $checkPath($t, (string) $path, $countries, "{$cw} delayed[{$i}]");
                        }
                    }
                    if (!empty($item['trigger_event'])) {
                        $t->true($content->hasEvent($item['trigger_event']), "{$cw} delayed[{$i}]: trigger {$item['trigger_event']}");
                    }
                    if (!empty($item['headline'])) {
                        $t->contains($item['headline']['outlet_id'], $outlets, "{$cw} delayed[{$i}]: headline outlet");
                    }
                }
            }
        }
    },
    'outlets are the four fictional names' => static function (Assert $t) use ($content): void {
        $names = array_map(static function (array $o): string {
            return $o['name'];
        }, array_values($content->outlets()));
        foreach (['National Wire', 'Capital Observer', 'American Ledger', 'World Desk'] as $n) {
            $t->contains($n, $names, 'outlet present');
        }
    },
];
