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
     * Get configured timezone
     */
    private static function get_timezone() {
        $timezone = get_option('wcte_timezone', 'America/Sao_Paulo');
        return new DateTimeZone($timezone);
    }

    /**
     * Get current time in configured timezone
     */
    private static function get_current_time() {
        $timezone = self::get_timezone();
        $datetime = new DateTime('now', $timezone);
        return $datetime->getTimestamp();
    }

    /**
     * Convert time string to timestamp in configured timezone
     */
    private static function convert_to_timestamp($time_string) {
        $timezone = self::get_timezone();
        $datetime = new DateTime($time_string, $timezone);
        return $datetime->getTimestamp();
    }

    /**
     * Initialize tracking data in Firebase
     */
    private static function initialize_tracking($tracking_code) {
        $timezone = self::get_timezone();
        $datetime = new DateTime('now', $timezone);
        
        $tracking_data = array(
            'tracking_code' => $tracking_code,
            'carrier' => 'correios',
            'tracking_status' => 'pending',
            'created_at' => $datetime->format('Y-m-d H:i:s'),
            'has_real_tracking' => false
        );

        // Save initial tracking data
        if (WCTE_Database::save_tracking_data($tracking_data)) {
            // Generate first fictional message immediately
            $fictitious_message = self::get_fictitious_message($tracking_code, true);
            if ($fictitious_message) {
                $update_data = array(
                    'message' => $fictitious_message,
                    'timestamp' => self::get_current_time()
                );
                WCTE_Database::save_fake_update($tracking_code, $update_data);
            }
            return true;
        }
        return false;
    }

    /**
     * Obtém informações de rastreamento
     */
    public static function get_tracking_info($tracking_code) {
        self::log('Iniciando consulta para código: ' . $tracking_code);

        // Check if tracking exists in Firebase, if not initialize it
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
        if (!$creation_date) {
            self::initialize_tracking($tracking_code);
        }

        if (preg_match('/^(LP|CN)/i', $tracking_code)) {
            return self::get_cainiao_tracking_info($tracking_code);
        } else {
            $tracking_info = self::get_correios_tracking_info($tracking_code);
            
            // Only use fake updates if we don't have real tracking data
            if ($tracking_info['status'] === 'error' || empty($tracking_info['data'])) {
                // Check for new fictional updates that should be added
                if (self::should_generate_fictional_update($tracking_code)) {
                    $fictitious_message = self::get_fictitious_message($tracking_code);
                    if ($fictitious_message) {
                        $update_data = array(
                            'message' => $fictitious_message,
                            'timestamp' => self::get_current_time()
                        );
                        WCTE_Database::save_fake_update($tracking_code, $update_data);
                    }
                }

                // Get all fake updates
                $fake_updates = WCTE_Database::get_fake_updates($tracking_code);
                if (!empty($fake_updates)) {
                    $formatted_updates = WCTE_Database::format_fake_updates($fake_updates);
                    return array(
                        'status' => 'in_transit',
                        'message' => end($formatted_updates)['description'],
                        'data' => $formatted_updates
                    );
                }
            } else {
                // If we have real tracking data, clear fictional updates
                self::maybe_clear_fictional_updates($tracking_code);
            }
            
            return $tracking_info;
        }
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
            return array(
                'status' => 'error',
                'message' => 'Aguardando informações dos Correios'
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
        $timezone = self::get_timezone();

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

            // Convert event time to configured timezone
            $event_date = new DateTime($event['dtHrCriado']);
            $event_date->setTimezone($timezone);

            $filtered_events[] = array(
                'date' => $event_date->format('d/m/Y H:i'),
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
     * Clear fictional updates when real tracking begins
     */
    private static function maybe_clear_fictional_updates($tracking_code) {
        $tracking_status = WCTE_Database::get_tracking_status($tracking_code);
        
        // If we haven't marked this tracking as having real data yet
        if (!$tracking_status) {
            // Clear fictional updates and mark as having real data
            WCTE_Database::clear_fake_updates($tracking_code);
        }
    }

    /**
     * Verifica se devemos gerar uma nova atualização fictícia
     */
    private static function should_generate_fictional_update($tracking_code) {
        $tracking_status = WCTE_Database::get_tracking_status($tracking_code);
        
        // Don't generate if we already have real tracking data
        if ($tracking_status) {
            return false;
        }

        $fake_updates = WCTE_Database::get_fake_updates($tracking_code);
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
        
        if (!$creation_date) {
            return true;
        }

        // Get the next scheduled message
        $messages = get_option('wcte_fictitious_messages', array());
        $now = self::get_current_time();
        $creation_timestamp = self::convert_to_timestamp($creation_date);

        foreach ($messages as $message_data) {
            if (empty($message_data['message']) || !isset($message_data['days']) || empty($message_data['hour'])) {
                continue;
            }

            $scheduled_time = strtotime($creation_date . ' +' . intval($message_data['days']) . ' days ' . $message_data['hour']);
            
            // If this message should be shown now and we haven't shown it yet
            if ($now >= $scheduled_time) {
                $message_exists = false;
                foreach ($fake_updates as $update) {
                    if ($update['message'] === $message_data['message']) {
                        $message_exists = true;
                        break;
                    }
                }
                
                if (!$message_exists) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Obtém a mensagem fictícia com base no tempo decorrido
     */
    private static function get_fictitious_message($tracking_code, $first_message = false) {
        $messages = get_option('wcte_fictitious_messages', array());
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);

        if (!$creation_date) {
            return 'Seu pedido está em processamento.';
        }

        $now = self::get_current_time();

        // If this is the first message, return the day 0 message
        if ($first_message) {
            foreach ($messages as $message_data) {
                if (isset($message_data['days']) && $message_data['days'] == 0 && !empty($message_data['message'])) {
                    return $message_data['message'];
                }
            }
        }

        // Get all messages that should be shown by now
        $valid_messages = array();
        foreach ($messages as $message_data) {
            if (empty($message_data['message']) || !isset($message_data['days']) || empty($message_data['hour'])) {
                continue;
            }

            $scheduled_time = strtotime($creation_date . ' +' . intval($message_data['days']) . ' days ' . $message_data['hour']);
            
            if ($now >= $scheduled_time) {
                // Check if this message has already been used
                $fake_updates = WCTE_Database::get_fake_updates($tracking_code);
                $message_exists = false;
                foreach ($fake_updates as $update) {
                    if ($update['message'] === $message_data['message']) {
                        $message_exists = true;
                        break;
                    }
                }
                
                if (!$message_exists) {
                    $valid_messages[] = array(
                        'message' => $message_data['message'],
                        'time' => $scheduled_time
                    );
                }
            }
        }

        // Sort by scheduled time and get the earliest message
        if (!empty($valid_messages)) {
            usort($valid_messages, function($a, $b) {
                return $a['time'] - $b['time'];
            });
            return $valid_messages[0]['message'];
        }

        return null;
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
