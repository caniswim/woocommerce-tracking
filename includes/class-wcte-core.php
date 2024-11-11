<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_Core {

    public function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-tracking-page.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-api-handler.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-database.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-slack-integration.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-admin-settings.php';
    }

    private function init_hooks() {
        // Ativação e desativação do plugin
        register_activation_hook( __FILE__, array( 'WCTE_Database', 'install' ) );
        register_uninstall_hook( __FILE__, array( 'WCTE_Database', 'uninstall' ) );

        // Inicializa classes
        new WCTE_Tracking_Page();
        new WCTE_Admin_Settings();

        // Carrega scripts e estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts() {
        wp_enqueue_style( 'wcte-style', WCTE_PLUGIN_URL . 'assets/css/style.css' );
        wp_enqueue_script( 'wcte-script', WCTE_PLUGIN_URL . 'assets/js/script.js', array( 'jquery' ), false, true );

        // Passa dados do PHP para o JavaScript
        wp_localize_script( 'wcte-script', 'wcte_ajax_object', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ) );
    }
}

