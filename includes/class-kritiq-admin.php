<?php
/**
 * WooCommerce → KRITIQ settings screen and the "Connect to KRITIQ" flow.
 */

defined( 'ABSPATH' ) || exit;

class Kritiq_Admin {
	const PAGE = 'kritiq-pixel';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_callback' ) );
		add_action( 'admin_post_kritiq_connect', array( __CLASS__, 'start_connect' ) );
		add_action( 'admin_post_kritiq_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_kritiq_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'KRITIQ', 'KRITIQ', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function start_connect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'kritiq-pixel' ) );
		}
		check_admin_referer( 'kritiq_connect' );
		$url = Kritiq_Api::connect_url( self::page_url( array( 'kq_callback' => 1 ) ) );
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external, fixed host.
		exit;
	}

	public static function maybe_handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- protected by the PKCE state stored per user.
		if ( ! isset( $_GET['page'], $_GET['kq_callback'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( isset( $_GET['error'] ) ) {
			delete_transient( 'kritiq_connect_' . get_current_user_id() );
			wp_safe_redirect( self::page_url( array( 'kq_notice' => 'cancelled' ) ) );
			exit;
		}
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$result = Kritiq_Api::finish_connect( $code, $state );
		// phpcs:enable
		if ( is_wp_error( $result ) ) {
			set_transient( 'kritiq_error_' . get_current_user_id(), $result->get_error_message(), 60 );
			wp_safe_redirect( self::page_url( array( 'kq_notice' => 'error' ) ) );
		} else {
			wp_safe_redirect( self::page_url( array( 'kq_notice' => 'connected' ) ) );
		}
		exit;
	}

	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'kritiq-pixel' ) );
		}
		check_admin_referer( 'kritiq_disconnect' );
		Kritiq_Api::disconnect();
		wp_safe_redirect( self::page_url( array( 'kq_notice' => 'disconnected' ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'kritiq-pixel' ) );
		}
		check_admin_referer( 'kritiq_save' );
		$values = array(
			'require_consent' => ! empty( $_POST['require_consent'] ),
			'consent_mode'    => isset( $_POST['consent_mode'] ) && in_array( $_POST['consent_mode'], array( 'auto', 'custom' ), true ) ? sanitize_key( $_POST['consent_mode'] ) : 'auto',
			'revenue'         => isset( $_POST['revenue'] ) && 'subtotal' === $_POST['revenue'] ? 'subtotal' : 'total',
			'server_events'   => ! empty( $_POST['server_events'] ),
		);
		if ( ! Kritiq_Options::is_connected() && isset( $_POST['pixel_id'] ) ) {
			$pid = strtoupper( sanitize_text_field( wp_unslash( $_POST['pixel_id'] ) ) );
			if ( '' === $pid || Kritiq_Options::valid_pixel_id( $pid ) ) {
				$values['pixel_id'] = $pid;
			}
		}
		Kritiq_Options::update( $values );
		wp_safe_redirect( self::page_url( array( 'kq_notice' => 'saved' ) ) );
		exit;
	}

	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n = isset( $_GET['kq_notice'] ) ? sanitize_key( $_GET['kq_notice'] ) : '';
		$messages = array(
			'connected'    => array( 'success', __( 'Your store is connected to KRITIQ. Events will show in KRITIQ within a few minutes of your first visitors.', 'kritiq-pixel' ) ),
			'disconnected' => array( 'success', __( 'Disconnected from KRITIQ. The pixel ID stays in place until you remove it.', 'kritiq-pixel' ) ),
			'saved'        => array( 'success', __( 'Settings saved.', 'kritiq-pixel' ) ),
			'cancelled'    => array( 'warning', __( 'Connection cancelled.', 'kritiq-pixel' ) ),
		);
		if ( 'error' === $n ) {
			$msg = get_transient( 'kritiq_error_' . get_current_user_id() );
			$messages['error'] = array( 'error', __( 'Could not connect to KRITIQ: ', 'kritiq-pixel' ) . ( $msg ? $msg : __( 'unknown error', 'kritiq-pixel' ) ) );
		}
		if ( isset( $messages[ $n ] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $messages[ $n ][0] ), esc_html( $messages[ $n ][1] ) );
		}
	}

	public static function render() {
		$o         = Kritiq_Options::all();
		$connected = Kritiq_Options::is_connected();
		$status    = $connected ? Kritiq_Api::status() : null;
		?>
		<div class="wrap">
			<h1>KRITIQ</h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'See what your ads and creatives do on your store: page views, product views, add to cart, checkout and purchases, attributed to the ad that brought each visitor.', 'kritiq-pixel' ); ?></p>

			<h2><?php esc_html_e( 'Connection', 'kritiq-pixel' ); ?></h2>
			<?php if ( $connected ) : ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Account', 'kritiq-pixel' ); ?></th><td><?php echo esc_html( trim( $o['account_name'] . ( $o['brand_name'] ? ' · ' . $o['brand_name'] : '' ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Pixel ID', 'kritiq-pixel' ); ?></th><td><code><?php echo esc_html( $o['pixel_id'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'kritiq-pixel' ); ?></th><td>
						<?php
						if ( is_wp_error( $status ) ) {
							echo esc_html( sprintf( /* translators: %s: error */ __( 'Could not reach KRITIQ (%s). Tracking still runs.', 'kritiq-pixel' ), $status->get_error_message() ) );
						} elseif ( is_array( $status ) && ! empty( $status['last_event_at'] ) ) {
							echo esc_html( sprintf( /* translators: 1: date, 2: count */ __( 'Receiving events. Last event %1$s, %2$d events in the last 24 hours.', 'kritiq-pixel' ), $status['last_event_at'], isset( $status['events_24h_total'] ) ? (int) $status['events_24h_total'] : 0 ) );
						} else {
							esc_html_e( 'Connected. Waiting for the first event (open your store in a new tab and accept cookies).', 'kritiq-pixel' );
						}
						?>
					</td></tr>
				</table>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( Kritiq_Options::app_url() . '/media?tab=pixel' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in KRITIQ', 'kritiq-pixel' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kritiq_disconnect' ), 'kritiq_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'kritiq-pixel' ); ?></a>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Sign in to KRITIQ (or start a 14-day free trial) and choose the brand this store belongs to. We create the pixel for you and fill in everything below.', 'kritiq-pixel' ); ?></p>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kritiq_connect' ), 'kritiq_connect' ) ); ?>"><?php esc_html_e( 'Connect to KRITIQ', 'kritiq-pixel' ); ?></a></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kritiq_save" />
				<?php wp_nonce_field( 'kritiq_save' ); ?>
				<h2><?php esc_html_e( 'Settings', 'kritiq-pixel' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php if ( ! $connected ) : ?>
					<tr>
						<th scope="row"><label for="kq-pixel-id"><?php esc_html_e( 'Pixel ID (without connecting)', 'kritiq-pixel' ); ?></label></th>
						<td>
							<input id="kq-pixel-id" name="pixel_id" type="text" class="regular-text" placeholder="KQ-XXXXXXXXXX" value="<?php echo esc_attr( $o['pixel_id'] ); ?>" pattern="KQ-[A-Za-z0-9]{10}" />
							<p class="description"><?php esc_html_e( 'From KRITIQ → Media → Pixel. Browser events work with just the ID; connecting also sends paid orders from the server, so purchases blocked by ad blockers still count.', 'kritiq-pixel' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cookie consent', 'kritiq-pixel' ); ?></th>
						<td>
							<label><input type="checkbox" name="require_consent" value="1" <?php checked( $o['require_consent'] ); ?> /> <?php esc_html_e( 'Wait for the visitor to accept statistics cookies (recommended, POPIA / GDPR)', 'kritiq-pixel' ); ?></label>
							<p><select name="consent_mode">
								<option value="auto" <?php selected( $o['consent_mode'], 'auto' ); ?>><?php esc_html_e( 'Detect my cookie banner (WP Consent API, Complianz, CookieYes, Cookiebot)', 'kritiq-pixel' ); ?></option>
								<option value="custom" <?php selected( $o['consent_mode'], 'custom' ); ?>><?php esc_html_e( 'I will call kq("consent", "granted") from my own banner', 'kritiq-pixel' ); ?></option>
							</select></p>
							<p class="description"><?php esc_html_e( 'Until consent is given nothing is stored in the browser and nothing is sent. You remain responsible for your cookie notice.', 'kritiq-pixel' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purchase value', 'kritiq-pixel' ); ?></th>
						<td><select name="revenue">
							<option value="total" <?php selected( $o['revenue'], 'total' ); ?>><?php esc_html_e( 'Order total (incl. tax and shipping)', 'kritiq-pixel' ); ?></option>
							<option value="subtotal" <?php selected( $o['revenue'], 'subtotal' ); ?>><?php esc_html_e( 'Products after discounts (excl. shipping)', 'kritiq-pixel' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Server-side purchases', 'kritiq-pixel' ); ?></th>
						<td><label><input type="checkbox" name="server_events" value="1" <?php checked( $o['server_events'] ); ?> <?php disabled( ! $connected ); ?> /> <?php esc_html_e( 'Also send paid orders from the server (needs a connected account; only for visitors who consented)', 'kritiq-pixel' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
