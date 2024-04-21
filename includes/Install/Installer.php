<?php

namespace WPSquad\BKashPayments\Install;

use WP_Roles;

/**
 * bKash Payments installer class
 *
 * @author Al Amin Ahamed
 */
class Installer {
    public function do_install() {
        // installs
        $this->add_version_info();
        $this->woocommerce_settings();
        $this->create_tables();
        $this->schedule_cron_jobs();

        if ( ! bkash_payments()->has_woocommerce() ) {
            set_transient( 'bkash_payments_setup_wizard_no_wc', true, 15 * MINUTE_IN_SECONDS );
            set_transient( 'bkash_payments_plugin_version_for_updater', get_option( 'bkash_payments_plugin_version', false ) );
        }


        flush_rewrite_rules();

        $was_installed_before = get_option( 'bkash_payments_plugin_version', false );

        update_option( 'bkash_payments_plugin_version', BKASH_PAYMENTS_PLUGIN_VERSION );

        if ( ! $was_installed_before ) {
            update_option( 'bkash_payments_admin_setup_wizard_ready', false );
            set_transient( '_bkash_payments_setup_page_redirect', true, 30 );
        }
    }

    /**
     * Schedule cron jobs
     *
     * @since 1.0
     *
     * @return void
     */
    private function schedule_cron_jobs() {
        if ( ! function_exists( 'WC' ) || ! WC()->queue() ) {
            return;
        }

        // schedule daily cron job
        $hook = 'bkash_payments_daily_midnight_cron';

        // check if we've defined the cron hook
        $cron_schedule = as_next_scheduled_action( $hook ); // this method will return false if the hook is not scheduled
        if ( $cron_schedule ) {
            as_unschedule_all_actions( $hook );
        }

        // schedule recurring cron action
        $now = bkash_payments_current_datetime()->modify( 'midnight' )->getTimestamp();
        WC()->queue()->schedule_cron( $now, '0 0 * * *', $hook, [], 'bkash_payments' );

        // add cron jobs as needed
    }

    /**
     * Adds plugin installation time.
     *
     * @since 1.0
     *
     * @return boolean
     */
    public function add_version_info() {
        if ( empty( get_option( 'bkash_payments_installed_time' ) ) ) {
            $current_time = bkash_payments_current_datetime()->getTimestamp();
            update_option( 'bkash_payments_installed_time', $current_time );
        }
    }

    /**
     * Update WooCommerce mayaccount registration settings
     *
     * @since 1.0
     *
     * @return void
     */
    public function woocommerce_settings() {
        update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
    }

    /**
     * Create necessary tables
     *
     * @since 1.0
     *
     * @return void
     */
    public function create_tables() {
        include_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->create_transactions_table();
        $this->create_refunds_table();
        $this->create_orders_table();
    }

    /**
     * Create transactons table
     *
     * @return void
     */
    public function create_transactions_table() {
        global $wpdb;

        $sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bkash_payments_transactions` (
                    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    `trxId` int(11) DEFAULT NULL,
                    `sender` varchar(15) DEFAULT NULL,
                    `ref` varchar(100) DEFAULT NULL,
                    `amount` varchar(10) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `trxId` (`trxId`)
               ) ENGINE=InnoDB {$wpdb->get_charset_collate()};";

        dbDelta( $sql );
    }

    /**
     * Create order orders table
     *
     * @return void
     */
    public function create_orders_table() {
        global $wpdb;

        $sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bkash_payments_orders` (
                    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    `order_id` bigint(20) DEFAULT NULL,
                    `seller_id` bigint(20) DEFAULT NULL,
                    `order_total` decimal(19,4) DEFAULT NULL,
                    `net_amount` decimal(19,4) DEFAULT NULL,
                    `order_status` varchar(30) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `order_id` (`order_id`),
                    KEY `seller_id` (`seller_id`)
               ) ENGINE=InnoDB {$wpdb->get_charset_collate()};";

        dbDelta( $sql );
    }

    /**
     * Add new table for refunds request
     *
     * @since 1.0
     *
     * @return void
     */
    public function create_refunds_table() {
        global $wpdb;

        $sql = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bkash_payments_refunds` (
                    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    `order_id` bigint(20) unsigned NOT NULL,
                    `seller_id` bigint(20) NOT NULL,
                    `refund_amount` decimal(19,4) NOT NULL,
                    `refund_reason` text NULL,
                    `item_qtys` varchar(200) NULL,
                    `item_totals` text NULL,
                    `item_tax_totals` text NULL,
                    `restock_items` varchar(10) NULL,
                    `date` timestamp NOT NULL,
                    `status` int(1) NOT NULL,
                    `method` varchar(30) NOT NULL,
                    PRIMARY KEY (id)
               ) ENGINE=InnoDB {$wpdb->get_charset_collate()};";

        dbDelta( $sql );
    }

    /**
     * Show plugin changes from upgrade notice
     *
     * @since 1.0
     */
    public static function in_plugin_update_message( $args ) {
        $transient_name = 'bkash_payments_upgrade_notice_' . $args['Version'];
        $upgrade_notice = get_transient( $transient_name );

        if ( ! $upgrade_notice ) {
            $response = wp_safe_remote_get( 'https://plugins.svn.wordpress.org/woocommerce-bkash-payments/trunk/readme.txt' );

            if ( ! is_wp_error( $response ) && ! empty( $response['body'] ) ) {
                $upgrade_notice = self::parse_update_notice( $response['body'], $args['new_version'] );
                set_transient( $transient_name, $upgrade_notice, DAY_IN_SECONDS );
            }
        }

        echo wp_kses_post( $upgrade_notice );
    }

    /**
     * Parse upgrade notice from readme.txt file.
     *
     * @since 1.0
     *
     * @param string $content
     * @param string $new_version
     *
     * @return string
     */
    private static function parse_update_notice( $content, $new_version ) {
        // Output Upgrade Notice.
        $matches        = null;
        $regexp         = '~==\s*Upgrade Notice\s*==\s*=\s*(.*)\s*=(.*)(=\s*' . preg_quote( BKASH_PAYMENTS_PLUGIN_VERSION, '/' ) . '\s*=|$)~Uis';
        $upgrade_notice = '';

        if ( preg_match( $regexp, $content, $matches ) ) {
            $notices = (array) preg_split( '~[\r\n]+~', trim( $matches[2] ) );

            // Convert the full version strings to minor versions.
            $notice_version_parts  = explode( '.', trim( $matches[1] ) );
            $current_version_parts = explode( '.', BKASH_PAYMENTS_PLUGIN_VERSION );

            if ( 3 !== count( $notice_version_parts ) ) {
                return;
            }

            $notice_version  = $notice_version_parts[0] . '.' . $notice_version_parts[1];
            $current_version = $current_version_parts[0] . '.' . $current_version_parts[1];

            // Check the latest stable version and ignore trunk.
            if ( version_compare( $current_version, $notice_version, '<' ) ) {
                $upgrade_notice .= '</p><p class="bkash-payments-plugin-upgrade-notice">';

                foreach ( $notices as $index => $line ) {
                    $upgrade_notice .= preg_replace( '~\[([^\]]*)\]\(([^\)]*)\)~', '<a href="${2}">${1}</a>', $line );
                }
            }
        }

        return wp_kses_post( $upgrade_notice );
    }
}
