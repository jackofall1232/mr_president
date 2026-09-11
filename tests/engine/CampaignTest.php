<?php
declare(strict_types=1);
use MrPresident\Engine\CampaignSystem;
use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\GameEngine;
use MrPresident\Engine\GameState;
use MrPresident\Engine\PresidentProfile;
use MrPresident\Tests\Assert;

$campaignEngine = new GameEngine(new ContentRepository(__DIR__ . '/../../mr-president-game/data'));
$campaignRun = static function (bool $win, bool $reload) use ($campaignEngine): array {
    $s = $campaignEngine->newGame('new-administration', 'President Example', 42);
    while ('active' === $s->get('campaign.status')) {
        $card = $campaignEngine->activeEvent($s);
        if ($card && !$s->get('active_event.resolved_choice_id')) {
            $campaignEngine->applyDecision($s, $card['choices'][0]['id']);
        }
        if (46 === $s->turn()) { $s->set('public.approval', $win ? 51 : 50); }
        $campaignEngine->advanceTurn($s);
        if ($reload) { $s = GameState::fromArray(json_decode(json_encode($s->toArray()), true)); }
        if ($s->turn() > 97) { throw new RuntimeException('Campaign failed to end'); }
    }
    return json_decode(json_encode($s->toArray()), true);
};

return [
    'strict reelection boundary including fractional approval' => static function (Assert $t): void {
        foreach ([49.9, 50, 50.01, 51] as $approval) {
            $s = GameState::fromArray(['turn' => 47, 'public' => ['approval' => $approval]]);
            CampaignSystem::tick($s);
            $t->same($approval > 50, $s->get('campaign.reelected'));
            $before = $s->toArray();
            CampaignSystem::tick($s);
            $t->same($before, $s->toArray(), 'Election resolves once');
        }
    },
    'midterms affect only contested Senate class and bound all seat counts' => static function (Assert $t): void {
        foreach ([0, 31, 49, 50, 64, 100] as $approval) {
            $s = GameState::fromArray(['turn' => 23, 'public' => ['approval' => $approval]]);
            $rng = $s->toArray()['rng'];
            CampaignSystem::tick($s);
            $classes = $s->get('campaign.senate_classes');
            $t->same(18, $classes[1]); $t->same(17, $classes[2]);
            $t->true($classes[0] >= 0 && $classes[0] <= 33);
            $t->true($s->get('campaign.house') >= 0 && $s->get('campaign.house') <= 435);
            if ($approval < 50) { $t->true($s->get('campaign.house') < 238); }
            $t->same($rng, $s->toArray()['rng'], 'No event RNG consumed');
        }
    },
    'complete campaigns end on transfer dates and survive reload at every turn' => static function (Assert $t) use ($campaignRun, $campaignEngine): void {
        foreach ([false, true] as $win) {
            $direct = $campaignRun($win, false);
            $t->same($direct, $campaignRun($win, true));
            $t->same($win ? '2009-01-20' : '2005-01-20', $direct['date']);
            $t->same($win ? 'completed' : 'defeated', $direct['campaign']['status']);
            $t->same($win ? 96 : 48, $direct['campaign']['legacy']['months_served']);
            $t->same($win ? 4 : 2, count($direct['campaign']['results']));
            $t->same(null, $direct['active_event']);
            $s = GameState::fromArray($direct);
            $t->throws(static function () use ($s, $campaignEngine) { $campaignEngine->advanceTurn($s); }, 'campaign_finished');
            $t->throws(static function () use ($s, $campaignEngine) { $campaignEngine->applyDecision($s, 'fake'); }, 'campaign_finished');
            $t->same($direct, json_decode(json_encode($s->toArray()), true), 'Rejected commands do not change completed saves');
        }
    },
    'old saves migrate without resetting RNG or retroactively losing reelection' => static function (Assert $t): void {
        $s = GameState::fromArray(['schema_version' => 1, 'turn' => 60, 'rng' => ['state' => 123]]);
        $t->same(2, $s->toArray()['schema_version']);
        $t->same(60, $s->turn());
        $t->same(true, $s->get('campaign.reelected'));
        $t->same(123, $s->toArray()['rng']['state']);
    },
    'profile validation strips unauthorized state and rejects invalid identities' => static function (Assert $t): void {
        $profile = ['age' => 45, 'home_state' => 'CA', 'alignment' => 'Centrist', 'priorities' => ['Economy', 'Energy', 'Healthcare']];
        $t->same($profile, PresidentProfile::validate($profile + ['approval' => 100]));
        foreach (['age' => 34, 'home_state' => 'XX', 'alignment' => 'fake', 'priorities' => ['Economy', 'Economy', 'Energy']] as $key => $bad) {
            $test = $profile; $test[$key] = $bad;
            $t->throws(static function () use ($test) { PresidentProfile::validate($test); }, 'invalid_profile');
        }
    },
];
