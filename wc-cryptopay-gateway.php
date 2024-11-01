<?php
/**
 * Plugin Name: Cryptopay Gateway for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/wc-cryptopay-gateway/
 * Description: Accept crypto – get fiat! Make the most of receiving, exchanging and sending crypto globally.
 * Author: Cryptopay Team
 * Author URI: https://business.cryptopay.me/
 * Version: 1.2.0
 * Requires at least: 4.4
 * Tested up to: 5.6.2
 * WC requires at least: 2.6.0
 * WC tested up to: 5.0.0
 * Text Domain: wc-cryptopay-gateway
 *
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce fallback notice.
 *
 * @return string
 */
function woocommerce_cryptopay_missing_wc_notice()
{
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('Cryptopay requires WooCommerce to be installed and active. You can download %s here.', 'wc-cryptopay-gateway'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

add_action('plugins_loaded', 'woocommerce_cryptopay_gateway_init');

function woocommerce_cryptopay_gateway_init()
{
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', 'woocommerce_cryptopay_missing_wc_notice');
        return;
    }

    if (! class_exists('WC_Cryptopay')) :
    /**
     * Required minimums and constants
     */
    define('WC_CRYPTOPAY_VERSION', '1.2.0');
    define('WC_CRYPTOPAY_MIN_PHP_VER', '5.6.0');
    define('WC_CRYPTOPAY_MIN_WC_VER', '2.6.0');
    define('WC_CRYPTOPAY_MAIN_FILE', __FILE__);
    define('WC_CRYPTOPAY_BEBOP_PAY_URL_SAND', 'https://pay-business-sandbox.cryptopay.me');
    // define('WC_CRYPTOPAY_BEBOP_PAY_URL_SAND', 'https://bebop-pay.stagingpay.com/');
    define('WC_CRYPTOPAY_BEBOP_PAY_URL_PROD', 'https://business-pay.cryptopay.me');
    define('WC_CRYPTOPAY_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
    define('WC_CRYPTOPAY_PLUGIN_PATH', untrailingslashit(plugin_dir_path(__FILE__)));

    class WC_Cryptopay
    {

            /**
             * @var Singleton The reference the *Singleton* instance of this class
             */
        private static $instance;

        /**
         * @var Reference to logging class.
         */
        private static $log;

        /**
         * Returns the *Singleton* instance of this class.
         *
         * @return Singleton The *Singleton* instance.
         */
        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Private clone method to prevent cloning of the instance of the
         * *Singleton* instance.
         *
         * @return void
         */
        private function __clone()
        {
        }

        /**
         * unserialize method to prevent unserializing of the *Singleton*
         * instance.
         *
         * @return void
         */
        function __wakeup()
        {
        }

        /**
         * Protected constructor to prevent creating a new instance of the
         * *Singleton* via the `new` operator from outside of this class.
         */
        private function __construct()
        {
            add_action('admin_init', array( $this, 'install' ));
            $this->init();
        }

        /**
         * Init the plugin after plugins_loaded so environment variables are set.
         *
         */
        public function init()
        {
            require_once(dirname(__FILE__) . '/includes/class-wc-cryptopay-gateway.php');

            add_filter('woocommerce_payment_gateways', array( $this, 'add_gateways' ));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'plugin_action_links' ));

            if (version_compare(WC_VERSION, '3.4', '<')) {
                add_filter('woocommerce_get_sections_checkout', array( $this, 'filter_gateway_order_admin' ));
            }
        }

        /**
         * Updates the plugin version in db
         *
         */
        public function update_plugin_version()
        {
            delete_option('wc_cryptopay_version');
            update_option('wc_cryptopay_version', WC_CRYPTOPAY_VERSION);
        }

        /**
         * Handles upgrade routines.
         *
         */
        public function install()
        {
            if (! is_plugin_active(plugin_basename(__FILE__))) {
                return;
            }

            if (! defined('IFRAME_REQUEST') && (WC_CRYPTOPAY_VERSION !== get_option('wc_cryptopay_version'))) {
                do_action('woocommerce_cryptopay_updated');

                if (! defined('WC_CRYPTOPAY_INSTALLING')) {
                    define('WC_CRYPTOPAY_INSTALLING', true);
                }

                $this->update_plugin_version();
            }
        }

        /**
         * Adds plugin action links.
         *
         */
        public function plugin_action_links($links)
        {
            $plugin_links = array(
                    '<a href="admin.php?page=wc-settings&tab=checkout&section=cryptopay">' . esc_html__('Settings', 'wc-cryptopay-gateway') . '</a>',
                    '<a href="https://business.cryptopay.me">' . esc_html__('Website', 'wc-cryptopay-gateway') . '</a>',
                    '<a href="https://developers.cryptopay.me/guides/prebuilt-integrations/e-commerce-payment-plugins" target="_blank">' . esc_html__('Docs', 'wc-cryptopay-gateway') . '</a>',
                );
            return array_merge($plugin_links, $links);
        }

        /**
         * Add the gateways to WooCommerce.
         *
         */
        public function add_gateways($methods)
        {
            $methods[] = 'WC_Cryptopay_Gateway';
            return $methods;
        }

        /**
         * Modifies the order of the gateways displayed in admin.
         *
         */
        public function filter_gateway_order_admin($sections)
        {
            unset($sections['cryptopay']);
            $sections['cryptopay'] = 'Cryptopay';
            return $sections;
        }
    }

    WC_Cryptopay::get_instance();
    endif;
}
