<?php
/**
 * Talks to the KRITIQ store API on kritiq.app.
 *
 *   GET  {app}/connect/store              sign-in + approval screen (browser redirect, PKCE S256)
 *   POST {app}/api/stores/token           code + verifier -> store token + pixel ID
 *   GET  {app}/api/stores/me              connection status for the settings screen
 *   POST {app}/api/stores/events          server-side order events (de-duplicated by order ID)
 *   POST {app}/api/stores/disconnect      revokes the store token
 */

defined( 'ABSPATH' ) || exit;

class Kritiq_Api {
	const PLATFORM = 'woocommerce';

	public static function shop_domain() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** Builds the kritiq.app connect URL and remembers the PKCE verifier for this admin user. */
	public static function connect_url( $return_url ) {
		$verifier  = self::b64url( random_bytes( 32 ) );
		$state     = self::b64url( random_bytes( 16 ) );
		$challenge = self::b64url( hash( 'sha256', $verifier, true ) );
		set_transient(
			'kritiq_connect_' . get_current_user_id(),
			array(
				'state'      => $state,
				'verifier'   => $verifier,
				'return_url' => $return_url,
			),
			15 * MINUTE_IN_SECONDS
		);
		return add_query_arg(
			array(
				'platform'              => self::PLATFORM,
				'shop'                  => self::shop_domain(),
				'shop_name'             => get_bloginfo( 'name' ),
				'currency'              => get_woocommerce_currency(),
				'return_url'            => $return_url,
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			Kritiq_Options::app_url() . '/connect/store'
		);
	}

	/** Completes the connect flow. Returns true or a WP_Error. */
	public static function finish_connect( $code, $state ) {
		$key     = 'kritiq_connect_' . get_current_user_id();
		$pending = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) || ! hash_equals( $pending['state'], (string) $state ) ) {
			return new WP_Error( 'kritiq_state', __( 'The connection request expired. Please try again.', 'kritiq-pixel' ) );
		}
		$res = self::request(
			'POST',
			'/api/stores/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => (string) $code,
				'code_verifier' => $pending['verifier'],
				'platform'      => self::PLATFORM,
				'shop'          => self::shop_domain(),
				'return_url'    => $pending['return_url'],
			),
			false
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['store_token'] ) || ! Kritiq_Options::valid_pixel_id( isset( $res['pixel_id'] ) ? $res['pixel_id'] : '' ) ) {
			return new WP_Error( 'kritiq_response', __( 'KRITIQ sent an unexpected response.', 'kritiq-pixel' ) );
		}
		Kritiq_Options::update(
			array(
				'store_token'     => sanitize_text_field( $res['store_token'] ),
				'pixel_id'        => $res['pixel_id'],
				'require_consent' => ! isset( $res['require_consent'] ) || false !== $res['require_consent'],
				'account_name'    => isset( $res['account']['name'] ) ? sanitize_text_field( $res['account']['name'] ) : '',
				'brand_name'      => isset( $res['brand']['name'] ) ? sanitize_text_field( $res['brand']['name'] ) : '',
				'connected_at'    => time(),
			)
		);
		delete_transient( 'kritiq_status' );
		return true;
	}

	public static function status() {
		if ( ! Kritiq_Options::is_connected() ) {
			return null;
		}
		$cached = get_transient( 'kritiq_status' );
		if ( false !== $cached ) {
			return $cached;
		}
		$res = self::request( 'GET', '/api/stores/me' );
		if ( is_wp_error( $res ) && 'kritiq_401' === $res->get_error_code() ) {
			// Token revoked from the KRITIQ side: keep the pixel running, drop the server connection.
			Kritiq_Options::update( array( 'store_token' => '' ) );
		}
		set_transient( 'kritiq_status', $res, 5 * MINUTE_IN_SECONDS );
		return $res;
	}

	public static function send_events( array $events ) {
		return self::request( 'POST', '/api/stores/events', array( 'events' => $events ) );
	}

	public static function disconnect() {
		if ( Kritiq_Options::is_connected() ) {
			self::request( 'POST', '/api/stores/disconnect', array() );
		}
		Kritiq_Options::update(
			array(
				'store_token'  => '',
				'account_name' => '',
				'brand_name'   => '',
				'connected_at' => 0,
			)
		);
		delete_transient( 'kritiq_status' );
	}

	private static function request( $method, $path, $body = null, $auth = true ) {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent'   => 'kritiq-woocommerce/' . KRITIQ_PIXEL_VERSION,
		);
		if ( $auth ) {
			$headers['Authorization'] = 'Bearer ' . Kritiq_Options::get( 'store_token' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( Kritiq_Options::app_url() . $path, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'kritiq_' . $code, $msg );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
