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
        // Classes originais
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-tracking-page.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-api-handler.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-database.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-slack-integration.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-admin-settings.php';
        
        // Novas classes para a API REST
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-rest-api.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-api-settings.php';
        
        // Novas classes para integração com 17track
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-17track-api.php';
        require_once WCTE_PLUGIN_DIR . 'includes/class-wcte-17track-settings.php';
    }

    private function init_hooks() {
        // Ativação e desativação do plugin
        register_activation_hook( WCTE_PLUGIN_FILE, array( $this, 'activate_plugin' ) );
        register_deactivation_hook( WCTE_PLUGIN_FILE, array( $this, 'deactivate_plugin' ) );

        // Inicializa classes originais
        new WCTE_Tracking_Page();
        new WCTE_Admin_Settings();
        
        // Inicializa novas classes
        new WCTE_REST_API();
        new WCTE_API_Settings();
        new WCTE_17Track_Settings();
        
        // Configura cron para atualização de rastreamentos
        WCTE_17Track_Settings::setup_cron();

        // Carrega scripts e estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Adiciona menu de administração
        add_action('admin_menu', array($this, 'admin_menu'));
        
        // Adiciona link para a configuração no plugin
        add_filter('plugin_action_links_' . plugin_basename(WCTE_PLUGIN_FILE), array($this, 'plugin_action_links'));
        
        // Registra rota de API admin para limpar cache de códigos registrados no 17track
        add_action('admin_init', array($this, 'register_settings_routes'));
    }
    
    /**
     * Ativação do plugin
     */
    public function activate_plugin() {
        // Instala o banco de dados
        WCTE_Database::install();
        
        // Configura o cron
        WCTE_17Track_Settings::setup_cron();
        
        // Limpa cache de regras de reescrita
        flush_rewrite_rules();
    }
    
    /**
     * Desativação do plugin
     */
    public function deactivate_plugin() {
        // Remove agendamentos de cron
        wp_clear_scheduled_hook('wcte_check_tracking_updates');
    }

    public function enqueue_scripts() {
        wp_enqueue_style( 'wcte-style', WCTE_PLUGIN_URL . 'assets/css/style.css' );
        wp_enqueue_script( 'wcte-script', WCTE_PLUGIN_URL . 'assets/js/script.js', array( 'jquery' ), false, true );

        // Passa dados do PHP para o JavaScript
        wp_localize_script( 'wcte-script', 'wcte_ajax_object', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ) );
    }

    /**
     * Registra rotas de admin para configurações
     */
    public function register_settings_routes() {
        // Rota para limpar cache de códigos registrados
        add_action('admin_post_wcte_clear_17track_cache', array($this, 'clear_17track_registered_codes_cache'));
    }

    /**
     * Limpa o cache local de códigos registrados no 17track
     */
    public function clear_17track_registered_codes_cache() {
        // Verifica nonce de segurança
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wcte_clear_17track_cache')) {
            wp_die('Ação não autorizada.');
        }
        
        // Verifica permissões
        if (!current_user_can('manage_options')) {
            wp_die('Você não tem permissão para realizar esta ação.');
        }
        
        // Limpa o cache
        delete_option('wcte_17track_registered_codes');
        
        // Redireciona de volta para a página de configurações
        wp_redirect(admin_url('admin.php?page=wcte-settings&tab=17track&cache_cleared=1'));
        exit;
    }
}

