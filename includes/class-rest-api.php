<?php
/**
 * REST routes.
 *
 * @package MrPresident
 */

namespace MrPresident\Plugin;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `mr-president/v1` namespace (engineering spec section 8.3).
 *
 * Every route declares a `permission_callback`, every parameter declares a
 * `validate_callback` and a `sanitize_callback`, and no route accepts a state value: the
 * only things a client may send are a president name, a scenario id and a choice id.
 * Ownership is not checked here — it is enforced inside the query by
 * {@see Database_Save_Store}, so a uuid belonging to somebody else is a 404.
 *
 * The controller is built lazily through a factory so that registering routes on
 * `rest_api_init` costs nothing: content JSON is only parsed once a request actually
 * reaches a callback.
 */
final class Rest_Api {

	/**
	 * Route fragment matching a v4-shaped uuid.
	 */
	const UUID_PATTERN = '(?P<uuid>[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})';

	/**
	 * Regex for kebab-case content ids.
	 */
	const ID_PATTERN = '/^[a-z0-9-]{1,64}$/';

	/**
	 * Regex for a president's name: letters, spaces, apostrophes, periods and hyphens.
	 */
	const NAME_PATTERN = '/^[\p{L}][\p{L} .\'\-]*$/u';

	/**
	 * Shortest accepted president name.
	 */
	const NAME_MIN = 2;

	/**
	 * Longest accepted president name.
	 */
	const NAME_MAX = 60;

	/**
	 * Returns a {@see Game_Controller} when a request needs one.
	 *
	 * @var callable
	 */
	private $controller_factory;

	/**
	 * Memoised controller.
	 *
	 * @var Game_Controller|null
	 */
	private $controller = null;

	/**
	 * @since 0.1.0
	 *
	 * @param callable $controller_factory Builds the controller on first use.
	 */
	public function __construct( $controller_factory ) {
		$this->controller_factory = $controller_factory;
	}

	/**
	 * Hook route registration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declare every route in the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = MRP_REST_NAMESPACE;
		$uuid_args = array( 'uuid' => $this->uuid_arg() );

		register_rest_route(
			$namespace,
			'/games',
			$this->endpoint( 'GET', 'handle_list' )
		);

		register_rest_route(
			$namespace,
			'/game/new',
			$this->endpoint(
				'POST',
				'handle_create',
				array(
					'president_name' => $this->name_arg(),
					'scenario_id'    => $this->id_arg( false ),
				)
			)
		);

		register_rest_route(
			$namespace,
			'/game/' . self::UUID_PATTERN,
			array(
				$this->endpoint( 'GET', 'handle_get', $uuid_args ),
				$this->endpoint( 'DELETE', 'handle_delete', $uuid_args ),
			)
		);

		register_rest_route(
			$namespace,
			'/game/' . self::UUID_PATTERN . '/briefing',
			$this->endpoint( 'GET', 'handle_briefing', $uuid_args )
		);

		register_rest_route(
			$namespace,
			'/game/' . self::UUID_PATTERN . '/decision',
			$this->endpoint(
				'POST',
				'handle_decision',
				array(
					'uuid'      => $this->uuid_arg(),
					'choice_id' => $this->id_arg( true ),
				)
			)
		);

		register_rest_route(
			$namespace,
			'/game/' . self::UUID_PATTERN . '/advance',
			$this->endpoint( 'POST', 'handle_advance', $uuid_args )
		);

		register_rest_route(
			$namespace,
			'/game/' . self::UUID_PATTERN . '/save',
			$this->endpoint( 'POST', 'handle_save', $uuid_args )
		);
	}

	/**
	 * Only signed-in users reach the game; core checks the `wp_rest` nonce for cookie auth.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'mrp_not_logged_in',
			__( 'Sign in to run an administration.', 'mr-president-game' ),
			array( 'status' => function_exists( 'rest_authorization_required_code' ) ? rest_authorization_required_code() : 401 )
		);
	}

	/**
	 * `GET /games`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_list( $request ) {
		unset( $request );

		return $this->respond( $this->controller()->list_games( get_current_user_id() ) );
	}

	/**
	 * `POST /game/new`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_create( $request ) {
		return $this->respond(
			$this->controller()->create_game(
				get_current_user_id(),
				(string) $request->get_param( 'president_name' ),
				$request->get_param( 'scenario_id' ),
				$this->body( $request )
			)
		);
	}

	/**
	 * `GET /game/{uuid}`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_get( $request ) {
		return $this->respond(
			$this->controller()->get_game( get_current_user_id(), (string) $request->get_param( 'uuid' ) )
		);
	}

	/**
	 * `GET /game/{uuid}/briefing`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_briefing( $request ) {
		return $this->respond(
			$this->controller()->get_briefing( get_current_user_id(), (string) $request->get_param( 'uuid' ) )
		);
	}

	/**
	 * `POST /game/{uuid}/decision`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_decision( $request ) {
		return $this->respond(
			$this->controller()->decide(
				get_current_user_id(),
				(string) $request->get_param( 'uuid' ),
				(string) $request->get_param( 'choice_id' ),
				$this->body( $request )
			)
		);
	}

	/**
	 * `POST /game/{uuid}/advance`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_advance( $request ) {
		return $this->respond(
			$this->controller()->advance(
				get_current_user_id(),
				(string) $request->get_param( 'uuid' ),
				$this->body( $request )
			)
		);
	}

	/**
	 * `POST /game/{uuid}/save`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_save( $request ) {
		return $this->respond(
			$this->controller()->save_game( get_current_user_id(), (string) $request->get_param( 'uuid' ) )
		);
	}

	/**
	 * `DELETE /game/{uuid}`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return mixed
	 */
	public function handle_delete( $request ) {
		return $this->respond(
			$this->controller()->delete_game( get_current_user_id(), (string) $request->get_param( 'uuid' ) )
		);
	}

	/**
	 * Validate a president's name.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_president_name( $value ) {
		$name   = trim( (string) $value );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );

		if ( $length < self::NAME_MIN || $length > self::NAME_MAX ) {
			return new WP_Error(
				'mrp_invalid_president_name',
				sprintf(
					/* translators: 1: minimum length, 2: maximum length. */
					__( 'A president needs a name of %1$d to %2$d characters.', 'mr-president-game' ),
					self::NAME_MIN,
					self::NAME_MAX
				),
				array( 'status' => 400 )
			);
		}

		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			return new WP_Error(
				'mrp_invalid_president_name',
				__( 'A president\'s name may use letters, spaces, apostrophes, periods and hyphens.', 'mr-president-game' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate a kebab-case content id.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_id( $value ) {
		if ( 1 === preg_match( self::ID_PATTERN, (string) $value ) ) {
			return true;
		}

		return new WP_Error(
			'mrp_invalid_id',
			__( 'That identifier is not in the expected format.', 'mr-president-game' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Validate a game uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_uuid( $value ) {
		if ( Save_Manager::is_uuid( (string) $value ) ) {
			return true;
		}

		return new WP_Error(
			'mrp_invalid_uuid',
			__( 'That game identifier is not in the expected format.', 'mr-president-game' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Sanitize a kebab-case id down to the characters the pattern allows.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	public static function sanitize_id( $value ) {
		return substr( preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $value ) ), 0, 64 );
	}

	/**
	 * Sanitize a president's name.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string
	 */
	public static function sanitize_president_name( $value ) {
		return trim( sanitize_text_field( (string) $value ) );
	}

	/**
	 * One endpoint definition, always carrying a permission callback.
	 *
	 * @since 0.1.0
	 *
	 * @param string $methods  HTTP method(s).
	 * @param string $callback Method name on this class.
	 * @param array  $args     Parameter definitions.
	 *
	 * @return array
	 */
	private function endpoint( $methods, $callback, array $args = array() ) {
		return array(
			'methods'             => $methods,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => $args,
		);
	}

	/**
	 * Parameter definition for the uuid path segment.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	private function uuid_arg() {
		return array(
			'description'       => __( 'Game identifier.', 'mr-president-game' ),
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( __CLASS__, 'validate_uuid' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Parameter definition for a content id.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $required Whether the parameter must be present.
	 *
	 * @return array
	 */
	private function id_arg( $required ) {
		return array(
			'description'       => __( 'Kebab-case content identifier.', 'mr-president-game' ),
			'type'              => 'string',
			'required'          => (bool) $required,
			'validate_callback' => array( __CLASS__, 'validate_id' ),
			'sanitize_callback' => array( __CLASS__, 'sanitize_id' ),
		);
	}

	/**
	 * Parameter definition for the president's name.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	private function name_arg() {
		return array(
			'description'       => __( 'Name of the president taking office.', 'mr-president-game' ),
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( __CLASS__, 'validate_president_name' ),
			'sanitize_callback' => array( __CLASS__, 'sanitize_president_name' ),
		);
	}

	/**
	 * The raw JSON body, used only to log what the server ignored.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array
	 */
	private function body( $request ) {
		$body = $request->get_json_params();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Turn a controller result into a REST response.
	 *
	 * @since 0.1.0
	 *
	 * @param array|WP_Error $result Controller result.
	 *
	 * @return mixed
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * The memoised controller.
	 *
	 * @since 0.1.0
	 *
	 * @return Game_Controller
	 */
	private function controller() {
		if ( null === $this->controller ) {
			$this->controller = call_user_func( $this->controller_factory );
		}

		return $this->controller;
	}
}
