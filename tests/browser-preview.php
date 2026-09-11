<?php
/** Local visual fixture: php -S 127.0.0.1:8097 -t . ; /tests/browser-preview.php
 * Test-only, never packaged in the WordPress plugin. Network calls are mocked.
 */
require_once __DIR__ . '/wp/bootstrap-stubs.php';
require_once __DIR__ . '/../mr-president-game/mr-president-game.php';
$content = new MrPresident\Engine\ContentRepository(MRP_DATA_DIR);
$engine = new MrPresident\Engine\GameEngine($content);
$state = $engine->newGame('new-administration', 'Alexandra Reyes', 42, [
    'age' => 45, 'home_state' => 'CA', 'alignment' => 'Centrist', 'priorities' => ['Economy', 'Healthcare', 'Energy'],
]);
$state->set('game_uuid', 'preview-game');
$scene = $_GET['scene'] ?? 'new';
if (in_array($scene, ['legacy', 'defeat', 'congress'], true)) {
    $target = $scene === 'legacy' ? 97 : ($scene === 'defeat' ? 49 : 24);
    while ($state->turn() < $target) {
        $card = $engine->activeEvent($state);
        if ($card && !$state->get('active_event.resolved_choice_id')) {
            $engine->applyDecision($state, $card['choices'][0]['id']);
        }
        if ($state->turn() === 46) { $state->set('public.approval', $scene === 'defeat' ? 50 : 65); }
        $engine->advanceTurn($state);
    }
}
$view = new MrPresident\Plugin\View_Model($content);
$game = $view->game($state);
$config = MrPresident\Plugin\Assets::config();
$config['isLoggedIn'] = true;
$config['assetsUrl'] = '/mr-president-game/assets/';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/mr-president-game/assets/css/game.css"><title>Campaign preview</title></head>
<body class="mrp-fullscreen"><div id="mrp-app" class="mrp-app" data-mrp-state="ready"></div>
<script>
window.MRP_CONFIG = <?= json_encode($config, JSON_HEX_TAG) ?>;
window.fixtureGame = <?= json_encode($game, JSON_HEX_TAG) ?>;
window.fetch = function(url, options) {
    var data = /\/games$/.test(url) ? {games: []} : {game: window.fixtureGame};
    if (options && options.body) window.lastRequest = JSON.parse(options.body);
    return Promise.resolve({ok:true,headers:{get:function(){return 'application/json';}},json:function(){return Promise.resolve(data);}});
};
</script>
<?php
$scripts = array_merge(MrPresident\Plugin\Assets::BASE_SCRIPTS, MrPresident\Plugin\Assets::VIEW_SCRIPTS, MrPresident\Plugin\Assets::PANEL_SCRIPTS, ['app' => 'assets/js/app.js']);
foreach ($scripts as $path) { echo '<script src="/mr-president-game/' . htmlspecialchars($path, ENT_QUOTES) . '"></script>'; }
?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    MRP.store.set(<?= json_encode($scene === 'new' ? ['screen'=>'new'] : ['screen'=>'game','game'=>$game,'view'=>$scene === 'congress' ? 'congress' : 'situation'], JSON_HEX_TAG) ?>);
});
</script></body></html>
