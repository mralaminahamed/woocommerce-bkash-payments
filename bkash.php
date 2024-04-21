<?php
/**
 * Plugin Name: bKash Payments for WooCommerce
 * Plugin URI:  https://woocommerce.com/products/woocommerce-bkash-payments/
 * Description: A bKash payment gateway plugin for WooCommerce.
 * Version:     1.0
 * Author:      Al Amin Ahamed
 * Author URI:  https://alaminahamed.com/
 * License:     GPL-2.0
 * Text Domain: bkash-payments-for-woocommerce
 *
 * Requires at least: 6.4
 * Requires PHP: 7.2
 * Requires Plugins: woocommerce
 *
 * WC requires at least: 3.9
 * WC tested up to: 8.8.2
 *
 * @package BKashPayments
 * @category Payments
 */

// don't call the file directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce - bKash integration
 *
 * @author Al Amin Ahamed
 */
class BKashPayments {
    /**
     * Plugin version
     *
     * @var string
     */
    public $version = '1.0';

    /**
     * Instance of self
     *
     * @var BKashPayments
     */
    private static $instance = null;

    /**
     * Minimum PHP version required
     *
     * @var string
     */
    private $min_php = '7.4';

    /**
     * Holds various class instances
     *
     * @since 1.0
     *
     * @var array
     */
    private $container = [];

    /**
     * Databse version key
     *
     * @since 1.0
     *
     * @var string
     */
    private $db_version_key = '_bkash_payments_version';

    /**
     * Kick off the plugin
     */
    public function __construct() {
        register_activation_hook( __FILE__, array($this, 'activate') );

        // https://woocommerce.com/document/high-performance-order-storage/
        // https://developer.woocommerce.com/2022/01/17/the-plan-for-the-woocommerce-custom-order-table/
        add_action( 'before_woocommerce_init', [ $this, 'declare_woocommerce_feature_compatibility' ] );
        add_action( 'woocommerce_loaded', [ $this, 'init_plugin' ] );
        add_filter( 'woocommerce_payment_gateways', array($this, 'register_gateway') );
    }

    /**
     * Initializes the BKashPayments() class
     *
     * Checks for an existing BKashPayments() instance
     * and if it doesn't find one, create it.
     */
    public static function init() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Check if the PHP version is supported
     *
     * @return bool
     */
    public function is_supported_php() {
        if ( version_compare( PHP_VERSION, $this->min_php, '<=' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path() {
        return untrailingslashit( plugin_dir_path( __FILE__ ) );
    }

    /**
     * Get the template path.
     *
     * @return string
     */
    public function template_path() {
        return apply_filters( 'bkash_payments_template_path', 'bkash-payments/' );
    }

    /**
     * Placeholder for activation function
     *
     * Nothing being called here yet.
     */
    public function activate() {
        if ( ! $this->has_woocommerce() ) {
            set_transient( 'bkash_payments_wc_missing_notice', true );
        }

        if ( ! $this->is_supported_php() ) {
            require_once WC_ABSPATH . 'includes/wc-notice-functions.php';

            /* translators: 1: Required PHP Version 2: Running php version */
            wc_print_notice( sprintf( __( 'The Minimum PHP Version Requirement for <b>bKash Payments</b> is %1$s. You are Running PHP %2$s', 'woocommerce-bkash-payments' ), $this->min_php, phpversion() ), 'error' );
            exit;
        }

        require_once dirname( __FILE__ ) . '/includes/Install/Installer.php';
        $installer                   = new \BKashPayments\Install\Installer();
        $installer->do_install();

        // // rewrite rules during activation
        if ( $this->has_woocommerce() ) {
            $this->flush_rewrite_rules();
        }
    }

    /**
     * Add High Performance Order Storage Support
     *
     * @since 3.8.0
     *
     * @return void
     */
    public function declare_woocommerce_feature_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
        }
    }

    /**
     * Load the plugin after Woo Commerce is loaded
     *
     * @return void
     */
    public function init_plugin() {
        $this->includes();
        $this->init_hooks();

        do_action( 'bkash_payments_loaded' );
    }

    /**
     * Include all the required files
     *
     * @return void
     */
    public function includes() {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        require_once dirname( __FILE__ ) . '/includes/Gateway/class-wc-bkash.php';
        require_once dirname( __FILE__ ) . '/includes/Gateway/class-wc-gateway-bkash-payments.php';
    }

    /**
     * Initialize the actions
     *
     * @return void
     */
    public function init_hooks() {
        // Localize our plugin
        add_action( 'init', [ $this, 'localization_setup' ] );

        // // initialize the classes
        add_action( 'init', [ $this, 'init_classes' ], 4 );
        add_action( 'init', [ $this, 'wpdb_table_shortcuts' ], 1 );
    }

    /**
     * Init all the classes
     *
     * @return void
     */
    public function init_classes() {}

    /**
     * Flush rewrite rules after dokan is activated or woocommerce is activated
     *
     * @since 3.2.8
     */
    public function flush_rewrite_rules() {
        flush_rewrite_rules();
    }

    /**
     * Placeholder for deactivation function
     *
     * Nothing being called here yet.
     */
    public function deactivate() {
        delete_transient( 'bkash_payments_wc_missing_notice', true );
    }

    /**
     * Initialize plugin for localization
     *
     * @uses load_plugin_textdomain()
     */
    public function localization_setup() {
        load_plugin_textdomain( 'woocommerce-bkash-payments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Define all constants
     *
     * @return void
     */
    public function define_constants() {
        $this->define( 'BKASH_PAYMENTS_PLUGIN_VERSION', $this->version );
        $this->define( 'BKASH_PAYMENTS_FILE', __FILE__ );
        $this->define( 'BKASH_PAYMENTS_DIR', __DIR__ );
        $this->define( 'BKASH_PAYMENTS_INC_DIR', __DIR__ . '/includes' );
        $this->define( 'BKASH_PAYMENTS_LIB_DIR', __DIR__ . '/lib' );
        $this->define( 'BKASH_PAYMENTS_PLUGIN_ASSEST', plugins_url( 'assets', __FILE__ ) );

        // give a way to turn off loading styles and scripts from parent theme
        $this->define( 'BKASH_PAYMENTS_LOAD_STYLE', true );
        $this->define( 'BKASH_PAYMENTS_LOAD_SCRIPTS', true );
    }

    /**
     * Define constant if not already defined
     *
     * @since 1.0
     *
     * @param string      $name
     * @param string|bool $value
     *
     * @return void
     */
    private function define( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    /**
     * Load table prefix for withdraw and orders table
     *
     * @since 1.0
     *
     * @return void
     */
    public function wpdb_table_shortcuts() {
        global $wpdb;

        $wpdb->bkash_payments_transactions  = $wpdb->prefix . 'bkash_payments_transactions';
        $wpdb->bkash_payments_orders        = $wpdb->prefix . 'bkash_payments_orders';
        $wpdb->bkash_payments_refund        = $wpdb->prefix . 'bkash_payments_refund';
    }

    /**
     * Check whether woocommerce is installed and active
     *
     * @since 2.9.16
     *
     * @return bool
     */
    public function has_woocommerce() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Check whether woocommerce is installed
     *
     * @since 3.2.8
     *
     * @return bool
     */
    public function is_woocommerce_installed() {
        return in_array( 'woocommerce/woocommerce.php', array_keys( get_plugins() ), true );
    }

    /**
     * Handles scenerios when WooCommerce is not active
     *
     * @since 2.9.27
     *
     * @return void
     */
    public function woocommerce_not_loaded() {
        if ( did_action( 'woocommerce_loaded' ) || ! is_admin() ) {
            return;
        }

        require_once DOKAN_INC_DIR . '/functions.php';

        if ( get_transient( '_dokan_setup_page_redirect' ) ) {
            dokan_redirect_to_admin_setup_wizard();
        }

        new \WeDevs\Dokan\Admin\SetupWizardNoWC();
    }

    /**
     * Register WooCommerce Gateway
     *
     * @param  array  $gateways
     *
     * @return array
     */
    function register_gateway( $gateways ) {
        $gateways[] = 'WC_Gateway_bKash_Payments';

        return $gateways;
    }

    /**
     * Get Dokan db version key
     *
     * @since 3.0.0
     *
     * @return string
     */
    public function get_db_version_key() {
        return $this->db_version_key;
    }
}
/**
 * Load BKash_payments Plugin when all plugins loaded
 *
 * @return BKashPayments
 */
function bkash_payments() { // phpcs:ignore
    return BKashPayments::init();
}

// Lets Go....
bkash_payments();
