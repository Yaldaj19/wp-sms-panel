<?php
/**
 * WooCommerce order — «ارسال پیامک پیگیری مرسوله» metabox.
 *
 * Adds a side metabox on the order-edit screen (HPOS + legacy CPT) that lets a
 * shop manager send a tracking SMS to the customer through the active gateway.
 * Replaces the per-theme handler that previously lived in each YJ19 theme.
 *
 * @package WP_SMS_Panel
 */

defined( 'ABSPATH' ) || exit;

class WP_SMS_Panel_WC_Order_SMS {

	/**
	 * Singleton.
	 *
	 * @var WP_SMS_Panel_WC_Order_SMS|null
	 */
	private static $instance = null;

	/**
	 * @return WP_SMS_Panel_WC_Order_SMS
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'wp_ajax_wp_sms_panel_order_sms', array( $this, 'ajax_send' ) );
	}

	/**
	 * Order-edit screen ids: legacy CPT + HPOS.
	 *
	 * @return string[]
	 */
	private function screen_ids() {
		$ids = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Register the side metabox on both order screens.
	 */
	public function register_metabox() {
		if ( ! function_exists( 'wc_get_order' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		foreach ( $this->screen_ids() as $screen ) {
			add_meta_box(
				'wp_sms_panel_order_sms',
				__( 'ارسال پیامک پیگیری مرسوله', 'wp-sms-panel' ),
				array( $this, 'render_metabox' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Metabox HTML.
	 *
	 * @param WP_Post|WC_Order $order Current order (post on legacy, WC_Order on HPOS).
	 */
	public function render_metabox( $order ) {
		if ( $order instanceof WP_Post ) {
			$order_id = (int) $order->ID;
		} elseif ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			$order_id = (int) $order->get_id();
		} else {
			$order_id = 0;
		}

		wp_nonce_field( 'wp_sms_panel_order_sms', 'wp_sms_panel_order_sms_nonce' );
		?>
		<div class="wpsp-order-sms">
			<textarea id="wpsp_order_sms_message" style="width:100%;height:80px;margin-bottom:8px;"
				placeholder="<?php esc_attr_e( 'فقط کد پیگیری را وارد نمایید', 'wp-sms-panel' ); ?>"></textarea>
			<div style="text-align:left;">
				<button type="button" class="button button-primary" id="wpsp_order_sms_send" style="width:100%;">
					<?php esc_html_e( 'ارسال', 'wp-sms-panel' ); ?>
				</button>
				<span class="spinner" style="float:none;margin:4px 0;"></span>
			</div>
			<div id="wpsp_order_sms_response" style="margin-top:10px;"></div>
		</div>
		<script type="text/javascript">
			jQuery(function ($) {
				$('#wpsp_order_sms_send').on('click', function () {
					var btn = $(this), spinner = btn.next('.spinner'), out = $('#wpsp_order_sms_response');
					btn.prop('disabled', true);
					spinner.addClass('is-active');
					out.html('');
					$.post(ajaxurl, {
						action: 'wp_sms_panel_order_sms',
						order_id: '<?php echo esc_js( $order_id ); ?>',
						message: $('#wpsp_order_sms_message').val(),
						nonce: $('#wp_sms_panel_order_sms_nonce').val()
					}, function (r) {
						var cls = (r && r.success) ? 'success' : 'error';
						out.html('<div class="notice notice-' + cls + ' inline"><p>' + (r && r.data ? r.data : '') + '</p></div>');
					}).fail(function () {
						out.html('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'ارسال پیامک ناموفق بود.', 'wp-sms-panel' ) ); ?></p></div>');
					}).always(function () {
						btn.prop('disabled', false);
						spinner.removeClass('is-active');
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * AJAX: send the tracking SMS through the active gateway.
	 */
	public function ajax_send() {
		check_ajax_referer( 'wp_sms_panel_order_sms', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( 'دسترسی مجاز نیست.', 'wp-sms-panel' ) );
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			wp_send_json_error( __( 'ووکامرس فعال نیست.', 'wp-sms-panel' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $order_id || '' === $message ) {
			wp_send_json_error( __( 'شماره سفارش یا متن پیام نامعتبر است.', 'wp-sms-panel' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'سفارش یافت نشد.', 'wp-sms-panel' ) );
		}

		$phone = (string) $order->get_billing_phone();
		if ( '' === trim( $phone ) ) {
			wp_send_json_error( __( 'شماره تماس مشتری ثبت نشده است.', 'wp-sms-panel' ) );
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$body = ( '' !== $name ) ? ( $name . "\n" . $message ) : $message;

		$result = WP_SMS_Panel_SMS::send( $phone, $body );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'پیام با موفقیت ارسال شد.', 'wp-sms-panel' ) );
	}
}
