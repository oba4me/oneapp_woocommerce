<?php
/**
 * Plugin Name: Oneapp Woocommerce Checkout
 * Description: Integrates 1app's inline popup checkout with WooCommerce.
 * Version: 1.0
 * Author: Alexander Bamidele
 * Author URI: https://achievablenautomated.com
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// --- Webhook Handler ---
add_action('init', function() {
    if (
        isset($_GET['oneapp_wc_webhook']) &&
        $_GET['oneapp_wc_webhook'] == '1' &&
        $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);

        // Use TrackingID or TrackingRef to get the order ID
        $tracking = $data['TrackingID'] ?? ($data['TrackingRef'] ?? '');
        if ($tracking && preg_match('/WC_(\d+)_/', $tracking, $matches)) {
            $order_id = intval($matches[1]);
            $order = wc_get_order($order_id);

            // Check for Success to update order status
            $is_success = (
                (isset($data['event_status']) && $data['event_status'] === 'success') &&
                (isset($data['trans_status']) && $data['trans_status'] == '01')
            );

            if ($is_success && $order && in_array($order->get_status(), ['pending', 'on-hold'])) {
                $order->payment_complete($data['Reference'] ?? '');
                $order->add_order_note('1app payment verified via webhook/callback and order completed.');
            } elseif ($order && $order->get_status() === 'completed') {
                $order->add_order_note('1app webhook/callback received for already completed order.');
            } elseif ($order) {
                $order->add_order_note('1app webhook/callback received but order not in a payable state.');
            }
        }
        status_header(200);
        echo 'Webhook processed';
        exit;
    }
});

// --- REST API: Public Key ---
add_action('rest_api_init', function () {
    register_rest_route('oneapp/v1', '/public-key', array(
        'methods' => 'GET',
        'callback' => function () {
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $oneapp_gateway = isset($gateways['oneappcheckout']) ? $gateways['oneappcheckout'] : null;
            $public_key = $oneapp_gateway ? $oneapp_gateway->public_key : '';
            return ['publickey' => $public_key];
        },
        'permission_callback' => '__return_true'
    ));
});

// --- Register Oneapp Gateway ---
add_filter('woocommerce_payment_gateways', function($gateways) {
    $gateways[] = 'WC_OneappGateway';
    return $gateways;
});

// --- Icon CSS to Minimize the Checkout Logo ---
add_action('wp_head', function() {
    if (is_checkout()) {
        echo '<style>
            .woocommerce-checkout .payment_methods img[src*="1app.png"] {
                max-width: 80px !important;
                height: auto !important;
            }
        </style>';
    }
});

// --- Oneapp Gateway Class ---
add_action('plugins_loaded', function() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_OneappGateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'oneappcheckout';
            $this->icon = plugins_url('images/1app.png', __FILE__);
            $this->has_fields = false;
            $this->method_title = 'Oneapp Payment';
            $this->method_description = 'Pay securely using Oneapp inline checkout.';
            $this->supports = array( 'products' );

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->public_key = $this->get_option('public_key');
            $this->secret_key = $this->get_option('secret_key');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        }

        public function init_form_fields() {
            $webhook_url = esc_url(home_url('/?oneapp_wc_webhook=1'));
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable 1app Payment',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title on the checkout page.',
                    'default'     => '1app Payment',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Payment method description.<br><strong>Webhook/Callback URL:</strong> <code>' . $webhook_url . '</code>',
                    'default'     => 'Pay securely using 1app inline checkout.',
                ),
                'public_key' => array(
                    'title'       => 'Public Key',
                    'type'        => 'text',
                    'description' => 'Your 1app public key.',
                    'default'     => '',
                ),
                'secret_key' => array(
                    'title'       => 'Secret Key',
                    'type'        => 'text',
                    'description' => 'Your 1app secret key.',
                    'default'     => '',
                ),
            );
        }

        public function payment_scripts() {
            if (!is_checkout() && !is_wc_endpoint_url('order-pay')) return;
            wp_enqueue_script('oneapp-checkout', 'https://js.oneappgo.com/v1/checkout.js', array(), null, true);
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            // Mark as pending (not on-hold or completed)
            $order->update_status('pending', __('Awaiting 1app payment', 'woocommerce'));

            // Reduce stock
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            // Redirect to the order pay page (not thank you page)
            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        public function is_available() {
            return (
                $this->enabled === 'yes'
                && !empty($this->public_key)
                && !empty($this->secret_key)
                && get_woocommerce_currency() === 'NGN'
            );
        }
    }
});

// --- AJAX: Check Order Status for Polling ---
add_action('wp_ajax_oneapp_check_order_status', 'oneapp_check_order_status');
add_action('wp_ajax_nopriv_oneapp_check_order_status', 'oneapp_check_order_status');
function oneapp_check_order_status() {
    $order_id = intval($_GET['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    if ($order) {
        wp_send_json_success(['status' => $order->get_status()]);
    } else {
        wp_send_json_error(['message' => 'Order not found']);
    }
}

// --- Log Payment Response (optional, can be removed in production) ---
add_action('wp_ajax_oneapp_log_payment_response', 'oneapp_log_payment_response');
add_action('wp_ajax_nopriv_oneapp_log_payment_response', 'oneapp_log_payment_response');
function oneapp_log_payment_response() {
    // You may remove this function in production if you don't want to log JS responses
    wp_send_json_success();
}

// --- Inline Popup on Order Pay Page with Polling ---
add_action('woocommerce_receipt_oneappcheckout', function($order_id) {
    $order = wc_get_order($order_id);
    $gateways = WC()->payment_gateways->get_available_payment_gateways();
    $oneapp_gateway = $gateways['oneappcheckout'] ?? null;
    $publickey = $oneapp_gateway ? esc_js($oneapp_gateway->public_key) : '';
    $amount = intval($order->get_total());
    $fname = esc_js($order->get_billing_first_name());
    $lname = esc_js($order->get_billing_last_name());
    $email = esc_js($order->get_billing_email());
    $phone = esc_js($order->get_billing_phone());
    $reference = 'WC_' . $order_id . '_' . time();
    $order_received_url = esc_url($order->get_checkout_order_received_url());
    ?>
<script>
(function waitForOneAppCheckout() {
    if (typeof OneAppCheckout === 'undefined') {
        setTimeout(waitForOneAppCheckout, 100);
        return;
    }
    const intipay = new OneAppCheckout({
        publickey: '<?php echo $publickey; ?>',
        amount: <?php echo $amount; ?>,
        fname: '<?php echo $fname; ?>',
        lname: '<?php echo $lname; ?>',
        customer_email: '<?php echo $email; ?>',
        phone: '<?php echo $phone; ?>',
        reference: '<?php echo $reference; ?>',
        currency: 'NGN',
        onComplete: function(response) {
            if (
                response.status && response.message && response.message.toLowerCase().includes('transaction completed')
            ) {
                alert('✅ Payment received! Awaiting confirmation. You will be redirected once your order is confirmed.');
                pollOrderStatus();
            } else {
                alert('❌ Payment Failed: ' + response.message);
            }
        }
    });
    intipay.makePayment();

    function pollOrderStatus() {
        var attempts = 0, maxAttempts = 24;
        function checkStatus() {
            attempts++;
            jQuery.get('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'oneapp_check_order_status',
                order_id: '<?php echo $order_id; ?>'
            }, function(res) {
                if (res.success && res.data.status === 'completed') {
                    window.location.href = '<?php echo $order_received_url; ?>';
                } else if (attempts < maxAttempts) {
                    setTimeout(checkStatus, 5000);
                } else {
                    alert('Payment confirmation is taking longer than expected. Please check your email or contact support.');
                }
            });
        }
        checkStatus();
    }
})();
</script>
<?php
});