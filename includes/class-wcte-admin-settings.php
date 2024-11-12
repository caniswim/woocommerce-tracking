<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_settings_page() {
        add_menu_page(
            'WooCommerce Tracking Enhanced',           
            'WC Tracking Enhanced',                    
            'manage_options',                          
            'wcte_settings',                           
            array( $this, 'render_settings_page' ),    
            'dashicons-admin-tools',                   
            56                                         
        );
    }

    public function register_settings() {
        // Registra as configurações dos Correios
        register_setting( 'wcte_correios_settings', 'wcte_correios_api_key' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_username' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_password' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_cartao_postagem' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_contrato' );
        register_setting( 'wcte_correios_settings', 'wcte_correios_token' );

        // Registra as configurações do Slack
        register_setting( 'wcte_slack_settings', 'wcte_slack_webhook_url' );

        // Registra as configurações de mensagens fictícias
        register_setting( 'wcte_fictitious_settings', 'wcte_fictitious_messages' );

        // Seção dos Correios
        add_settings_section(
            'wcte_correios_section',
            'Configurações dos Correios',
            null,
            'wcte_correios_settings'
        );

        // Seção do Slack
        add_settings_section(
            'wcte_slack_section',
            'Configurações do Slack',
            null,
            'wcte_slack_settings'
        );

        // Seção de Mensagens Fictícias
        add_settings_section(
            'wcte_fictitious_section',
            'Configurações de Mensagens Fictícias',
            null,
            'wcte_fictitious_settings'
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

        // Campo do Slack
        add_settings_field(
            'wcte_slack_webhook_url',
            'URL do Webhook do Slack',
            array( $this, 'slack_webhook_field_callback' ),
            'wcte_slack_settings',
            'wcte_slack_section'
        );

        // Campo de Mensagens Fictícias
        add_settings_field(
            'wcte_fictitious_messages',
            'Mensagens Fictícias',
            array( $this, 'fictitious_messages_field_callback' ),
            'wcte_fictitious_settings',
            'wcte_fictitious_section'
        );
    }

    public function api_key_field_callback() {
        $api_key = get_option( 'wcte_correios_api_key' );
        echo '<input type="text" name="wcte_correios_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" />';
    }

    public function username_field_callback() {
        $username = get_option( 'wcte_correios_username' );
        echo '<input type="text" name="wcte_correios_username" value="' . esc_attr( $username ) . '" class="regular-text" />';
    }

    public function password_field_callback() {
        $password = get_option( 'wcte_correios_password' );
        echo '<input type="password" name="wcte_correios_password" value="' . esc_attr( $password ) . '" class="regular-text" />';
    }

    public function cartao_postagem_field_callback() {
        $cartao_postagem = get_option( 'wcte_correios_cartao_postagem' );
        echo '<input type="text" name="wcte_correios_cartao_postagem" value="' . esc_attr( $cartao_postagem ) . '" class="regular-text" />';
    }

    public function contrato_field_callback() {
        $contrato = get_option( 'wcte_correios_contrato' );
        echo '<input type="text" name="wcte_correios_contrato" value="' . esc_attr( $contrato ) . '" class="regular-text" />';
    }

    public function token_field_callback() {
        $token = get_option( 'wcte_correios_token' );
        echo '<input type="text" name="wcte_correios_token" value="' . esc_attr( $token ) . '" class="regular-text" />';
    }

    public function slack_webhook_field_callback() {
        $webhook_url = get_option( 'wcte_slack_webhook_url' );
        echo '<input type="text" name="wcte_slack_webhook_url" value="' . esc_attr( $webhook_url ) . '" class="regular-text" />';
    }

    public function fictitious_messages_field_callback() {
        $messages = get_option( 'wcte_fictitious_messages', array() );

        echo '<table class="widefat">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Ordem</th>';
        echo '<th>Mensagem</th>';
        echo '<th>Dias após o envio</th>';
        echo '<th>Hora</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        for ( $i = 0; $i < 12; $i++ ) {
            $msg = isset( $messages[ $i ] ) ? $messages[ $i ] : array( 'message' => '', 'days' => '', 'hour' => '' );

            echo '<tr>';
            echo '<td>' . ( $i + 1 ) . '</td>';
            echo '<td><input type="text" name="wcte_fictitious_messages[' . $i . '][message]" value="' . esc_attr( $msg['message'] ) . '" class="regular-text" /></td>';
            echo '<td><input type="number" name="wcte_fictitious_messages[' . $i . '][days]" value="' . esc_attr( $msg['days'] ) . '" min="0" class="small-text" /></td>';
            echo '<td><input type="time" name="wcte_fictitious_messages[' . $i . '][hour]" value="' . esc_attr( $msg['hour'] ) . '" /></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '<p class="description">Defina as mensagens e quando elas devem ser exibidas após a geração do código de rastreamento.</p>';
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Configurações do WooCommerce Tracking Enhanced</h1>

            <?php
            $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'correios';
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=wcte_settings&tab=correios" class="nav-tab <?php echo $active_tab == 'correios' ? 'nav-tab-active' : ''; ?>">Correios</a>
                <a href="?page=wcte_settings&tab=slack" class="nav-tab <?php echo $active_tab == 'slack' ? 'nav-tab-active' : ''; ?>">Slack</a>
                <a href="?page=wcte_settings&tab=fictitious" class="nav-tab <?php echo $active_tab == 'fictitious' ? 'nav-tab-active' : ''; ?>">Mensagens Fictícias</a>
            </h2>

            <form method="post" action="options.php">
                <?php
                if ($active_tab == 'correios') {
                    settings_fields('wcte_correios_settings');
                    do_settings_sections('wcte_correios_settings');
                } elseif ($active_tab == 'slack') {
                    settings_fields('wcte_slack_settings');
                    do_settings_sections('wcte_slack_settings');
                } else {
                    settings_fields('wcte_fictitious_settings');
                    do_settings_sections('wcte_fictitious_settings');
                }
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Inicializa a classe de configurações
new WCTE_Admin_Settings();
