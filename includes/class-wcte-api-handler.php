<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCTE_API_Handler {
    // URLs da API de Homologação
    private const CWS_TOKEN_URL = 'https://api.correios.com.br/token/v1/autentica/cartaopostagem';
    private const API_RASTRO_URL = 'https://api.correios.com.br/srorastro/v1/objetos/';

    /**
     * Log helper
     */
    private static function log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $log_message = 'WCTE API: ' . $message;
            if ($data !== null) {
                $log_message .= ' - Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }

    /**
     * Obtém informações de rastreamento
     */
    public static function get_tracking_info($tracking_code) {
        self::log('Iniciando consulta para código: ' . $tracking_code);

        if (preg_match('/^(LP|CN)/i', $tracking_code)) {
            return self::get_cainiao_tracking_info($tracking_code);
        } else {
            return self::get_correios_tracking_info($tracking_code);
        }
    }

    /**
     * Obtém o token de autenticação do CWS
     */
    private static function get_auth_token() {
        $cached_token = get_transient('wcte_correios_token');
        if ($cached_token) {
            $token_expira_em = get_transient('wcte_correios_token_expira_em');
            if ($token_expira_em > (time() + (30 * 60))) {
                self::log('Usando token em cache');
                return $cached_token;
            }
        }

        $username = get_option('wcte_correios_username');
        $codigo_acesso = get_option('wcte_correios_api_key');
        $cartao_postagem = get_option('wcte_correios_cartao_postagem');

        if (empty($username) || empty($codigo_acesso) || empty($cartao_postagem)) {
            self::log('Credenciais dos Correios não configuradas');
            return null;
        }

        $auth_header = 'Basic ' . base64_encode($username . ':' . $codigo_acesso);

        $body = array('numero' => $cartao_postagem);

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => $auth_header
            ),
            'body' => json_encode($body),
            'method' => 'POST',
            'timeout' => 30
        );

        self::log('Solicitando token CWS', [
            'url' => self::CWS_TOKEN_URL,
            'headers' => $args['headers'],
            'body' => $args['body']
        ]);

        $response = wp_remote_post(self::CWS_TOKEN_URL, $args);

        if (is_wp_error($response)) {
            self::log('Erro ao obter token: ' . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);
        $data = json_decode($body_response, true);

        if ($status_code !== 201 || empty($data['token']) || empty($data['expiraEm'])) {
            self::log('Resposta inválida do CWS', [
                'status_code' => $status_code,
                'response' => $body_response
            ]);
            return null;
        }

        $token_expira_em = strtotime($data['expiraEm']);
        $expires_in = $token_expira_em - time();

        set_transient('wcte_correios_token', $data['token'], $expires_in);
        set_transient('wcte_correios_token_expira_em', $token_expira_em, $expires_in);

        return $data['token'];
    }

    /**
     * Obtém informações de rastreamento dos Correios
     */
    private static function get_correios_tracking_info($tracking_code) {
        $token = self::get_auth_token();
        if (!$token) {
            return array(
                'status' => 'error',
                'message' => 'Não foi possível autenticar com os Correios'
            );
        }

        $url = self::API_RASTRO_URL . $tracking_code . '?resultado=T';

        $args = array(
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 30
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => 'Erro ao conectar com a API dos Correios'
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200 || empty($data['objetos']) || empty($data['objetos'][0]['eventos'])) {
            $fictitious_message = self::get_fictitious_message($tracking_code);
            return array(
                'status' => 'fictitious',
                'message' => $fictitious_message,
            );
        }

        $objeto = $data['objetos'][0];

        if (isset($objeto['mensagem'])) {
            return array(
                'status' => 'error',
                'message' => $objeto['mensagem']
            );
        }

        $events = array_reverse($objeto['eventos']);
        $filtered_events = array();

        foreach ($events as $event) {
            $location = '';
            if (isset($event['unidade']['endereco'])) {
                $endereco = $event['unidade']['endereco'];
                $location = sprintf('%s - %s/%s',
                    $endereco['cidade'] ?? '',
                    $endereco['uf'] ?? '',
                    $endereco['pais'] ?? 'BR'
                );
            }

            $filtered_events[] = array(
                'date' => date('d/m/Y H:i', strtotime($event['dtHrCriado'])),
                'description' => $event['descricao'],
                'location' => $location
            );
        }

        $last_event = reset($filtered_events);
        $status = 'in_transit';

        if (stripos($last_event['description'], 'entregue') !== false) {
            $status = 'delivered';
        }

        return array(
            'status' => $status,
            'message' => $last_event['description'],
            'data' => $filtered_events
        );
    }

    /**
     * Obtém a mensagem fictícia com base no tempo decorrido
     */
    private static function get_fictitious_message($tracking_code) {
        $messages = get_option('wcte_fictitious_messages', array());
        $creation_date = self::get_tracking_creation_date($tracking_code);

        if (!$creation_date) {
            return 'Seu pedido está em processamento.';
        }

        $now = current_time('timestamp');
        $current_message = 'Seu pedido está em processamento.';

        foreach ($messages as $message_data) {
            if (empty($message_data['message']) || empty($message_data['days']) || empty($message_data['hour'])) {
                continue;
            }

            $scheduled_time = strtotime($creation_date . ' +' . intval($message_data['days']) . ' days ' . $message_data['hour']);

            if ($now >= $scheduled_time) {
                $current_message = $message_data['message'];
            }
        }

        return $current_message;
    }

   /**
     * Obtém a data de criação do código de rastreamento, se a tabela existir
     */
    private static function get_tracking_creation_date($tracking_code) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcte_order_tracking';

        // Verifica se a tabela existe antes de tentar acessar
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            error_log('WCTE - Tabela wp_wcte_order_tracking não existe.');
            return null;
        }

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at FROM $table_name WHERE tracking_code = %s",
            $tracking_code
        ));

        return $result ? $result->created_at : null;
    }

    /**
     * Obtém informações de rastreamento do Cainiao e retorna um link para a ParcelsApp
     */
    private static function get_cainiao_tracking_info($tracking_code) {
        $tracking_url = 'https://parcelsapp.com/pt/tracking/' . urlencode($tracking_code);
        return array(
            'status' => 'cainiao',
            'tracking_url' => $tracking_url,
            'message' => 'Clique no botão abaixo para acompanhar seu pedido no site da transportadora.'
        );
    }
}
