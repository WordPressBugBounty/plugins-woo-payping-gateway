<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PayPing data box on the order admin screen.
 *
 * Payment events are stored in order meta instead of generic order notes,
 * so PayPing data stays separate from data written by other gateways and
 * the box can later host actions such as refund or reverse.
 */
class Payping_Order_Panel {

    const EVENTS_META     = '_payping_events';
    const LAST_ERROR_META = '_payping_last_error';
    const MAX_EVENTS      = 30;

    public static function init() {
        if ( is_admin() ) {
            add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
            add_action( 'admin_post_woo_payping_reverify', array( __CLASS__, 'handle_reverify' ) );
            add_action( 'admin_post_woo_payping_refund_sync', array( __CLASS__, 'handle_refund_sync' ) );
            add_action( 'wp_ajax_woo_payping_refund_check', array( __CLASS__, 'ajax_refund_check' ) );
            add_action( 'wp_ajax_woo_payping_refund_quote', array( __CLASS__, 'ajax_refund_quote' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
            add_action( 'admin_notices', array( __CLASS__, 'print_reverify_notice' ) );
            add_action( 'admin_notices', array( __CLASS__, 'print_refund_notice' ) );
        }

        // Refunds can also be booked from REST or other plugins, so
        // the manual-refund note must not depend on an admin request.
        add_action( 'woocommerce_order_partially_refunded', array( __CLASS__, 'note_manual_refund' ), 10, 2 );
        add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'note_manual_refund' ), 10, 2 );
    }

    /**
     * Timeline note for refunds booked without the refund service:
     * the money did not move at PayPing, so the shop owner must
     * return it from the PayPing panel separately. Gateway refunds
     * are already logged by process_refund and skipped here.
     */
    public static function note_manual_refund( $order_id, $refund_id ) {
        $order  = wc_get_order( $order_id );
        $refund = $refund_id ? wc_get_order( $refund_id ) : false;

        if ( ! $order || ! $refund || 'WC_payping' !== $order->get_payment_method() ) {
            return;
        }
        if ( $refund->get_refunded_payment() ) {
            return;
        }

        $event = sprintf(
            'استرداد دستی به مبلغ %s تومان ثبت شد | بازگشت وجه از طریق پی‌پینگ انجام نشده و در صورت نیاز باید جداگانه از پنل پی‌پینگ انجام شود',
            number_format_i18n( (float) $refund->get_amount() )
        );
        self::log_event( $order, 'refund', $event );
    }

    /**
     * Append a payment event to the order timeline.
     *
     * Error events also refresh the stored last-error snapshot, which
     * keeps the PayPing trace id available for support requests.
     */
    public static function log_event( $order, $type, $message, $extra = array() ) {
        if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) {
            return;
        }

        $events   = $order->get_meta( self::EVENTS_META );
        $events   = is_array( $events ) ? $events : array();
        $events[] = array(
            'type'    => sanitize_key( $type ),
            'message' => wp_strip_all_tags( (string) $message ),
            'time'    => time(),
        );

        $order->update_meta_data( self::EVENTS_META, array_slice( $events, -self::MAX_EVENTS ) );

        if ( 'error' === $type || 'failed' === $type ) {
            $order->update_meta_data( self::LAST_ERROR_META, array(
                'message'  => wp_strip_all_tags( (string) $message ),
                'trace_id' => isset( $extra['trace_id'] ) ? sanitize_text_field( (string) $extra['trace_id'] ) : '',
                'time'     => time(),
            ) );
        } elseif ( $order->get_meta( self::LAST_ERROR_META ) ) {
            // A later successful step means the stored problem is
            // resolved: keep the row aligned with the real state.
            $order->delete_meta_data( self::LAST_ERROR_META );
        }

        $order->save();
    }

    public static function register_meta_box() {
        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

        $order = self::get_current_order();
        if ( ! $order || 'WC_payping' !== $order->get_payment_method() ) {
            return;
        }

        add_meta_box( 'payping_order_panel', 'داده‌های درگاه پی‌پینگ', array( __CLASS__, 'render' ), $screen, 'side', 'high' );
    }

    public static function render( $post = null ) {
        $order = ( $post instanceof WP_Post ) ? wc_get_order( $post->ID ) : self::get_current_order();
        if ( ! $order || 'WC_payping' !== $order->get_payment_method() ) {
            return;
        }

        $events = $order->get_meta( self::EVENTS_META );
        $events = is_array( $events ) ? $events : array();

        self::print_styles();
        ?>
        <div class="payping-order-panel">
            <ul class="payping-rows">
                <?php foreach ( self::get_rows( $order, $events ) as $row ) : ?>
                    <?php
                    $classes = array( 'value' );
                    if ( ! empty( $row['class'] ) ) {
                        $classes[] = $row['class'];
                    }
                    if ( ! empty( $row['ltr'] ) ) {
                        $classes[] = 'ltr';
                    }
                    ?>
                    <li>
                        <span class="label"><?php echo esc_html( $row['label'] ); ?></span>
                        <span class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
                            <?php echo esc_html( $row['value'] ); ?>
                            <?php if ( ! empty( $row['copy'] ) ) : ?>
                                <button type="button" class="button button-small payping-copy" data-copy="<?php echo esc_attr( $row['value'] ); ?>">کپی</button>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php self::render_reverify_section( $order ); ?>
            <?php self::render_refund_section( $order ); ?>
            <?php if ( $events ) : ?>
                <div class="payping-section-title">رویدادها</div>
                <ul class="payping-events">
                    <?php foreach ( array_reverse( $events ) as $event ) : ?>
                        <li class="payping-ev-<?php echo esc_attr( $event['type'] ); ?>">
                            <span class="payping-ev-time">
                                <?php echo esc_html( self::format_time( (int) $event['time'] ) ); ?>
                                <span class="payping-ev-badge"><?php echo esc_html( self::event_badge_label( $event['type'] ) ); ?></span>
                            </span>
                            <span class="payping-ev-text"><?php echo esc_html( $event['message'] ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
        self::print_scripts();
    }

    /**
     * Short Persian labels for the event timeline badges.
     */
    private static function event_badge_label( $type ) {
        $labels = array(
            'created'    => 'ثبت',
            'verified'   => 'تأیید',
            'duplicate'  => 'تکراری',
            'failed'     => 'ناموفق',
            'error'      => 'خطا',
            'cancelled'  => 'انصراف',
            'invalidated' => 'ابطال',
            'refund'     => 'استرداد',
        );

        return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
    }

    /**
     * The re-check button for unpaid orders whose return trip from the
     * gateway was lost or failed. Needs both the payment code and the
     * reference id the gateway handed back.
     */
    private static function render_reverify_section( $order ) {
        // A refunded order is not "paid" for WooCommerce, so it would
        // slip through the is_paid guard below: once a refund exists,
        // the only refresh button is the refund one.
        if ( $order->has_status( 'refunded' ) || $order->get_meta( '_payping_refund_code' ) ) {
            return;
        }

        if ( $order->is_paid() || 'WC_payping' !== $order->get_payment_method() ) {
            return;
        }

        $pay_code = $order->get_meta( '_payping_payCode' );
        if ( ! $pay_code ) {
            return;
        }

        $refid = $order->get_meta( '_payping_refid' );
        if ( ! $refid ) {
            echo '<p class="payping-reverify-hint">کد پیگیری درگاه برای این پرداخت ثبت نشده و استعلام مجدد ممکن نیست.</p>';
            return;
        }

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=woo_payping_reverify&order=' . $order->get_id() ),
            'woo_payping_reverify_' . $order->get_id()
        );

        echo '<p class="payping-reverify"><a href="' . esc_url( $url ) . '" class="button">استعلام مجدد پرداخت</a></p>';
    }

    /**
     * Refund state of a paid order: the stored snapshot of the last check
     * plus a button to refresh it against the PayPing refund service.
     */
    private static function render_refund_section( $order ) {
        // Refunded orders are not "paid" for WooCommerce either, but
        // they still need the refund state button.
        if ( ! $order->is_paid() && ! $order->get_meta( '_payping_refund_code' ) ) {
            return;
        }

        if ( 'WC_payping' !== $order->get_payment_method() ) {
            return;
        }

        $refund_code = $order->get_meta( '_payping_refund_code' );
        if ( ! $refund_code ) {
            return;
        }

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=woo_payping_refund_sync&order=' . $order->get_id() ),
            'woo_payping_refund_sync_' . $order->get_id()
        );

        echo '<div class="payping-section-title">استرداد</div>';
        echo '<p class="payping-reverify"><a href="' . esc_url( $url ) . '" class="button">بروزرسانی وضعیت استرداد</a></p>';
    }

    /**
     * admin-post handler behind the re-check button.
     */
    public static function handle_reverify() {
        $order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
        check_admin_referer( 'woo_payping_reverify_' . $order_id );

        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'شما اجازه انجام این عملیات را ندارید.' );
        }

        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! is_a( $order, 'WC_Order' ) || 'WC_payping' !== $order->get_payment_method() ) {
            wp_die( 'سفارش نامعتبر است.' );
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $gateways['WC_payping'] ) ) {
            wp_die( 'درگاه پی‌پینگ در دسترس نیست.' );
        }

        $outcome = $gateways['WC_payping']->process_reverify( $order );

        wp_safe_redirect( add_query_arg(
            array(
                'payping_reverify' => $outcome['success'] ? 'ok' : 'fail',
                'payping_msg'      => rawurlencode( $outcome['message'] ),
            ),
            $order->get_edit_order_url()
        ) );
        exit;
    }

    /**
     * admin-post handler behind the refund state button on the order page.
     */
    public static function handle_refund_sync() {
        $order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
        check_admin_referer( 'woo_payping_refund_sync_' . $order_id );

        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'شما اجازه انجام این عملیات را ندارید.' );
        }

        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! is_a( $order, 'WC_Order' ) || 'WC_payping' !== $order->get_payment_method() ) {
            wp_die( 'سفارش نامعتبر است.' );
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $gateways['WC_payping'] ) ) {
            wp_die( 'درگاه پی‌پینگ در دسترس نیست.' );
        }

        $outcome = $gateways['WC_payping']->process_refund_status( $order );

        wp_safe_redirect( add_query_arg(
            array(
                'payping_refund' => $outcome['success'] ? 'ok' : 'fail',
                'payping_msg'    => rawurlencode( $outcome['message'] ),
            ),
            $order->get_edit_order_url()
        ) );
        exit;
    }

    /**
     * Ajax behind the readiness check button on the settings card:
     * token permission, service initialization and wallet balance.
     */
    public static function ajax_refund_check() {
        check_ajax_referer( 'woo_payping_refund_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'شما اجازه انجام این عملیات را ندارید.' ) );
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $gateways['WC_payping'] ) ) {
            wp_send_json_error( array( 'message' => 'درگاه پی‌پینگ در دسترس نیست.' ) );
        }

        $outcome = $gateways['WC_payping']->check_refund_service();

        // Feed the settings card until the next check. The cache also
        // drops itself whenever a refund moves the wallet.
        set_transient( 'woo_payping_refund_status', $outcome + array( 'time' => time() ), 30 * MINUTE_IN_SECONDS );

        wp_send_json_success( array( 'html' => self::refund_status_card() ) );
    }

    /**
     * Ajax behind the admin refund confirmation: the amount that goes
     * back to the customer plus the wage drawn from the wallet.
     */
    public static function ajax_refund_quote() {
        check_ajax_referer( 'woo_payping_refund_admin', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'شما اجازه انجام این عملیات را ندارید.' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! is_a( $order, 'WC_Order' ) || 'WC_payping' !== $order->get_payment_method() ) {
            wp_send_json_error( array( 'message' => 'سفارش نامعتبر است.' ) );
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $gateways['WC_payping'] ) || ! $gateways['WC_payping']->supports( 'refunds' ) ) {
            wp_send_json_error( array( 'message' => 'سرویس استرداد وجه پی‌پینگ فعال نیست.' ) );
        }

        $amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
        $quote  = $gateways['WC_payping']->get_refund_wage_quote( $order, $amount );

        // An empty box or a non-positive entry blocks the dialog with
        // its own note instead of WooCommerce's English alert.
        if ( ! empty( $quote['invalid'] ) ) {
            wp_send_json_success( array(
                'invalid'         => true,
                'empty'           => ! empty( $quote['empty'] ),
                'fully'           => (bool) $quote['fully'],
                'total_label'     => $quote['total_label'],
                'remaining_label' => $quote['remaining_label'],
            ) );
        }

        // An amount above the refundable rest blocks the dialog.
        if ( ! empty( $quote['over'] ) ) {
            wp_send_json_success( array(
                'over'            => true,
                'fully'           => (bool) $quote['fully'],
                'entered_label'   => isset( $quote['entered_label'] ) ? $quote['entered_label'] : '',
                'total_label'     => $quote['total_label'],
                'remaining_label' => $quote['remaining_label'],
            ) );
        }

        wp_send_json_success( array(
            'over'           => false,
            'fully'          => (bool) $quote['fully'],
            'total_label'    => $quote['total_label'],
            'remaining_label' => $quote['remaining_label'],
            'full'           => $quote['full'],
            'amount'         => $quote['amount'],
            'wage'           => $quote['wage'],
            'amount_label'   => number_format_i18n( $quote['amount'] ) . ' تومان',
            'wage_label'     => null === $quote['wage'] ? '' : number_format_i18n( $quote['wage'] ) . ' تومان',
            'debit_label'    => null === $quote['wage'] ? '' : number_format_i18n( $quote['amount'] + $quote['wage'] ) . ' تومان',
        ) );
    }

    /**
     * Status card of the refund system for the gateway settings page.
     * Server-rendered, so the ajax refresh swaps it in one piece.
     */
    public static function refund_status_card() {
        $status = get_transient( 'woo_payping_refund_status' );

        // Transients written by an earlier build carry no state key.
        if ( is_array( $status ) && ! isset( $status['state'] ) && isset( $status['message'] ) ) {
            $status['state'] = ! empty( $status['ok'] ) ? 'ok' : 'error';
        }

        $state  = is_array( $status ) && isset( $status['state'] ) ? $status['state'] : 'none';
        $ok     = 'ok' === $state;
        $lines  = array();
        $note   = '';

        if ( 'none' === $state ) {
            $lines[] = 'وضعیت سرویس استرداد بررسی نشده است.';
            $note    = 'برای بررسی دسترسی توکن و موجودی کیف پول استرداد، دکمه زیر را بزنید.';
        } elseif ( $ok ) {
            $lines[] = ! empty( $status['message'] ) ? $status['message'] : 'سرویس استرداد پی‌پینگ فعال است.';
            if ( isset( $status['balance'] ) && null !== $status['balance'] ) {
                $lines[] = 'موجودی قابل استفاده کیف پول استرداد: ' . number_format_i18n( (float) $status['balance'] ) . ' تومان';
            }
            if ( ! empty( $status['wage'] ) ) {
                $lines[] = 'کارمزد استرداد: ' . $status['wage'];
            }
            $note = 'شارژ کیف پول باید مبلغ استرداد به‌علاوه کارمزد را پوشش دهد.';
        } else {
            $lines[] = ! empty( $status['message'] ) ? $status['message'] : 'سرویس استرداد در دسترس نیست.';
        }

        $tone   = $ok ? 'ok' : ( 'none' === $state ? 'none' : 'error' );
        $labels = array(
            'ok'    => 'فعال',
            'none'  => 'بررسی نشده',
            'error' => 'نیاز به بررسی',
        );

        ob_start();
        ?>
        <div class="payping-refund-card payping-refund-card--<?php echo esc_attr( $tone ); ?>">
            <div class="payping-refund-card-head">
                <span class="payping-refund-dot" aria-hidden="true"></span>
                <span class="payping-refund-state"><?php echo esc_html( $labels[ $tone ] ); ?></span>
                <button type="button" class="button button-small payping-refund-check">بررسی وضعیت استرداد</button>
            </div>
            <ul class="payping-refund-card-lines">
                <?php foreach ( $lines as $line ) : ?>
                    <li><?php echo esc_html( $line ); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ( $note ) : ?>
                <p class="payping-refund-note"><?php echo esc_html( $note ); ?></p>
            <?php endif; ?>
            <?php if ( is_array( $status ) && ! empty( $status['time'] ) ) : ?>
                <p class="payping-refund-time">آخرین بررسی: <?php echo esc_html( self::format_time( (int) $status['time'] ) ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Refund admin assets, loaded only on the two screens that use
     * them: the gateway settings page and a PayPing order edit page.
     */
    public static function enqueue_admin_assets( $hook ) {
        // WooCommerce lowercases the section in its settings links, so
        // the comparison must not care about the gateway id casing.
        $is_settings = 'woocommerce_page_wc-settings' === $hook
            && isset( $_GET['tab'], $_GET['section'] )
            && 'checkout' === $_GET['tab']
            && 'wc_payping' === sanitize_title( wp_unslash( $_GET['section'] ) );

        $order = null;
        if ( in_array( $hook, array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ), true ) ) {
            $order = self::get_current_order();
        }
        $is_order_edit = $order && 'WC_payping' === $order->get_payment_method();

        if ( ! $is_settings && ! $is_order_edit ) {
            return;
        }

        // File mtime keeps the cached browser copy fresh while iterating.
        $version = (string) filemtime( WOO_GPPDIR . 'assets/admin.js' );
        wp_enqueue_style( 'payping-refund-admin', WOO_GPPDU . 'assets/admin.css', array(), $version );
        wp_enqueue_script( 'payping-refund-admin', WOO_GPPDU . 'assets/admin.js', array(), $version, true );

        // The confirmation dialog only belongs to a refundable order.
        $confirm_enabled = false;
        if ( $is_order_edit ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if ( isset( $gateways['WC_payping'] ) ) {
                $confirm_enabled = $gateways['WC_payping']->supports( 'refunds' );
            }
        }

        wp_localize_script( 'payping-refund-admin', 'paypingRefundAdmin', array(
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'woo_payping_refund_admin' ),
            'confirmEnabled' => (bool) $confirm_enabled,
            'orderId'        => $order ? $order->get_id() : 0,
            'i18n'           => array(
                'checking'      => 'در حال بررسی...',
                'checkFailed'   => 'بررسی وضعیت سرویس استرداد وجه ناموفق بود.',
                'networkError'  => 'خطا در ارتباط با سرور؛ دوباره تلاش کنید.',
                'title'         => 'تایید استرداد از طریق پی‌پینگ',
                'orderLabel'    => 'مبلغ کل سفارش',
                'amountLabel'   => 'مبلغ بازگشتی به مشتری',
                'wageLabel'     => 'کارمزد استرداد (از کیف پول شما)',
                'totalLabel'    => 'جمع کسر از کیف پول استرداد',
                'loading'       => 'در حال دریافت...',
                'wagePerTariff' => 'طبق تعرفه پی‌پینگ',
                'overNote'      => 'مبلغ واردشده بیش از مبلغ قابل استرداد این سفارش است؛ مبلغ را اصلاح کنید.',
                'fullyNote'     => 'این سفارش قبلاً به‌طور کامل استرداد شده و مبلغ قابل استردادی برای آن باقی نمانده است.',
                'emptyNote'     => 'مبلغ استرداد را وارد کنید تا ادامه دهید.',
                'invalidNote'   => 'مبلغ استرداد باید بیش از صفر باشد؛ برای بازگشت کالا به انبار بدون بازگشت پول، از دکمه استرداد دستی استفاده کنید.',
                'maxLabel'      => 'حداکثر قابل استرداد',
                'note'          => 'مبلغ به کارت پرداخت‌کننده بازمی‌گردد و کارمزد از کیف پول استرداد شما کسر می‌شود. پس از تایید، این عملیات قابل بازگشت نیست.',
                'confirm'       => 'تایید استرداد',
                'cancel'        => 'انصراف',
            ),
        ) );
    }

    /**
     * Result banner for the refund state refresh and the refund check.
     */
    public static function print_refund_notice() {
        if ( empty( $_GET['payping_refund'] ) || empty( $_GET['payping_msg'] ) ) {
            return;
        }

        $ok      = 'ok' === sanitize_text_field( wp_unslash( $_GET['payping_refund'] ) );
        $message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['payping_msg'] ) ) );

        echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    /**
     * Result banner the admin lands on after the re-check.
     */
    public static function print_reverify_notice() {
        if ( empty( $_GET['payping_reverify'] ) || empty( $_GET['payping_msg'] ) ) {
            return;
        }

        $ok      = 'ok' === sanitize_text_field( wp_unslash( $_GET['payping_reverify'] ) );
        $message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['payping_msg'] ) ) );

        echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    private static function print_scripts() {
        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;
        ?>
        <script>
            (function () {
                var panel = document.querySelector('.payping-order-panel');
                if (!panel) {
                    return;
                }
                panel.addEventListener('click', function (event) {
                    var button = event.target.closest('.payping-copy');
                    if (!button) {
                        return;
                    }
                    var text = button.getAttribute('data-copy');
                    var done = function () {
                        var original = button.textContent;
                        button.textContent = 'کپی شد';
                        setTimeout(function () { button.textContent = original; }, 1200);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done);
                    } else {
                        var input = document.createElement('textarea');
                        input.value = text;
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                        done();
                    }
                });
            })();
        </script>
        <?php
    }

    /**
     * Rows shown at the top of the box. The list is filterable so other
     * code can add fields without touching the template.
     */
    private static function get_rows( $order, $events ) {
        $rows = array( self::get_status_row( $order, $events ) );

        $pay_code = $order->get_meta( '_payping_payCode' );
        if ( $pay_code ) {
            $rows[] = array( 'label' => 'کد پرداخت', 'value' => $pay_code, 'ltr' => true, 'copy' => true );
        }

        $amount = $order->get_meta( '_payping_amount' );
        if ( '' !== $amount && null !== $amount ) {
            $rows[] = array( 'label' => 'مبلغ ارسالی', 'value' => number_format_i18n( (int) $amount ) . ' تومان' );
        }

        $transaction_id = $order->get_transaction_id();
        if ( $transaction_id ) {
            $rows[] = array( 'label' => 'شماره تراکنش', 'value' => $transaction_id, 'ltr' => true, 'copy' => true );
        }

        $card_number = $order->get_meta( 'payping_payment_card_number' );
        if ( $card_number && '-' !== $card_number ) {
            $rows[] = array( 'label' => 'شماره کارت', 'value' => self::format_card_number( $card_number ), 'ltr' => true );
        }

        $refund = $order->get_meta( '_payping_refund_info' );
        if ( ! empty( $refund['state'] ) ) {
            $state_label = ! empty( $refund['state_label'] ) ? $refund['state_label'] : $refund['state'];
            $rows[] = array(
                'label' => 'استرداد',
                'value' => sprintf( '%s (%s تومان)', $state_label, number_format_i18n( (float) $refund['refund_amount'] ) ),
                'class' => 6 === (int) $refund['state'] ? 'payping-st-ok' : 'payping-st-warn',
            );

            if ( ! empty( $refund['bank_tracking'] ) ) {
                $rows[] = array( 'label' => 'کد پیگیری بانکی', 'value' => $refund['bank_tracking'], 'ltr' => true, 'copy' => true );
            }
        }

        // The stored snapshot only speaks for the order while the newest
        // event is still the failure: once a later step succeeded, the
        // row would describe a problem that no longer exists.
        $last_error = $order->get_meta( self::LAST_ERROR_META );
        $last_event = is_array( $events ) && $events ? end( $events ) : array();
        if ( ! empty( $last_error['message'] ) && isset( $last_event['type'] ) && in_array( $last_event['type'], array( 'error', 'failed' ), true ) ) {
            $rows[] = array( 'label' => 'آخرین خطا', 'value' => $last_error['message'] );

            if ( ! empty( $last_error['trace_id'] ) ) {
                $rows[] = array( 'label' => 'Trace ID', 'value' => $last_error['trace_id'], 'ltr' => true, 'copy' => true );
            }
        }

        if ( isset( $last_event['time'] ) ) {
            $rows[] = array( 'label' => 'آخرین بروزرسانی', 'value' => self::format_time( (int) $last_event['time'] ) );
        }

        return apply_filters( 'woo_payping_order_panel_rows', $rows, $order );
    }

    /**
     * Show card numbers from the gateway in readable groups. Masked values
     * (containing stars) already carry their own separators.
     */
    private static function format_card_number( $card_number ) {
        $card_number = (string) $card_number;

        if ( false === strpos( $card_number, '*' ) && preg_match( '/^\d{12,19}$/', $card_number ) ) {
            return trim( chunk_split( $card_number, 4, '-' ), '-' );
        }

        return $card_number;
    }

    /**
     * Payment status derived from the order state and the last event.
     */
    private static function get_status_row( $order, $events ) {
        if ( $order->is_paid() ) {
            return array( 'label' => 'وضعیت', 'value' => 'پرداخت شده', 'class' => 'payping-st-ok' );
        }

        $last_type = $events ? end( $events )['type'] : '';

        if ( 'error' === $last_type || 'failed' === $last_type ) {
            return array( 'label' => 'وضعیت', 'value' => 'ناموفق', 'class' => 'payping-st-fail' );
        }

        if ( 'cancelled' === $last_type ) {
            return array( 'label' => 'وضعیت', 'value' => 'انصراف از پرداخت', 'class' => 'payping-st-warn' );
        }

        if ( $order->get_meta( '_payping_payCode' ) ) {
            return array( 'label' => 'وضعیت', 'value' => 'در انتظار پرداخت', 'class' => 'payping-st-pending' );
        }

        return array( 'label' => 'وضعیت', 'value' => '—' );
    }

    private static function format_time( $timestamp ) {
        return wp_date( 'Y/m/d H:i', $timestamp );
    }

    /**
     * Order being edited on the current admin screen.
     */
    private static function get_current_order() {
        // HPOS order screens pass the order id in the query string,
        // classic order screens expose the post global.
        $order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $order_id && ! empty( $GLOBALS['post']->ID ) ) {
            $order_id = (int) $GLOBALS['post']->ID;
        }

        return $order_id ? wc_get_order( $order_id ) : false;
    }

    private static function print_styles() {
        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;
        ?>
        <style>
            .payping-order-panel ul { margin: 0; padding: 0; list-style: none; }
            .payping-order-panel .payping-rows li { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
            .payping-order-panel .payping-rows li:last-child { border-bottom: 0; }
            .payping-order-panel .payping-rows .label { color: #646970; flex-shrink: 0; }
            .payping-order-panel .payping-rows .value { text-align: left; word-break: break-word; }
            .payping-order-panel .payping-rows .value.ltr { direction: ltr; unicode-bidi: embed; }
            .payping-order-panel .payping-st-ok { color: #00a32a; font-weight: 600; }
            .payping-order-panel .payping-st-fail { color: #d63638; font-weight: 600; }
            .payping-order-panel .payping-st-warn { color: #996800; font-weight: 600; }
            .payping-order-panel .payping-st-pending { color: #646970; }
            .payping-order-panel .payping-section-title { margin: 12px 0 6px; color: #1d2327; font-weight: 600; }
            .payping-order-panel .payping-events li { position: relative; margin: 0; padding: 0 16px 12px 0; border-right: 2px solid #e2e4e7; }
            .payping-order-panel .payping-events li:last-child { border-right-color: transparent; padding-bottom: 2px; }
            .payping-order-panel .payping-events li::before { content: ''; position: absolute; right: -5px; top: 4px; width: 8px; height: 8px; border-radius: 50%; background: #8c8f94; }
            .payping-order-panel .payping-events li.payping-ev-verified::before,
            .payping-order-panel .payping-events li.payping-ev-duplicate::before { background: #00a32a; }
            .payping-order-panel .payping-events li.payping-ev-error::before,
            .payping-order-panel .payping-events li.payping-ev-failed::before { background: #d63638; }
            .payping-order-panel .payping-events li.payping-ev-cancelled::before { background: #dba617; }
            .payping-order-panel .payping-events li.payping-ev-refund::before { background: #2271b1; }
            .payping-order-panel .payping-ev-time { display: block; margin-bottom: 2px; font-size: 11px; color: #8c8f94; }
            .payping-order-panel .payping-ev-text { display: block; }
            .payping-order-panel .payping-ev-badge { display: inline-block; margin-right: 6px; padding: 0 6px; border-radius: 3px; font-size: 10px; line-height: 16px; background: #f0f0f1; color: #50575e; }
            .payping-order-panel li.payping-ev-verified .payping-ev-badge,
            .payping-order-panel li.payping-ev-duplicate .payping-ev-badge { background: #edfaef; color: #00a32a; }
            .payping-order-panel li.payping-ev-error .payping-ev-badge,
            .payping-order-panel li.payping-ev-failed .payping-ev-badge { background: #fcf0f1; color: #d63638; }
            .payping-order-panel li.payping-ev-cancelled .payping-ev-badge { background: #fcf9e8; color: #996800; }
            .payping-order-panel li.payping-ev-refund .payping-ev-badge { background: #f0f6fc; color: #2271b1; }
            .payping-order-panel .payping-copy { margin-right: 6px; padding: 0 6px; line-height: 20px; height: 20px; font-size: 10px; vertical-align: middle; cursor: pointer; }
            .payping-order-panel .payping-reverify { margin: 10px 0 0; }
            .payping-order-panel .payping-reverify .button { width: 100%; text-align: center; justify-content: center; }
            .payping-order-panel .payping-reverify-hint { margin: 10px 0 0; font-size: 11px; color: #8c8f94; }
        </style>
        <?php
    }
}
