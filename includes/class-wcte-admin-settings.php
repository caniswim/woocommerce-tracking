<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_Admin_Settings {
    private $logs = array();
    
    // Códigos das transportadoras no 17track
    private const CORREIOS_CARRIER_CODE = 2151;
    private const CAINIAO_CARRIER_CODE = 800;
    private const ALIEXPRESS_CARRIER_CODE = 900;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_wcte_clear_logs', array( $this, 'clear_logs' ) );
        add_action( 'wp_ajax_wcte_get_logs', array( $this, 'ajax_get_logs' ) );
    }

    public function ajax_get_logs() {
        check_ajax_referer('wcte_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
            return;
        }

        wp_send_json_success($this->get_logs());
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
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                margin-top: 20px;
                border-radius: 4px;
            }
            .wcte-logs-viewer textarea {
                width: 100%;
                min-height: 300px;
                font-family: monospace;
                margin-bottom: 10px;
                padding: 10px;
                background: #f6f7f7;
                border: 1px solid #ddd;
            }
            .wcte-logs-actions {
                margin-bottom: 10px;
            }
            .wcte-logs-actions button {
                margin-right: 10px;
            }
            .wcte-firebase-rules {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                margin-top: 20px;
                border-radius: 4px;
            }
            .wcte-firebase-rules pre {
                background: #f6f7f7;
                padding: 15px;
                overflow-x: auto;
                border: 1px solid #ddd;
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
            $submenu['wcte_settings'][0][0] = 'Integrações';
        }
    }

    public function register_settings() {
        // Registra as configurações do 17track
        register_setting( 'wcte_correios_settings', 'wcte_17track_api_key' );
        register_setting( 'wcte_correios_settings', 'wcte_17track_enabled' );
        register_setting( 'wcte_correios_settings', 'wcte_17track_check_interval' );
        register_setting( 'wcte_correios_settings', 'wcte_17track_ignored_events' );
        register_setting( 'wcte_correios_settings', 'wcte_slack_webhook_url' );

        // Registra as configurações do Firebase
        register_setting( 'wcte_correios_settings', 'wcte_firebase_api_key' );
        register_setting( 'wcte_correios_settings', 'wcte_firebase_database_url' );
        register_setting( 'wcte_correios_settings', 'wcte_firebase_project_id' );

        // Registra as configurações de mensagens fictícias
        register_setting( 'wcte_fictitious_settings', 'wcte_fictitious_messages', array(
            'sanitize_callback' => array($this, 'sanitize_fictitious_messages')
        ));

        // Seção do 17track
        add_settings_section(
            'wcte_17track_section',
            'Configurações do 17track',
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

        // Campos do 17track
        add_settings_field(
            'wcte_17track_enabled',
            'Ativar integração 17track',
            array( $this, 'track17_enabled_field_callback' ),
            'wcte_correios_settings',
            'wcte_17track_section'
        );

        add_settings_field(
            'wcte_17track_api_key',
            'Chave API (Security Key) do 17track',
            array( $this, 'track17_api_key_field_callback' ),
            'wcte_correios_settings',
            'wcte_17track_section'
        );

        add_settings_field(
            'wcte_17track_check_interval',
            'Intervalo de verificação (horas)',
            array( $this, 'track17_check_interval_field_callback' ),
            'wcte_correios_settings',
            'wcte_17track_section'
        );

        add_settings_field(
            'wcte_17track_ignored_events',
            'Eventos a serem ignorados',
            array( $this, 'track17_ignored_events_field_callback' ),
            'wcte_correios_settings',
            'wcte_17track_section'
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
                    'hour' => sanitize_text_field($msg['hour']),
                    'applies_to' => isset($msg['applies_to']) ? sanitize_text_field($msg['applies_to']) : 'both',
                );
            }
        }
        return $sanitized;
    }
    

    // Callbacks dos campos do 17track
    public function track17_enabled_field_callback() {
        $enabled = get_option( 'wcte_17track_enabled', true );
        echo '<input type="checkbox" name="wcte_17track_enabled" value="1" ' . checked($enabled, 1, false) . '>';
        echo '<p class="description">Quando desativado, o sistema manterá apenas as mensagens fictícias.</p>';
    }

    public function track17_api_key_field_callback() {
        $api_key = get_option( 'wcte_17track_api_key' );
        echo '<input type="text" name="wcte_17track_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" />';
        echo '<p class="description">Chave de segurança fornecida pelo 17track. Obtenha suas credenciais em <a href="https://www.17track.net/en/api" target="_blank">https://www.17track.net/en/api</a></p>';
    }

    public function track17_check_interval_field_callback() {
        $check_interval = get_option( 'wcte_17track_check_interval', 12 );
        echo '<input type="number" class="small-text" name="wcte_17track_check_interval" value="' . esc_attr( $check_interval ) . '" min="1" max="48">';
        echo '<p class="description">Intervalo em horas para verificar atualizações de rastreamento em segundo plano. Este valor define apenas a frequência do processo automático, não afeta consultas individuais que sempre usam dados em tempo real.</p>';
    }

    public function track17_ignored_events_field_callback() {
        $ignored_events = get_option( 'wcte_17track_ignored_events', '' );
        echo '<textarea name="wcte_17track_ignored_events" rows="5" cols="50" class="large-text">' . esc_textarea( $ignored_events ) . '</textarea>';
        echo '<p class="description">Especifique eventos que devem ser ignorados nas atualizações de rastreamento (um por linha). Qualquer evento contendo estes textos não será mostrado aos clientes. Exemplo: "Objeto postado", "Objeto em trânsito".</p>';
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
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Faz teste de conexão com a API 17track se solicitado
        $test_result = null;
        if (isset($_POST['wcte_test_17track']) && check_admin_referer('wcte_test_17track', 'wcte_test_17track_nonce')) {
            $test_result = $this->test_17track_connection();
        }
        
        // Testa consulta de rastreamento se solicitado
        $tracking_test_result = null;
        $tracking_code = '';
        if (isset($_POST['wcte_test_tracking']) && check_admin_referer('wcte_test_tracking', 'wcte_test_tracking_nonce')) {
            $tracking_code = sanitize_text_field($_POST['tracking_code']);
            if (!empty($tracking_code)) {
                $tracking_test_result = $this->test_tracking_query($tracking_code);
            }
        }
        ?>
        <div class="wrap">
            <h1>Configurações do WooCommerce Tracking Enhanced</h1>

            <?php if (isset($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Configurações atualizadas com sucesso.</p>
                </div>
            <?php endif; ?>

            <?php if ($test_result !== null): ?>
                <?php if ($test_result === true): ?>
                    <div class="notice notice-success">
                        <p>Conexão com a API do 17track realizada com sucesso!</p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error">
                        <p>Erro ao conectar com a API do 17track: <?php echo esc_html($test_result); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('wcte_correios_settings');
                do_settings_sections('wcte_correios_settings');
                submit_button();
                ?>
            </form>

            <div style="margin-top: 20px;">
                <form method="post">
                    <?php wp_nonce_field('wcte_test_17track', 'wcte_test_17track_nonce'); ?>
                    <input type="submit" name="wcte_test_17track" class="button button-secondary" value="Testar Conexão com API do 17track">
                </form>
            </div>
            
            <div class="card" style="margin-top: 20px; padding: 15px;">
                <h2>Teste de Consulta de Rastreamento</h2>
                <p>Digite um código de rastreio para testar a consulta à API do 17track e ver a resposta crua:</p>
                
                <form method="post">
                    <?php wp_nonce_field('wcte_test_tracking', 'wcte_test_tracking_nonce'); ?>
                    <p>
                        <input type="text" name="tracking_code" value="<?php echo esc_attr($tracking_code); ?>" class="regular-text" placeholder="Digite o código de rastreio">
                        <input type="submit" name="wcte_test_tracking" class="button button-secondary" value="Testar Rastreamento">
                    </p>
                </form>
                
                <?php if ($tracking_test_result !== null): ?>
                <div style="margin-top: 10px;">
                    <h3>Resposta da API para o código: <?php echo esc_html($tracking_code); ?></h3>
                    <p>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('wcte_test_tracking', 'wcte_test_tracking_nonce'); ?>
                            <input type="hidden" name="tracking_code" value="<?php echo esc_attr($tracking_code); ?>">
                            <input type="submit" name="wcte_test_tracking" class="button button-secondary" value="Recarregar Resultado">
                        </form>
                        <small style="margin-left: 10px;">Esta é uma consulta em tempo real que consome 10 cotas por execução.</small>
                    </p>
                    <textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($tracking_test_result); ?></textarea>
                </div>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top: 20px; padding: 15px;">
                <h2>Sobre a integração com 17track</h2>
                <p>O 17track é um serviço global que agrega informações de rastreamento de mais de 600 transportadoras de todo o mundo.</p>
                <p>Benefícios da integração:</p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>Rastreamento de múltiplas transportadoras em uma única API</li>
                    <li>Informações mais precisas e detalhadas sobre os pacotes</li>
                    <li>Suporte a rastreamento internacional</li>
                    <li>Detecção automática de transportadora para muitos formatos de código</li>
                </ul>
                <p>Para usar a integração, você precisa:</p>
                <ol style="list-style-type: decimal; margin-left: 20px;">
                    <li>Registrar-se no 17track para desenvolvedores</li>
                    <li>Obter sua credencial de API (security key)</li>
                    <li>Configurar a chave nesta página</li>
                </ol>
                <p><strong>Importante:</strong> Esta integração utiliza consultas em tempo real para garantir informações sempre atualizadas. Cada consulta individual consome 10 cotas do 17track (em vez de 1 cota para consultas em cache). Certifique-se de que seu plano comporta esse volume de requisições.</p>
                <p><strong>Nota:</strong> O plano gratuito do 17track permite um número limitado de consultas por dia. Verifique os limites na documentação oficial.</p>
            </div>

            <div class="wcte-logs-viewer">
                <h2>Logs do Sistema</h2>
                <textarea id="wcte-logs" readonly><?php echo esc_textarea($this->get_logs()); ?></textarea>
                <div class="wcte-logs-actions">
                    <button type="button" class="button button-secondary" id="wcte-clear-logs">Limpar Logs</button>
                    <button type="button" class="button button-secondary" id="wcte-refresh-logs">Atualizar Logs</button>
                </div>
                <p class="description">Os logs são atualizados automaticamente a cada 30 segundos.</p>
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
        </div>
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
                            <th>Aplica-se a</th> <!-- Nova coluna -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $messages = get_option('wcte_fictitious_messages', array());
                        for ($i = 0; $i < 12; $i++) {
                            $msg = isset($messages[$i]) ? $messages[$i] : array('message' => '', 'days' => '', 'hour' => '', 'applies_to' => 'both');
                            ?>
                            <tr>
                                <td><?php echo ($i + 1); ?></td>
                                <td><input type="text" name="wcte_fictitious_messages[<?php echo $i; ?>][message]" value="<?php echo esc_attr($msg['message']); ?>" class="regular-text" /></td>
                                <td><input type="number" name="wcte_fictitious_messages[<?php echo $i; ?>][days]" value="<?php echo esc_attr($msg['days']); ?>" min="0" class="small-text" /></td>
                                <td><input type="time" name="wcte_fictitious_messages[<?php echo $i; ?>][hour]" value="<?php echo esc_attr($msg['hour']); ?>" /></td>
                                <td>
                                    <select name="wcte_fictitious_messages[<?php echo $i; ?>][applies_to]">
                                        <option value="both" <?php selected($msg['applies_to'], 'both'); ?>>Ambos</option>
                                        <option value="with_tracking" <?php selected($msg['applies_to'], 'with_tracking'); ?>>Com Rastreamento</option>
                                        <option value="without_tracking" <?php selected($msg['applies_to'], 'without_tracking'); ?>>Sem Rastreamento</option>
                                    </select>
                                </td>
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
    
    /**
     * Testa a conexão com a API do 17track
     * 
     * @return bool|string true em caso de sucesso, mensagem de erro em caso de falha
     */
    private function test_17track_connection() {
        $api_key = get_option('wcte_17track_api_key');
        
        if (empty($api_key)) {
            return 'A chave de API do 17track deve ser configurada';
        }
        
        // Faz requisição para verificar a quota/status da API (endpoint de quota)
        $response = wp_remote_post('https://api.17track.net/track/v2.2/getquota', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                '17token' => $api_key
            ),
            'body' => json_encode([])
        ));
        
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            return isset($data['data']['errors'][0]['message']) ? $data['data']['errors'][0]['message'] : 'Erro desconhecido (código ' . $response_code . ')';
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || $data['code'] !== 0 || !isset($data['data']['quota_total'])) {
            return 'Resposta da API não contém informações de quota';
        }
        
        // Exibe mensagem de sucesso com a quota disponível
        add_settings_error(
            'wcte_correios_settings',
            'wcte_17track_quota',
            sprintf(
                'Conexão com sucesso! Quota disponível: %d de %d. Quota diária: %d.',
                $data['data']['quota_remain'],
                $data['data']['quota_total'],
                $data['data']['max_track_daily']
            ),
            'success'
        );
        
        return true;
    }

    /**
     * Testa uma consulta de rastreamento com a API do 17track
     * 
     * @param string $tracking_code Código de rastreamento a ser consultado
     * @return string Resposta bruta da API em formato JSON formatado
     */
    private function test_tracking_query($tracking_code) {
        $api_key = get_option('wcte_17track_api_key');
        
        if (empty($api_key)) {
            return 'Erro: A chave de API do 17track deve ser configurada antes de testar.';
        }
        
        // Prepara a requisição para obter informações de rastreamento
        $payload = array(
            array(
                'number' => $tracking_code,
                'cacheLevel' => 1
            )
        );
        
        // Define a transportadora - sempre usa Correios, exceto para Cainiao
        $carrier_name = 'Correios Brasil';
        $carrier_code = self::CORREIOS_CARRIER_CODE;
        
        // Apenas verifica se é Cainiao para fins de redirecionamento
        if (preg_match('/^LP\d{12,}$/', $tracking_code) || 
            preg_match('/^CNBR\d{8,}$/', $tracking_code) || 
            preg_match('/^YT\d{16}$/', $tracking_code)) {
            // Cainiao - exibir apenas para informação, mas não usar no payload
            $carrier_name = 'Cainiao (redirecionado)';
            $carrier_code = self::CAINIAO_CARRIER_CODE;
        }
        
        // Adiciona sempre o código dos Correios
        $payload[0]['carrier'] = self::CORREIOS_CARRIER_CODE;
        
        // Faz requisição à API do 17track
        $response = wp_remote_post('https://api.17track.net/track/v2.2/gettrackinfo', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                '17token' => $api_key
            ),
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return 'Erro: ' . $response->get_error_message();
        }
        
        $body = wp_remote_retrieve_body($response);
        $json_data = json_decode($body, true);
        
        // Adiciona informações do carrier detectado
        $carrier_info = "\n\n--- Informações de Detecção ---\n";
        $carrier_info .= "Transportadora detectada: {$carrier_name} (código: {$carrier_code})\n";
        $carrier_info .= "Transportadora utilizada na consulta: Correios Brasil (código: " . self::CORREIOS_CARRIER_CODE . ")\n";
        $carrier_info .= "Tipo de consulta: Tempo real (cacheLevel=1)\n";
        
        // Formata o JSON para melhor visualização
        return json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $carrier_info;
    }
}

// Inicializa a classe de configurações
new WCTE_Admin_Settings();