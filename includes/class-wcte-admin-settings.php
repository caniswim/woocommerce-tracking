<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
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
            'wcte_correios_settings'
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
            'wcte_correios_settings',
            'wcte_slack_section'
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

    public function render_credentials_page() {
        ?>
        <div class="wrap">
            <h1>Configurações do WooCommerce Tracking Enhanced</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'wcte_correios_settings' );
                do_settings_sections( 'wcte_correios_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_fictitious_page() {
        ?>
        <div class="wrap">
            <h1>Configurações de Mensagens Fictícias</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wcte_fictitious_settings' ); ?>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Ordem</th>
                            <th>Mensagem</th>
                            <th>Dias após o envio</th>
                            <th>Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $messages = get_option( 'wcte_fictitious_messages', array() );
                        for ( $i = 0; $i < 12; $i++ ) {
                            $msg = isset( $messages[ $i ] ) ? $messages[ $i ] : array( 'message' => '', 'days' => '', 'hour' => '' );
                            ?>
                            <tr>
                                <td><?php echo ( $i + 1 ); ?></td>
                                <td><input type="text" name="wcte_fictitious_messages[<?php echo $i; ?>][message]" value="<?php echo esc_attr( $msg['message'] ); ?>" class="regular-text" /></td>
                                <td><input type="number" name="wcte_fictitious_messages[<?php echo $i; ?>][days]" value="<?php echo esc_attr( $msg['days'] ); ?>" min="0" class="small-text" /></td>
                                <td><input type="time" name="wcte_fictitious_messages[<?php echo $i; ?>][hour]" value="<?php echo esc_attr( $msg['hour'] ); ?>" /></td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
                <p class="description">Defina as mensagens e quando elas devem ser exibidas após a geração do código de rastreamento.</p>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

// Inicializa a classe de configurações
new WCTE_Admin_Settings();
