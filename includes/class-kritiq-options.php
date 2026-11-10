<?php
/**
 * Plugin settings, stored in one option that is not autoloaded.
 */

defined( 'ABSPATH' ) || exit;

class Kritiq_Options {
	const OPTION = 'kritiq_pixel_settings';

	const DEFAULTS = array(
		'pixel_id'        => '',
		'store_token'     => '',
		'require_consent' => true,
		'consent_mode'    => 'auto', // auto | custom | off
		'revenue'         => 'total', // total | subtotal
		'server_events'   => true,
		'account_name'    => '',
		'brand_name'      => '',
		'connected_at'    => 0,
	);

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::DEFAULTS );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = array_merge( self::all(), array_intersect_key( $values, self::DEFAULTS ) );
		update_option( self::OPTION, $all, false );
	}

	public static function is_connected() {
		return '' !== self::get( 'store_token' );
	}

	public static function valid_pixel_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^KQ-[A-Z0-9]{10}$/', $id );
	}

	/** Base URL of KRITIQ. Filterable so a staging app can be used for testing. */
	public static function app_url() {
		return untrailingslashit( apply_filters( 'kritiq_pixel_app_url', 'https://kritiq.app' ) );
	}

	/** Same rule as the KRITIQ collector: letters, digits and _.:- only, so browser and server order IDs match. */
	public static function order_ref( $order ) {
		$ref = preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $order->get_order_number() );
		return substr( '' !== $ref ? $ref : (string) $order->get_id(), 0, 100 );
	}

	public static function order_value( $order ) {
		if ( 'subtotal' === self::get( 'revenue' ) ) {
			$value = (float) $order->get_subtotal() - (float) $order->get_discount_total();
		} else {
			$value = (float) $order->get_total();
		}
		return (float) apply_filters( 'kritiq_pixel_order_value', round( max( 0, $value ), 2 ), $order );
	}
}
