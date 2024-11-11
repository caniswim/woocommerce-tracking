<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove opções do plugin
delete_option( 'wcte_correios_api_key' );
delete_option( 'wcte_slack_webhook_url' );
delete_option( 'wcte_fictitious_messages' );

// Remove tabela personalizada do banco de dados
global $wpdb;
$table_name = $wpdb->prefix . 'wcte_order_tracking';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
