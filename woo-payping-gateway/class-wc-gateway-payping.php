<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'WC_payping' ) ) {
    
    class WC_payping extends WC_Payment_Gateway {
        
        private $baseurl = 'https://api.payping.ir/v3';
        private $refund_baseurl;
        private $paypingToken;
        private $success_massage;
        private $failed_massage;

        public function __construct() {
            $this->id                 = 'WC_payping';
            $this->method_title       = __( 'پرداخت از طریق درگاه پی‌پینگ', 'woo-payping-gateway' );
            $this->method_description = __( 'تنظیمات درگاه پرداخت پی‌پینگ برای افزونه فروشگاه ساز ووکامرس', 'woo-payping-gateway' );
            $this->icon               = apply_filters( 'woo_payping_logo', WOO_GPPDU . '/assets/images/logo.png' );
            $this->has_fields         = false;
            
            $this->init_form_fields();
            $this->init_settings();

            $checkserver = $this->settings['ioserver'] ?? 'no';
            if ( $checkserver == 'yes' ) {
                $this->baseurl = 'https://api.payping.io/v3';
            }

            // The refund service lives under /v1/refund on the same host.
            $this->refund_baseurl = str_replace( '/v3', '', $this->baseurl ) . '/v1/refund';

            // The refund button only appears when the merchant turned the
            // refund system on, so refunds never fail by surprise.
            $this->supports = array( 'products' );
            if ( 'yes' === ( $this->settings['refund_enabled'] ?? 'no' ) ) {
                $this->supports[] = 'refunds';
            }
            
            $this->title          = $this->settings['title'] ?? __( 'پرداخت از طریق پی‌پینگ', 'woo-payping-gateway' );
            $this->description    = $this->settings['description'] ?? '';
            $this->paypingToken   = $this->settings['paypingToken'] ?? '';
            $this->success_massage= $this->settings['success_massage'] ?? '';
            $this->failed_massage = $this->settings['failed_massage'] ?? '';

            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            } else {
                add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
            }

            add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'payment_receipt_page' ) );
            add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'Return_from_payping_Gateway' ) );
        }

        public function init_form_fields() {
            $this->form_fields = apply_filters( 'WC_payping_Config', array(
                'base_confing' => array(
                    'title'       => __( 'تنظیمات پایه ای', 'woo-payping-gateway' ),
                    'type'        => 'title',
                    'description' => '',
                ),
                'enabled' => array(
                    'title'       => __( 'فعالسازی/غیرفعالسازی', 'woo-payping-gateway' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'فعالسازی درگاه پی‌پینگ', 'woo-payping-gateway' ),
                    'description' => __( 'برای فعالسازی درگاه پرداخت پی‌پینگ باید چک باکس را تیک بزنید', 'woo-payping-gateway' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'ioserver' => array(
                    'title'       => __( 'سرور خارج', 'woo-payping-gateway' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'اتصال به سرور خارج', 'woo-payping-gateway' ),
                    'description' => __( 'در صورت تیک خوردن، درگاه به سرور خارج از کشور متصل می‌شود.', 'woo-payping-gateway' ),
                    'default'     => 'no',
                    'desc_tip'    => true,
                ),
                'title' => array(
                    'title'       => __( 'عنوان درگاه', 'woo-payping-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'عنوان درگاه که در طی خرید به مشتری نمایش داده میشود', 'woo-payping-gateway' ),
                    'default'     => __( 'پرداخت از طریق پی‌پینگ', 'woo-payping-gateway' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'توضیحات درگاه', 'woo-payping-gateway' ),
                    'type'        => 'text',
                    'desc_tip'    => true,
                    'description' => __( 'توضیحاتی که در طی عملیات پرداخت برای درگاه نمایش داده خواهد شد', 'woo-payping-gateway' ),
                    'default'     => __( 'پرداخت به وسیله کلیه کارت های عضو شتاب از طریق درگاه پی‌پینگ', 'woo-payping-gateway' )
                ),
                'account_confing' => array(
                    'title'       => __( 'تنظیمات حساب پی‌پینگ', 'woo-payping-gateway' ),
                    'type'        => 'title',
                    'description' => '',
                ),
                'paypingToken' => array(
                    'title'       => __( 'توکن', 'woo-payping-gateway' ),
                    'type'        => 'password',
                    'custom_attributes' => array( 'autocomplete' => 'off' ),
                    'description' => __( 'توکن درگاه پی‌پینگ', 'woo-payping-gateway' ),
                    'default'     => '',
                    'desc_tip'    => true
                ),
                'payment_confing' => array(
                    'title'       => __( 'تنظیمات عملیات پرداخت', 'woo-payping-gateway' ),
                    'type'        => 'title',
                    'description' => '',
                ),
                'success_massage' => array(
                    'title'       => __( 'پیام پرداخت موفق', 'woo-payping-gateway' ),
                    'type'        => 'textarea',
                    'description' => __( 'متن پیامی که میخواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {transaction_id} برای نمایش کد رهگیری (توکن) پی‌پینگ استفاده نمایید .', 'woo-payping-gateway' ),
                    'default'     => __( 'با تشکر از شما . سفارش شما با موفقیت پرداخت شد .', 'woo-payping-gateway' ),
                ),
                'failed_massage' => array(
                    'title'       => __( 'پیام پرداخت ناموفق', 'woo-payping-gateway' ),
                    'type'        => 'textarea',
                    'description' => __( 'متن پیامی که میخواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {fault} برای نمایش دلیل خطای رخ داده استفاده نمایید .', 'woo-payping-gateway' ),
                    'default'     => __( 'پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .', 'woo-payping-gateway' ),
                ),
                'refund_confing' => array(
                    'title'       => __( 'سرویس استرداد وجه (ریفاند)', 'woo-payping-gateway' ),
                    'type'        => 'title',
                    'description' => __( 'با فعال‌سازی سرویس استرداد وجه، امکان بازگشت وجه در صفحه سفارش‌های پرداخت‌شده اضافه می‌شود و مبلغ به کارت پرداخت‌کننده بازمی‌گردد. برای استفاده، توکن درگاه باید دسترسی استرداد داشته باشد و کیف پول استرداد در پنل پی‌پینگ شارژ باشد.', 'woo-payping-gateway' ),
                ),
                'refund_enabled' => array(
                    'title'       => __( 'فعالسازی/غیرفعالسازی', 'woo-payping-gateway' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'فعالسازی سرویس استرداد وجه', 'woo-payping-gateway' ),
                    'description' => $this->get_refund_status_html(),
                    'default'     => 'no',
                    'desc_tip'    => false,
                )
            ) );
        }

        public function process_payment( $order_id ) {
            $order   = wc_get_order( $order_id );
            $pay_url = $this->get_payment_start_url( $order );

            if ( is_wp_error( $pay_url ) ) {
                $this->record_send_failure( $order, $pay_url );
                wc_add_notice( $this->get_failed_notice( $pay_url->get_error_message() ), 'error' );

                return array( 'result' => 'failure' );
            }

            return array(
                'result'   => 'success',
                'redirect' => $pay_url
            );
        }

        // Legacy receipt page: order-pay URL with the order key only.
        public function payment_receipt_page( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order || $order->get_payment_method() !== $this->id ) {
                return;
            }

            // Do not re-enter the gateway for a paid order.
            if ( $order->is_paid() ) {
                wp_redirect( $this->get_return_url( $order ) );
                exit;
            }

            $this->Send_to_payping_Gateway( $order_id );
        }

        // Legacy entry point, also used by third-party code.
        public function Send_to_payping_Gateway( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                wc_add_notice( __( 'سفارش یافت نشد!', 'woo-payping-gateway' ), 'error' );
                wp_safe_redirect( wc_get_checkout_url() );
                exit;
            }

            $pay_url = $this->get_payment_start_url( $order );

            if ( is_wp_error( $pay_url ) ) {
                $this->record_send_failure( $order, $pay_url );
                wc_add_notice( $this->get_failed_notice( $pay_url->get_error_message() ), 'error' );

                wp_safe_redirect( $order->get_checkout_payment_url() );
                exit;
            }

            wp_redirect( $pay_url );
            exit;
        }

        /**
         * Get the pay/start URL for the order, creating the payment first when needed.
         *
         * @return string|WP_Error
         */
        private function get_payment_start_url( $order ) {
            global $woocommerce;
            $order_id = $order->get_id();

            // Session fallback for the return URL.
            if ( isset( $woocommerce->session ) ) {
                $woocommerce->session->order_id_payping = $order_id;
            }

            // Reuse the stored payment code, unless the order total has changed
            // since the payment was created.
            $paypingpayCode = $order->get_meta( '_payping_payCode' );
            if ( '' !== $paypingpayCode ) {
                $stored_amount = $this->get_stored_amount( $order );
                if ( null === $stored_amount || $stored_amount === $this->get_order_amount_in_toman( $order ) ) {
                    return sprintf( '%s/pay/start/%s', $this->baseurl, $paypingpayCode );
                }

                $this->invalidate_payment_code( $order, __( 'مبلغ سفارش تغییر کرده است', 'woo-payping-gateway' ) );
            }

            // PayPing requires the amount as a JSON number.
            $Amount = $this->get_order_amount_in_toman( $order );
        
            $CallbackUrl = add_query_arg( 'wc_order', $order_id, WC()->api_request_url( 'WC_payping' ) );
            
            $Description = sprintf(
                'خرید به شماره سفارش: %s | توسط: %s %s | خرید از %s',
                $order->get_order_number(),
                $order->get_billing_first_name(),
                $order->get_billing_last_name(),
                get_bloginfo( 'name' )
            );
            
            $Mobile = $order->get_billing_phone() ?: '-';
            $Email = $order->get_billing_email();
            $Paymenter = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        
            $Description = apply_filters( 'WC_payping_Description', $Description, $order_id );
            $Mobile = apply_filters( 'WC_payping_Mobile', $Mobile, $order_id );
            $Email = apply_filters( 'WC_payping_Email', $Email, $order_id );
            $Paymenter = apply_filters( 'WC_payping_Paymenter', $Paymenter, $order_id );
            
            $payerIdentity = '';
            if ( filter_var( $Email, FILTER_VALIDATE_EMAIL ) ) {
                $payerIdentity = $Email;
            } elseif ( preg_match( '/^09[0-9]{9}$/', $Mobile ) ) {
                $payerIdentity = $Mobile;
            }
        
            $data = [
                'PayerName'     => $Paymenter,
                'Amount'        => $Amount,
                'PayerIdentity' => $payerIdentity,
                'ReturnUrl'     => $CallbackUrl,
                'Description'   => $Description,
                'ClientRefId'   => $this->get_client_ref_id( $order ),
                'NationalCode'  => ''
            ];
        
            $args = [
                'body'    => wp_json_encode( $data ),
                'timeout' => 45,
                'headers' => [
                    'X-Platform'         => 'woocommerce',
                    'X-Platform-Version' => WOO_PAYPING_VERSION,
                    'Authorization'      => 'Bearer ' . $this->paypingToken,
                    'Content-Type'       => 'application/json',
                    'Accept'             => 'application/json'
                ]
            ];

            $api_url  = apply_filters( 'WC_payping_Gateway_Payment_api_url', $this->baseurl . '/pay', $order_id );
            $api_args = apply_filters( 'WC_payping_Gateway_Payment_api_args', $args, $order_id );

            $api     = new WooPayping\Payping_Api();
            $result  = $api->create_payment( $api_url, $api_args );

            if ( $result->ok ) {
                $payment_code = $result->get( 'payment_code' );

                $order->update_meta_data( '_payping_payCode', $payment_code );
                $order->update_meta_data( '_payping_amount', $Amount );
                $order->save();

                Payping_Order_Panel::log_event( $order, 'created', 'ساخت موفق پرداخت، کد پرداخت: ' . $payment_code );

                return sprintf( '%s/pay/start/%s', $this->baseurl, $payment_code );
            }

            $Message = $result->message;

            $ERR_ID = $result->get( 'request_id' );
            if ( $ERR_ID ) {
                $Message .= ' | ' . __( 'شناسه درخواست', 'woo-payping-gateway' ) . ': ' . $ERR_ID;
            }

            // Keep the trace id from the error body for the order panel.
            $error_details = [];
            $trace_id      = $result->get( 'trace_id' );
            if ( $trace_id ) {
                $error_details['trace_id'] = $trace_id;
            }

            return new WP_Error( 'payping_send_to_gateway', $Message, $error_details );
        }

        private function record_send_failure( $order, $error ) {
            $Message = is_wp_error( $error ) ? $error->get_error_message() : $error;
            $details = is_wp_error( $error ) ? (array) $error->get_error_data() : [];

            Payping_Order_Panel::log_event( $order, 'error', __( 'خطا در هنگام ارسال به بانک: ', 'woo-payping-gateway' ) . $Message, $details );
            do_action( 'woo_payping_Send_to_Gateway_Failed', $order->get_id(), $Message );
        }

        /**
         * Amount the stored payment code was created with, or null for older payments.
         */
        private function get_stored_amount( $order ) {
            $amount = $order->get_meta( '_payping_amount' );

            return '' === $amount ? null : (int) $amount;
        }

        /**
         * Amount a payment response must match: the stored amount, or the
         * current order amount for older payments.
         */
        private function get_expected_amount( $order ) {
            return $this->get_stored_amount( $order ) ?? $this->get_order_amount_in_toman( $order );
        }

        /**
         * Remove the stored payment code and amount, so the next attempt
         * creates a new payment.
         */
        private function invalidate_payment_code( $order, $reason = '' ) {
            if ( '' === $order->get_meta( '_payping_payCode' ) ) {
                return;
            }

            $order->delete_meta_data( '_payping_payCode' );
            $order->delete_meta_data( '_payping_amount' );
            $order->delete_meta_data( '_payping_refid' );
            $order->save();

            $message = 'کد پرداخت این سفارش در ووکامرس باطل شد';
            if ( '' !== $reason ) {
                $message .= ' | ' . $reason;
            }
            Payping_Order_Panel::log_event( $order, 'invalidated', $message );
        }

        public function Return_from_payping_Gateway() {
            global $woocommerce;

            $paypingResponse = isset( $_REQUEST['data'] ) ? wp_unslash( $_REQUEST['data'] ) : '';
            $responseData    = json_decode( $paypingResponse, true ) ?: [];

            // Some PayPing posts arrive as a raw JSON body instead of the
            // form-encoded "data" parameter; accept both shapes.
            $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
            if ( empty( $responseData ) && 'POST' === $request_method ) {
                $raw_body = file_get_contents( 'php://input' );
                if ( is_string( $raw_body ) && '' !== trim( $raw_body ) ) {
                    $responseData = json_decode( $raw_body, true ) ?: [];
                }
            }

            if ( isset( $_REQUEST['wc_order'] ) ) {
                $order_id = absint( $_REQUEST['wc_order'] );
            } elseif ( ! empty( $woocommerce->session->order_id_payping ) ) {
                $order_id = absint( $woocommerce->session->order_id_payping );
                unset( $woocommerce->session->order_id_payping );
            } else {
                wp_redirect( wc_get_checkout_url() );
                exit;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
                wp_redirect( wc_get_checkout_url() );
                exit;
            }

            // Nothing left to do for a paid order.
            if ( $order->is_paid() ) {
                wp_redirect( $this->get_return_url( $order ) );
                exit;
            }

            // No active PayPing payment on this order.
            if ( $order->get_payment_method() !== $this->id || '' === $order->get_meta( '_payping_payCode' ) ) {
                wp_redirect( wc_get_checkout_url() );
                exit;
            }

            $clientRefId   = isset( $responseData['clientRefId'] ) ? sanitize_text_field( $responseData['clientRefId'] ) : '';
            $expectedRefId = $this->get_client_ref_id( $order );
            $refid         = isset( $responseData['paymentRefId'] ) ? sanitize_text_field( $responseData['paymentRefId'] ) : null;
            $Transaction_ID = apply_filters( 'WC_payping_return_refid', $refid );

            // Keep the gateway reference id on the order even when the
            // verify call fails; it is what the admin re-check uses.
            if ( $Transaction_ID ) {
                $order->update_meta_data( '_payping_refid', $Transaction_ID );
                $order->save();
            }

            if ( '' === $clientRefId || $clientRefId !== $expectedRefId ) {
                $error_message = sprintf( 'شناسه سفارش برگشتی (%s) با شناسه سفارش اصلی (%s) مطابقت ندارد', $clientRefId ?: 'ندارد', $expectedRefId );
                $this->handle_verification_error( $order, $Transaction_ID, $error_message );
                return;
            }

            $status = isset( $_REQUEST['status'] ) ? absint( $_REQUEST['status'] ) : null;
            
            // User cancelled at the bank: back to the order payment page
            if ( 0 === $status ) {
                Payping_Order_Panel::log_event( $order, 'cancelled', 'کاربر در صفحه بانک از پرداخت انصراف داده است.' );
                wc_add_notice( 'تراكنش توسط شما لغو شد. لطفاً برای تکمیل خرید مجدداً اقدام نمایید.', 'error' );

                do_action( 'woo_payping_Payment_Cancelled', $order_id );

                wp_redirect( $order->get_checkout_payment_url() );
                exit;
            }

            $validation_error = $this->validate_return_data( $order, $responseData );
            if ( $validation_error ) {
                $this->handle_verification_error( $order, $Transaction_ID, $validation_error );
                return;
            }

            $this->verify_payment( $order, $Transaction_ID, $responseData );
        }

        private function validate_return_data( $order, $responseData ) {
            $stored_payment_code = $order->get_meta( '_payping_payCode' );

            if ( empty( $stored_payment_code ) ) {
                return 'کد پرداخت ذخیره شده یافت نشد';
            }
            if ( ! isset( $responseData['paymentCode'] ) ) {
                return 'پارامتر کد پرداخت در پاسخ درگاه وجود ندارد';
            }

            $returned_payment_code = sanitize_text_field( $responseData['paymentCode'] );
            if ( $returned_payment_code !== $stored_payment_code ) {
                return sprintf( 'کد پرداخت برگشتی (%s) با کد ذخیره شده (%s) مطابقت ندارد', $returned_payment_code, $stored_payment_code );
            }

            // Amount must match what was sent to PayPing
            $expected_amount = $this->get_expected_amount( $order );

            if ( ! isset( $responseData['amount'] ) ) {
                return 'پارامتر مبلغ در پاسخ درگاه وجود ندارد';
            }
            
            $returned_amount = intval( $responseData['amount'] );
            if ( $returned_amount !== $expected_amount ) {
                return sprintf( 'مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد', number_format( $returned_amount ), number_format( $expected_amount ) );
            }
            
            return false;
        }

        private function verify_payment( $order, $Transaction_ID, $responseData ) {
            $order_id = $order->get_id();

            // Two browser tabs (or PayPing retries) can hit the callback at
            // the same time; hold a short lock so the order is only completed
            // once. Stale locks expire on their own.
            $lock_key = 'woo_payping_verify_' . $order_id;
            if ( get_transient( $lock_key ) ) {
                wp_redirect( $this->get_return_url( $order ) );
                exit;
            }
            set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

            $stored_payment_code = $order->get_meta( '_payping_payCode' );

            // Amount must match what was sent to PayPing
            $expected_amount = $this->get_expected_amount( $order );

            $data = [
                'PaymentRefId' => $Transaction_ID,
                'PaymentCode'  => $stored_payment_code,
                'Amount'       => $expected_amount
            ];

            $args = [
                'body'    => wp_json_encode( $data ),
                'timeout' => 45,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->paypingToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ]
            ];

            $verify_api_url = apply_filters( 'WC_payping_Gateway_Payment_verify_api_url', $this->baseurl . '/pay/verify', $order_id );

            $api    = new WooPayping\Payping_Api();
            $result = $api->verify_payment( $verify_api_url, $args );

            if ( 0 === $result->status_code && ! $result->ok ) {
                $this->release_verify_lock( $order_id );
                $this->handle_verification_error( $order, $Transaction_ID, 'خطا در ارتباط به پی‌پینگ: ' . $result->message );
                return;
            }

            $code = $result->status_code;
            $rbody = $result->get( 'body' );

            // 409/110 means this payment was already verified before.
            if ( $result->duplicate ) {
                $this->handle_duplicate_payment( $order, $Transaction_ID );
                return;
            }

            if ( 200 !== $code && isset( $rbody['status'], $rbody['metaData']['code'] ) ) {
                $this->release_verify_lock( $order_id );
                $this->handle_payment_failure( $order, $Transaction_ID, $result->message, $result->get( 'trace_id' ) );
                return;
            }

            if ( ! isset( $rbody['code'] ) || $rbody['code'] !== $stored_payment_code ) {
                $this->release_verify_lock( $order_id );
                $this->handle_verification_error( $order, $Transaction_ID, 'کد پرداخت برگشتی با کد ذخیره شده مطابقت ندارد' );
                return;
            }

            $response_amount = isset( $rbody['amount'] ) ? intval( $rbody['amount'] ) : 0;
            if ( $response_amount !== $expected_amount ) {
                $this->release_verify_lock( $order_id );
                $this->handle_verification_error( $order, $Transaction_ID, sprintf( 'مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد', number_format( $response_amount ), number_format( $expected_amount ) ) );
                return;
            }

            $cardNumber  = isset( $rbody['cardNumber'] ) ? sanitize_text_field( $rbody['cardNumber'] ) : '-';
            $CardHashPan = isset( $rbody['cardHashPan'] ) ? sanitize_text_field( $rbody['cardHashPan'] ) : '-';

            $order->update_meta_data( 'payping_payment_card_number', $cardNumber );
            $order->update_meta_data( 'payping_payment_card_hashpan', $CardHashPan );
            $order->save();

            if ( 200 === $code ) {
                $this->handle_payment_success( $order, $Transaction_ID, $cardNumber, $this->status_message( $code ) );
            } else {
                $this->release_verify_lock( $order_id );
                $this->handle_verification_error( $order, $Transaction_ID, $this->status_message( $code ) ?: 'خطای نامشخص در تایید پرداخت!' );
            }
        }

        // Drop the verify lock of an order after a failed verification, so
        // the buyer can immediately try again. Successful and duplicate
        // payments keep the lock; the is_paid() check on entry covers them.
        private function release_verify_lock( $order_id ) {
            delete_transient( 'woo_payping_verify_' . $order_id );
        }

        /**
         * Re-run the verification of an unpaid order from the admin panel.
         *
         * Used when the buyer paid at the bank but the return trip was lost
         * or the verify call failed. Requires the stored payment code and
         * the reference id the gateway returned. Changes order state and
         * logs events, but never redirects: the caller decides where the
         * admin lands afterwards.
         *
         * @return array outcome: array( 'success' => bool, 'message' => string )
         */
        public function process_reverify( $order ) {
            $order_id = $order->get_id();

            if ( $order->is_paid() ) {
                return array( 'success' => true, 'message' => 'این سفارش از قبل پرداخت شده است.' );
            }

            // WooCommerce does not count a refunded order as paid, so the
            // visibility rule alone is not enough here: a refunded payment
            // is never re-verified. The refund state has its own button.
            if ( $order->has_status( 'refunded' ) || $order->get_meta( '_payping_refund_code' ) ) {
                return array( 'success' => false, 'message' => 'این سفارش پرداخت و استرداد شده است؛ برای پیگیری بازگشت وجه از دکمه «بروزرسانی وضعیت استرداد» استفاده کنید.' );
            }

            $payment_code = $order->get_meta( '_payping_payCode' );
            $refid        = $order->get_meta( '_payping_refid' );

            if ( ! $payment_code || ! $refid ) {
                return array( 'success' => false, 'message' => 'کد پرداخت یا کد پیگیری درگاه برای استعلام موجود نیست.' );
            }

            $lock_key = 'woo_payping_verify_' . $order_id;
            if ( get_transient( $lock_key ) ) {
                return array( 'success' => false, 'message' => 'یک بررسی دیگر برای این سفارش در جریان است؛ چند لحظه بعد دوباره تلاش کنید.' );
            }
            set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

            $expected_amount = $this->get_expected_amount( $order );

            $args = [
                'body'    => wp_json_encode( [
                    'PaymentRefId' => $refid,
                    'PaymentCode'  => $payment_code,
                    'Amount'       => $expected_amount
                ] ),
                'timeout' => 45,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->paypingToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ]
            ];

            $api    = new WooPayping\Payping_Api();
            $result = $api->verify_payment(
                apply_filters( 'WC_payping_Gateway_Payment_verify_api_url', $this->baseurl . '/pay/verify', $order_id ),
                $args
            );

            // Transport failure: nothing definitive, let the admin retry.
            if ( 0 === $result->status_code && ! $result->ok ) {
                $this->release_verify_lock( $order_id );
                return array( 'success' => false, 'message' => 'خطا در ارتباط به پی‌پینگ: ' . $result->message );
            }

            if ( $result->duplicate ) {
                $this->reverify_complete( $order, $refid, 'این سفارش قبلا تایید شده است.', 'duplicate' );
                return array( 'success' => true, 'message' => 'این پرداخت قبلاً تأیید شده بود و سفارش تکمیل شد.' );
            }

            if ( $result->ok ) {
                $rbody = $result->get( 'body' );

                if ( ! isset( $rbody['code'] ) || $rbody['code'] !== $payment_code ) {
                    $this->release_verify_lock( $order_id );
                    Payping_Order_Panel::log_event( $order, 'error', 'خطا در استعلام مجدد: کد پرداخت پاسخ درگاه با کد ذخیره شده مطابقت ندارد' );
                    return array( 'success' => false, 'message' => 'کد پرداخت پاسخ درگاه با کد ذخیره شده مطابقت ندارد.' );
                }

                $response_amount = isset( $rbody['amount'] ) ? intval( $rbody['amount'] ) : 0;
                if ( $response_amount !== $expected_amount ) {
                    $this->release_verify_lock( $order_id );
                    Payping_Order_Panel::log_event( $order, 'error', sprintf( 'خطا در استعلام مجدد: مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد', number_format( $response_amount ), number_format( $expected_amount ) ) );
                    return array( 'success' => false, 'message' => sprintf( 'مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد', number_format( $response_amount ), number_format( $expected_amount ) ) );
                }

                $cardNumber  = isset( $rbody['cardNumber'] ) ? sanitize_text_field( $rbody['cardNumber'] ) : '-';
                $CardHashPan = isset( $rbody['cardHashPan'] ) ? sanitize_text_field( $rbody['cardHashPan'] ) : '-';

                $order->update_meta_data( 'payping_payment_card_number', $cardNumber );
                $order->update_meta_data( 'payping_payment_card_hashpan', $CardHashPan );
                $order->save();

                $this->reverify_complete( $order, $refid, 'پرداخت این سفارش در استعلام مجدد تایید شد. شماره کارت: ' . $cardNumber, 'verified' );

                return array( 'success' => true, 'message' => 'پرداخت با موفقیت تأیید و سفارش تکمیل شد.' );
            }

            $this->release_verify_lock( $order_id );

            Payping_Order_Panel::log_event( $order, 'error', 'خطا در استعلام مجدد: ' . $result->message, array( 'trace_id' => $result->get( 'trace_id' ) ) );

            return array( 'success' => false, 'message' => 'استعلام ناموفق بود: ' . $result->message );
        }

        /**
         * Complete an order from the admin re-check. Kept apart from the
         * front-end success handler: no cart and no wc notices exist on
         * admin requests.
         */
        private function reverify_complete( $order, $refid, $log_message, $event_type ) {
            $order->update_meta_data( '_transaction_id', $refid );
            $order->save();

            Payping_Order_Panel::log_event( $order, $event_type, $log_message );

            $order->payment_complete( $refid );

            /**
             * Fires after a payment is confirmed by the admin re-check.
             *
             * @param int $order_id Order id.
             * @param string $refid PayPing reference id.
             */
            do_action( 'woo_payping_Admin_Reverified', $order->get_id(), $refid );
        }

        /**
         * WooCommerce refund handler: sends the amount back to the card the
         * customer paid with, through the PayPing refund service.
         *
         * @return true|WP_Error
         */
        public function process_refund( $order_id, $amount = null, $reason = '' ) {
            if ( 'yes' !== ( $this->settings['refund_enabled'] ?? 'no' ) ) {
                return new WP_Error( 'payping_refund_disabled', __( 'سرویس استرداد وجه پی‌پینگ فعال نیست؛ آن را از تنظیمات درگاه فعال کنید.', 'woo-payping-gateway' ) );
            }

            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_payment_method() !== $this->id ) {
                return new WP_Error( 'payping_refund_order', __( 'سفارش یافت نشد یا از طریق درگاه پی‌پینگ پرداخت نشده است.', 'woo-payping-gateway' ) );
            }

            $payment_code = $order->get_meta( '_payping_payCode' );
            if ( '' === $payment_code ) {
                return new WP_Error( 'payping_refund_payment_code', __( 'کد پرداخت این سفارش در فروشگاه ذخیره نشده و استرداد از افزونه ممکن نیست؛ آن را از پنل پی‌پینگ انجام دهید.', 'woo-payping-gateway' ) );
            }

            // WooCommerce books the refund record before this hook
            // runs, so the refundable rest is already reduced by the
            // very refund being processed. A numeric amount is always
            // sent explicitly: the rest alone can no longer tell a
            // full refund from a partial one, and an empty amount at
            // PayPing means the WHOLE payment back - a one-item refund
            // on a two-item order must never become that.
            // The wage is always taken from the merchant wallet, so
            // the business is declared as the wage payer (2).
            $remaining = (float) $order->get_remaining_refund_amount();

            // A zero (restock-only) or negative amount passes Woo's
            // own validation but must never reach PayPing: block it
            // with a pointer to the manual-refund path instead.
            if ( null !== $amount && (float) $amount <= 0 ) {
                Payping_Order_Panel::log_event( $order, 'error', 'خطا در استرداد: مبلغ استرداد باید بیش از صفر باشد' );
                return new WP_Error( 'payping_refund_amount', __( 'مبلغ استرداد باید بیش از صفر باشد؛ برای بازگشت کالا به انبار بدون بازگشت پول، از دکمه استرداد دستی استفاده کنید.', 'woo-payping-gateway' ) );
            }

            // Only a direct call with no amount against an emptied
            // order is a doomed "full refund". Numeric amounts belong
            // to a refund record WooCommerce has just validated and
            // booked, and stay valid even at a zero rest.
            if ( null === $amount && $remaining <= 0.01 ) {
                Payping_Order_Panel::log_event( $order, 'error', 'خطا در استرداد: این سفارش قبلاً به‌طور کامل استرداد شده است' );
                return new WP_Error( 'payping_refund_fully', __( 'این سفارش به‌طور کامل استرداد شده و مبلغ قابل استردادی برای آن باقی نمانده است.', 'woo-payping-gateway' ) );
            }

            // Over-amount guard for direct (programmatic) calls: in
            // the admin flow WooCommerce itself rejects those before
            // booking, and a booked record has already cut the rest.
            if ( null !== $amount && (float) $amount > $remaining + 0.01 && (float) $amount > (float) $order->get_total_refunded() ) {
                Payping_Order_Panel::log_event( $order, 'error', sprintf( 'خطا در استرداد: مبلغ استرداد (%s) بیش از مبلغ قابل استرداد این سفارش است', number_format_i18n( (float) $amount ) ) );
                return new WP_Error( 'payping_refund_over', sprintf( __( 'مبلغ استرداد (%s تومان) بیش از مبلغ قابل استرداد این سفارش است.', 'woo-payping-gateway' ), number_format_i18n( (float) $amount ) ) );
            }

            // Full only when nothing stays booked after this refund;
            // the label just feeds the event log.
            $is_full      = null === $amount || $remaining <= 0.01;
            $refund_toman = null !== $amount ? $this->get_refund_amount_in_toman( $order, (float) $amount ) : ( $this->get_stored_amount( $order ) ?: 0 );
            $data         = array(
                'paymentCode' => $payment_code,
                'wagePayer'   => 2,
            );

            if ( null !== $amount ) {
                $data['amount'] = $refund_toman;
            }

            $args = $this->get_refund_request_args( $data );

            $api_url  = apply_filters( 'WC_payping_Gateway_Refund_api_url', $this->refund_baseurl . '/Create', $order_id );
            $api_args = apply_filters( 'WC_payping_Gateway_Refund_api_args', $args, $order_id );

            $api    = new WooPayping\Payping_Api();
            $result = $api->refund_post( $api_url, $api_args );

            // 401 means the token itself is rejected; 403 means the token
            // is valid but refund access or account activation is missing.
            if ( 401 === $result->status_code ) {
                Payping_Order_Panel::log_event( $order, 'error', 'خطا در استرداد: توکن درگاه نامعتبر یا منقضی است' );
                return new WP_Error( 'payping_refund_unauthorized', __( 'توکن درگاه نامعتبر یا منقضی است؛ آن را از پنل پی‌پینگ تعویض و در تنظیمات درگاه جایگزین کنید.', 'woo-payping-gateway' ) );
            }
            if ( 403 === $result->status_code ) {
                Payping_Order_Panel::log_event( $order, 'error', 'خطا در استرداد: دسترسی استرداد توکن یا فعال بودن سرویس تایید نشد' );
                return new WP_Error( 'payping_refund_forbidden', __( 'دسترسی استرداد به این توکن داده نشده یا سرویس استرداد برای حساب شما فعال نیست؛ توکنی با دسترسی ریفاند بسازید و در صورت تکرار با پشتیبانی پی‌پینگ تماس بگیرید.', 'woo-payping-gateway' ) );
            }

            if ( ! $result->ok ) {
                Payping_Order_Panel::log_event( $order, 'error', 'خطا در استرداد: ' . $result->message, array( 'trace_id' => $result->get( 'trace_id' ) ) );
                return new WP_Error( 'payping_refund_failed', $result->message );
            }

            $rbody       = $result->get( 'body' );
            $refund_code = isset( $rbody['code'] ) ? sanitize_text_field( (string) $rbody['code'] ) : '';

            // An empty wallet does not fail the request: PayPing keeps
            // the operation pending and runs it after the next recharge.
            $awaiting_recharge = ! empty( $rbody['isPendingRecharge'] );

            $order->update_meta_data( '_payping_refund_code', $refund_code );
            $order->delete_meta_data( '_payping_refund_info' );
            $order->save();

            // The wallet balance just changed: drop the cached figure
            // from the settings card, the next check reads it live.
            delete_transient( 'woo_payping_refund_status' );

            $event = sprintf( 'ثبت استرداد %s به مبلغ %s تومان', $is_full ? 'کامل' : 'بخشی', number_format_i18n( $refund_toman ) );
            if ( $awaiting_recharge ) {
                $event .= ' | در انتظار شارژ کیف پول استرداد';
            }
            Payping_Order_Panel::log_event( $order, 'refund', $event );

            /**
             * Fires after a refund operation is created at PayPing.
             *
             * @param int $order_id Order id.
             * @param string $refund_code Refund operation code.
             * @param int|null $amount Refund amount in Toman, null for full refunds.
             */
            do_action( 'woo_payping_Refund_Created', $order_id, $refund_code, $is_full ? null : $data['amount'] );

            return true;
        }

        /**
         * Refresh the refund state of an order from the PayPing refund
         * service. Updates the stored snapshot and logs state changes,
         * but never redirects: the caller decides where the admin lands.
         *
         * @return array outcome: array( 'success' => bool, 'message' => string )
         */
        public function process_refund_status( $order ) {
            $payment_code = $order->get_meta( '_payping_payCode' );
            if ( '' === $payment_code ) {
                return array( 'success' => false, 'message' => 'کد پرداخت این سفارش در فروشگاه ذخیره نشده و پیگیری استرداد ممکن نیست.' );
            }

            $api    = new WooPayping\Payping_Api();
            $result = $api->refund_get(
                apply_filters( 'WC_payping_Gateway_Refund_api_url', $this->refund_baseurl . '/LatestByPaymentCode/' . rawurlencode( $payment_code ), $order->get_id() ),
                $this->get_refund_request_args()
            );

            if ( ! $result->ok ) {
                return array( 'success' => false, 'message' => 'خطا در دریافت وضعیت استرداد: ' . $result->message );
            }

            $info = $result->get( 'body' );

            $snapshot = array(
                'state'         => isset( $info['state'] ) ? (int) $info['state'] : 0,
                'state_label'   => isset( $info['localizedState'] ) ? sanitize_text_field( (string) $info['localizedState'] ) : '',
                'refund_amount' => isset( $info['refundAmount'] ) ? (float) $info['refundAmount'] : 0,
                'refund_wage'   => isset( $info['refundWage'] ) ? (float) $info['refundWage'] : 0,
                'bank_tracking' => isset( $info['bankTrackingCode'] ) ? sanitize_text_field( (string) $info['bankTrackingCode'] ) : '',
                'success_date'  => isset( $info['successDateTime'] ) ? sanitize_text_field( (string) $info['successDateTime'] ) : '',
            );

            $previous = $order->get_meta( '_payping_refund_info' );
            $previous = is_array( $previous ) ? $previous : array();
            $state_changed = $snapshot['state'] !== ( isset( $previous['state'] ) ? (int) $previous['state'] : 0 );

            $order->update_meta_data( '_payping_refund_info', $snapshot );
            $order->save();

            // Only log real transitions, not repeated checks.
            if ( $state_changed && 0 !== $snapshot['state'] ) {
                $event = sprintf( 'وضعیت استرداد: %s | مبلغ: %s تومان', $snapshot['state_label'] ?: $snapshot['state'], number_format_i18n( $snapshot['refund_amount'] ) );
                Payping_Order_Panel::log_event( $order, 5 === $snapshot['state'] ? 'error' : 'refund', $event );

                // A moved refund probably moved the wallet too.
                delete_transient( 'woo_payping_refund_status' );
            }

            $message = sprintf( 'وضعیت استرداد: %s | مبلغ: %s تومان', $snapshot['state_label'] ?: $snapshot['state'], number_format_i18n( $snapshot['refund_amount'] ) );
            if ( '' !== $snapshot['bank_tracking'] ) {
                $message .= ' | ' . __( 'کد پیگیری بانکی', 'woo-payping-gateway' ) . ': ' . $snapshot['bank_tracking'];
            }

            return array( 'success' => true, 'message' => $message );
        }

        /**
         * Readiness check behind the settings button: verifies the token
         * can reach the refund service, initializes it on first use and
         * reports the wallet balance. The outcome is cached for the
         * settings page by the caller.
         *
         * @return array outcome: array( 'success' => bool, 'message' => string )
         */
        public function check_refund_service() {
            $api    = new WooPayping\Payping_Api();
            $config = $api->refund_get( $this->refund_baseurl . '/UserConfiguration', $this->get_refund_request_args() );

            // 401 means the token itself is rejected; 403 means the token
            // is valid but refund access or account activation is missing.
            if ( 401 === $config->status_code ) {
                return array(
                    'ok'      => false,
                    'state'   => 'unauthorized',
                    'message' => __( 'توکن درگاه نامعتبر یا منقضی است؛ آن را از پنل پی‌پینگ تعویض و در تنظیمات درگاه جایگزین کنید.', 'woo-payping-gateway' ),
                );
            }
            if ( 403 === $config->status_code ) {
                return array(
                    'ok'      => false,
                    'state'   => 'forbidden',
                    'message' => __( 'دسترسی استرداد به این توکن داده نشده یا سرویس استرداد برای حساب شما فعال نیست؛ توکنی با دسترسی ریفاند بسازید و در صورت تکرار با پشتیبانی پی‌پینگ تماس بگیرید.', 'woo-payping-gateway' ),
                );
            }

            if ( ! $config->ok ) {
                return array( 'ok' => false, 'state' => 'error', 'message' => 'خطا در ارتباط با سرویس استرداد: ' . $config->message );
            }

            $body = $config->get( 'body' );

            // Keep the wage tariff around for the refund confirmation.
            $this->store_refund_config( $body );

            // First use of the refund service by this merchant.
            if ( empty( $body['userInitializationType'] ) ) {
                $init = $api->refund_post( $this->refund_baseurl . '/InitializeUser', $this->get_refund_request_args( array() ) );
                if ( ! $init->ok ) {
                    return array( 'ok' => false, 'state' => 'error', 'message' => 'راه‌اندازی سرویس استرداد ناموفق بود: ' . $init->message );
                }
            }

            $result = array(
                'ok'      => true,
                'state'   => 'ok',
                'message' => __( 'سرویس استرداد پی‌پینگ فعال است.', 'woo-payping-gateway' ),
                'balance' => null,
                'wage'    => '',
            );

            $balance = $api->refund_get( $this->refund_baseurl . '/Balance', $this->get_refund_request_args() );
            if ( $balance->ok ) {
                $bbody = $balance->get( 'body' );
                $result['balance'] = isset( $bbody['accessibleBalance'] ) ? (float) $bbody['accessibleBalance'] : 0;
            }

            if ( isset( $body['wageValue'] ) ) {
                $result['wage'] = 1 === (int) ( $body['wageType'] ?? 0 )
                    ? number_format_i18n( (float) $body['wageValue'] ) . '٪'
                    : number_format_i18n( (float) $body['wageValue'] ) . ' ' . __( 'تومان', 'woo-payping-gateway' );
            }

            return $result;
        }

        /**
         * Amount and wage preview for the admin refund confirmation: what
         * goes back to the customer and what the wallet will be charged,
         * based on the tariff cached from the last service check.
         */
        public function get_refund_wage_quote( $order, $amount = null ) {
            $remaining = (float) $order->get_remaining_refund_amount();

            // Order context for the confirmation dialog, so the admin
            // always sees what the amount is compared against.
            $context = array(
                'total_label'     => number_format_i18n( $this->get_stored_amount( $order ) ?: 0 ) . ' تومان',
                'remaining_label' => number_format_i18n( $this->get_refund_amount_in_toman( $order, $remaining ) ) . ' تومان',
                'fully'           => $remaining <= 0.005,
            );

            // Nothing left to refund: every request is blocked, so even
            // an empty amount (normally a full refund) cannot show up
            // in the dialog as a normal refund.
            if ( $remaining <= 0.005 ) {
                return $context + array( 'over' => true );
            }

            // The refund box starts empty and WooCommerce rejects an
            // empty submit with a raw English alert; an entered zero
            // or negative value is the same story. Both are blocked
            // here so the dialog says it in Persian instead. A null
            // amount (direct calls) still means a full refund.
            if ( '' === $amount ) {
                return $context + array( 'invalid' => true, 'empty' => true );
            }
            if ( null !== $amount && (float) $amount <= 0 ) {
                return $context + array( 'invalid' => true );
            }

            // An amount above the refundable rest is reported back, so
            // the dialog can block it with a clear message before
            // WooCommerce rejects it with a raw English alert.
            if ( null !== $amount && (float) $amount > $remaining + 0.01 ) {
                return $context + array(
                    'over'          => true,
                    'entered_label' => number_format_i18n( $this->get_refund_amount_in_toman( $order, (float) $amount ) ) . ' تومان',
                );
            }

            // Mirror process_refund exactly: a numeric amount is always
            // converted from what the merchant entered, even when it
            // equals the rest (the last chunk of a partially refunded
            // order). Only a null amount stands for the whole payment.
            $is_full = null === $amount || ( (float) $amount >= $remaining - 0.01 );
            $toman   = null !== $amount ? $this->get_refund_amount_in_toman( $order, (float) $amount ) : ( $this->get_stored_amount( $order ) ?: 0 );

            $config = get_transient( 'woo_payping_refund_config' );
            if ( ! is_array( $config ) ) {
                $config = $this->fetch_refund_config();
            }

            $wage = null;
            if ( is_array( $config ) && isset( $config['wageValue'] ) ) {
                if ( 1 === (int) $config['wageType'] ) {
                    $wage = $toman * (float) $config['wageValue'] / 100;
                    if ( ! empty( $config['minPercentageWage'] ) ) {
                        $wage = max( $wage, (float) $config['minPercentageWage'] );
                    }
                    if ( ! empty( $config['maxPercentageWage'] ) ) {
                        $wage = min( $wage, (float) $config['maxPercentageWage'] );
                    }
                } else {
                    $wage = (float) $config['wageValue'];
                }
            }

            return $context + array(
                'over'   => false,
                'full'   => $is_full,
                'amount' => (int) round( $toman ),
                'wage'   => null === $wage ? null : (int) round( $wage ),
            );
        }

        /**
         * Live tariff lookup, used when nothing is cached yet.
         */
        private function fetch_refund_config() {
            $api    = new WooPayping\Payping_Api();
            $config = $api->refund_get( $this->refund_baseurl . '/UserConfiguration', $this->get_refund_request_args() );

            if ( ! $config->ok ) {
                return null;
            }

            return $this->store_refund_config( $config->get( 'body' ) );
        }

        /**
         * Cache the wage tariff behind the confirmation dialog.
         */
        private function store_refund_config( $body ) {
            $data = array(
                'wageType'          => isset( $body['wageType'] ) ? (int) $body['wageType'] : 0,
                'wageValue'         => isset( $body['wageValue'] ) ? (float) $body['wageValue'] : 0,
                'minPercentageWage' => isset( $body['minPercentageWage'] ) ? (float) $body['minPercentageWage'] : null,
                'maxPercentageWage' => isset( $body['maxPercentageWage'] ) ? (float) $body['maxPercentageWage'] : null,
            );
            set_transient( 'woo_payping_refund_config', $data, 6 * HOUR_IN_SECONDS );

            return $data;
        }

        /**
         * Partial refund amount converted to Toman with the rate the order
         * was actually paid with, so later currency changes never skew the
         * returned amount.
         */
        private function get_refund_amount_in_toman( $order, $amount ) {
            $paid_amount = $this->get_stored_amount( $order );
            $order_total = (float) $order->get_total();

            if ( $paid_amount && $order_total > 0 ) {
                return (int) round( $amount * ( $paid_amount / $order_total ) );
            }

            // Older payments without a stored amount: convert like the payment did.
            $currency = apply_filters( 'WC_payping_Currency', $order->get_currency(), $order->get_id() );
            return (int) round( $this->payping_check_currency( $amount, $currency ) );
        }

        /**
         * Request args shared by every refund service call.
         */
        private function get_refund_request_args( $body = null ) {
            $args = [
                'timeout' => 45,
                'headers' => [
                    'X-Platform'         => 'woocommerce',
                    'X-Platform-Version' => WOO_PAYPING_VERSION,
                    'Authorization'      => 'Bearer ' . $this->paypingToken,
                    'Content-Type'       => 'application/json',
                    'Accept'             => 'application/json'
                ]
            ];

            if ( null !== $body ) {
                $args['body'] = wp_json_encode( $body );
            }

            return $args;
        }

        /**
         * Inline refund status line for the settings form, fed by the
         * cached readiness check. Remote calls happen in the admin-post
         * handler; this only reads the cache.
         */
        private function get_refund_status_html() {
            if ( ! is_admin() ) {
                return __( 'با فعال‌سازی، دکمه بازگشت وجه به صفحه سفارش‌های پرداخت‌شده اضافه می‌شود.', 'woo-payping-gateway' );
            }

            return Payping_Order_Panel::refund_status_card();
        }

        // ClientRefId sent to PayPing for this order.
        private function get_client_ref_id( $order ) {
            return (string) $order->get_order_number();
        }

        private function handle_payment_success( $order, $Transaction_ID, $cardNumber, $message ) {
            global $woocommerce;
            $order_id = $order->get_id();

            $order->update_meta_data( '_transaction_id', $Transaction_ID );
            $order->save();

            $woocommerce->cart->empty_cart();
            $order->payment_complete( $Transaction_ID );

            $event = sprintf( '%s | شماره کارت: %s | شماره پیگیری پرداخت: %s', $message, $cardNumber, $Transaction_ID );
            Payping_Order_Panel::log_event( $order, 'verified', $event );

            // The payment code and amount stay on the order: the refund
            // service needs them after the payment is complete.

            do_action( 'woo_payping_Payment_Success', $order_id, $Transaction_ID );

            $notice = wpautop( wptexturize( $this->success_massage ) );
            $notice = str_replace( '{transaction_id}', $Transaction_ID, $notice );
            $notice = apply_filters( 'woocommerce_thankyou_order_received_text', $notice, $order_id, $Transaction_ID );
            wc_add_notice( $notice, 'success' );

            wp_redirect( add_query_arg( 'wc_status', 'success', $this->get_return_url( $order ) ) );
            exit;
        }

        private function handle_verification_error( $order, $Transaction_ID, $error_message, $trace_id = '' ) {
            $tr_id  = ( $Transaction_ID && $Transaction_ID != 0 ) ? ' | کد پیگیری: ' . $Transaction_ID : '';
            $message = sprintf( __( 'خطا در تأیید پرداخت: %s%s', 'woo-payping-gateway' ), $error_message, $tr_id );

            Payping_Order_Panel::log_event( $order, 'error', $message, array( 'trace_id' => $trace_id ) );
            wc_add_notice( $this->get_failed_notice( $error_message, $Transaction_ID ), 'error' );
            
            // Back to the order payment page for another attempt
            wp_redirect( $order->get_checkout_payment_url() );
            exit;
        }

        private function handle_duplicate_payment( $order, $Transaction_ID ) {
            global $woocommerce;
            $message = 'این سفارش قبلا تایید شده است.';

            if ( ! $order->is_paid() ) {
                $order->update_meta_data( '_transaction_id', $Transaction_ID );
                $order->save();

                $woocommerce->cart->empty_cart();
                $order->payment_complete( $Transaction_ID );
                Payping_Order_Panel::log_event( $order, 'duplicate', $message );
            }

            // The code was already used by the first verification, but it
            // stays on the order for later refunds.

            do_action( 'woo_payping_Payment_Duplicate', $order->get_id(), $Transaction_ID );

            wc_add_notice( $message, 'success' );

            wp_redirect( add_query_arg( 'wc_status', 'success', $this->get_return_url( $order ) ) );
            exit;
        }

        private function handle_payment_failure( $order, $Transaction_ID, $message, $trace_id = '' ) {
            $tr_id  = ( $Transaction_ID && $Transaction_ID != 0 ) ? ' | کد پیگیری: ' . $Transaction_ID : '';
            $event = sprintf( __( 'خطا در هنگام تایید پرداخت: %s%s', 'woo-payping-gateway' ), $message, $tr_id );

            Payping_Order_Panel::log_event( $order, 'failed', $event, array( 'trace_id' => $trace_id ) );

            // Force a new payment on the next attempt.
            $this->invalidate_payment_code( $order, $message );

            do_action( 'woo_payping_Payment_Failed', $order->get_id(), $Transaction_ID, $message );

            wc_add_notice( $this->get_failed_notice( $message, $Transaction_ID ), 'error' );
            
            // Back to the order payment page for another attempt
            wp_redirect( $order->get_checkout_payment_url() );
            exit;
        }

        public function payping_check_currency( $Amount, $currency ) {
            $currency_lower = strtolower( $currency );
            if ( in_array( $currency_lower, array( 'irt', 'toman', 'iran toman', 'iranian toman', 'iran-toman', 'iranian-toman', 'iran_toman', 'iranian_toman', 'تومان', 'تومان ایران' ) ) ) {
                $Amount = $Amount * 1;
            } elseif ( $currency_lower == 'irht' ) {
                $Amount = $Amount * 1000;
            } elseif ( $currency_lower == 'irhr' ) {
                $Amount = $Amount * 100;
            } elseif ( $currency_lower == 'irr' ) {
                $Amount = $Amount / 10;
            }
            return $Amount;
        }

        /**
         * Order total converted to Toman. The filter chain is kept for
         * third-party code that adjusts the amount. Returns an integer.
         */
        private function get_order_amount_in_toman( $order ) {
            $order_id = $order->get_id();
            $currency = apply_filters( 'WC_payping_Currency', $order->get_currency(), $order_id );
            $Amount   = (float) $order->get_total();
            $Amount   = apply_filters( 'woocommerce_order_amount_total_IRANIAN_gateways_before_check_currency', $Amount, $currency );
            $Amount   = $this->payping_check_currency( $Amount, $currency );
            $Amount   = apply_filters( 'woocommerce_order_amount_total_IRANIAN_gateways_after_check_currency', $Amount, $currency );
            $Amount   = apply_filters( 'woocommerce_order_amount_total_IRANIAN_gateways_irt', $Amount, $currency );
            $Amount   = apply_filters( 'woocommerce_order_amount_total_payping_gateway', $Amount, $currency );

            return (int) round( $Amount );
        }

        /**
         * Failure notice based on failed_massage ({transaction_id}, {fault}).
         */
        private function get_failed_notice( $fault, $Transaction_ID = '' ) {
            $message = $this->failed_massage;
            if ( '' === $message ) {
                $message = __( 'پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .', 'woo-payping-gateway' );
            }

            $notice = wpautop( wptexturize( $message ) );
            $notice = str_replace( '{transaction_id}', wp_strip_all_tags( (string) $Transaction_ID ), $notice );
            $notice = str_replace( '{fault}', wp_strip_all_tags( $fault ), $notice );

            return $notice;
        }

        public function status_message( $code ) {
            switch ( $code ) {
                case 200: return 'عملیات با موفقیت انجام شد';
                case 400: return 'مشکلی در ارسال درخواست وجود دارد';
                case 500: return 'مشکلی در سرور رخ داده است';
                case 503: return 'سرور در حال حاضر قادر به پاسخگویی نمی‌باشد';
                case 401: return 'عدم دسترسی';
                case 403: return 'دسترسی غیر مجاز';
                case 404: return 'آیتم درخواستی مورد نظر موجود نمی‌باشد';
                default:  return 'خطای نامشخص';
            }
        }
    }
}