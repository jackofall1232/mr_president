<?php
/** Connector contract checks without network or credentials. */
use MrPresident\Plugin\AI_Settings;
use MrPresident\Plugin\AI\WordPress_AI_Provider;
use MrPresident\Plugin\AI\Template_AI_Provider;
use MrPresident\Tests\Assert;

require_once __DIR__ . '/bootstrap-stubs.php';
if (!defined('MRP_VERSION')) {
    require_once __DIR__ . '/../../mr-president-game/mr-president-game.php';
}

final class MRP_Test_AI {
    public static $available = true;
    public static $cache = [];
    public static $calls = 0;
    public static $prompt;
    public static $model;
    public static $result = 'A measured briefing.';
    public function using_model_preference($model) { self::$model = $model; return $this; }
    public function using_system_instruction($text) { return $this; }
    public function using_max_tokens($tokens) { return $this; }
    public function generate_text() {
        self::$calls++;
        if (self::$result instanceof Throwable) { throw self::$result; }
        return self::$result;
    }
}
function wp_get_connector($id) { return MRP_Test_AI::$available ? ['name' => 'OpenAI'] : null; }
function wp_ai_client_prompt($text) { MRP_Test_AI::$prompt = $text; return new MRP_Test_AI(); }
function get_transient($key) { return MRP_Test_AI::$cache[$key] ?? false; }
function set_transient($key, $value, $ttl) { MRP_Test_AI::$cache[$key] = $value; }
function wp_strip_all_tags($text) { return strip_tags($text); }

return [
    'AI defaults to Luna and rejects unknown settings' => static function (Assert $t) {
        MRP_Test_WP::$options = [];
        $t->same('gpt-5.6-luna', AI_Settings::model());
        $t->same('offline', AI_Settings::sanitize_mode(['bad']));
        $t->same('gpt-5.6-luna', AI_Settings::sanitize_model(['bad']));
        foreach (AI_Settings::models() as $id => $label) { $t->same($id, AI_Settings::sanitize_model($id)); }
    },
    'WordPress AI uses selected model, caches prose and excludes private state' => static function (Assert $t) {
        MRP_Test_WP::$options = ['mrp_ai_model' => 'gpt-6-astra'];
        MRP_Test_AI::$cache = [];
        MRP_Test_AI::$calls = 0;
        $provider = new WordPress_AI_Provider();
        $brief = ['debug' => ['secret' => 'NEVER_SEND_THIS']];
        $t->same('A measured briefing.', $provider->generate_briefing_summary($brief));
        $t->same(['openai', 'gpt-6-astra'], MRP_Test_AI::$model);
        $t->same(false, strpos(MRP_Test_AI::$prompt, 'NEVER_SEND_THIS'));
        $provider->generate_briefing_summary($brief);
        $t->same(1, MRP_Test_AI::$calls);
        MRP_Test_WP::$options['mrp_ai_model'] = 'gpt-5.6-sol';
        $provider->generate_briefing_summary($brief);
        $t->same(2, MRP_Test_AI::$calls);
    },
    'WordPress AI degrades safely on disabled, unavailable and failed requests' => static function (Assert $t) {
        $provider = new WordPress_AI_Provider();
        $expected = (new Template_AI_Provider())->generate_briefing_summary([]);
        MRP_Test_AI::$calls = 0;
        MRP_Test_WP::$options = ['mrp_ai_mode' => 'offline'];
        $t->same($expected, $provider->generate_briefing_summary([]));
        $t->same(0, MRP_Test_AI::$calls);
        MRP_Test_WP::$options = [];
        MRP_Test_AI::$available = false;
        $t->same($expected, $provider->generate_briefing_summary([]));
        $t->same(0, MRP_Test_AI::$calls);
        MRP_Test_AI::$available = true;
        foreach ([new WP_Error('failed', 'private error'), new RuntimeException('private error'), '', ['invalid'], str_repeat('x', 6001)] as $result) {
            MRP_Test_AI::$cache = [];
            MRP_Test_AI::$result = $result;
            $t->same($expected, $provider->generate_briefing_summary([]));
        }
        MRP_Test_AI::$cache = [];
        MRP_Test_AI::$result = 'A measured briefing.';
        MRP_Test_WP::$options = [];
    },
];
