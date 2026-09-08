<?php
/**
 * Plugin layer without WordPress: hidden data never leaks, every route is guarded.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\ContentRepository;
use MrPresident\Engine\GameEngine;
use MrPresident\Plugin\Rest_Api;
use MrPresident\Plugin\View_Model;
use MrPresident\Tests\Assert;

require_once __DIR__ . '/bootstrap-stubs.php';

if (!defined('MRP_VERSION')) {
    require_once __DIR__ . '/../../mr-president-game/mr-president-game.php';
}

$content = new ContentRepository(MRP_DATA_DIR);
$engine  = new GameEngine($content);

const MRP_SECRET_KEYS = ['hidden', 'rng', 'seed', 'delayed_queue', 'cooldowns', 'flags', 'counters', 'seen_events', 'indicator_snapshot'];

/**
 * Walk an array and fail if any forbidden key appears anywhere in it.
 */
$assertNoKeys = static function (Assert $t, array $data, array $forbidden, string $where = 'root'): void {
    $walk = static function (array $node, string $path) use (&$walk, $t, $forbidden): void {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                foreach ($forbidden as $bad) {
                    if ($key === $bad) {
                        throw new \MrPresident\Tests\AssertionFailed("forbidden key '{$bad}' at {$path}.{$key}");
                    }
                }
            }
            if (is_array($value)) {
                $walk($value, $path . '.' . $key);
            }
        }
    };
    $walk($data, $where);
};

return [
    'player payload carries no hidden state and no choice effects' => static function (Assert $t) use ($engine, $content, $assertNoKeys): void {
        $state = $engine->newGame('new-administration', 'Leak Test', 77);
        $state->set('game_uuid', '11111111-2222-4333-8444-555555555555');
        $engine->applyDecision($state, $engine->activeEvent($state)['choices'][0]['id']);
        $view = new View_Model($content);
        $game = $view->game($state, false);
        $assertNoKeys($t, $game, MRP_SECRET_KEYS);
        $t->notHasKey('debug', $game);
        $assertNoKeys($t, $game['active_event'], ['effects', 'hidden_effects', 'delayed', 'memory', 'headlines', 'outcome_text']);
        $t->hasKey('indicators', $game);
        $t->hasKey('economy_status', $game['indicators']);
        $t->hasKey('security_status', $game['indicators']);
        $t->hasKey('countries', $game);
        $t->hasKey('memories', $game);
        $t->hasKey('last_outcome', $game);
        $t->notHasKey('hidden_effects', $game['last_outcome'] ?? []);
        $t->hasKey('hidden_change_count', $game['last_outcome']);
    },
    'debug payload adds the developer block only when asked' => static function (Assert $t) use ($engine, $content): void {
        $state = $engine->newGame('new-administration', 'Debug Test', 78);
        $view  = new View_Model($content);
        $view->set_eligibility_provider(static function ($s) use ($engine): array {
            return $engine->eligibilityReport($s);
        });
        $game = $view->game($state, true);
        $t->hasKey('debug', $game);
        foreach (['seed', 'hidden', 'flags', 'counters', 'delayed_queue', 'cooldowns', 'seen_events', 'eligibility', 'raw_state'] as $k) {
            $t->hasKey($k, $game['debug'], 'debug block');
        }
        $t->same(78, $game['debug']['seed']);
    },
    'briefing payload strips structured facts into a string summary slot' => static function (Assert $t) use ($engine, $content): void {
        $state    = $engine->newGame('new-administration', 'Brief Test', 79);
        $view     = new View_Model($content);
        $briefing = $view->briefing($engine->buildBriefing($state), $state);
        $t->true(is_string($briefing['summary']), 'summary is a string slot for the AI seam');
        $t->true(is_array($briefing['summary_facts']), 'structured facts preserved for providers');
        $t->hasKey('cabinet', $briefing);
        $t->count(6, $briefing['cabinet'], 'six advisors weigh in');
    },
    'every REST route has a permission callback and validated args' => static function (Assert $t): void {
        MRP_Test_WP::reset();
        $api = new Rest_Api(static function () {
            return null;
        });
        $api->register_routes();
        $routes = MRP_Test_WP::$routes;
        $t->true(count($routes) >= 7, 'seven route registrations');
        foreach ($routes as $route) {
            $endpoints = isset($route['args']['methods']) ? [$route['args']] : $route['args'];
            foreach ($endpoints as $endpoint) {
                $t->hasKey('permission_callback', $endpoint, 'route ' . $route['route']);
                $t->true(is_callable($endpoint['permission_callback']), 'permission callback callable');
                foreach ($endpoint['args'] ?? [] as $name => $arg) {
                    $t->hasKey('validate_callback', $arg, "arg {$name} on {$route['route']}");
                    $t->hasKey('sanitize_callback', $arg, "arg {$name} on {$route['route']}");
                }
            }
        }
    },
    'permission callback rejects logged-out users' => static function (Assert $t): void {
        $api = new Rest_Api(static function () {
            return null;
        });
        MRP_Test_WP::$logged_in = false;
        $t->true(is_wp_error($api->check_permission()), 'logged-out is an error');
        MRP_Test_WP::$logged_in = true;
        $t->same(true, $api->check_permission());
    },
    'input validators accept the intent shape and reject state-like payloads' => static function (Assert $t): void {
        $t->same(true, Rest_Api::validate_id('sanctions'));
        $t->true(is_wp_error(Rest_Api::validate_id('approval=100')));
        $t->true(is_wp_error(Rest_Api::validate_id('')));
        $t->same(true, Rest_Api::validate_president_name("Alexandra O'Neil-Reyes"));
        $t->true(is_wp_error(Rest_Api::validate_president_name('A')));
        $t->true(is_wp_error(Rest_Api::validate_president_name('<script>')));
        $t->same(true, Rest_Api::validate_uuid('11111111-2222-4333-8444-555555555555'));
        $t->true(is_wp_error(Rest_Api::validate_uuid('1 OR 1=1')));
    },
];
