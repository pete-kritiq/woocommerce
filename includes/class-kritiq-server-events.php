<?php
/**
 * Server-side purchases. When an order is paid it is sent to KRITIQ from the server, so purchases
 * still count when an ad blocker or a skipped thank-you page stops the browser event.
 * KRITIQ counts each order ID once, browser and server combined.
 *
 * Consent: the browser script writes the first-party cookie `_kq_ctx` (anonymous browser ID + ad click)
 * only after the visitor accepted. No cookie and consent required = the order is not sent.
 */

defined( 'ABSPATH' ) || exit;

class Kritiq_Server_Events {
	const META_CTX  = '_kritiq_ctx';
	const META_SENT = '_kritiq_sent';
	const ACTION    = 'kritiq_send_order';

	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'capture_context' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'capture_context' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'schedule' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'schedule' ) );
		add_action( self::ACTION, array( __CLASS__, 'send' ) );
	}

	/** Reads and validates the consent-gated context cookie. */
	public static function context_from_cookie() {
		if ( empty( $_COOKIE['_kq_ctx'] ) ) {
			return null;
		}
		$raw = sanitize_text_field( wp_unslash( $_COOKIE['_kq_ctx'] ) );
		$ctx = json_decode( rawurldecode( $raw ), true );
		if ( ! is_array( $ctx ) || empty( $ctx['aid'] ) || ! preg_match( '/^[a-f0-9]{24}$/', (string) $ctx['aid'] ) ) {
			return null;
		}
		$out  = array( 'aid' => $ctx['aid'] );
		$attr = isset( $ctx['attr'] ) && is_array( $ctx['attr'] ) ? $ctx['attr'] : null;
		if ( $attr ) {
			$clean = array();
			foreach ( array( 'source', 'medium', 'campaign', 'kqAd' ) as $k ) {
				if ( isset( $attr[ $k ] ) && is_string( $attr[ $k ] ) ) {
					$clean[ $k ] = substr( sanitize_text_field( $attr[ $k ] ), 0, 120 );
				}
			}
			foreach ( array( 'fbclid', 'ttclid', 'gclid' ) as $k ) {
				$clean[ $k ] = empty( $attr[ $k ] ) ? 0 : 1;
			}
			if ( isset( $attr['ts'] ) && is_numeric( $attr['ts'] ) ) {
				$clean['ts'] = (int) $attr['ts'];
			}
			$out['attr'] = $clean;
		}
		return $out;
	}

	public static function capture_context( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$ctx = self::context_from_cookie();
		if ( $ctx ) {
			$order->update_meta_data( self::META_CTX, wp_json_encode( $ctx ) );
			$order->save_meta_data();
		}
	}

	public static function schedule( $order_id ) {
		if ( ! Kritiq_Options::is_connected() || ! Kritiq_Options::get( 'server_events' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_SENT ) ) {
			return;
		}
		if ( Kritiq_Options::get( 'require_consent' ) && ! $order->get_meta( self::META_CTX ) ) {
			return; // No recorded consent for this shopper.
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, array( 'order_id' => (int) $order_id ), 'kritiq' );
		} else {
			self::send( $order_id );
		}
	}

	public static function send( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_SENT ) || ! Kritiq_Options::is_connected() ) {
			return;
		}
		$ctx   = json_decode( (string) $order->get_meta( self::META_CTX ), true );
		$paid  = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		$event = array(
			'e'   => 'Purchase',
			'v'   => Kritiq_Options::order_value( $order ),
			'c'   => $order->get_currency(),
			'oid' => Kritiq_Options::order_ref( $order ),
			'url' => home_url( '/checkout/order-received/' ),
			'ts'  => $paid ? $paid->getTimestamp() * 1000 : time() * 1000,
		);
		if ( is_array( $ctx ) ) {
			$event['aid'] = isset( $ctx['aid'] ) ? $ctx['aid'] : null;
			if ( isset( $ctx['attr'] ) ) {
				$event['attr'] = $ctx['attr'];
			}
		}
		$res = Kritiq_Api::send_events( array( apply_filters( 'kritiq_pixel_server_event', $event, $order ) ) );
		if ( is_wp_error( $res ) ) {
			$code = $res->get_error_code();
			// 4xx (except 429) will not succeed on retry; network errors, 429 and 5xx retry with backoff (max 5 tries).
			$tries = (int) $order->get_meta( '_kritiq_tries' ) + 1;
			if ( ( 'kritiq_429' === $code || ! preg_match( '/^kritiq_4\d\d$/', $code ) ) && $tries < 5 && function_exists( 'as_schedule_single_action' ) ) {
				$order->update_meta_data( '_kritiq_tries', $tries );
				$order->save_meta_data();
				as_schedule_single_action( time() + 60 * ( 2 ** $tries ), self::ACTION, array( 'order_id' => (int) $order_id ), 'kritiq' );
				return;
			}
			$order->add_order_note( sprintf( /* translators: %s: error */ __( 'KRITIQ: purchase not sent (%s).', 'kritiq-pixel' ), $res->get_error_message() ) );
			return;
		}
		$order->update_meta_data( self::META_SENT, time() );
		$order->save_meta_data();
	}
}
