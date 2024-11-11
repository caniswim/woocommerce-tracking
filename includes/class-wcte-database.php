<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_Database {

    public static function install() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcte_order_tracking';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            woocommerce_order_id BIGINT NOT NULL,
            yampi_order_number VARCHAR(20) NOT NULL,
            email VARCHAR(255) NOT NULL,
            tracking_code VARCHAR(50) NOT NULL,
            carrier ENUM('correios', 'cainiao') NOT NULL,
            tracking_status ENUM('pending', 'in_transit', 'delivered') NOT NULL DEFAULT 'pending',
            last_checked DATETIME DEFAULT NULL,
            scheduled_notification_time DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    public static function uninstall() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcte_order_tracking';
        $sql = "DROP TABLE IF EXISTS $table_name;";
        $wpdb->query( $sql );
    }
}

