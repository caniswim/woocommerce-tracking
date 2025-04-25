<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe responsável pelas configurações da API REST
 * 
 * Esta classe gerencia as configurações da API REST, incluindo:
 * - Geração e gerenciamento de API Keys
 * - Configurações de segurança da API
 * - Interface de administração para as configurações
 */
class WCTE_API_Settings {

    /**
     * Construct da classe
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
    }

    /**
     * Registra configurações da API
     */
    public function register_settings() {
        register_setting('wcte_api_settings', 'wcte_api_key_enabled');
        register_setting('wcte_api_settings', 'wcte_api_key');
        register_setting('wcte_api_settings', 'wcte_api_rate_limit');
        register_setting('wcte_api_settings', 'wcte_api_allowed_domains');
    }

    /**
     * Adiciona página de configurações no menu admin
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Configurações de API REST do Rastreamento',
            'API de Rastreamento',
            'manage_options',
            'wcte-api-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Renderiza a página de configurações
     */
    public function render_settings_page() {
        $api_key_enabled = get_option('wcte_api_key_enabled', false);
        $api_key = get_option('wcte_api_key', '');
        $api_rate_limit = get_option('wcte_api_rate_limit', 60);
        $api_allowed_domains = get_option('wcte_api_allowed_domains', '');
        
        // Gera uma nova API Key se necessário
        if (isset($_POST['wcte_generate_api_key'])) {
            $api_key = $this->generate_api_key();
            update_option('wcte_api_key', $api_key);
            echo '<div class="notice notice-success"><p>Nova API Key gerada com sucesso!</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Configurações da API REST de Rastreamento</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('wcte_api_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Habilitar autenticação por API Key</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wcte_api_key_enabled" value="1" <?php checked($api_key_enabled, 1); ?>>
                                Exigir API Key para acessar a API
                            </label>
                            <p class="description">
                                Quando ativado, todas as requisições à API devem incluir um cabeçalho X-WCTE-API-Key com uma chave válida.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" class="regular-text" name="wcte_api_key" value="<?php echo esc_attr($api_key); ?>" readonly>
                            <button type="submit" name="wcte_generate_api_key" class="button button-secondary">Gerar Nova Key</button>
                            <p class="description">
                                Esta é a chave que deve ser usada para autenticar requisições à API.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Limite de Requisições (por minuto)</th>
                        <td>
                            <input type="number" class="small-text" name="wcte_api_rate_limit" value="<?php echo esc_attr($api_rate_limit); ?>" min="1" max="1000">
                            <p class="description">
                                Número máximo de requisições permitidas por minuto para cada IP.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Domínios permitidos (CORS)</th>
                        <td>
                            <textarea name="wcte_api_allowed_domains" rows="4" class="large-text"><?php echo esc_textarea($api_allowed_domains); ?></textarea>
                            <p class="description">
                                Lista de domínios permitidos para fazer requisições à API (um por linha). Deixe em branco para permitir qualquer origem.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card">
                <h2>Documentação da API</h2>
                <p>A API REST do WooCommerce Tracking Enhanced permite acessar dados de rastreamento e pedidos.</p>
                
                <h3>Endpoints disponíveis:</h3>
                <ul>
                    <li><code>GET /wp-json/wcte/v1/orders</code> - Lista pedidos com filtros</li>
                    <li><code>GET /wp-json/wcte/v1/orders/{id}</code> - Detalhes de um pedido específico</li>
                    <li><code>GET /wp-json/wcte/v1/tracking/{code}</code> - Informações de rastreamento por código</li>
                    <li><code>GET /wp-json/wcte/v1/tracking/email/{email}</code> - Pedidos por email</li>
                </ul>
                
                <h3>Autenticação:</h3>
                <p>Se a autenticação por API Key estiver ativada, inclua o cabeçalho <code>X-WCTE-API-Key</code> com a chave em todas as requisições.</p>
                
                <h3>Exemplo de requisição:</h3>
                <pre>
curl -X GET <?php echo esc_url(home_url('/wp-json/wcte/v1/orders')); ?> \
-H "X-WCTE-API-Key: <?php echo esc_attr($api_key); ?>"
                </pre>
            </div>
        </div>
        <?php
    }

    /**
     * Gera uma nova API Key
     * 
     * @return string API Key
     */
    private function generate_api_key() {
        return bin2hex(random_bytes(16));
    }

    /**
     * Verifica se o domínio de origem está permitido (CORS)
     * 
     * @param string $origin Domínio de origem
     * @return bool
     */
    public function is_allowed_domain($origin) {
        $allowed_domains = get_option('wcte_api_allowed_domains', '');
        
        if (empty($allowed_domains)) {
            return true; // Permite qualquer origem se não houver restrições
        }
        
        $domains = explode("\n", $allowed_domains);
        foreach ($domains as $domain) {
            $domain = trim($domain);
            if ($domain === $origin) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Verifica se o limite de requisições foi excedido
     * 
     * @param string $ip Endereço IP
     * @return bool
     */
    public function check_rate_limit($ip) {
        $rate_limit = get_option('wcte_api_rate_limit', 60);
        $transient_key = 'wcte_rate_limit_' . md5($ip);
        
        $count = get_transient($transient_key);
        
        if ($count === false) {
            set_transient($transient_key, 1, 60); // 1 minuto
            return true;
        }
        
        if ($count >= $rate_limit) {
            return false;
        }
        
        set_transient($transient_key, $count + 1, 60); // 1 minuto
        return true;
    }
} 