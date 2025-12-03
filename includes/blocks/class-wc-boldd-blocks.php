<?php
error_log("WC_Boldd_Blocks_Support loaded");

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

class WC_Boldd_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'bolddcheckout';

    public function get_payment_method_id() {
        return 'bolddcheckout';
    }

    public function get_payment_method_type() {
        return 'payment-method';
    }

    public function initialize() {
        add_action( 'woocommerce_rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    public function is_active() {
        $settings = get_option( 'woocommerce_bolddcheckout_settings', [] );
        return ! empty( $settings['enabled'] ) && $settings['enabled'] === 'yes';
    }

    public function get_payment_method_icon() {
        return ''; // required
    }

    public function get_payment_method_data() {
        $settings = get_option( 'woocommerce_bolddcheckout_settings', [] );

        return [
            'title'       => $settings['title'] ?? 'Boldd Checkout',
            'description' => $settings['description'] ?? '',
            'supports'    => [ 'products' ],
        ];
    }

    public function get_payment_method_script_handles(): array {
        return [ 'boldd-blocks-script' ];
    }

    public function register_rest_routes() {
        register_rest_route(
            'boldd/v1',
            '/initiate',
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'rest_initiate_payment' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function rest_initiate_payment( WP_REST_Request $request ) {
        $order_id = $request->get_param( 'order_id' );

        if ( ! $order_id ) {
            return new WP_Error( 'no_order', 'No order ID.', [ 'status' => 400 ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'invalid_order', 'Order not found.', [ 'status' => 404 ] );
        }

        $blackCart = new Boldd();
        $init = $blackCart->createPaymentIntent(
            $order->get_total(),
            $order->get_currency(),
            [
                'email'      => $order->get_billing_email(),
                'full_name'  => $order->get_formatted_billing_full_name(),
            ]
        );

        if ( empty( $init['status'] ) ) {
            return new WP_Error( 'failed', 'Initiation failed.', [ 'status' => 400 ] );
        }

        return [
            'status'            => true,
            'authorization_url' => $init['data']['authorization_url'],
            'reference'         => $init['data']['reference'],
        ];
    }
}