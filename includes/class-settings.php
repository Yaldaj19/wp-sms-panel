<?php
/**
 * Admin settings page + sanitisation + test/credit AJAX + legacy migration.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_Settings {

	const GROUP    = 'wp_sms_panel_group';
	const PAGE     = 'wp-sms-panel';
	const CAP      = 'manage_options';

	/**
	 * @var WP_SMS_Panel_Settings|null
	 */
	private static $instance = null;

	/**
	 * @return WP_SMS_Panel_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'wp_ajax_wp_sms_panel_test', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_wp_sms_panel_credit', array( $this, 'ajax_credit' ) );
	}

	/* ---------------------------------------------------------------------
	 * Defaults + migration
	 * ------------------------------------------------------------------- */

	/**
	 * Seed defaults on activation if no settings exist yet.
	 */
	public static function install_defaults() {
		if ( false === get_option( WP_SMS_PANEL_OPTION, false ) ) {
			add_option( WP_SMS_PANEL_OPTION, WP_SMS_Panel_SMS::defaults() );
		}
	}

	/**
	 * One-time import from the legacy rangnet-sms plugin settings.
	 */
	public function maybe_migrate() {
		if ( get_option( 'wp_sms_panel_migrated' ) ) {
			return;
		}

		$legacy = get_option( 'rangnet_sms_settings' );
		if ( is_array( $legacy ) && ! empty( $legacy ) ) {
			$provider = isset( $legacy['provider'] ) ? $legacy['provider'] : 'dev';
			$new      = WP_SMS_Panel_SMS::defaults();

			$new['active_provider'] = $provider;
			$new['otp_length']      = isset( $legacy['otp_length'] ) ? (int) $legacy['otp_length'] : 5;
			$new['otp_ttl']         = isset( $legacy['otp_ttl'] ) ? (int) $legacy['otp_ttl'] : 120;

			$pcfg = array();
			switch ( $provider ) {
				case 'kavenegar':
					$pcfg = array(
						'api_key' => isset( $legacy['api_key'] ) ? $legacy['api_key'] : '',
						'sender'  => isset( $legacy['sender'] ) ? $legacy['sender'] : '',
						'pattern' => isset( $legacy['pattern'] ) ? $legacy['pattern'] : '',
					);
					break;
				case 'melipayamak':
					$pcfg = array(
						'username' => isset( $legacy['username'] ) ? $legacy['username'] : '',
						'password' => isset( $legacy['api_key'] ) ? $legacy['api_key'] : '',
						'sender'   => isset( $legacy['sender'] ) ? $legacy['sender'] : '',
					);
					break;
				case 'smsir':
					$pcfg = array(
						'api_key' => isset( $legacy['api_key'] ) ? $legacy['api_key'] : '',
						'sender'  => isset( $legacy['sender'] ) ? $legacy['sender'] : '',
					);
					break;
			}
			if ( $pcfg ) {
				$new['providers'][ $provider ] = $pcfg;
			}

			update_option( WP_SMS_PANEL_OPTION, $new );
		}

		update_option( 'wp_sms_panel_migrated', 1 );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------- */

	public function add_menu() {
		add_menu_page(
			__( 'پنل پیامک', 'wp-sms-panel' ),
			__( 'پنل پیامک', 'wp-sms-panel' ),
			self::CAP,
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-email-alt',
			58
		);
	}

	public function register() {
		register_setting(
			self::GROUP,
			WP_SMS_PANEL_OPTION,
			array( $this, 'sanitize' )
		);
	}

	/**
	 * Sanitise the nested settings array.
	 *
	 * @param array $input Raw posted settings.
	 * @return array
	 */
	public function sanitize( $input ) {
		$out = WP_SMS_Panel_SMS::defaults();

		$choices = WP_SMS_Panel_Provider_Registry::choices();
		$active  = isset( $input['active_provider'] ) ? sanitize_key( $input['active_provider'] ) : 'dev';
		$out['active_provider'] = isset( $choices[ $active ] ) ? $active : 'dev';

		$out['otp_length']  = isset( $input['otp_length'] ) ? max( 4, min( 8, (int) $input['otp_length'] ) ) : 5;
		$out['otp_ttl']     = isset( $input['otp_ttl'] ) ? max( 30, min( 600, (int) $input['otp_ttl'] ) ) : 120;
		$out['otp_message'] = isset( $input['otp_message'] ) ? sanitize_textarea_field( $input['otp_message'] ) : WP_SMS_Panel_SMS::defaults()['otp_message'];

		$out['login_page']  = empty( $input['login_page'] ) ? 0 : 1;
		$out['login_title'] = isset( $input['login_title'] ) ? sanitize_text_field( $input['login_title'] ) : WP_SMS_Panel_SMS::defaults()['login_title'];

		$style        = isset( $input['style'] ) && is_array( $input['style'] ) ? $input['style'] : array();
		$out['style'] = array(
			'accent'      => ( isset( $style['accent'] ) ? sanitize_hex_color( $style['accent'] ) : '' ) ?: '#2563eb',
			'button_text' => ( isset( $style['button_text'] ) ? sanitize_hex_color( $style['button_text'] ) : '' ) ?: '#ffffff',
			'card_bg'     => ( isset( $style['card_bg'] ) ? sanitize_hex_color( $style['card_bg'] ) : '' ) ?: '#ffffff',
			'field_bg'    => ( isset( $style['field_bg'] ) ? sanitize_hex_color( $style['field_bg'] ) : '' ) ?: '#f7f8fa',
			'border'      => ( isset( $style['border'] ) ? sanitize_hex_color( $style['border'] ) : '' ) ?: '#e5e7eb',
			'radius'      => isset( $style['radius'] ) ? max( 0, min( 30, (int) $style['radius'] ) ) : 10,
		);

		// Sanitise each provider's declared fields.
		$out['providers'] = array();
		foreach ( WP_SMS_Panel_Provider_Registry::all() as $key => $provider ) {
			$fields  = $provider->get_fields();
			$posted  = isset( $input['providers'][ $key ] ) && is_array( $input['providers'][ $key ] ) ? $input['providers'][ $key ] : array();
			$pconfig = array();
			foreach ( $fields as $field ) {
				$fk            = $field['key'];
				$val           = isset( $posted[ $fk ] ) ? $posted[ $fk ] : '';
				$pconfig[ $fk ] = sanitize_text_field( $val );
			}
			if ( $pconfig ) {
				$out['providers'][ $key ] = $pconfig;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'wp-sms-panel-admin', WP_SMS_PANEL_URL . 'assets/admin.css', array(), WP_SMS_PANEL_VERSION );
		wp_enqueue_script( 'wp-sms-panel-admin', WP_SMS_PANEL_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), WP_SMS_PANEL_VERSION, true );
		wp_localize_script(
			'wp-sms-panel-admin',
			'WPSMSPanelAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_sms_panel_admin' ),
				'i18n'    => array(
					'testing'  => __( 'در حال ارسال…', 'wp-sms-panel' ),
					'checking' => __( 'در حال استعلام…', 'wp-sms-panel' ),
					'error'    => __( 'خطا در ارتباط.', 'wp-sms-panel' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------- */

	public function render() {
		$settings  = WP_SMS_Panel_SMS::settings();
		$active    = $settings['active_provider'];
		$providers = WP_SMS_Panel_Provider_Registry::all();
		$style     = isset( $settings['style'] ) ? $settings['style'] : array();

		// Active provider label for the status pill.
		$active_label = isset( $providers[ $active ] ) ? $providers[ $active ]->get_label() : $active;
		?>
		<div class="wrap wpsp-admin">

			<!-- ===== Page Header ===== -->
			<div class="wpsp-page-header">
				<div class="wpsp-page-header-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
					</svg>
				</div>
				<div class="wpsp-page-header-body">
					<h1><?php esc_html_e( 'پنل پیامک', 'wp-sms-panel' ); ?></h1>
					<p><?php esc_html_e( 'تنظیمات درگاه پیامک، کد یک‌بارمصرف و ظاهر فرم‌های سایت', 'wp-sms-panel' ); ?></p>
				</div>
				<div class="wpsp-provider-pill">
					<span class="wpsp-provider-pill-dot" aria-hidden="true"></span>
					<?php echo esc_html( $active_label ); ?>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<!-- ===== Section 1: Gateway selector ===== -->
				<div class="wpsp-card">
					<div class="wpsp-card-header">
						<div class="wpsp-card-header-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="3"></circle>
								<path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"></path>
							</svg>
						</div>
						<h2 class="wpsp-card-title"><?php esc_html_e( 'درگاه پیامک', 'wp-sms-panel' ); ?></h2>
					</div>
					<div class="wpsp-card-body">
						<div class="wpsp-field-row">
							<div class="wpsp-field-label">
								<label for="wpsp-provider"><?php esc_html_e( 'درگاه فعال', 'wp-sms-panel' ); ?></label>
							</div>
							<div class="wpsp-field-control">
								<select id="wpsp-provider" name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[active_provider]">
									<?php foreach ( $providers as $key => $provider ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active, $key ); ?>>
											<?php echo esc_html( $provider->get_label() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php esc_html_e( 'درگاهی که برای ارسال پیامک استفاده می‌شود. اطلاعات همه‌ی درگاه‌ها ذخیره می‌ماند؛ فقط درگاه فعال استفاده می‌شود.', 'wp-sms-panel' ); ?></span>
							</div>
						</div>
					</div>
				</div>

				<!-- ===== Section 2: Provider credentials (one card per provider, JS-toggled) ===== -->
				<?php foreach ( $providers as $key => $provider ) : ?>
					<?php
					$fields = $provider->get_fields();
					if ( empty( $fields ) ) {
						continue;
					}
					$pconf      = isset( $settings['providers'][ $key ] ) ? $settings['providers'][ $key ] : array();
					$prov_style = ( $key === $active ) ? '' : 'display:none;';
					?>
					<div class="wpsp-card wpsp-provider-fields" data-provider="<?php echo esc_attr( $key ); ?>" style="<?php echo esc_attr( $prov_style ); ?>">
						<div class="wpsp-card-header">
							<div class="wpsp-card-header-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
									<path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
								</svg>
							</div>
							<h2 class="wpsp-card-title"><?php echo esc_html( sprintf( __( 'تنظیمات %s', 'wp-sms-panel' ), $provider->get_label() ) ); ?></h2>
						</div>
						<div class="wpsp-card-body">
							<?php foreach ( $fields as $field ) : ?>
								<?php
								$fk   = $field['key'];
								$type = isset( $field['type'] ) ? $field['type'] : 'text';
								$val  = isset( $pconf[ $fk ] ) ? $pconf[ $fk ] : '';
								$name = WP_SMS_PANEL_OPTION . '[providers][' . $key . '][' . $fk . ']';
								$id   = 'wpsp-' . $key . '-' . $fk;
								?>
								<div class="wpsp-field-row">
									<div class="wpsp-field-label">
										<label for="<?php echo esc_attr( $id ); ?>">
											<?php echo esc_html( $field['label'] ); ?>
											<?php if ( ! empty( $field['required'] ) ) : ?>
												<span class="required-star" aria-hidden="true">*</span>
											<?php endif; ?>
										</label>
									</div>
									<div class="wpsp-field-control">
										<input type="<?php echo esc_attr( 'password' === $type ? 'password' : ( 'number' === $type ? 'number' : 'text' ) ); ?>"
											id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( $name ); ?>"
											value="<?php echo esc_attr( $val ); ?>"
											class="regular-text" autocomplete="off" dir="ltr">
										<?php if ( ! empty( $field['help'] ) ) : ?>
											<span class="description"><?php echo esc_html( $field['help'] ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<!-- ===== Section 3: OTP (full width) ===== -->
					<!-- OTP Settings -->
					<div class="wpsp-card">
						<div class="wpsp-card-header">
							<div class="wpsp-card-header-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
									<line x1="8" y1="21" x2="16" y2="21"></line>
									<line x1="12" y1="17" x2="12" y2="21"></line>
								</svg>
							</div>
							<h2 class="wpsp-card-title"><?php esc_html_e( 'کد یک‌بارمصرف (OTP)', 'wp-sms-panel' ); ?></h2>
						</div>
						<div class="wpsp-card-body">
							<div class="wpsp-field-row">
								<div class="wpsp-field-label">
									<label for="wpsp-otp-length"><?php esc_html_e( 'طول کد', 'wp-sms-panel' ); ?></label>
								</div>
								<div class="wpsp-field-control">
									<input type="number" id="wpsp-otp-length" min="4" max="8"
										name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[otp_length]"
										value="<?php echo esc_attr( $settings['otp_length'] ); ?>" class="small-text">
									<span class="description"><?php esc_html_e( 'تعداد ارقام کد (۴ تا ۸).', 'wp-sms-panel' ); ?></span>
								</div>
							</div>
							<div class="wpsp-field-row">
								<div class="wpsp-field-label">
									<label for="wpsp-otp-ttl"><?php esc_html_e( 'مدت اعتبار (ثانیه)', 'wp-sms-panel' ); ?></label>
								</div>
								<div class="wpsp-field-control">
									<input type="number" id="wpsp-otp-ttl" min="30" max="600"
										name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[otp_ttl]"
										value="<?php echo esc_attr( $settings['otp_ttl'] ); ?>" class="small-text">
									<span class="description"><?php esc_html_e( 'مدت معتبر بودن کد (۳۰ تا ۶۰۰ ثانیه).', 'wp-sms-panel' ); ?></span>
								</div>
							</div>
							<div class="wpsp-field-row">
								<div class="wpsp-field-label">
									<label for="wpsp-otp-message"><?php esc_html_e( 'متن پیامک کد', 'wp-sms-panel' ); ?></label>
								</div>
								<div class="wpsp-field-control">
									<textarea id="wpsp-otp-message" rows="3" class="large-text" dir="auto"
										name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[otp_message]"><?php echo esc_textarea( $settings['otp_message'] ); ?></textarea>
									<span class="description"><?php esc_html_e( 'از {code} برای جای کد استفاده کنید.', 'wp-sms-panel' ); ?></span>
								</div>
							</div>
						</div>
					</div>

					<!-- Login Page Settings -->
					<div class="wpsp-card">
						<div class="wpsp-card-header">
							<div class="wpsp-card-header-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
									<circle cx="12" cy="7" r="4"></circle>
								</svg>
							</div>
							<h2 class="wpsp-card-title"><?php esc_html_e( 'صفحه ورود وردپرس', 'wp-sms-panel' ); ?></h2>
						</div>
						<div class="wpsp-card-body">
							<div class="wpsp-field-row">
								<div class="wpsp-field-label">
									<?php esc_html_e( 'ورود با موبایل', 'wp-sms-panel' ); ?>
								</div>
								<div class="wpsp-field-control">
									<label class="wpsp-checkbox-label">
										<input type="checkbox" value="1"
											name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[login_page]"
											<?php checked( ! empty( $settings['login_page'] ) ); ?>>
										<?php esc_html_e( 'فعال‌سازی فرم ورود با موبایل (OTP)', 'wp-sms-panel' ); ?>
									</label>
									<span class="description"><?php esc_html_e( 'فرم ورود با شماره موبایل بالای فرم پیش‌فرض وردپرس نمایش داده می‌شود.', 'wp-sms-panel' ); ?></span>
								</div>
							</div>
							<div class="wpsp-field-row">
								<div class="wpsp-field-label">
									<label for="wpsp-login-title"><?php esc_html_e( 'عنوان فرم ورود', 'wp-sms-panel' ); ?></label>
								</div>
								<div class="wpsp-field-control">
									<input type="text" id="wpsp-login-title" class="regular-text"
										name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[login_title]"
										value="<?php echo esc_attr( isset( $settings['login_title'] ) ? $settings['login_title'] : '' ); ?>">
								</div>
							</div>
						</div>
					</div>


				<!-- ===== Section 5: Colors ===== -->
				<div class="wpsp-card">
					<div class="wpsp-card-header">
						<div class="wpsp-card-header-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"></circle>
								<circle cx="17.5" cy="10.5" r=".5" fill="currentColor"></circle>
								<circle cx="8.5" cy="7.5" r=".5" fill="currentColor"></circle>
								<circle cx="6.5" cy="12.5" r=".5" fill="currentColor"></circle>
								<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path>
							</svg>
						</div>
						<h2 class="wpsp-card-title"><?php esc_html_e( 'ظاهر و رنگ‌ها', 'wp-sms-panel' ); ?></h2>
					</div>
					<div class="wpsp-card-body">
						<p class="description" style="margin-block-end:18px"><?php esc_html_e( 'رنگ‌ها را با تم سایت هماهنگ کنید. روی فرم شورت‌کد و فرم صفحه ورود اعمال می‌شود.', 'wp-sms-panel' ); ?></p>
						<div class="wpsp-color-grid">

							<div class="wpsp-color-item">
								<label for="wpsp-accent"><?php esc_html_e( 'رنگ اصلی (دکمه و فوکوس)', 'wp-sms-panel' ); ?></label>
								<input type="text" id="wpsp-accent" class="wpsp-color"
									name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[style][accent]"
									value="<?php echo esc_attr( isset( $style['accent'] ) ? $style['accent'] : '#2563eb' ); ?>"
									data-default-color="#2563eb">
							</div>

							<div class="wpsp-color-item">
								<label for="wpsp-btn-text"><?php esc_html_e( 'رنگ متن دکمه', 'wp-sms-panel' ); ?></label>
								<input type="text" id="wpsp-btn-text" class="wpsp-color"
									name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[style][button_text]"
									value="<?php echo esc_attr( isset( $style['button_text'] ) ? $style['button_text'] : '#ffffff' ); ?>"
									data-default-color="#ffffff">
							</div>

							<div class="wpsp-color-item">
								<label for="wpsp-card-bg"><?php esc_html_e( 'پس‌زمینه کارت', 'wp-sms-panel' ); ?></label>
								<input type="text" id="wpsp-card-bg" class="wpsp-color"
									name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[style][card_bg]"
									value="<?php echo esc_attr( isset( $style['card_bg'] ) ? $style['card_bg'] : '#ffffff' ); ?>"
									data-default-color="#ffffff">
								<span class="description"><?php esc_html_e( 'رنگ پس‌زمینه‌ی کادر کلی فرم.', 'wp-sms-panel' ); ?></span>
							</div>

							<div class="wpsp-color-item">
								<label for="wpsp-field-bg"><?php esc_html_e( 'پس‌زمینه فیلدها', 'wp-sms-panel' ); ?></label>
								<input type="text" id="wpsp-field-bg" class="wpsp-color"
									name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[style][field_bg]"
									value="<?php echo esc_attr( isset( $style['field_bg'] ) ? $style['field_bg'] : '#f7f8fa' ); ?>"
									data-default-color="#f7f8fa">
								<span class="description"><?php esc_html_e( 'رنگ پس‌زمینه‌ی کادرهای ورودی.', 'wp-sms-panel' ); ?></span>
							</div>

							<div class="wpsp-color-item">
								<label for="wpsp-border"><?php esc_html_e( 'رنگ بوردر', 'wp-sms-panel' ); ?></label>
								<input type="text" id="wpsp-border" class="wpsp-color"
									name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[style][border]"
									value="<?php echo esc_attr( isset( $style['border'] ) ? $style['border'] : '#e5e7eb' ); ?>"
									data-default-color="#e5e7eb">
								<span class="description"><?php esc_html_e( 'رنگ خط دور کارت و فیلدها.', 'wp-sms-panel' ); ?></span>
							</div>

							<div class="wpsp-color-item">
								<label for="wpsp-radius"><?php esc_html_e( 'گردی گوشه‌ها (px)', 'wp-sms-panel' ); ?></label>
								<div class="wpsp-radius-row">
									<input type="number" id="wpsp-radius" min="0" max="30" class="small-text"
										name="<?php echo esc_attr( WP_SMS_PANEL_OPTION ); ?>[style][radius]"
										value="<?php echo esc_attr( isset( $style['radius'] ) ? (int) $style['radius'] : 10 ); ?>">
									<span class="description">px</span>
								</div>
							</div>

						</div><!-- /.wpsp-color-grid -->
					</div>
				</div>

				<!-- ===== Submit bar ===== -->
				<div class="wpsp-submit-bar">
					<?php submit_button( null, 'primary', 'submit', false ); ?>
				</div>

			</form>

			<hr class="wpsp-section-divider">

			<!-- ===== Test & Credit section (outside form intentionally) ===== -->
			<div class="wpsp-card">
				<div class="wpsp-card-header">
					<div class="wpsp-card-header-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
						</svg>
					</div>
					<h2 class="wpsp-card-title"><?php esc_html_e( 'تست و استعلام', 'wp-sms-panel' ); ?></h2>
				</div>
				<div class="wpsp-card-body">
					<div class="wpsp-field-row">
						<div class="wpsp-field-label">
							<label for="wpsp-test-phone"><?php esc_html_e( 'ارسال پیامک تست', 'wp-sms-panel' ); ?></label>
						</div>
						<div class="wpsp-field-control">
							<div class="wpsp-test-row">
								<input type="tel" id="wpsp-test-phone" placeholder="09xxxxxxxxx" class="regular-text" dir="ltr">
								<button type="button" class="button" id="wpsp-test-btn"><?php esc_html_e( 'ارسال تست', 'wp-sms-panel' ); ?></button>
								<button type="button" class="button" id="wpsp-credit-btn"><?php esc_html_e( 'استعلام اعتبار', 'wp-sms-panel' ); ?></button>
							</div>
							<p class="description wpsp-test-result" id="wpsp-test-result"></p>
							<span class="description"><?php esc_html_e( 'ابتدا تنظیمات را ذخیره کنید، سپس تست بزنید.', 'wp-sms-panel' ); ?></span>
						</div>
					</div>
				</div>
			</div>

		</div><!-- /.wrap.wpsp-admin -->
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX (admin)
	 * ------------------------------------------------------------------- */

	public function ajax_test() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'wp-sms-panel' ) ) );
		}
		check_ajax_referer( 'wp_sms_panel_admin', 'nonce' );

		$phone = WP_SMS_Panel_SMS::normalize_phone( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' );
		if ( is_wp_error( $phone ) ) {
			wp_send_json_error( array( 'message' => $phone->get_error_message() ) );
		}

		$result = WP_SMS_Panel_SMS::send( $phone, __( 'پیامک تست از پنل تنظیمات WP SMS Panel.', 'wp-sms-panel' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$settings = WP_SMS_Panel_SMS::settings();
		$msg      = ( 'dev' === $settings['active_provider'] )
			? __( 'حالت توسعه: پیامک ارسال نشد ولی در error_log ثبت شد.', 'wp-sms-panel' )
			: __( 'پیامک تست با موفقیت ارسال شد.', 'wp-sms-panel' );
		wp_send_json_success( array( 'message' => $msg ) );
	}

	public function ajax_credit() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'wp-sms-panel' ) ) );
		}
		check_ajax_referer( 'wp_sms_panel_admin', 'nonce' );

		$credit = WP_SMS_Panel_SMS::credit();
		if ( is_wp_error( $credit ) ) {
			wp_send_json_error( array( 'message' => $credit->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => sprintf( __( 'اعتبار باقی‌مانده: %s', 'wp-sms-panel' ), $credit ) ) );
	}
}
