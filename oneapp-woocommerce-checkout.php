<?php
/**
 * Plugin Name: Boldd WooCommerce Checkout
 * Description: Integrates Boldd's inline popup checkout with WooCommerce.
 * Version: 2.0
 * Author: Alexander Bamidele
 * Author URI: https://achievablenautomated.com
 * Text Domain: boldd
 * Domain Path: /languages
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --- Webhook Handler ---
// NOTE: kept same webhook GET param to avoid changing the gateway side URL as requested.
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

            // Check for Success to update order status.
            $is_success = (
                (isset($data['event_status']) && $data['event_status'] === 'success') &&
                (isset($data['trans_status']) && $data['trans_status'] == '01')
            );

            if ($is_success && $order && in_array($order->get_status(), ['pending', 'on-hold'])) {
                $order->payment_complete(isset($data['Reference']) ? sanitize_text_field($data['Reference']) : '');
                $order->add_order_note(__('Boldd payment verified via webhook/callback and order completed.', 'boldd'));
            } elseif ($order && $order->get_status() === 'completed') {
                $order->add_order_note(__('Boldd webhook/callback received for already completed order.', 'boldd'));
            } elseif ($order) {
                $order->add_order_note(__('Boldd webhook/callback received but order not in a payable state.', 'boldd'));
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
            if (! function_exists('WC')) {
                return rest_ensure_response(['publickey' => '']);
            }
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $oneapp_gateway = isset($gateways['oneappcheckout']) ? $gateways['oneappcheckout'] : null;
            $public_key = $oneapp_gateway ? $oneapp_gateway->public_key : '';
            return rest_ensure_response(['publickey' => $public_key]);
        },
        'permission_callback' => '__return_true'
    ));
});

// --- Register Gateway ---
add_filter('woocommerce_payment_gateways', function($gateways) {
    $gateways[] = 'WC_OneappGateway';
    return $gateways;
});

// --- Icon + Styling for Checkout (branding) ---
add_action('wp_head', function() {
    if (is_checkout()) {
        // Restrict CSS to checkout; minimize selector dependency on filename only.
        echo '<style>
            /* Reduce logo size and apply Boldd brand color */
            .woocommerce-checkout .payment_methods img[src*="boldd-logo.png"] {
                max-width: 80px !important;
                height: auto !important;
            }
            /* Apply brand color to gateway title/label where possible */
            .woocommerce-checkout .payment_methods li.payment_method_oneappcheckout label,
            .woocommerce-checkout .payment_methods li.payment_method_oneappcheckout .payment_box {
                color: #214df5;
            }
            /* If plugin outputs a button or link, ensure color coherence */
            .woocommerce-checkout .payment_methods li.payment_method_oneappcheckout .button {
                background-color: #214df5 !important;
                border-color: #214df5 !important;
                color: #fff !important;
            }
        </style>';
    }
});

// --- Main Gateway Class ---
add_action('plugins_loaded', function() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_OneappGateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'oneappcheckout'; // kept to avoid breaking existing stored options/hooks
            $this->icon = plugins_url('images/boldd-logo.png', __FILE__);
            $this->has_fields = false;
            $this->method_title = __('Boldd Payment', 'boldd');
            $this->method_description = __('Pay securely using Boldd inline checkout.', 'boldd');
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
            $webhook_url = esc_url(home_url('/?oneapp_wc_webhook=1')); // kept same param on purpose
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'boldd'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Boldd Payment', 'boldd'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __('Title', 'boldd'),
                    'type'        => 'text',
                    'description' => __('This controls the title on the checkout page.', 'boldd'),
                    'default'     => __('Boldd Payment', 'boldd'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'boldd'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description.<br><strong>Webhook/Callback URL:</strong> <code>' . $webhook_url . '</code>', 'boldd'),
                    'default'     => __('Pay securely using Boldd inline checkout.', 'boldd'),
                ),
                'public_key' => array(
                    'title'       => __('Public Key', 'boldd'),
                    'type'        => 'text',
                    'description' => __('Your Boldd public key.', 'boldd'),
                    'default'     => '',
                ),
                'secret_key' => array(
                    'title'       => __('Secret Key', 'boldd'),
                    'type'        => 'text',
                    'description' => __('Your Boldd secret key.', 'boldd'),
                    'default'     => '',
                ),
            );
        }

        public function payment_scripts() {
            if (!is_checkout() && !is_wc_endpoint_url('order-pay')) return;
            // Include the Boldd checkout JS (unchanged url).
            wp_enqueue_script('oneapp-checkout', 'https://js.oneappgo.com/v1/checkout.js', array('jquery'), null, true);
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            if (! $order) {
                return array(
                    'result' => 'fail',
                    'redirect' => wc_get_checkout_url()
                );
            }

            // Mark as pending (not on-hold or completed)
            $order->update_status('pending', __('Awaiting Boldd payment', 'boldd'));

            // Reduce stock
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            // Redirect to order pay page to trigger inline popup on receipt page
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

// --- (Optional) Log endpoint kept for debugging ---
add_action('wp_ajax_oneapp_log_payment_response', 'oneapp_log_payment_response');
add_action('wp_ajax_nopriv_oneapp_log_payment_response', 'oneapp_log_payment_response');
function oneapp_log_payment_response() {
    // This can be removed in production; currently returns success so JS won't error.
    wp_send_json_success();
}

// --- Inline Popup on Order Pay Page with Polling ---
add_action('woocommerce_receipt_oneappcheckout', function($order_id) {
    $order = wc_get_order($order_id);
    $gateways = function_exists('WC') ? WC()->payment_gateways->get_available_payment_gateways() : array();
    $oneapp_gateway = $gateways['oneappcheckout'] ?? null;
    $publickey = $oneapp_gateway ? esc_js($oneapp_gateway->public_key) : '';
    // Use raw number (no formatting) for the amount. If Boldd expects kobo, multiply accordingly.
    $amount = number_format((float) $order->get_total(), 2, '.', '');
    $fname = esc_js($order->get_billing_first_name());
    $lname = esc_js($order->get_billing_last_name());
    $email = esc_js($order->get_billing_email());
    $phone = esc_js($order->get_billing_phone());
    $reference = 'WC_' . intval($order_id) . '_' . time();
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
            // The gateway returns different shapes; check typical success indicators.
            if (response && ((response.status && response.message && response.message.toLowerCase().includes('transaction completed')) || (response.responsecode && response.responsecode === '01'))) {
                alert('✅ Payment Received! Awaiting Confirmation. You will be redirected once your order is confirmed.');
                pollOrderStatus();
            } else {
                alert('❌ Payment Failed: ' + (response.message || 'Unknown response'));
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
                order_id: '<?php echo intval($order_id); ?>'
            }, function(res) {
                if (res.success && res.data.status === 'completed') {
                    window.location.href = '<?php echo $order_received_url; ?>';
                } else if (attempts < maxAttempts) {
                    setTimeout(checkStatus, 5000);
                } else {
                    alert('Payment confirmation is taking longer than expected. Please check your email or contact support at hi@useboldd.com');
                }
            });
        }
        checkStatus();
    }
})();
</script>
<?php
});
