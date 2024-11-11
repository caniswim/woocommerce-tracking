<?php
/*
Plugin Name: WooCommerce Tracking Enhanced
Description: Plugin para aprimorar o rastreamento de pedidos no WooCommerce com mensagens personalizadas e integração com Slack.
Version: 1.0.0
Author: Brunno Vert
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define constantes do plugin
define('WCTE_VERSION', '1.0.0');
define('WCTE_PLUGIN_FILE', __FILE__);
define('WCTE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCTE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Carrega as classes necessárias
require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-core.php';

// Inicializa o plugin
function wcte_init() {
    $wcte = new WCTE_Core();
}
add_action('plugins_loaded', 'wcte_init');