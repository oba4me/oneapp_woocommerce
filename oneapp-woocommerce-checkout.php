<?php
/**
 * Plugin Name: Boldd WooCommerce Checkout
 * Description: Integrates Boldd inline/standard checkout with WooCommerce. Uses gateway's Reference as canonical transaction id.
 * Version: 2.1
 * Author: Alexander Bamidele
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function boldd_log_debug( $message ) {
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        if ( is_array( $message ) || is_object( $message ) ) {
            $message = print_r( $message, true );
        }
        error_log( '[Boldd DEBUG] ' . $message );
    }
}

define( 'BOLDD_PLUGIN_VERSION', '2.1' );

/**
 * --- WEBHOOK Hander (supports old param as fallback) ---
 */
add_action( 'init', function() {
    $is_webhook = ( isset( $_GET['boldd_wc_webhook'] ) && $_GET['boldd_wc_webhook'] == '1' )
               || ( isset( $_GET['oneapp_wc_webhook'] ) && $_GET['oneapp_wc_webhook'] == '1' );

    if ( ! $is_webhook || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        return;
    }

    boldd_log_debug('Webhook endpoint hit');

    $payload = file_get_contents( 'php://input' );
    boldd_log_debug('Oneapp webhook payload: ' . $payload);

    $data = json_decode( $payload, true );

    if ( ! is_array( $data ) ) {
        status_header(400);
        echo 'Invalid payload';
        boldd_log_debug('Webhook: Invalid payload, not JSON.');
        exit;
    }

    // Attempt to find the order from TrackingID / TrackingRef (fallback)
    $tracking = $data['TrackingID'] ?? ( $data['TrackingRef'] ?? '' );
    $order = false;
    $order_id = 0;

    if ( $tracking && preg_match( '/WC_(\d+)_/', $tracking, $matches ) ) {
        $order_id = intval( $matches[1] );
        $order = wc_get_order( $order_id );
    }

    // If no order found by tracking, attempt other heuristics (meta search)
    if ( ! $order && ! empty( $data['TrackingID'] ) ) {
        // Try to find order by meta (older installs may have stored tracking somewhere)
        $orders = wc_get_orders( [
            'limit'  => 1,
            'status' => array( 'pending', 'on-hold', 'processing' ),
            'meta_key' => '_boldd_initiated_reference',
            'meta_value' => $data['TrackingID'],
            'return' => 'objects'
        ] );
        if ( ! empty( $orders ) && is_array( $orders ) ) {
            $order = $orders[0];
            $order_id = $order->get_id();
        }
    }

    // If still not found, try TrackingRef or other provided identifiers
    if ( ! $order && ! empty( $data['TrackingRef'] ) && preg_match( '/WC_(\d+)_/', $data['TrackingRef'], $matches2 ) ) {
        $order_id = intval( $matches2[1] );
        $order = wc_get_order( $order_id );
    }

    if ( ! $order ) {
        boldd_log_debug("Webhook: Order not found from tracking. Data: " . print_r( $data, true ));
        status_header(404);
        echo 'Order not found';
        exit;
    }

    // Gateway-provided canonical reference (the one we will use as transaction id)
    $gateway_reference = $data['Reference'] ?? ( $data['reference'] ?? '' );
    $tracking_ref = $tracking ?: ( $data['TrackingRef'] ?? '' );

    $order->add_order_note( 'Webhook received. Tracking: ' . $tracking_ref . ', Reference: ' . $gateway_reference );
    boldd_log_debug("Webhook: Found order {$order_id}. Event status: " . ($data['event_status'] ?? '') . " Trans status: " . ($data['trans_status'] ?? ''));

    // Unified success detection - flexible with common variations
    $event_status = strtolower( trim( (string) ( $data['event_status'] ?? $data['status'] ?? '' ) ) );
    $trans_status = strtolower( trim( (string) ( $data['trans_status'] ?? $data['responsecode'] ?? $data['statuscode'] ?? '' ) ) );
    $responsecode = (string) ( $data['responsecode'] ?? $data['data']['responsecode'] ?? '' );

    $is_event_success = in_array( $event_status, array( 'success', 'successful', 'completed', '1', 'true' ), true );
    $is_trans_success = in_array( $trans_status, array( '01', '1', 'success', 'successful', 'approved' ), true )
                        || in_array( $responsecode, array( '01', '1' ), true );

    $is_success = $is_event_success && $is_trans_success;

    if ( $is_success && in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {

        // Save the real gateway reference so all verification flows use it
        if ( ! empty( $gateway_reference ) ) {
            $order->update_meta_data( '_boldd_gateway_reference', sanitize_text_field( $gateway_reference ) );
            // Set the WC transaction id too
            if ( method_exists( $order, 'set_transaction_id' ) ) {
                $order->set_transaction_id( sanitize_text_field( $gateway_reference ) );
            } else {
                $order->update_meta_data( '_transaction_id', sanitize_text_field( $gateway_reference ) );
            }
            $order->save();
        }

        // Mark payment complete (this will reduce stock, send emails, etc.)
        // payment_complete accepts optional transaction id; passing gateway_reference if available
        try {
            if ( ! empty( $gateway_reference ) ) {
                $order->payment_complete( sanitize_text_field( $gateway_reference ) );
            } else {
                $order->payment_complete();
            }

            // If still pending (some shops prefer processing), set to processing when needed
            if ( $order->get_status() === 'pending' ) {
                if ( $order->needs_processing() ) {
                    $order->update_status( 'processing', 'Updated after Boldd webhook payment confirmation.' );
                } else {
                    $order->update_status( 'completed', 'Updated after Boldd webhook payment confirmation.' );
                }
            }

            $order->add_order_note( 'Boldd payment verified via webhook and order completed. Gateway Reference: ' . ( $gateway_reference ?: 'N/A' ) );
            boldd_log_debug("Webhook: Order {$order_id} marked complete (gateway reference: {$gateway_reference}).");

        } catch ( Exception $e ) {
            boldd_log_debug("Webhook: Exception when completing order {$order_id}: " . $e->getMessage() );
            $order->add_order_note( 'Error when completing order after Boldd webhook: ' . $e->getMessage() );
        }

    } elseif ( $order->get_status() === 'completed' || $order->get_status() === 'processing' ) {
        $order->add_order_note( 'Boldd webhook received for already completed/processing order.' );
        boldd_log_debug("Webhook: Order {$order_id} already completed/processing.");
    } else {
        $order->add_order_note( 'Boldd webhook received but order not in a payable state. Current status: ' . $order->get_status() );
        boldd_log_debug("Webhook: Order {$order_id} not in payable state (" . $order->get_status() . ").");
    }

    status_header(200);
    echo 'Webhook processed';
    exit;
});

/**
 * --- Register REST endpoint returning public key (optional) ---
 */
add_action( 'rest_api_init', function() {
    register_rest_route(
        'boldd/v1',
        '/public-key',
        array(
            'methods' => 'GET',
            'callback' => function() {
                if ( ! function_exists( 'WC' ) ) {
                    return rest_ensure_response( array( 'publickey' => '' ) );
                }
                $gateways = WC()->payment_gateways->get_available_payment_gateways();
                $gw = $gateways['bolddcheckout'] ?? null;
                $public_key = $gw ? $gw->public_key : '';
                return rest_ensure_response( array( 'publickey' => $public_key ) );
            },
            'permission_callback' => '__return_true',
        )
    );
} );

/**
 * --- Register Boldd Gateway into WooCommerce ---
 */
add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
    $gateways[] = 'WC_Boldd_Gateway';
    return $gateways;
} );

/**
 * Enqueue small CSS on checkout only
 */
add_action( 'wp_head', function() {
    if ( is_checkout() ) {
        echo '<style>
            .woocommerce-checkout .payment_methods img[src*="boldd-logo.png"] { max-width: 80px !important; height: auto !important; }
            .boldd-brand-button { background-color: #214df5 !important; color: #fff !important; border: none !important; padding: .5rem 1rem; border-radius: 3px; cursor: pointer; }
        </style>';
    }
} );

// Cron schedule
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['every_five_minutes'] ) ) {
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes' ),
        );
    }
    return $schedules;
});

register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'boldd_verify_pending_orders' ) ) {
        wp_schedule_event( time(), 'every_five_minutes', 'boldd_verify_pending_orders' );
    }
});

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'boldd_verify_pending_orders' );
});

add_action('wp_ajax_boldd_initiate_payment', function() {
    $gateway = new WC_Boldd_Gateway();
    $gateway->ajax_initiate_payment();
});
add_action('wp_ajax_nopriv_boldd_initiate_payment', function() {
    $gateway = new WC_Boldd_Gateway();
    $gateway->ajax_initiate_payment();
});

/**
 * --- Gateway Class ---
 */
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    class WC_Boldd_Gateway extends WC_Payment_Gateway {
        public $public_key;
        public $secret_key;
        public $checkout_mode;
        public $dashboard_url;

        public function __construct() {
            $this->id = 'bolddcheckout';
            $this->icon = plugins_url( 'images/boldd-logo.png', __FILE__ );
            $this->has_fields = false;
            $this->method_title = 'Boldd Payment';
            $this->method_description = 'Pay securely using Boldd inline or standard checkout.';
            $this->supports = array( 'products' );

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option( 'enabled', 'no' );
            $this->title = $this->get_option( 'title', 'Boldd Payment' );
            $this->description = $this->get_option( 'description', 'Pay securely using Boldd.' );
            $this->public_key = $this->get_option( 'public_key', '' );
            $this->secret_key = $this->get_option( 'secret_key', '' );
            $this->checkout_mode = $this->get_option( 'checkout_mode', 'inline' );
            $this->dashboard_url = $this->get_option( 'dashboard_url', 'http://dash.useboldd.com' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

            add_action( 'wp_ajax_boldd_verify_payment', array( $this, 'ajax_verify_payment' ) );
            add_action( 'wp_ajax_nopriv_boldd_verify_payment', array( $this, 'ajax_verify_payment' ) );
        }

        public function init_form_fields() {
            $webhook_url = esc_url_raw( home_url( '/?boldd_wc_webhook=1' ) );
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'type'        => 'checkbox',
                    'label'       => 'Enable Boldd Payment',
                    'default'     => 'yes',
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Controls the title the user sees during checkout.',
                    'default'     => 'Boldd Payment',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Payment method description. <br><strong>Webhook/Callback URL:</strong> <code>' . $webhook_url . '</code>',
                    'default'     => 'Pay securely using Boldd inline checkout.',
                ),
                'public_key' => array(
                    'title'       => 'Public Key',
                    'type'        => 'text',
                    'description' => 'Your Boldd public key.',
                    'default'     => '',
                ),
                'secret_key' => array(
                    'title'       => 'Secret Key',
                    'type'        => 'text',
                    'description' => 'Your Boldd secret key (used for server-side verification).',
                    'default'     => '',
                ),
                'checkout_mode' => array(
                    'title'       => 'Checkout Mode',
                    'type'        => 'select',
                    'options'     => array(
                        'inline'   => 'Inline (popup on order pay page)',
                        'standard' => 'Standard (redirect to hosted payment page)',
                    ),
                    'description' => 'Choose how the checkout should behave.',
                    'default'     => 'inline',
                ),
                'dashboard_url' => array(
                    'title'       => 'Boldd Dashboard URL',
                    'type'        => 'text',
                    'description' => 'URL to Boldd dashboard. Also shown as an admin quick link.',
                    'default'     => 'https://dash.useboldd.com',
                ),
            );
        }

        public function payment_scripts() {
            if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
                return;
            }

            wp_enqueue_script( 'boldd-checkout-js', 'https://js.oneappgo.com/v1/checkout.js', array( 'jquery' ), null, true );

            wp_localize_script( 'boldd-checkout-js', 'boldd_params', array(
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'brand_color'  => '#214df5',
            ) );
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                return array(
                    'result'   => 'failure',
                    'redirect' => wc_get_checkout_url(),
                );
            }

            // Mark as pending
            $order->update_status( 'pending', __( 'Awaiting Boldd payment', 'woocommerce' ) );

            // Reduce stock
            wc_reduce_stock_levels( $order_id );

            // Remove cart
            WC()->cart->empty_cart();

            // Redirect user to pay page (order-pay)
            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url( true ),
            );
        }

        public function is_available() : bool {
            if ( $this->enabled !== 'yes' ) {
                return false;
            }
            if ( empty( $this->public_key ) || empty( $this->secret_key ) ) {
                return false;
            }
            if ( function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() !== 'NGN' ) {
                return false;
            }
            return true;
        }

        /**
         * AJAX verify endpoint
         */
        public function ajax_verify_payment() {
            $reference = sanitize_text_field( $_REQUEST['reference'] ?? '' );
            $order_id = intval( $_REQUEST['order_id'] ?? 0 );

            boldd_log_debug("AJAX verify called for order $order_id with reference $reference");

            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    boldd_log_debug("AJAX verify: Order $order_id not found.");
                    wp_send_json_error( array( 'message' => 'Order not found' ), 404 );
                }
            } else {
                boldd_log_debug('AJAX verify: Missing order_id parameter.');
                wp_send_json_error( array( 'message' => 'Missing order id' ), 400 );
            }

            // If no explicit reference, try stored gateway reference
            if ( empty( $reference ) ) {
                $reference = $order->get_meta( '_boldd_gateway_reference', true );
                if ( empty( $reference ) ) {
                    // fallback to initiated reference
                    $reference = $order->get_meta( '_boldd_initiated_reference', true );
                }
            }

            if ( empty( $reference ) ) {
                boldd_log_debug('AJAX verify: No reference available to verify.');
                wp_send_json_error( array( 'message' => 'No reference to verify' ), 400 );
            }

            if ( empty( $this->secret_key ) ) {
                boldd_log_debug('AJAX verify: Secret key not configured.');
                wp_send_json_error( array( 'message' => 'Secret key not configured' ), 500 );
            }

            $response = $this->verify_reference_with_api( $reference, $this->secret_key );

            if ( is_wp_error( $response ) ) {
                boldd_log_debug('AJAX verify: Verification API error: ' . $response->get_error_message() );
                wp_send_json_error( array( 'message' => 'Verification API error', 'details' => $response->get_error_message() ), 502 );
            }

            $body = wp_remote_retrieve_body( $response );
            $code = wp_remote_retrieve_response_code( $response );

            boldd_log_debug("AJAX verify: API response code $code, body: $body");

            if ( 200 !== $code ) {
                wp_send_json_error( array( 'message' => 'Verification failed', 'http_code' => $code, 'body' => $body ), 502 );
            }

            $data = json_decode( $body, true );

            // Flexible success check
            $event_status = strtolower( (string) ( $data['event_status'] ?? $data['status'] ?? '' ) );
            $trans_status = strtolower( (string) ( $data['data']['trans_status'] ?? $data['data']['responsecode'] ?? $data['trans_status'] ?? '' ) );
            $responsecode = (string) ( $data['data']['responsecode'] ?? $data['responsecode'] ?? '' );

            $is_event_success = in_array( $event_status, array( 'success','successful','completed','1','true' ), true );
            $is_trans_success = in_array( $trans_status, array( '01','1','success','successful','approved' ), true ) || in_array( $responsecode, array( '01','1' ), true );

            $is_success = ( (isset($data['status']) && (bool)$data['status'] === true) || $is_event_success ) && $is_trans_success;

            if ( $is_success ) {
                if ( in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
                    // Save gateway reference if present in verify response
                    $gateway_reference = $data['data']['reference'] ?? ( $data['Reference'] ?? $reference );
                    if ( ! empty( $gateway_reference ) ) {
                        $order->update_meta_data( '_boldd_gateway_reference', sanitize_text_field( $gateway_reference ) );
                        if ( method_exists( $order, 'set_transaction_id' ) ) {
                            $order->set_transaction_id( sanitize_text_field( $gateway_reference ) );
                        }
                    }

                    $order->save();

                    $order->payment_complete( sanitize_text_field( $gateway_reference ) );
                    $order->add_order_note( 'Boldd payment automatically verified (server-side verifytrans). Reference: ' . $gateway_reference );
                    boldd_log_debug("AJAX verify: Order {$order->get_id()} marked complete.");
                }

                wp_send_json_success( array( 'message' => 'Payment verified', 'order_status' => $order->get_status() ) );
            }

            boldd_log_debug("AJAX verify: Payment not successful. Data: " . print_r( $data, true ) );
            wp_send_json_error( array( 'message' => 'Payment not successful', 'details' => $data ), 400 );
        }

        /**
         * AJAX: initiate standard checkout
         */
       public function ajax_initiate_payment() {
            $order_id = intval($_POST['order_id'] ?? 0);

            if (!$order_id) {
                wp_send_json_error(['message' => 'Missing order ID'], 400);
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                wp_send_json_error(['message' => 'Order not found'], 404);
            }

            $secret = $this->secret_key;
            if (empty($secret)) {
                wp_send_json_error(['message' => 'Secret key not configured'], 500);
            }

            // Build internal initiated reference (for our mapping)
            $initiated_reference = 'WC_' . $order_id . '_' . time();

            // Build redirect url (use order received url and append our initiated ref)
            $order_received_url = $order->get_checkout_order_received_url();
            $url_parts = parse_url($order_received_url);
            parse_str($url_parts['query'] ?? '', $query_params);

            // Remove potential previous txref/transref to avoid duplicates
            unset($query_params['txref'], $query_params['transref']);

            $query_params['txref'] = $initiated_reference;
            $query_params['transref'] = $initiated_reference;
            $new_query = http_build_query($query_params);

            $redirect_url = $url_parts['scheme'] . '://' . $url_parts['host'];
            if (isset($url_parts['port'])) {
                $redirect_url .= ':' . $url_parts['port'];
            }
            $redirect_url .= $url_parts['path'] ?? '';
            $redirect_url .= '?' . $new_query;
            if (isset($url_parts['fragment'])) {
                $redirect_url .= '#' . $url_parts['fragment'];
            }

            $payload = [
                'reference'      => $initiated_reference,
                'amount'         => number_format((float) $order->get_total(), 2, '.', ''),
                'customer_email' => $order->get_billing_email(),
                'phone'          => $order->get_billing_phone(),
                'currency'       => 'NGN',
                'redirecturl'    => $redirect_url,
                'fname'          => $order->get_billing_first_name(),
                'lname'          => $order->get_billing_last_name(),
                'meta_data' => [
                    'trackingid' => $initiated_reference
                ]
            ];

            $response = $this->initiate_payment_with_api($payload, $secret);

            if (is_wp_error($response)) {
                wp_send_json_error([
                    'message' => 'Init API error',
                    'details' => $response->get_error_message()
                ], 502);
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (empty($body['status']) || empty($body['authorization_url'])) {
                wp_send_json_error([
                    'message' => 'Invalid initiate response',
                    'body'    => $body
                ], 400);
            }

            // Save the initiated reference to order meta so we can map back if needed
            $order->update_meta_data('_boldd_initiated_reference', sanitize_text_field( $initiated_reference ) );
            $order->save();

            wp_send_json_success([
                'authorization_url' => $body['authorization_url']
            ]);
        }

        /**
         * Helper: call verifytrans API using wp_remote_post
         */
        public function verify_reference_with_api( string $reference, string $secret ) {
            $url = 'https://api.oneappgo.com/v1/business/verifytrans';
            $args = array(
                'body'    => wp_json_encode( array( 'reference' => $reference ) ),
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret,
                    'Content-Type'  => 'application/json',
                ),
                'timeout'     => 20,
                'redirection' => 5,
            );

            return wp_remote_post( $url, $args );
        }

        /**
         * Helper: call initiatetrans API using wp_remote_post
         */
        public function initiate_payment_with_api( array $payload, string $secret ) {
            $url = 'https://api.oneappgo.com/v1/business/initiatetrans';

            $args = array(
                'body'    => wp_json_encode($payload),
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret,
                    'Content-Type'  => 'application/json',
                ),
                'timeout'     => 20,
                'redirection' => 5,
            );

            return wp_remote_post( $url, $args );
        }

    }
} );

/**
 * --- Receipt / Order Pay output (inline popup or standard redirect) ---
 */
add_action( 'woocommerce_receipt_bolddcheckout', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $gateways = WC()->payment_gateways->get_available_payment_gateways();
    $boldd_gateway = $gateways['bolddcheckout'] ?? null;
    $publickey = $boldd_gateway ? esc_js( $boldd_gateway->public_key ) : '';

    $amount = number_format((float) $order->get_total(), 2, '.', '');
    $fname = esc_js( $order->get_billing_first_name() );
    $lname = esc_js( $order->get_billing_last_name() );
    $email = esc_js( $order->get_billing_email() );
    $phone = esc_js( $order->get_billing_phone() );

    // Use the initiated reference if present, otherwise fall back (plugin will accept gateway's Reference)
    $reference = $order->get_meta('_boldd_initiated_reference', true);
    if ( empty( $reference ) ) {
        $reference = 'WC_' . $order_id . '_' . time();
        $order->update_meta_data('_boldd_initiated_reference', sanitize_text_field($reference));
        $order->save();
    }

    $order_received_url = esc_url( $order->get_checkout_order_received_url() );

    $mode = $boldd_gateway ? $boldd_gateway->checkout_mode : 'inline';
    ?>
    <div id="boldd-payment-wrap" style="padding:20px 0;">
        <p><?php esc_html_e( 'You will be redirected to pay with Boldd shortly.' ); ?></p>
        <p><strong><?php echo esc_html( $boldd_gateway ? $boldd_gateway->title : 'Boldd Payment' ); ?></strong></p>
    </div>

    <div id="boldd-verifying-overlay" style="
    display:none; position:fixed; top:0; left:0; width:100%; height:100%;
    background:rgba(0,0,0,0.65); z-index:999999; color:#fff; text-align:center;
    padding-top:20%; font-size:20px;">
        <div id="boldd-verifying-text">Verifying your payment…<br>Please wait, do not close this page.</div>
    </div>

    <script type="text/javascript">
    (function ($) {
        'use strict';

        var publickey = '<?php echo $publickey; ?>';
        var reference = '<?php echo esc_js( $reference ); ?>';
        var orderId = '<?php echo (int) $order_id; ?>';
        var orderReceivedUrl = '<?php echo $order_received_url; ?>';
        var customer = {
            fname: '<?php echo $fname; ?>',
            lname: '<?php echo $lname; ?>',
            email: '<?php echo $email; ?>',
            phone: '<?php echo $phone; ?>'
        };
        var amount = '<?php echo $amount; ?>';
        var checkoutMode = '<?php echo esc_js( $mode ); ?>';

        function serverVerify(reference, orderId) {
            return $.ajax({
                url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                data: {
                    action: 'boldd_verify_payment',
                    reference: reference,
                    order_id: orderId
                },
                method: 'POST',
                dataType: 'json'
            });
        }

        function openInline() {
            function waitForOneApp() {
                if (typeof OneAppCheckout === 'undefined') {
                    setTimeout(waitForOneApp, 100);
                    return;
                }
                var intipay = new OneAppCheckout({
                    publickey: publickey,
                    amount: amount,
                    fname: customer.fname,
                    lname: customer.lname,
                    customer_email: customer.email,
                    phone: customer.phone,
                    reference: reference,
                    currency: 'NGN',
                    onComplete: function(response) {
                        var ref = response.reference || response.Reference || reference;
                        serverVerify(ref, orderId).done(function(res) {
                            if (res.success) {
                                window.location.href = orderReceivedUrl;
                            } else {
                                pollStatus();
                            }
                        }).fail(function() {
                            pollStatus();
                        });
                    },
                    onClose: function() {}
                });
                intipay.makePayment();
            }
            waitForOneApp();
        }

        function openStandard() {
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'boldd_initiate_payment',
                    order_id: orderId
                },
                success: function(res) {
                    if (res.success && res.data.authorization_url) {
                        window.location.href = res.data.authorization_url;
                    } else {
                        alert('Unable to initiate payment. Please try again.');
                    }
                },
                error: function(xhr) {
                    alert('Payment initiation failed. Please try again.');
                }
            });
        }

        function pollStatus() {
            var attempts = 0, maxAttempts = 30;
            $('#boldd-verifying-overlay').fadeIn(200);
            $('#boldd-verifying-text').html("Verifying your payment…<br>Please wait, do not close this page.");

            function check() {
                attempts++;
                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    method: 'GET',
                    data: {
                        action: 'oneapp_check_order_status',
                        order_id: orderId
                    },
                    success: function(res) {
                        if (res.success && (res.data.status === 'completed' || res.data.status === 'processing')) {
                            $('#boldd-verifying-text').html("Payment received!<br>Redirecting…");
                            setTimeout(function () {
                                window.location.href = orderReceivedUrl;
                            }, 800);
                            return;
                        }
                        if (attempts < maxAttempts) {
                            setTimeout(check, 4000);
                        } else {
                            $('#boldd-verifying-text').html("Your payment was received, but final confirmation is still pending.<br>You will receive an email once completed.<br><br><strong>You may now safely close this page.</strong>");
                        }
                    },
                    error: function() {
                        if (attempts < maxAttempts) {
                            setTimeout(check, 4000);
                        } else {
                            $('#boldd-verifying-text').html("Payment verification is delayed.<br>You will receive an email once confirmed.");
                        }
                    }
                });
            }

            check();
        }

        $(function() {
            if (checkoutMode === 'inline') {
                openInline();
            } else {
                openStandard();
            }
        });
    })(jQuery);
    </script>
    <?php
} );

/**
 * --- AJAX: Backwards-compatible order status polling ---
 */
add_action( 'wp_ajax_oneapp_check_order_status', 'oneapp_check_order_status' );
add_action( 'wp_ajax_nopriv_oneapp_check_order_status', 'oneapp_check_order_status' );
function oneapp_check_order_status() {
    $order_id = intval( $_GET['order_id'] ?? 0 );
    $order = wc_get_order( $order_id );
    if ( $order ) {
        wp_send_json_success( array( 'status' => $order->get_status() ) );
    }
    wp_send_json_error( array( 'message' => 'Order not found' ), 404 );
}

//Verify on reidirect
add_action('wp_ajax_nopriv_oneapp_verify_on_redirect', 'oneapp_verify_on_redirect');
add_action('wp_ajax_oneapp_verify_on_redirect', 'oneapp_verify_on_redirect');

function oneapp_verify_on_redirect()
{
    $order_id = intval($_GET['order_id'] ?? 0);
    $reference = sanitize_text_field($_GET['ref'] ?? '');

    if (!$order_id || !$reference) {
        wp_die('missing');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die('no-order');
    }

    if ($order->is_paid()) {
        wp_die('already-paid');
    }

    $verify = oneapp_api_verifytrans($reference);

    if ($verify && isset($verify->status) && $verify->status == 1) {
        if (!empty($verify->reference)) {
            $order->update_meta_data('_boldd_gateway_reference', $verify->reference);
            $order->set_transaction_id($verify->reference);
        }

        $order->add_order_note('Payment verified via redirect AJAX.');
        $order->payment_complete();
    }

    wp_die('ok');
}

//Thank you
add_action('woocommerce_thankyou', 'oneapp_show_pending_payment_notice', 5);
function oneapp_show_pending_payment_notice($order_id)
{
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    // If order is already paid, nothing to do
    if ($order->is_paid()) return;

    echo '<div class="woocommerce-info" style="margin-top:20px;padding:15px;font-size:16px;">
            <strong>Your payment is being confirmed...</strong><br>
            Please do not make a second payment.  
            You will receive an email once the payment verification is complete.
          </div>';

    // Hide pay button
    echo "
    <style>
        .woocommerce-order .order-again,
        .woocommerce-order .woocommerce-order-overview .pay {
            display:none !important;
        }
        a.pay {
            display:none !important;
        }
    </style>
    ";
}

/**
 * --- Scheduled cron to verify pending orders every 5 minutes ---
 */
add_action( 'boldd_verify_pending_orders', function() {
    $args = [
        'limit'        => -1,
        'status'       => ['pending', 'on-hold'],
        'return'       => 'ids',
    ];

    $orders = wc_get_orders( $args );

    if ( empty( $orders ) ) {
        return;
    }

    $gateway = new WC_Boldd_Gateway();

    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) continue;

        // Prefer stored gateway reference
        $reference = $order->get_meta( '_boldd_gateway_reference', true );

        // If no gateway reference, try initiated reference so we can verify whatever we started
        if ( empty( $reference ) ) {
            $reference = $order->get_meta( '_boldd_initiated_reference', true );
        }

        if ( empty( $reference ) ) {
            continue;
        }

        $response = $gateway->verify_reference_with_api( $reference, $gateway->secret_key );

        if ( is_wp_error( $response ) ) {
            boldd_log_debug("Cron verify: WP_Error for order {$order_id}: " . $response->get_error_message() );
            continue;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            boldd_log_debug("Cron verify: Non-200 for order {$order_id}: {$code}");
            continue;
        }

        $data = json_decode( $body, true );

        $event_status = strtolower( (string) ( $data['event_status'] ?? $data['status'] ?? '' ) );
        $trans_status = strtolower( (string) ( $data['data']['trans_status'] ?? $data['data']['responsecode'] ?? $data['trans_status'] ?? '' ) );
        $responsecode = (string) ( $data['data']['responsecode'] ?? $data['responsecode'] ?? '' );

        $is_event_success = in_array( $event_status, array( 'success','successful','completed','1','true' ), true );
        $is_trans_success = in_array( $trans_status, array( '01','1','success','successful','approved' ), true ) || in_array( $responsecode, array( '01','1' ), true );

        $is_success = ( (isset($data['status']) && (bool)$data['status'] === true) || $is_event_success ) && $is_trans_success;

        if ( $is_success && in_array( $order->get_status(), ['pending','on-hold'], true ) ) {
            $gateway_reference = $data['data']['reference'] ?? ( $data['Reference'] ?? $reference );
            if ( ! empty( $gateway_reference ) ) {
                $order->update_meta_data( '_boldd_gateway_reference', sanitize_text_field( $gateway_reference ) );
                if ( method_exists( $order, 'set_transaction_id' ) ) {
                    $order->set_transaction_id( sanitize_text_field( $gateway_reference ) );
                }
                $order->save();
            }

            $order->payment_complete( sanitize_text_field( $gateway_reference ) );
            $order->add_order_note( 'Boldd payment automatically verified via scheduled cron.' );
            boldd_log_debug("Cron verify: Order {$order_id} marked complete (gateway reference: {$gateway_reference}).");
        }
    }
});

/**
 * --- Plugin links ---
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $dashboard = 'http://dash.useboldd.com';
    $links[] = '<a href="' . esc_url( $dashboard ) . '" target="_blank" rel="noopener noreferrer">Boldd Dashboard</a>';
    return $links;
});