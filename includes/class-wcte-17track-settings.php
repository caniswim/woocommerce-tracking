<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe para configurações da integração com 17track
 * 
 * Esta classe gerencia as configurações da integração com a API do 17track,
 * incluindo credenciais de API e outras opções relacionadas.
 */
class WCTE_17Track_Settings {

    /**
     * Construct da classe
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
    }

    /**
     * Registra configurações da integração
     */
    public function register_settings() {
        register_setting('wcte_17track_settings', 'wcte_17track_api_key');
        register_setting('wcte_17track_settings', 'wcte_17track_enabled');
        register_setting('wcte_17track_settings', 'wcte_17track_check_interval');
        register_setting('wcte_17track_settings', 'wcte_17track_ignored_events');
    }

    /**
     * Adiciona página de configurações no menu admin
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Configurações 17track',
            'Integração 17track',
            'manage_options',
            'wcte-17track-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Renderiza a página de configurações
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Processa ações como teste de conexão
        $api_test_result = null;
        if (isset($_POST['wcte_test_17track'])) {
            check_admin_referer('wcte_17track_settings');
            $api_test_result = $this->test_17track_connection();
        }
        
        // Se o cache foi limpo, mostra mensagem
        $cache_cleared = isset($_GET['cache_cleared']) && $_GET['cache_cleared'] == '1';
        ?>
        <div class="wrap">
            <h1>Configurações da Integração com 17track</h1>
            
            <?php if ($cache_cleared): ?>
            <div class="notice notice-success is-dismissible">
                <p>Cache de códigos registrados limpo com sucesso!</p>
            </div>
            <?php endif; ?>
            
            <?php if ($api_test_result !== null): ?>
            <div class="notice <?php echo $api_test_result === true ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                <p><?php echo is_string($api_test_result) ? esc_html($api_test_result) : 'Conexão com a API do 17track estabelecida com sucesso!'; ?></p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('wcte_17track_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Ativar integração com 17track</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wcte_17track_enabled" value="1" <?php checked(get_option('wcte_17track_enabled', true), 1); ?>>
                                Ativar integração com a API do 17track
                            </label>
                            <p class="description">Quando desativado, o sistema voltará a usar apenas mensagens fictícias.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">API Key do 17track</th>
                        <td>
                            <input type="text" name="wcte_17track_api_key" value="<?php echo esc_attr(get_option('wcte_17track_api_key')); ?>" class="regular-text">
                            <p class="description">Sua chave de API do 17track. Obtenha uma em <a href="https://www.17track.net/en/api" target="_blank">https://www.17track.net/en/api</a></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Intervalo de verificação</th>
                        <td>
                            <input type="number" name="wcte_17track_check_interval" value="<?php echo esc_attr(get_option('wcte_17track_check_interval', 12)); ?>" class="small-text" min="1" max="48"> horas
                            <p class="description">Intervalo em horas para verificar atualizações de rastreamento em segundo plano.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Eventos a serem ignorados</th>
                        <td>
                            <textarea name="wcte_17track_ignored_events" rows="4" class="large-text"><?php echo esc_textarea(get_option('wcte_17track_ignored_events', '')); ?></textarea>
                            <p class="description">Lista de eventos a serem ignorados nas atualizações de rastreamento. Digite um evento por linha. Exemplo: "Objeto em trânsito", "Objeto postado".</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="postbox">
                <h2 class="hndle"><span>Ferramentas da Integração</span></h2>
                <div class="inside">
                    <form method="post" action="">
                        <?php wp_nonce_field('wcte_17track_settings'); ?>
                        <p>
                            <button type="submit" name="wcte_test_17track" class="button button-secondary">Testar conexão com API</button>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wcte_clear_17track_cache'), 'wcte_clear_17track_cache')); ?>" class="button button-secondary">Limpar cache de códigos registrados</a>
                        </p>
                    </form>
                </div>
            </div>
            
            <?php if (is_string($api_test_result) && $api_test_result !== true): ?>
            <div class="postbox">
                <h2 class="hndle"><span>Resultados do Teste</span></h2>
                <div class="inside">
                    <pre><?php echo esc_html($api_test_result); ?></pre>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Configura a execução periódica das atualizações de rastreamento
     */
    public static function setup_cron() {
        if (!wp_next_scheduled('wcte_check_tracking_updates')) {
            // Agenda para executar a cada X horas
            $interval_hours = get_option('wcte_17track_check_interval', 12);
            wp_schedule_event(time(), 'hourly', 'wcte_check_tracking_updates');
        }
        
        // Adiciona hook para a ação do cron
        add_action('wcte_check_tracking_updates', array('WCTE_17Track_API', 'batch_update_tracking_info'));
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
        
        // Exibe informações detalhadas
        $info = 'Conexão bem sucedida! Informações do 17track:' . "\n\n";
        $info .= 'Quota Total: ' . $data['data']['quota_total'] . "\n";
        $info .= 'Quota Restante: ' . $data['data']['quota_remaining'] . "\n";
        $info .= 'Quota Usada: ' . $data['data']['quota_used'] . "\n";
        
        return $info;
    }
}

// Inicializa a classe de configurações
new WCTE_17Track_Settings(); 