<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_Admin_Settings {
    private $logs = array();

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_wcte_clear_logs', array( $this, 'clear_logs' ) );
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wcte_settings') === false) {
            return;
        }

        wp_enqueue_script('wcte-admin', WCTE_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), '1.0.0', true);
        wp_localize_script('wcte-admin', 'wcte_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcte_admin_nonce')
        ));

        wp_add_inline_style('admin-bar', '
            .wcte-logs-viewer {
                background: #f6f7f7;
                border: 1px solid #ddd;
                padding: 10px;
                margin-top: 20px;
                border-radius: 4px;
            }
            .wcte-logs-viewer textarea {
                width: 100%;
                min-height: 300px;
                font-family: monospace;
                margin-bottom: 10px;
            }
            .wcte-firebase-rules {
                background: #f6f7f7;
                border: 1px solid #ddd;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .wcte-firebase-rules pre {
                background: #fff;
                padding: 10px;
                overflow-x: auto;
            }
        ');
    }

    public function add_menu_pages() {
        // Menu principal
        add_menu_page(
            'WooCommerce Tracking Enhanced',           
            'WC Tracking Enhanced',                    
            'manage_options',                          
            'wcte_settings',                           
            array( $this, 'render_credentials_page' ),    
            'dashicons-admin-tools',                   
            56                                         
        );

        // Submenu para mensagens fictícias
        add_submenu_page(
            'wcte_settings',
            'Mensagens Fictícias',
            'Mensagens Fictícias',
            'manage_options',
            'wcte_fictitious',
            array( $this, 'render_fictitious_page' )
        );

        // Renomeia o primeiro item do submenu
        global $submenu;
        if (isset($submenu['wcte_settings'])) {
            $submenu['wcte_settings'][0][0] = 'Credenciais';
        }
    }

    public function register_settings() {
        // Registra as configurações dos Correios
        register_setting( 'wcte_correios_settings', 'wcte_correios_api_key' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_username' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_password' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_cartao_postagem' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_contrato' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_token' );
        register_setting( 'wcte_correios_settings', 'wcte_slack_webhook_url' );

        // Registra as configurações do Firebase
        register_setting( 'wcte_correios_settings', 'wcte_firebase_api_key' );
        register_setting( 'wcte_correios_settings', 'wcte_firebase_database_url' );
        register_setting( 'wcte_correios_settings', 'wcte_firebase_project_id' );

        // Registra configuração de timezone
        register_setting( 'wcte_correios_settings', 'wcte_timezone', array(
            'type' => 'string',
            'default' => 'America/Sao_Paulo'
        ));

        // Registra as configurações de mensagens fictícias
        register_setting( 'wcte_fictitious_settings', 'wcte_fictitious_messages', array(
            'sanitize_callback' => array($this, 'sanitize_fictitious_messages')
        ));

        // Seção dos Correios
        add_settings_section(
            'wcte_correios_section',
            'Configurações dos Correios',
            null,
            'wcte_correios_settings'
        );

        // Seção do Firebase
        add_settings_section(
            'wcte_firebase_section',
            'Configurações do Firebase',
            array($this, 'render_firebase_section'),
            'wcte_correios_settings'
        );

        // Seção de Timezone
        add_settings_section(
            'wcte_timezone_section',
            'Configurações de Fuso Horário',
            null,
            'wcte_correios_settings'
        );

        // Seção do Slack
        add_settings_section(
            'wcte_slack_section',
            'Configurações do Slack',
            null,
            'wcte_correios_settings'
        );

        // Campo de Timezone
        add_settings_field(
            'wcte_timezone',
            'Fuso Horário',
            array($this, 'timezone_field_callback'),
            'wcte_correios_settings',
            'wcte_timezone_section'
        );

        // Campos dos Correios
        add_settings_field(
            'wcte_correios_api_key',
            'Código de Acesso à API dos Correios',
            array( $this, 'api_key_field_callback' ),
            'wcte_correios_settings',
            'wcte_correios_section'
        );

        add_settings_field(
            'wcte_correios_username',
            'Usuário do Meu Correios',
            array( $this, 'username_field_callback' ),
            'wcte_correios_settings',
            'wcte_correios_section'
        );

        add_settings_field(
            'wcte_correios_password',
            'Senha do Meu Correios',
            array( $this, 'password_field_callback' ),
            'wcte_correios_settings',
            'wcte_correios_section'
        );

        add_settings_field(
            'wcte_correios_cartao_postagem',
            'Cartão de Postagem dos Correios',
            array( $this, 'cartao_postagem_field_callback' ),
            'wcte_correios_settings',
            'wcte_correios_section'
        );

        add_settings_field(
            'wcte_correios_contrato',
            'Contrato dos Correios',
            array( $this, 'contrato_field_callback' ),
            'wcte_correios_settings',
            'wcte_correios_section'
        );

        add_settings_field(
            'wcte_correios_token',
            'Token dos Correios (opcional)',
            array( $this, 'token_field_callback' ),
            'wcte_correios_settings',
            'wcte_correios_section'
        );

        // Campos do Firebase
        add_settings_field(
            'wcte_firebase_api_key',
            'API Key do Firebase',
            array( $this, 'firebase_api_key_field_callback' ),
            'wcte_correios_settings',
            'wcte_firebase_section'
        );

        add_settings_field(
            'wcte_firebase_database_url',
            'URL do Realtime Database',
            array( $this, 'firebase_database_url_field_callback' ),
            'wcte_correios_settings',
            'wcte_firebase_section'
        );

        add_settings_field(
            'wcte_firebase_project_id',
            'ID do Projeto',
            array( $this, 'firebase_project_id_field_callback' ),
            'wcte_correios_settings',
            'wcte_firebase_section'
        );

        // Campo do Slack
        add_settings_field(
            'wcte_slack_webhook_url',
            'URL do Webhook do Slack',
            array( $this, 'slack_webhook_field_callback' ),
            'wcte_correios_settings',
            'wcte_slack_section'
        );
    }

    public function timezone_field_callback() {
        $current_timezone = get_option('wcte_timezone', 'America/Sao_Paulo');
        $timezones = array(
            'America/Sao_Paulo' => 'São Paulo (GMT-3)',
            'America/Manaus' => 'Manaus (GMT-4)',
            'America/Belem' => 'Belém (GMT-3)',
            'America/Fortaleza' => 'Fortaleza (GMT-3)',
            'America/Recife' => 'Recife (GMT-3)',
            'America/Noronha' => 'Fernando de Noronha (GMT-2)',
            'America/Campo_Grande' => 'Campo Grande (GMT-4)',
            'America/Cuiaba' => 'Cuiabá (GMT-4)',
            'America/Porto_Velho' => 'Porto Velho (GMT-4)',
            'America/Boa_Vista' => 'Boa Vista (GMT-4)',
            'America/Rio_Branco' => 'Rio Branco (GMT-5)'
        );

        echo '<select name="wcte_timezone" id="wcte_timezone">';
        foreach ($timezones as $tz => $label) {
            echo '<option value="' . esc_attr($tz) . '" ' . selected($current_timezone, $tz, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
        echo '<p class="description">Selecione o fuso horário para exibição das atualizações de rastreio.</p>';
    }

    // ... rest of the class methods remain unchanged ...
}

// Inicializa a classe de configurações
new WCTE_Admin_Settings();
