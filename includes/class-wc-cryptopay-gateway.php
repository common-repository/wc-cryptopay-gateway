<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Cryptopay_Gateway extends WC_Payment_Gateway
{

    public function __construct()
    {
        $this->id                 = 'cryptopay';
        $this->method_title       = __('Cryptopay', 'wc-cryptopay-gateway');
        $this->method_description = sprintf(
            __("The simplest way to accept bitcoin and other cryptocurrency", 'wc-cryptopay-gateway')
        );
        $this->has_fields         = false;

        $this->callback_url       = site_url('/wc-api/callback');
        $this->cancel_url         = site_url('/wc-api/cancel');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled         = $this->get_option('enabled');
        $this->title           = $this->get_option('title');
        $this->description     = $this->get_option('description');
        $this->instructions    = $this->get_option('instructions');
        $this->callback_secret = $this->get_option('callback_secret');
        $this->widget_key      = $this->get_option('widget_key');
        $this->theme           = $this->get_option('theme');
        $this->show_qr         = $this->get_option('show_qr') == 'yes';
        $this->env             = $this->get_option('env');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_callback', array($this, 'callback'));
        add_action('woocommerce_api_cancel',   array($this, 'on_cancel'));
    }

    public function init_form_fields()
    {
        $this->form_fields = apply_filters('wc_cryptopay_settings', array(
            'instructions' => array(
                'title'       => __("Important!\n
                For the plugin to work correctly,
                    <ol>
                        <li>
                            <a href='https://business.cryptopay.me' target='_blank'> log in </a> to your account on business.cryptopay.me.
                        </li>
                        <li>
                            Then go to <a href='https://business.cryptopay.me/app/settings/api' target='_blank'> the Settings -> API page </a>
                            and save " . $this->callback_url . " in the Callback URL field
                        </li>
                    </ol>", 'wc-cryptopay-gateway'),
                'type'        => 'text',
                'description' => __('', 'wc-cryptopay-gateway'),
                'default'     => $this->callback_url,
                'desc_tip'    => false,
            ),
            'enabled' => array(
                'title'       => __('Enable Cryptopay', 'wc-cryptopay-gateway'),
                'label'       => __('', 'wc-cryptopay-gateway'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes',
            ),
            'title' => array(
                'title'       => __('Title', 'wc-cryptopay-gateway'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-cryptopay-gateway'),
                'default'     => __('Cryptopay', 'wc-cryptopay-gateway'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Payment method description', 'wc-cryptopay-gateway'),
                'type'        => 'text',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-cryptopay-gateway'),
                'default'     => __('Pay with crypto via Cryptopay.', 'wc-cryptopay-gateway'),
                'desc_tip'    => true,
            ),
            'callback_secret' => array(
                'title'       => __('Callback secret', 'wc-cryptopay-gateway'),
                'type'        => 'text',
                'description' => __('Get the Callback secret via the Settings -> API page in your account on business.cryptopay.me', 'wc-cryptopay-gateway'),
                'default'     => __('', 'wc-cryptopay-gateway'),
                'desc_tip'    => true,
            ),
            'widget_key' => array(
                'title'       => __('Widget key', 'wc-cryptopay-gateway'),
                'type'        => 'text',
                'description' => __('Get the Widget key via the Settings -> Widget page in your account on business.cryptopay.me', 'wc-cryptopay-gateway'),
                'default'     => __('', 'wc-cryptopay-gateway'),
                'desc_tip'    => true,
            ),
            'theme' => array(
                'title'             => __('Theme', 'wc-cryptopay-gateway'),
                'type'              => 'select',
                'css'               => 'width: 400px;',
                'default'           => 'Light',
                'description'       => __('To control the color design of the payment page.', 'wc-cryptopay-gateway'),
                'options'           => array('light' => 'Light', 'dark' => 'Dark'),
                'desc_tip'          => true,
            ),
            'show_qr' => array(
                'title'       => __('Show QR ', 'wc-cryptopay-gateway'),
                'label'       => __('', 'wc-cryptopay-gateway'),
                'type'        => 'checkbox',
                'description' => 'Put a tick to open the QR code on the page',
                'default'     => 'yes',
            ),
            'env' => array(
                'title'             => __('Environment', 'wc-cryptopay-gateway'),
                'type'              => 'select',
                'css'               => 'width: 400px;',
                'default'           => 'Sandbox',
                'options'           => array('sand' => 'Sandbox', 'prod' => 'Production'),
            ),
        ));
    }

    public function callback()
    {
        $body = file_get_contents('php://input');
        if (!$this->verify_callback($body, $_SERVER["HTTP_X_CRYPTOPAY_SIGNATURE"])) {
            wc_add_notice('API Failure: wrong signature', 'error');
            return;
        }
        $req = json_decode($body, true);
        if ($req["type"] != "Invoice") {
            return;
        }
        $data = $req["data"];
        $order_id = wc_get_order_id_by_order_key($data['custom_id']);
        $order = wc_get_order($order_id);
        if (!$this->verify_amount($order, $data)) {
            return $this->on_invalid_amount($order);
        }
        if ($data["status"] == "new") {
            return $this->on_create($order);
        }
        if (
            $data["status"] == "completed"
            || $data["status"] == "unresolved" && $data["status_context"] == "overpaid"
        ) {
            return $this->on_succcess($order);
        }
        if (
            $data["status"] == "cancelled"
            || $data["status"] == "refunded"
            || $data["status"] == "unresolved"
        ) {
            return $this->on_cancel($order);
        }
    }

    private function verify_callback($body, $signature)
    {
        $expected = hash_hmac('sha256', $body, $this->callback_secret);
        return $expected === $signature;
    }

    private function verify_amount($order, $callback)
    {
        return $order->get_currency() == $callback['price_currency']
            && $order->get_total() <= floatval($callback['price_amount']);
    }

    public function on_create($order)
    {
        if (!$order) {
            return;
        }
        $order->update_status('on-hold', __('Awaiting Cryptopay payment', 'wc-cryptopay-gateway'));
        wp_redirect(get_home_url());
        exit;
    }

    public function on_succcess($order)
    {
        if (!$order) {
            return;
        }
        $order->update_status('completed', __('Cryptopay payment recieved', 'wc-cryptopay-gateway'));
        $order->payment_complete();
        WC()->cart->empty_cart();

        wp_redirect($order->get_checkout_order_received_url());
        exit;
    }

    public function on_cancel($order)
    {
        if (!$order) {
            $order_id = wc_get_order_id_by_order_key($_GET['customId']);
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        $order->update_status('cancelled', __('Cryptopay payment cancelled', 'wc-cryptopay-gateway'));
        wp_redirect(wc_get_cart_url());
        exit;
    }

    private function on_invalid_amount($order)
    {
        $order->update_status('failed', __('Invalid Cryptopay payment amount', 'wc-cryptopay-gateway'));
        exit;
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order->update_status('on-hold', __('Awaiting Cryptopay payment', 'wc-cryptopay-gateway'));
        $currency = $order->get_data()['currency'];
        $total  = $order->get_total();
        $url = ($this->env == 'prod' ? WC_CRYPTOPAY_BEBOP_PAY_URL_PROD : WC_CRYPTOPAY_BEBOP_PAY_URL_SAND)
            . '?' . http_build_query(array(
                'customId' => $order->get_order_key(),
                'description' => 'WooCommerce order #' . $order->id,
                'priceAmount' => $total,
                'priceCurrency' => $currency,
                'successRedirectUrl' => $this->get_return_url($order),
                'unsuccessRedirectUrl' => $this->cancel_url,
                'widgetKey' => $this->widget_key,
                'isShowQr' => $this->show_qr ? 'true' : 'false',
                'theme' => $this->theme,
            ));

        return array(
            'result' => 'success',
            'redirect' => $url
        );
    }
}
