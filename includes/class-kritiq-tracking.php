<?php
/**
 * Browser events. The KRITIQ pixel (kq.js) is loaded from kritiq.app and handles consent,
 * storage and sending; this class only tells it which ecommerce events happened.
 *
 * Page caches: nothing visitor-specific is printed into cacheable pages. Add-to-cart events are
 * queued in the WooCommerce session and fetched by the browser through ?wc-ajax=kritiq_events.
 */

defined( 'ABSPATH' ) || exit;

class Kritiq_Tracking {
	const SESSION_KEY = 'kritiq_events';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'queue_add_to_cart' ), 10, 6 );
		add_action( 'wc_ajax_kritiq_events', array( __CLASS__, 'ajax_events' ) );
	}

	public static function should_track() {
		$pid   = Kritiq_Options::get( 'pixel_id' );
		$track = Kritiq_Options::valid_pixel_id( $pid ) && ! is_admin() && ! current_user_can( 'manage_woocommerce' );
		return (bool) apply_filters( 'kritiq_pixel_should_track', $track );
	}

	public static function enqueue() {
		if ( ! self::should_track() ) {
			return;
		}
		wp_enqueue_script( 'kritiq-woo', KRITIQ_PIXEL_URL . 'assets/kritiq-woo.js', array(), KRITIQ_PIXEL_VERSION, false );
		$config = array(
			'pixelId'        => Kritiq_Options::get( 'pixel_id' ),
			'src'            => Kritiq_Options::app_url() . '/kq.js',
			'requireConsent' => (bool) Kritiq_Options::get( 'require_consent' ),
			'consentMode'    => Kritiq_Options::get( 'consent_mode' ),
			'eventsUrl'      => WC_AJAX::get_endpoint( 'kritiq_events' ),
			'events'         => self::page_events(),
		);
		wp_add_inline_script( 'kritiq-woo', 'window.kritiqWoo = ' . wp_json_encode( apply_filters( 'kritiq_pixel_config', $config ) ) . ';', 'before' );
	}

	private static function event( $name, $value = null, $order_id = null ) {
		$e = array(
			'e' => $name,
			'c' => get_woocommerce_currency(),
		);
		if ( null !== $value ) {
			$e['v'] = round( (float) $value, 2 );
		}
		if ( null !== $order_id ) {
			$e['oid'] = $order_id;
		}
		return $e;
	}

	/** Events that belong to the page itself (safe to cache: same for every visitor, or on uncached pages). */
	private static function page_events() {
		$events = array();

		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				$events[] = self::event( 'ViewContent', wc_get_price_to_display( $product ) );
			}
		}

		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$events[] = self::event( 'InitiateCheckout', (float) WC()->cart->get_total( 'edit' ) );
		}

		if ( is_wc_endpoint_url( 'order-received' ) ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the proof of ownership.
			$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
			$order = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order && hash_equals( $order->get_order_key(), $key ) && ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
				$e      = self::event( 'Purchase', Kritiq_Options::order_value( $order ), Kritiq_Options::order_ref( $order ) );
				$e['c'] = $order->get_currency();
				// Reloads are fine: KRITIQ counts each order ID once, browser and server combined.
				$events[] = $e;
			}
		}

		return apply_filters( 'kritiq_pixel_page_events', $events );
	}

	public static function queue_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		if ( ! self::should_track() || ! WC()->session ) {
			return;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return;
		}
		$queue   = (array) WC()->session->get( self::SESSION_KEY, array() );
		$queue[] = self::event( 'AddToCart', wc_get_price_to_display( $product ) * max( 1, (int) $quantity ) );
		WC()->session->set( self::SESSION_KEY, array_slice( $queue, -10 ) );
		if ( ! headers_sent() ) {
			wc_setcookie( 'kq_pending', '1', 0, false, false );
		}
	}

	/** ?wc-ajax=kritiq_events : returns and clears this visitor's queued events. */
	public static function ajax_events() {
		nocache_headers();
		$events = array();
		if ( WC()->session ) {
			$events = (array) WC()->session->get( self::SESSION_KEY, array() );
			WC()->session->set( self::SESSION_KEY, array() );
		}
		wc_setcookie( 'kq_pending', '', time() - YEAR_IN_SECONDS, false, false );
		wp_send_json( array( 'events' => array_values( $events ) ) );
	}
}
