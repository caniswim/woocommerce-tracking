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

    public function render_firebase_section() {
        ?>
        <div class="wcte-firebase-rules">
            <h3>Regras Recomendadas do Firebase</h3>
            <p>Configure estas regras de segurança no console do Firebase para garantir o funcionamento adequado do plugin:</p>
            <pre>
{
  "rules": {
    "tracking": {
      "$tracking_code": {
        ".read": true,
        ".write": "auth != null || !data.exists()",
        "fake_updates": {
          ".read": true,
          ".write": "auth != null || !data.exists()"
        },
        "created_at": {
          ".read": true,
          ".write": "auth != null || !data.exists()"
        },
        "has_real_tracking": {
          ".read": true,
          ".write": "auth != null || !data.exists()"
        }
      }
    }
  }
}
            </pre>
            <p>Estas regras permitem:</p>
            <ul>
                <li>Leitura pública dos dados de rastreamento</li>
                <li>Criação de novos registros de rastreamento</li>
                <li>Atualizações apenas através de autenticação</li>
                <li>Proteção contra modificação não autorizada de dados existentes</li>
            </ul>
        </div>
        <?php
    }

    public function sanitize_fictitious_messages($input) {
        if (!is_array($input)) {
            return array();
        }

        $sanitized = array();
        foreach ($input as $key => $msg) {
            if (isset($msg['message']) || isset($msg['days']) || isset($msg['hour'])) {
                $sanitized[$key] = array(
                    'message' => sanitize_text_field($msg['message']),
                    'days' => absint($msg['days']),
                    'hour' => sanitize_text_field($msg['hour'])
                );
            }
        }
        return $sanitized;
    }

    // Callbacks dos campos dos Correios
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

    // Callbacks dos campos do Firebase
    public function firebase_api_key_field_callback() {
        $api_key = get_option( 'wcte_firebase_api_key' );
        echo '<input type="text" name="wcte_firebase_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" />';
        echo '<p class="description">A chave da API do seu projeto Firebase</p>';
    }

    public function firebase_database_url_field_callback() {
        $database_url = get_option( 'wcte_firebase_database_url' );
        echo '<input type="text" name="wcte_firebase_database_url" value="' . esc_attr( $database_url ) . '" class="regular-text" />';
        echo '<p class="description">URL do seu Realtime Database (ex: https://seu-projeto.firebaseio.com)</p>';
    }

    public function firebase_project_id_field_callback() {
        $project_id = get_option( 'wcte_firebase_project_id' );
        echo '<input type="text" name="wcte_firebase_project_id" value="' . esc_attr( $project_id ) . '" class="regular-text" />';
        echo '<p class="description">O ID do seu projeto Firebase</p>';
    }

    // Callback do campo do Slack
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

            <div class="wcte-logs-viewer">
                <h2>Logs do Sistema</h2>
                <textarea id="wcte-logs" readonly><?php echo esc_textarea($this->get_logs()); ?></textarea>
                <button type="button" class="button button-secondary" id="wcte-clear-logs">Limpar Logs</button>
                <button type="button" class="button button-secondary" id="wcte-refresh-logs">Atualizar Logs</button>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function refreshLogs() {
                $.post(ajaxurl, {
                    action: 'wcte_get_logs',
                    nonce: wcte_admin.nonce
                }, function(response) {
                    if (response.success) {
                        $('#wcte-logs').val(response.data);
                    }
                });
            }

            $('#wcte-clear-logs').on('click', function() {
                if (confirm('Tem certeza que deseja limpar os logs?')) {
                    $.post(ajaxurl, {
                        action: 'wcte_clear_logs',
                        nonce: wcte_admin.nonce
                    }, function(response) {
                        if (response.success) {
                            $('#wcte-logs').val('');
                        }
                    });
                }
            });

            $('#wcte-refresh-logs').on('click', function() {
                refreshLogs();
            });

            // Auto refresh logs every 30 seconds
            setInterval(refreshLogs, 30000);
        });
        </script>
        <?php
    }

    private function get_logs() {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($log_file)) {
            $logs = file_get_contents($log_file);
            // Filter only WCTE related logs
            $filtered_logs = array();
            $lines = explode("\n", $logs);
            foreach ($lines as $line) {
                if (strpos($line, 'WCTE') !== false) {
                    $filtered_logs[] = $line;
                }
            }
            return implode("\n", $filtered_logs);
        }
        return 'Nenhum log encontrado.';
    }

    public function clear_logs() {
        check_ajax_referer('wcte_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
            return;
        }

        $log_file = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
            wp_send_json_success();
        } else {
            wp_send_json_error('Arquivo de log não encontrado');
        }
    }

    public function render_fictitious_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Configurações de Mensagens Fictícias</h1>
            <form method="post" action="options.php">
                <?php 
                settings_fields('wcte_fictitious_settings');
                ?>
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
                        $messages = get_option('wcte_fictitious_messages', array());
                        for ($i = 0; $i < 12; $i++) {
                            $msg = isset($messages[$i]) ? $messages[$i] : array('message' => '', 'days' => '', 'hour' => '');
                            ?>
                            <tr>
                                <td><?php echo ($i + 1); ?></td>
                                <td><input type="text" name="wcte_fictitious_messages[<?php echo $i; ?>][message]" value="<?php echo esc_attr($msg['message']); ?>" class="regular-text" /></td>
                                <td><input type="number" name="wcte_fictitious_messages[<?php echo $i; ?>][days]" value="<?php echo esc_attr($msg['days']); ?>" min="0" class="small-text" /></td>
                                <td><input type="time" name="wcte_fictitious_messages[<?php echo $i; ?>][hour]" value="<?php echo esc_attr($msg['hour']); ?>" /></td>
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
