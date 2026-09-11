<?php
/** Optional AI configuration. Credentials remain owned by WordPress. */
namespace MrPresident\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Settings {
	const MODE_OPTION = 'mrp_ai_mode';
	const MODEL_OPTION = 'mrp_ai_model';
	const DEFAULT_MODEL = 'gpt-5.6-luna';

	public static function models() {
		return array(
			'gpt-5.6-luna' => 'Luna',
			'gpt-5.6-terra' => 'Terra',
			'gpt-5.6-sol' => 'Sol',
			'gpt-6-astra' => 'Astra',
		);
	}

	public static function sanitize_mode( $value ) {
		return 'wordpress' === $value ? 'wordpress' : 'offline';
	}

	public static function sanitize_model( $value ) {
		return is_string( $value ) && isset( self::models()[ $value ] ) ? $value : self::DEFAULT_MODEL;
	}

	public static function model() {
		return self::sanitize_model( get_option( self::MODEL_OPTION, self::DEFAULT_MODEL ) );
	}

	public static function available() {
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_get_connector' )
			&& null !== wp_get_connector( 'openai' );
	}

	public static function register() {
		foreach ( array( self::MODE_OPTION => 'mode', self::MODEL_OPTION => 'model' ) as $option => $kind ) {
			register_setting( Admin::OPTION_GROUP, $option, array(
				'type' => 'string',
				'default' => 'mode' === $kind ? 'wordpress' : self::DEFAULT_MODEL,
				'sanitize_callback' => array( __CLASS__, 'sanitize_' . $kind ),
			) );
		}
		add_settings_section( 'mrp_ai', __( 'Optional AI', 'mr-president-game' ), array( __CLASS__, 'description' ), Admin::PAGE_SLUG );
		add_settings_field( self::MODE_OPTION, __( 'AI integration', 'mr-president-game' ), array( __CLASS__, 'mode_field' ), Admin::PAGE_SLUG, 'mrp_ai' );
		add_settings_field( self::MODEL_OPTION, __( 'AI model', 'mr-president-game' ), array( __CLASS__, 'model_field' ), Admin::PAGE_SLUG, 'mrp_ai' );
	}

	public static function description() {
		echo '<p>' . esc_html__( 'AI enriches the daily brief; the simulation determines outcomes. Offline mode makes no AI requests. When AI is unavailable, the game uses built-in briefings.', 'mr-president-game' ) . '</p>';
		if ( function_exists( 'wp_get_connectors' ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Manage credentials in Settings → Connectors', 'mr-president-game' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'WordPress 7.0 Connectors and the OpenAI provider plugin are required for AI. Offline play is available on this installation.', 'mr-president-game' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Model access depends on your connected OpenAI account and provider plugin. AI requests send public game briefing data to OpenAI and may incur usage charges. Selecting a model does not verify access.', 'mr-president-game' ) . '</p>';
	}

	public static function mode_field() {
		self::select( self::MODE_OPTION, get_option( self::MODE_OPTION, 'wordpress' ), array(
			'wordpress' => __( 'WordPress Connectors — OpenAI (default)', 'mr-president-game' ),
			'offline' => __( 'Offline — built-in briefings', 'mr-president-game' ),
		) );
	}

	public static function model_field() {
		self::select( self::MODEL_OPTION, self::model(), self::models() );
	}

	private static function select( $name, $current, array $choices ) {
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}
}
