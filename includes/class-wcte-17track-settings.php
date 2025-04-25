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
        $api_key = get_option('wcte_17track_api_key', '');
        $enabled = get_option('wcte_17track_enabled', true);
        $check_interval = get_option('wcte_17track_check_interval', 12);
        $registered_codes_count = count(get_option('wcte_17track_registered_codes', array()));

        // Faz teste de conexão com a API se solicitado
        $test_result = null;
        if (isset($_POST['wcte_test_17track'])) {
            $test_result = $this->test_api_connection();
        }
        
        // Mensagem de cache limpo
        $cache_cleared = isset($_GET['cache_cleared']) && $_GET['cache_cleared'] == '1';
        
        ?>
        <div class="wrap">
            <h1>Configurações da Integração com 17track</h1>

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
            
            <?php if ($cache_cleared): ?>
                <div class="notice notice-success">
                    <p>Cache de códigos registrados foi limpo com sucesso!</p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('wcte_17track_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Ativar integração 17track</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wcte_17track_enabled" value="1" <?php checked($enabled, 1); ?>>
                                Usar 17track para rastreamento de encomendas
                            </label>
                            <p class="description">
                                Quando desativado, o sistema manterá apenas as mensagens fictícias.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Chave API (Security Key)</th>
                        <td>
                            <input type="text" class="regular-text" name="wcte_17track_api_key" value="<?php echo esc_attr($api_key); ?>">
                            <p class="description">
                                Chave de segurança fornecida pelo 17track. Obtenha suas credenciais em 
                                <a href="https://www.17track.net/en/api" target="_blank">https://www.17track.net/en/api</a>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Intervalo de verificação (horas)</th>
                        <td>
                            <input type="number" class="small-text" name="wcte_17track_check_interval" value="<?php echo esc_attr($check_interval); ?>" min="1" max="48">
                            <p class="description">
                                Intervalo em horas para verificar atualizações de rastreamento. Intervalo mínimo recomendado: 12 horas.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Cache Local</th>
                        <td>
                            <p>
                                <strong><?php echo $registered_codes_count; ?></strong> códigos de rastreio em cache local
                            </p>
                            <p class="description">
                                O sistema mantém um cache local de códigos já registrados para evitar registros duplicados na API 17track.
                            </p>
                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wcte_clear_17track_cache'), 'wcte_clear_17track_cache'); ?>" class="button">
                                Limpar Cache de Códigos Registrados
                            </a>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div style="margin-top: 20px;">
                <form method="post">
                    <?php wp_nonce_field('wcte_test_17track', 'wcte_test_17track_nonce'); ?>
                    <input type="submit" name="wcte_test_17track" class="button button-secondary" value="Testar Conexão com API">
                </form>
            </div>
            
            <div class="card" style="margin-top: 20px;">
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
                <p><strong>Nota:</strong> O plano gratuito do 17track permite um número limitado de consultas por dia. Verifique os limites na documentação oficial.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Testa a conexão com a API do 17track
     * 
     * @return bool|string true em caso de sucesso, mensagem de erro em caso de falha
     */
    private function test_api_connection() {
        if (!check_admin_referer('wcte_test_17track', 'wcte_test_17track_nonce')) {
            return 'Erro de verificação de segurança';
        }
        
        $api_key = get_option('wcte_17track_api_key');
        
        if (empty($api_key)) {
            return 'A chave de API deve ser configurada';
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
            'wcte_17track_settings',
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
     * Configura o cron para verificação periódica de rastreamentos
     * 
     * @return void
     */
    public static function setup_cron() {
        $enabled = get_option('wcte_17track_enabled', true);
        
        if (!$enabled) {
            // Remove o agendamento se a integração estiver desativada
            if (wp_next_scheduled('wcte_check_tracking_updates')) {
                wp_clear_scheduled_hook('wcte_check_tracking_updates');
            }
            return;
        }
        
        // Define o intervalo de verificação
        $interval = get_option('wcte_17track_check_interval', 12);
        $interval_seconds = $interval * HOUR_IN_SECONDS;
        
        // Agenda o cron se não estiver agendado
        if (!wp_next_scheduled('wcte_check_tracking_updates')) {
            wp_schedule_event(time(), 'custom_interval', 'wcte_check_tracking_updates');
        }
        
        // Adiciona o intervalo personalizado
        add_filter('cron_schedules', function($schedules) use ($interval_seconds) {
            $schedules['custom_interval'] = array(
                'interval' => $interval_seconds,
                'display' => sprintf('A cada %d horas', $interval_seconds / HOUR_IN_SECONDS)
            );
            return $schedules;
        });
        
        // Adiciona a função que será executada pelo cron
        add_action('wcte_check_tracking_updates', array('WCTE_17Track_API', 'batch_update_tracking_info'));
    }
} 