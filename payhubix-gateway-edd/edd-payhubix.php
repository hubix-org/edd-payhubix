<?php
/**
 * Plugin Name: Payhubix Gateway for Easy Digital Downloads
 * Plugin URI: https://payhubix.com
 * Description: Integrates Payhubix payment gateway with Easy Digital Downloads
 * Version: 1.1.0
 * Author: Payhubix TM, Mohammad Bina
 * Author URI: https://payhubix.com
 * License: GPL-2.0+
 * Text Domain: payhubix-gateway-edd
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Payhubix Payment Gateway for Easy Digital Downloads
 */
class Payhubix_EDD_Gateway {
    private $api_key;
    private $shop_id;
    private $time_for_payment;

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_filter('edd_payment_gateways', array($this, 'register_gateway'));
        add_filter('edd_settings_sections_gateways', array($this, 'add_gateway_section'));
        add_filter('edd_settings_gateways', array($this, 'add_gateway_settings'));
        add_action('edd_gateway_payhubix', array($this, 'process_payment'));
        add_action('edd_payhubix_cc_form', array($this, 'payment_form'));
        add_action('init', array($this, 'listen_for_payhubix_callback'));
    }

    public function register_gateway($gateways) {
        $gateways['payhubix'] = array(
            'admin_label' => 'Payhubix',
            'checkout_label' => __('Pay with Payhubix', 'payhubix-gateway-edd')
        );
        return $gateways;
    }

    public function add_gateway_section($sections) {
        $sections['payhubix'] = __('Payhubix', 'payhubix-gateway-edd');
        return $sections;
    }

    public function add_gateway_settings($settings) {
        $payhubix_settings = array(
            array(
                'id' => 'payhubix_settings',
                'name' => '<strong>' . __('Payhubix Settings', 'payhubix-gateway-edd') . '</strong>',
                'type' => 'header',
            ),
            array(
                'id' => 'payhubix_api_key',
                'name' => __('Payhubix API Key', 'payhubix-gateway-edd'),
                'type' => 'textarea',
                'desc' => __('Enter your Payhubix API Key', 'payhubix-gateway-edd')
            ),
            array(
                'id' => 'payhubix_shop_id',
                'name' => __('Payhubix Shop ID', 'payhubix-gateway-edd'),
                'type' => 'text',
                'desc' => __('Enter your Payhubix Shop ID', 'payhubix-gateway-edd')
            ),
            array(
                'id' => 'payhubix_time_for_payment',
                'name' => __('Time for Payment', 'payhubix-gateway-edd'),
                'type' => 'select',
                'options' => array(
                    '00:15' => '15 minutes',
                    '00:30' => '30 minutes',
                    '01:00' => '1 hour',
                    '02:00' => '2 hours',
                    '03:00' => '3 hours',
                ),
                'desc' => __('Select the time allowed for payment', 'payhubix-gateway-edd')
            )
        );

        $gateway_settings = $settings;
        $gateway_settings['payhubix'] = $payhubix_settings;

        return $gateway_settings;
    }

    public function payment_form() {
        return;
    }

    public function process_payment($purchase_data) {
        $this->api_key = edd_get_option('payhubix_api_key');
        $this->shop_id = edd_get_option('payhubix_shop_id');
        $this->time_for_payment = edd_get_option('payhubix_time_for_payment', '02:00');

        if (empty($this->api_key) || empty($this->shop_id)) {
            edd_set_error('payhubix_config_error', __('Payhubix gateway is not configured correctly.', 'payhubix-gateway-edd'));
            edd_send_back_to_checkout();
            return;
        }

        $payment_data = array(
            'price'         => $purchase_data['price'],
            'date'          => $purchase_data['date'],
            'user_email'    => $purchase_data['user_email'],
            'purchase_key'  => $purchase_data['purchase_key'],
            'currency'      => edd_get_currency(),
            'downloads'     => $purchase_data['downloads'],
            'user_info'     => $purchase_data['user_info'],
            'cart_details'  => $purchase_data['cart_details'],
            'gateway'       => 'payhubix',
            'status'        => 'pending'
        );

        $payment_id = edd_insert_payment($payment_data);

        $response = $this->call_payhubix_api($payment_id, $purchase_data);

        if (!isset($response['error']) || $response['error'] === false) {
            edd_update_payment_meta($payment_id, '_payhubix_invoice_id', $response['message']['link']);

            wp_redirect($response['message']['invoice_url']);
            exit;
        } else {
            edd_set_error('payhubix_payment_error', $response['message']);
            edd_send_back_to_checkout();
            return;
        }
    }

    /**
     * Make API call to Payhubix
     */
    private function call_payhubix_api($payment_id, $purchase_data) {
        $url = 'https://api.payhubix.com/v1/payment/shops/' . $this->shop_id . '/invoices/';
        
        $data = [
            'currency_amount'   => (float) $purchase_data['price'],
            'currency_symbol'   => edd_get_currency(),
            'customer_email'    => $purchase_data['user_email'],
            'time_for_payment'  => $this->time_for_payment,
            'currencies'        => [],
            'order_id'          => $payment_id,
            'order_description'=> 'Digital Download Purchase',
            'callback_url'      => add_query_arg(['edd-listener' => 'payhubix', 'order_id' => $payment_id], home_url('index.php')),
        ];

        $args = [
            'body'        => wp_json_encode($data),
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-Api-key'    => $this->api_key,
            ],
            'timeout'     => 30,
        ];

        $response = wp_remote_post($url, $args);
    
        if (is_wp_error($response)) {
            return [
                'error' => true, 
                'message' => $response->get_error_message()
            ];
        }
    
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
    
        if (!is_array($decoded_body) || isset($decoded_body['error']) && $decoded_body['error']) {
            return [
                'error' => true, 
                'message' => 'Invalid response from Payhubix'
            ];
        }

        return $decoded_body;
    }

    public function listen_for_payhubix_callback() {

        if (!isset($_GET['order_id']) || !isset($_GET['edd-listener']) || $_GET['edd-listener'] !== 'payhubix') {
            return;
        }

        $payment_id = sanitize_text_field(wp_unslash($_GET['order_id']));
        
        if (!$payment_id) {
            wp_redirect(edd_get_checkout_uri());
            exit;
        }

        $invoice_id = edd_get_payment_meta($payment_id, '_payhubix_invoice_id', true);

        $data = $this->check_payment_status($invoice_id);

        if (isset($data['error']) && !$data['error']) {
            $invoice_data = $data['message'];

            if ($invoice_id != $invoice_data['link']) {
                wp_redirect(edd_get_checkout_uri());
                exit;
            }

            switch ($invoice_data['status']) {
                case 'Paid':
                    edd_update_payment_status($payment_id, 'complete');
                    edd_insert_payment_note($payment_id, 'Payment successfully processed by Payhubix.');
                    wp_redirect(edd_get_success_page_uri());
                    exit;
                    break;

                case 'Canceled':
                    edd_update_payment_status($payment_id, 'cancelled');
                    edd_insert_payment_note($payment_id, 'Payment was canceled by the customer or Payhubix.');
                    break;

                case 'PartiallyExpired':
                case 'Expired':
                    edd_update_payment_status($payment_id, 'failed');
                    edd_insert_payment_note($payment_id, 'Payment expired or partially expired.');
                    break;

                default:
                    edd_update_payment_status($payment_id, 'pending');
                    edd_insert_payment_note($payment_id, 'Payment status: ' . $invoice_data['status']);
                    break;
            }

            wp_redirect(edd_get_failed_transaction_uri());
            exit;
        } else {
            wp_redirect(edd_get_checkout_uri());
            exit;
        }
    }

    private function check_payment_status($invoice_id) {
        $url = 'https://api.payhubix.com/v1/payment/invoices/' . $invoice_id;

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Api-key' => edd_get_option('payhubix_api_key'),
            ],
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return [
                'error' => true,
                'message' => $response->get_error_message()
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data;
    }
}

function payhubix_edd_init() {
    new Payhubix_EDD_Gateway();
}
add_action('plugins_loaded', 'payhubix_edd_init');
?>