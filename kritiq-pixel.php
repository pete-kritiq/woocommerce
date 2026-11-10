<?php
/**
 * Plugin Name:       KRITIQ Pixel for WooCommerce
 * Plugin URI:        https://www.kritiq.app
 * Description:       Connects your store to KRITIQ and sends consent-aware ecommerce events (page views, product views, add to cart, checkout, purchases) to your KRITIQ pixel, so you can see what your ads and creatives do on your site.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            KRITIQ
 * Author URI:        https://www.kritiq.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kritiq-pixel
 * WC requires at least: 7.0
 * WC tested up to:   10.3
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'KRITIQ_PIXEL_VERSION', '1.0.0' );
define( 'KRITIQ_PIXEL_FILE', __FILE__ );
define( 'KRITIQ_PIXEL_DIR', plugin_dir_path( __FILE__ ) );
define( 'KRITIQ_PIXEL_URL', plugin_dir_url( __FILE__ ) );

require_once KRITIQ_PIXEL_DIR . 'includes/class-kritiq-options.php';
require_once KRITIQ_PIXEL_DIR . 'includes/class-kritiq-api.php';
require_once KRITIQ_PIXEL_DIR . 'includes/class-kritiq-admin.php';
require_once KRITIQ_PIXEL_DIR . 'includes/class-kritiq-tracking.php';
require_once KRITIQ_PIXEL_DIR . 'includes/class-kritiq-server-events.php';

// Declare compatibility with High-Performance Order Storage and the Cart/Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Tell the WP Consent API (Complianz, CookieYes, Cookiebot for WP, etc.) that we respect its consent signals.
add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'KRITIQ Pixel needs WooCommerce to be installed and active.', 'kritiq-pixel' ) . '</p></div>';
				}
			);
			return;
		}
		Kritiq_Admin::init();
		Kritiq_Tracking::init();
		Kritiq_Server_Events::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=kritiq-pixel' ) ) . '">' . esc_html__( 'Settings', 'kritiq-pixel' ) . '</a>' );
		return $links;
	}
);
