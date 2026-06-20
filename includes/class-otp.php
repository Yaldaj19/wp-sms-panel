<?php
/**
 * OTP — phone login/register: shortcode form + AJAX send/verify.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_OTP {

	/**
	 * Singleton.
	 *
	 * @var WP_SMS_Panel_OTP|null
	 */
	private static $instance = null;

	/**
	 * @return WP_SMS_Panel_OTP
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'wp_sms_panel_form', array( $this, 'shortcode' ) );
		add_shortcode( 'yj19_sms_form', array( $this, 'shortcode' ) ); // legacy alias.

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// wp-login.php integration.
		add_action( 'login_enqueue_scripts', array( $this, 'login_assets' ) );
		add_filter( 'login_message', array( $this, 'login_message' ) );

		add_action( 'wp_ajax_nopriv_wp_sms_panel_send', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_wp_sms_panel_send', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_nopriv_wp_sms_panel_verify', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_wp_sms_panel_verify', array( $this, 'ajax_verify' ) );

		// Username/email + password login (only honoured when the setting is on).
		add_action( 'wp_ajax_nopriv_wp_sms_panel_password', array( $this, 'ajax_password' ) );
		add_action( 'wp_ajax_wp_sms_panel_password', array( $this, 'ajax_password' ) );
	}

	/**
	 * Whether the password-login option is enabled.
	 *
	 * @return bool
	 */
	private function password_enabled() {
		return ! empty( $this->setting( 'password_login', 0 ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private function setting( $key, $default = '' ) {
		$settings = WP_SMS_Panel_SMS::settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	private function is_dev() {
		return 'dev' === $this->setting( 'active_provider', 'dev' );
	}

	/* ---------------------------------------------------------------------
	 * Assets + shortcode
	 * ------------------------------------------------------------------- */

	public function register_assets() {
		wp_register_style( 'wp-sms-panel', WP_SMS_PANEL_URL . 'assets/form.css', array(), WP_SMS_PANEL_VERSION );
		wp_register_script( 'wp-sms-panel', WP_SMS_PANEL_URL . 'assets/form.js', array( 'jquery' ), WP_SMS_PANEL_VERSION, true );
		wp_localize_script(
			'wp-sms-panel',
			'WPSMSPanel',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_sms_panel' ),
				'isDev'   => $this->is_dev(),
				'i18n'    => array(
					'invalidPhone' => __( 'شماره موبایل معتبر نیست.', 'wp-sms-panel' ),
					'sent'         => __( 'کد تأیید پیامک شد.', 'wp-sms-panel' ),
					'enterCode'    => __( 'کد تأیید را وارد کنید.', 'wp-sms-panel' ),
					'success'      => __( 'با موفقیت وارد شدید…', 'wp-sms-panel' ),
					'error'        => __( 'خطایی رخ داد. دوباره تلاش کنید.', 'wp-sms-panel' ),
					'resend'       => __( 'ارسال مجدد', 'wp-sms-panel' ),
					'editPhone'    => __( 'ویرایش شماره', 'wp-sms-panel' ),
					'enterCreds'   => __( 'نام کاربری و رمز عبور را وارد کنید.', 'wp-sms-panel' ),
				),
			)
		);
	}

	/**
	 * Enqueue the OTP form assets on wp-login.php when login integration is on.
	 */
	public function login_assets() {
		if ( empty( $this->setting( 'login_page', 1 ) ) ) {
			return;
		}
		$this->register_assets();
		wp_enqueue_style( 'wp-sms-panel' );
		wp_enqueue_script( 'wp-sms-panel' );
		?>
		<style>
			#wpsp-login { margin-bottom: 16px; }
			#wpsp-login .wpsp-login-title { margin: 0 0 10px; font-size: 14px; font-weight: 700; color: #1d2327; text-align: center; }
			#wpsp-show-native { display: block; width: 100%; margin: 14px auto 2px; text-align: center; }
			/* Hidden only when JS is active, so no-JS users can always use the native form. */
			.wpsp-hide-native #loginform { display: none; }
			.wpsp-hide-native #nav { text-align: center; }
		</style>
		<script>
			document.documentElement.classList.add( 'wpsp-hide-native' );
			document.addEventListener( 'DOMContentLoaded', function () {
				var btn  = document.getElementById( 'wpsp-show-native' );
				var form = document.getElementById( 'loginform' );
				if ( ! btn || ! form ) {
					document.documentElement.classList.remove( 'wpsp-hide-native' );
					return;
				}
				btn.addEventListener( 'click', function () {
					document.documentElement.classList.remove( 'wpsp-hide-native' );
					btn.setAttribute( 'hidden', '' );
					var first = form.querySelector( 'input:not([type=hidden])' );
					if ( first ) { first.focus(); }
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Prepend the phone-OTP form above the default wp-login.php form.
	 *
	 * @param string $message Existing login message markup.
	 * @return string
	 */
	public function login_message( $message ) {
		if ( empty( $this->setting( 'login_page', 1 ) ) ) {
			return $message;
		}

		// Only on the main login/registration view (not lost-password, etc.).
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		if ( ! in_array( $action, array( 'login', 'register' ), true ) ) {
			return $message;
		}

		$title = $this->setting( 'login_title', __( 'ورود / ثبت‌نام با موبایل', 'wp-sms-panel' ) );

		$html  = '<div id="wpsp-login">';
		$html .= '<p class="wpsp-login-title">' . esc_html( $title ) . '</p>';
		// Phone-only on wp-login.php; the native WP form is the password method.
		$html .= $this->render_form( array( 'password_switch' => false ) );
		$html .= '<button type="button" id="wpsp-show-native" class="button button-secondary">'
			. esc_html__( 'ورود با نام کاربری و رمز', 'wp-sms-panel' ) . '</button>';
		$html .= '</div>';

		return $html . $message;
	}

	public function shortcode( $atts ) {
		$style = $this->style();
		$atts  = shortcode_atts(
			array(
				'type'        => 'login',
				'digit_count' => (int) $this->setting( 'otp_length', 5 ),
				'button_text' => __( 'ورود', 'wp-sms-panel' ),
				'accent'      => $style['accent'],
				'placeholder' => '09xxxxxxxxx',
			),
			$atts,
			'wp_sms_panel_form'
		);

		wp_enqueue_style( 'wp-sms-panel' );
		wp_enqueue_script( 'wp-sms-panel' );

		return $this->render_form( $atts );
	}

	/**
	 * Resolve the saved style settings with safe fallbacks.
	 *
	 * @return array{accent:string,button_text:string,radius:int}
	 */
	private function style() {
		$settings = WP_SMS_Panel_SMS::settings();
		$style    = isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : array();
		return array(
			'accent'      => ! empty( $style['accent'] ) ? $style['accent'] : '#2563eb',
			'button_text' => ! empty( $style['button_text'] ) ? $style['button_text'] : '#ffffff',
			'card_bg'     => ! empty( $style['card_bg'] ) ? $style['card_bg'] : '#ffffff',
			'field_bg'    => ! empty( $style['field_bg'] ) ? $style['field_bg'] : '#f7f8fa',
			'border'      => ! empty( $style['border'] ) ? $style['border'] : '#e5e7eb',
			'radius'      => isset( $style['radius'] ) ? (int) $style['radius'] : 10,
		);
	}

	/**
	 * Render the OTP form markup (shared by the shortcode and the login page).
	 *
	 * @param array $atts Display attributes.
	 * @return string
	 */
	public function render_form( $atts = array() ) {
		$style = $this->style();
		$atts  = wp_parse_args(
			$atts,
			array(
				'digit_count' => (int) $this->setting( 'otp_length', 5 ),
				'button_text' => __( 'ورود', 'wp-sms-panel' ),
				'accent'      => $style['accent'],
				'placeholder' => '09xxxxxxxxx',
			)
		);

		$accent = sanitize_hex_color( $atts['accent'] );
		if ( ! $accent ) {
			$accent = $style['accent'];
		}
		$digits    = max( 4, min( 8, (int) $atts['digit_count'] ) );
		$css_style = sprintf(
			'--rs-accent: %s; --rs-btn-text: %s; --rs-card-bg: %s; --rs-field-bg: %s; --rs-border: %s; --rs-radius: %dpx;',
			$accent,
			$style['button_text'],
			$style['card_bg'],
			$style['field_bg'],
			$style['border'],
			$style['radius']
		);

		// Tabs follow the setting, but callers (e.g. wp-login.php) can force phone-only.
		$pass_on = isset( $atts['password_switch'] ) ? (bool) $atts['password_switch'] : $this->password_enabled();

		ob_start();
		?>
		<div class="wpsp-form" style="<?php echo esc_attr( $css_style ); ?>"
			data-digits="<?php echo esc_attr( $digits ); ?>" dir="rtl">

			<?php if ( $pass_on ) : ?>
			<!-- Mode switch -->
			<div class="rs-switch" role="tablist" aria-label="<?php esc_attr_e( 'روش ورود', 'wp-sms-panel' ); ?>">
				<button type="button" class="rs-tab rs-tab-phone is-active" role="tab"
					aria-selected="true" data-mode="phone"><?php esc_html_e( 'با موبایل', 'wp-sms-panel' ); ?></button>
				<button type="button" class="rs-tab rs-tab-pass" role="tab"
					aria-selected="false" data-mode="pass"><?php esc_html_e( 'با رمز عبور', 'wp-sms-panel' ); ?></button>
			</div>
			<?php endif; ?>

			<!-- Phone (OTP) mode -->
			<div class="rs-mode rs-mode-phone" role="tabpanel">

			<!-- Step 1: phone -->
			<div class="rs-step rs-step-phone">
				<input type="tel" inputmode="numeric" autocomplete="tel" class="rs-input rs-phone"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" maxlength="11">
				<button type="button" class="rs-btn rs-send">
					<span class="rs-btn-label"><?php echo esc_html( $atts['button_text'] ); ?></span>
				</button>
			</div>

			<!-- Step 2: code -->
			<div class="rs-step rs-step-code" hidden>
				<p class="rs-code-hint">
					<?php esc_html_e( 'کد تأیید ارسال‌شده به', 'wp-sms-panel' ); ?>
					<b class="rs-phone-echo" dir="ltr"></b>
					<?php esc_html_e( 'را وارد کنید', 'wp-sms-panel' ); ?>
				</p>
				<div class="rs-otp" dir="ltr" role="group" aria-label="<?php esc_attr_e( 'کد تأیید', 'wp-sms-panel' ); ?>">
					<?php for ( $i = 0; $i < $digits; $i++ ) : ?>
						<input type="text" inputmode="numeric" pattern="[0-9]*"
							autocomplete="<?php echo 0 === $i ? 'one-time-code' : 'off'; ?>"
							class="rs-otp-box" maxlength="1"
							aria-label="<?php echo esc_attr( sprintf( __( 'رقم %d', 'wp-sms-panel' ), $i + 1 ) ); ?>">
					<?php endfor; ?>
				</div>
				<input type="hidden" class="rs-code">
				<button type="button" class="rs-btn rs-verify">
					<span class="rs-btn-label"><?php esc_html_e( 'تأیید و ورود', 'wp-sms-panel' ); ?></span>
				</button>
				<div class="rs-code-actions">
					<button type="button" class="rs-link rs-edit-phone"><?php esc_html_e( 'ویرایش شماره', 'wp-sms-panel' ); ?></button>
					<button type="button" class="rs-link rs-resend" disabled>
						<span class="rs-resend-label"><?php esc_html_e( 'ارسال مجدد', 'wp-sms-panel' ); ?></span>
						<span class="rs-timer"></span>
					</button>
				</div>
			</div>

			</div><!-- /.rs-mode-phone -->

			<?php if ( $pass_on ) : ?>
			<!-- Password mode -->
			<div class="rs-mode rs-mode-pass" role="tabpanel" hidden>
				<div class="rs-step rs-step-password">
					<input type="text" autocomplete="username" class="rs-input rs-user"
						placeholder="<?php esc_attr_e( 'نام کاربری یا ایمیل', 'wp-sms-panel' ); ?>"
						aria-label="<?php esc_attr_e( 'نام کاربری یا ایمیل', 'wp-sms-panel' ); ?>">
					<input type="password" autocomplete="current-password" class="rs-input rs-pass"
						placeholder="<?php esc_attr_e( 'رمز عبور', 'wp-sms-panel' ); ?>"
						aria-label="<?php esc_attr_e( 'رمز عبور', 'wp-sms-panel' ); ?>">
					<button type="button" class="rs-btn rs-pass-login">
						<span class="rs-btn-label"><?php esc_html_e( 'ورود', 'wp-sms-panel' ); ?></span>
					</button>
				</div>
			</div>
			<?php endif; ?>

			<p class="rs-message" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * AJAX — send code
	 * ------------------------------------------------------------------- */

	public function ajax_send() {
		check_ajax_referer( 'wp_sms_panel', 'nonce' );

		$phone = WP_SMS_Panel_SMS::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		if ( is_wp_error( $phone ) ) {
			wp_send_json_error( array( 'message' => $phone->get_error_message() ) );
		}

		// Throttle resends.
		if ( get_transient( 'wp_sms_panel_otp_wait_' . $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'کمی صبر کنید و سپس ارسال مجدد بزنید.', 'wp-sms-panel' ) ) );
		}

		$len  = max( 4, min( 8, (int) $this->setting( 'otp_length', 5 ) ) );
		$ttl  = (int) $this->setting( 'otp_ttl', 120 );
		$min  = (int) ( '1' . str_repeat( '0', $len - 1 ) );
		$max  = (int) str_repeat( '9', $len );
		$code = (string) wp_rand( $min, $max );

		set_transient(
			'wp_sms_panel_otp_' . $phone,
			array(
				'hash'     => wp_hash( $code ),
				'attempts' => 0,
			),
			$ttl
		);
		set_transient( 'wp_sms_panel_otp_wait_' . $phone, 1, min( 60, $ttl ) );

		$template = $this->setting( 'otp_message', __( 'کد ورود شما: {code}', 'wp-sms-panel' ) );
		$message  = str_replace( '{code}', $code, $template );

		$result = WP_SMS_Panel_SMS::send_otp( $phone, $code, $message );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$response = array(
			'message' => __( 'کد تأیید پیامک شد.', 'wp-sms-panel' ),
			'ttl'     => $ttl,
		);
		if ( $this->is_dev() ) {
			$response['dev_code'] = $code;
		}
		wp_send_json_success( $response );
	}

	/* ---------------------------------------------------------------------
	 * AJAX — verify + login/register
	 * ------------------------------------------------------------------- */

	public function ajax_verify() {
		check_ajax_referer( 'wp_sms_panel', 'nonce' );

		$phone = WP_SMS_Panel_SMS::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		$code  = preg_replace( '/\D/', '', isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '' );

		if ( is_wp_error( $phone ) || '' === $code ) {
			wp_send_json_error( array( 'message' => __( 'کد تأیید را وارد کنید.', 'wp-sms-panel' ) ) );
		}

		$stored = get_transient( 'wp_sms_panel_otp_' . $phone );
		if ( empty( $stored ) || empty( $stored['hash'] ) ) {
			wp_send_json_error( array( 'message' => __( 'کد منقضی شده است. دوباره درخواست دهید.', 'wp-sms-panel' ) ) );
		}

		if ( (int) $stored['attempts'] >= 5 ) {
			delete_transient( 'wp_sms_panel_otp_' . $phone );
			wp_send_json_error( array( 'message' => __( 'تعداد تلاش‌ها زیاد شد. دوباره درخواست دهید.', 'wp-sms-panel' ) ) );
		}

		if ( ! hash_equals( $stored['hash'], wp_hash( $code ) ) ) {
			++$stored['attempts'];
			set_transient( 'wp_sms_panel_otp_' . $phone, $stored, (int) $this->setting( 'otp_ttl', 120 ) );
			wp_send_json_error( array( 'message' => __( 'کد وارد شده درست نیست.', 'wp-sms-panel' ) ) );
		}

		// Success — consume the code.
		delete_transient( 'wp_sms_panel_otp_' . $phone );
		delete_transient( 'wp_sms_panel_otp_wait_' . $phone );

		$user = $this->get_or_create_user( $phone );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ) );
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		do_action( 'wp_login', $user->user_login, $user );

		$redirect = wp_validate_redirect( isset( $_POST['redirect'] ) ? wp_unslash( $_POST['redirect'] ) : '', '' );
		if ( empty( $redirect ) ) {
			$redirect = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		}
		wp_send_json_success( array( 'redirect' => $redirect ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX — username/email + password login
	 * ------------------------------------------------------------------- */

	public function ajax_password() {
		check_ajax_referer( 'wp_sms_panel', 'nonce' );

		// Never trust the client: the form only renders this when the option is on,
		// but re-verify server-side before doing any authentication.
		if ( ! $this->password_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'این روش ورود فعال نیست.', 'wp-sms-panel' ) ) );
		}

		// IP-based brute-force throttle.
		$ip  = $this->client_ip();
		$key = 'wp_sms_panel_pw_fail_' . md5( $ip );
		if ( (int) get_transient( $key ) >= 5 ) {
			wp_send_json_error( array( 'message' => __( 'تلاش‌های ناموفق زیاد بود. چند دقیقه بعد دوباره تلاش کنید.', 'wp-sms-panel' ) ) );
		}

		$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
		$pass  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( '' === $login || '' === $pass ) {
			wp_send_json_error( array( 'message' => __( 'نام کاربری و رمز عبور را وارد کنید.', 'wp-sms-panel' ) ) );
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $pass,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
			// Generic message — don't reveal whether the account exists.
			wp_send_json_error( array( 'message' => __( 'نام کاربری یا رمز عبور نادرست است.', 'wp-sms-panel' ) ) );
		}

		delete_transient( $key );

		$redirect = wp_validate_redirect( isset( $_POST['redirect'] ) ? wp_unslash( $_POST['redirect'] ) : '', '' );
		if ( empty( $redirect ) ) {
			$redirect = admin_url();
		}
		wp_send_json_success( array( 'redirect' => $redirect ) );
	}

	/**
	 * Best-effort client IP for throttling (not used for trust decisions).
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Find a user by phone, or create one.
	 *
	 * @param string $phone Normalised mobile.
	 * @return WP_User|WP_Error
	 */
	private function get_or_create_user( $phone ) {
		// 1) username == phone.
		$user = get_user_by( 'login', $phone );
		if ( $user ) {
			return $user;
		}

		// 2) phone meta (supports legacy rangnet_phone for migrated sites).
		$found = get_users(
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'billing_phone',
						'value' => $phone,
					),
					array(
						'key'   => 'wp_sms_panel_phone',
						'value' => $phone,
					),
					array(
						'key'   => 'rangnet_phone',
						'value' => $phone,
					),
				),
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		if ( ! empty( $found ) ) {
			return $found[0];
		}

		// 3) create.
		$role    = get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $phone,
				'user_pass'    => wp_generate_password( 20, true ),
				'role'         => $role,
				'nickname'     => $phone,
				'display_name' => $phone,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'billing_phone', $phone );
		update_user_meta( $user_id, 'wp_sms_panel_phone', $phone );

		return get_user_by( 'id', $user_id );
	}
}
