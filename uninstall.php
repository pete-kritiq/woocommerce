<?php
/**
 * Removes the plugin's settings. Orders keep their KRITIQ meta (harmless, a few bytes each).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'kritiq_pixel_settings' );
delete_transient( 'kritiq_status' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'kritiq_send_order' );
}
