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
        // Registra as configurações do grupo
        register_setting( 'wcte_settings_group', 'wcte_correios_api_key' ); // Código de Acesso à API
        register_setting( 'wcte_settings_group', 'wcte_correios_username' ); // Usuário dos Correios
        register_setting( 'wcte_settings_group', 'wcte_correios_password' ); // Senha dos Correios
        register_setting( 'wcte_settings_group', 'wcte_correios_cartao_postagem' ); // Cartão de Postagem
        register_setting( 'wcte_settings_group', 'wcte_correios_contrato' ); // Contrato dos Correios
        register_setting( 'wcte_settings_group', 'wcte_correios_token' ); // Token dos Correios
        register_setting( 'wcte_settings_group', 'wcte_slack_webhook_url' );
        register_setting( 'wcte_settings_group', 'wcte_fictitious_messages' );

        // Adiciona uma seção de configurações
        add_settings_section(
            'wcte_main_section',                       
            'Configurações Principais',                
            null,                                      
            'wcte_settings'                            
        );

        // Campos de configuração
        add_settings_field(
            'wcte_correios_api_key',
            'Código de Acesso à API dos Correios',
            array( $this, 'api_key_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );

        add_settings_field(
            'wcte_correios_username',
            'Usuário do Meu Correios',
            array( $this, 'username_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );

        add_settings_field(
            'wcte_correios_password',
            'Senha do Meu Correios',
            array( $this, 'password_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );

        add_settings_field(
            'wcte_correios_cartao_postagem',
            'Cartão de Postagem dos Correios',
            array( $this, 'cartao_postagem_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );

        add_settings_field(
            'wcte_correios_contrato',
            'Contrato dos Correios',
            array( $this, 'contrato_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );

        add_settings_field(
            'wcte_correios_token',
            'Token dos Correios (opcional)',
            array( $this, 'token_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );

        add_settings_field(
            'wcte_slack_webhook_url',
            'URL do Webhook do Slack',
            array( $this, 'slack_webhook_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );

        add_settings_field(
            'wcte_fictitious_messages',
            'Mensagens Fictícias',
            array( $this, 'fictitious_messages_field_callback' ),
            'wcte_settings',
            'wcte_main_section'
        );
    }

    public function api_key_field_callback() {
        $api_key = get_option( 'wcte_correios_api_key' );
        echo '<input type="text" name="wcte_correios_api_key" value="' . esc_attr( $api_key ) . '" />';
    }

    public function username_field_callback() {
        $username = get_option( 'wcte_correios_username' );
        echo '<input type="text" name="wcte_correios_username" value="' . esc_attr( $username ) . '" />';
    }

    public function password_field_callback() {
        $password = get_option( 'wcte_correios_password' );
        echo '<input type="password" name="wcte_correios_password" value="' . esc_attr( $password ) . '" />';
    }

    public function cartao_postagem_field_callback() {
        $cartao_postagem = get_option( 'wcte_correios_cartao_postagem' );
        echo '<input type="text" name="wcte_correios_cartao_postagem" value="' . esc_attr( $cartao_postagem ) . '" />';
    }

    public function contrato_field_callback() {
        $contrato = get_option( 'wcte_correios_contrato' );
        echo '<input type="text" name="wcte_correios_contrato" value="' . esc_attr( $contrato ) . '" />';
    }

    public function token_field_callback() {
        $token = get_option( 'wcte_correios_token' );
        echo '<input type="text" name="wcte_correios_token" value="' . esc_attr( $token ) . '" />';
    }

    public function slack_webhook_field_callback() {
        $webhook_url = get_option( 'wcte_slack_webhook_url' );
        echo '<input type="text" name="wcte_slack_webhook_url" value="' . esc_attr( $webhook_url ) . '" size="50" />';
    }

    public function fictitious_messages_field_callback() {
        $messages = get_option( 'wcte_fictitious_messages', array() );

        echo '<table>';
        echo '<tr>';
        echo '<th>Ordem</th>';
        echo '<th>Mensagem</th>';
        echo '<th>Dias após o envio</th>';
        echo '<th>Hora</th>';
        echo '</tr>';

        for ( $i = 0; $i < 12; $i++ ) {
            $msg = isset( $messages[ $i ] ) ? $messages[ $i ] : array( 'message' => '', 'days' => '', 'hour' => '' );

            echo '<tr>';
            echo '<td>' . ( $i + 1 ) . '</td>';
            echo '<td><input type="text" name="wcte_fictitious_messages[' . $i . '][message]" value="' . esc_attr( $msg['message'] ) . '" size="40" /></td>';
            echo '<td><input type="number" name="wcte_fictitious_messages[' . $i . '][days]" value="' . esc_attr( $msg['days'] ) . '" min="0" /></td>';
            echo '<td><input type="time" name="wcte_fictitious_messages[' . $i . '][hour]" value="' . esc_attr( $msg['hour'] ) . '" /></td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '<p>Defina as mensagens e quando elas devem ser exibidas após a geração do código de rastreamento.</p>';
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Configurações do WooCommerce Tracking Enhanced</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'wcte_settings_group' ); // Registra o grupo de configurações
                do_settings_sections( 'wcte_settings' );   // Exibe as seções para o slug correto
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Inicializa a classe de configurações
new WCTE_Admin_Settings();
