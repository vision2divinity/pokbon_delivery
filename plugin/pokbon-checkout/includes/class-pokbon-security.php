<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pokbon_Security {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'send_headers',   array( $this, 'send_security_headers' ) );
		add_action( 'admin_menu',     array( $this, 'register_log_page' ), 20 );
		add_action( 'admin_post_pokbon_checkout_clear_log', array( $this, 'handle_clear_log' ) );

		// Site-wide hardening (applied everywhere, not just checkout).
		$this->apply_site_wide_hardening();
	}

	/**
	 * Site-wide hardening applied on every request:
	 *  - Disable XML-RPC (DDoS amplification + brute force vector).
	 *  - Strip generator meta tags so plugin/core versions aren't fingerprintable.
	 *  - Force the Secure flag on cookies sent over HTTPS.
	 *
	 * These changes are minimal-impact: XML-RPC is rarely used on modern stores
	 * (Jetpack uses REST now), generator tags are purely informational, cookie
	 * Secure is required by spec on HTTPS anyway.
	 */
	private function apply_site_wide_hardening() {
		// XML-RPC: disable the entire endpoint and its method registry.
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_xmlrpc_methods', '__return_empty_array' );
		add_filter( 'pre_update_option_enable_xmlrpc', '__return_zero' );
		add_filter( 'pre_option_enable_xmlrpc', '__return_zero' );
		// Drop pingback header so attackers can't auto-discover the endpoint.
		add_filter( 'wp_headers', static function ( $headers ) {
			if ( isset( $headers['X-Pingback'] ) ) {
				unset( $headers['X-Pingback'] );
			}
			return $headers;
		} );
		// Strip pingback link tag and remove pingback methods on every request.
		add_action( 'init', static function () {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		} );
		// Hard-block /xmlrpc.php at the request level — return 403 before WP processes anything.
		// Belt-and-suspenders alongside xmlrpc_enabled=false.
		add_action( 'init', static function () {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			if ( stripos( $uri, '/xmlrpc.php' ) !== false ) {
				status_header( 403 );
				nocache_headers();
				exit( 'XML-RPC services are disabled on this site.' );
			}
		}, 0 );

		// Generator fingerprinting: hide WP core + plugin versions from page HTML / RSS.
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		// Some plugins emit their own generator tags via wp_head — buffer + strip them.
		add_action( 'wp_head', array( $this, 'strip_plugin_generator_tags' ), 1 );

		// Cookie hardening on HTTPS: force Secure flag for any Set-Cookie that lacks it.
		add_action( 'send_headers', array( $this, 'harden_cookies' ), 999 );
	}

	public function strip_plugin_generator_tags() {
		ob_start( static function ( $buffer ) {
			// Remove any <meta name="generator" ...> tag emitted by plugins.
			return preg_replace( '#<meta\s+name=["\']generator["\'][^>]*>\s*#i', '', $buffer );
		} );
		add_action( 'wp_head', static function () { @ob_end_flush(); }, PHP_INT_MAX );
	}

	public function harden_cookies() {
		if ( ! is_ssl() ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		// Re-emit every Set-Cookie header with Secure appended if missing.
		$existing = array();
		foreach ( headers_list() as $h ) {
			if ( stripos( $h, 'Set-Cookie:' ) === 0 ) {
				$existing[] = trim( substr( $h, 11 ) );
			}
		}
		if ( empty( $existing ) ) {
			return;
		}
		header_remove( 'Set-Cookie' );
		foreach ( $existing as $cookie ) {
			if ( stripos( $cookie, 'secure' ) === false ) {
				$cookie .= '; Secure';
			}
			header( 'Set-Cookie: ' . $cookie, false );
		}
	}

	public function send_security_headers() {
		if ( is_admin() ) {
			return;
		}

		// Baseline headers applied to ALL frontend pages.
		header( 'Strict-Transport-Security: max-age=63072000; includeSubDomains; preload' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		// Stronger headers only on the checkout page.
		if ( ! $this->is_checkout_context() ) {
			// Site-wide X-Frame-Options is SAMEORIGIN (lets Customizer / vendor frames work).
			if ( ! headers_sent() ) {
				header( 'X-Frame-Options: SAMEORIGIN' );
			}
			return;
		}

		header( 'X-Frame-Options: DENY' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self "https://checkout.paystack.com")' );
		header( 'Cross-Origin-Opener-Policy: same-origin-allow-popups' );

		/**
		 * Content Security Policy.
		 * - default 'self' only.
		 * - allows Paystack inline checkout, Google Fonts, jQuery from WP itself, and the site's own assets.
		 * - 'unsafe-inline' is permitted for styles only (WP/Woo and OceanWP inject inline styles).
		 *   For scripts we allow 'unsafe-inline' temporarily because OceanWP/WPCode still rely on it;
		 *   when the site is fully cleaned up this can be tightened to a nonce.
		 *
		 * Filter `pokbon_checkout_csp` to customise without editing this file.
		 */
		$paystack_hosts = 'https://*.paystack.com https://*.paystack.co https://*.paystackcdn.com';
		$google_hosts   = 'https://fonts.googleapis.com https://fonts.gstatic.com';
		$wp_self        = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$directives = array(
			"default-src 'self'",
			"base-uri 'self'",
			"form-action 'self' " . $paystack_hosts,
			"frame-ancestors 'none'",
			"img-src 'self' data: https: " . $paystack_hosts,
			"font-src 'self' data: " . $google_hosts,
			"style-src 'self' 'unsafe-inline' " . $google_hosts,
			"script-src 'self' 'unsafe-inline' 'unsafe-eval' " . $paystack_hosts,
			"connect-src 'self' " . $paystack_hosts,
			"frame-src 'self' " . $paystack_hosts,
			"object-src 'none'",
			"upgrade-insecure-requests",
		);
		$csp = apply_filters( 'pokbon_checkout_csp', implode( '; ', $directives ), $wp_self );
		header( 'Content-Security-Policy: ' . $csp );
	}

	private function is_checkout_context() {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return false;
		}
		global $post;
		if ( ! $post ) {
			return false;
		}
		return has_shortcode( $post->post_content, Pokbon_Form::SHORTCODE );
	}

	public static function get_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = (string) wp_unslash( $_SERVER[ $key ] );
			$first = trim( explode( ',', $value )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return '';
	}

	public static function is_rate_limited() {
		$ip = self::get_ip();
		if ( ! $ip ) {
			return false;
		}
		$key  = 'pokbon_chk_rate_' . md5( $ip );
		$hits = (int) get_transient( $key );
		return $hits >= Pokbon_Handler::RATE_LIMIT_PER_HOUR;
	}

	public static function increment_rate() {
		$ip = self::get_ip();
		if ( ! $ip ) {
			return;
		}
		$key  = 'pokbon_chk_rate_' . md5( $ip );
		$hits = (int) get_transient( $key );
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
	}

	public static function audit( $email, $order_id, $outcome, $detail = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . Pokbon_Installer::AUDIT_TABLE;
		$wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql' ),
				'ip'         => self::get_ip(),
				'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 240 ),
				'email'      => substr( (string) $email, 0, 190 ),
				'order_id'   => (int) $order_id,
				'outcome'    => substr( (string) $outcome, 0, 40 ),
				'detail'     => is_string( $detail ) ? substr( $detail, 0, 5000 ) : '',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public function register_log_page() {
		add_submenu_page(
			'woocommerce',
			__( 'POKBON Checkout Log', 'pokbon-checkout' ),
			__( 'Checkout Log', 'pokbon-checkout' ),
			'manage_woocommerce',
			'pokbon-checkout-log',
			array( $this, 'render_log_page' )
		);
	}

	public function render_log_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . Pokbon_Installer::AUDIT_TABLE;

		$per_page = 50;
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'POKBON Checkout — Audit Log', 'pokbon-checkout' ); ?></h1>
			<p><?php printf( esc_html__( 'Total entries: %d', 'pokbon-checkout' ), $total ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
				<?php wp_nonce_field( 'pokbon_checkout_clear_log' ); ?>
				<input type="hidden" name="action" value="pokbon_checkout_clear_log" />
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Clear all audit log entries? This cannot be undone.', 'pokbon-checkout' ) ); ?>');">
					<?php esc_html_e( 'Clear log', 'pokbon-checkout' ); ?>
				</button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'pokbon-checkout' ); ?></th>
						<th><?php esc_html_e( 'IP', 'pokbon-checkout' ); ?></th>
						<th><?php esc_html_e( 'Email', 'pokbon-checkout' ); ?></th>
						<th><?php esc_html_e( 'Order', 'pokbon-checkout' ); ?></th>
						<th><?php esc_html_e( 'Outcome', 'pokbon-checkout' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'pokbon-checkout' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No entries yet.', 'pokbon-checkout' ); ?></td></tr>
					<?php else : foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r->created_at ); ?></td>
							<td><?php echo esc_html( $r->ip ); ?></td>
							<td><?php echo esc_html( $r->email ); ?></td>
							<td>
								<?php if ( $r->order_id > 0 ) : ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' ) ); ?>">#<?php echo (int) $r->order_id; ?></a>
								<?php else : ?>—<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $r->outcome ); ?></code></td>
							<td style="max-width:380px;word-wrap:break-word;"><?php echo esc_html( $r->detail ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) :
				$base = remove_query_arg( 'paged' );
				?>
				<p class="tablenav-pages" style="margin-top:12px;">
					<?php for ( $i = 1; $i <= $pages; $i++ ) :
						$url = add_query_arg( 'paged', $i, $base );
						if ( $i === $page ) {
							echo '<strong style="margin-right:6px;">' . (int) $i . '</strong>';
						} else {
							echo '<a style="margin-right:6px;" href="' . esc_url( $url ) . '">' . (int) $i . '</a>';
						}
					endfor; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 'Forbidden', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pokbon_checkout_clear_log' );
		global $wpdb;
		$table = $wpdb->prefix . Pokbon_Installer::AUDIT_TABLE;
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		wp_safe_redirect( admin_url( 'admin.php?page=pokbon-checkout-log&cleared=1' ) );
		exit;
	}
}
