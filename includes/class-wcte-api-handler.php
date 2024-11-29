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
        return new DateTimeZone('America/Sao_Paulo');
    }

    /**
     * Get current time in Sao Paulo timezone
     */
    private static function get_current_time() {
        try {
            $timezone = self::get_timezone();
            $datetime = new DateTime('now', $timezone);
            return $datetime->getTimestamp();
        } catch (Exception $e) {
            self::log('Erro ao obter horário atual: ' . $e->getMessage());
            return time();
        }
    }

    /**
     * Format current datetime in Sao Paulo timezone
     */
    private static function get_current_datetime($format = 'Y-m-d H:i:s') {
        try {
            $timezone = self::get_timezone();
            $datetime = new DateTime('now', $timezone);
            return $datetime->format($format);
        } catch (Exception $e) {
            self::log('Erro ao formatar horário atual: ' . $e->getMessage());
            return date($format);
        }
    }

    /**
     * Convert time string to timestamp in Sao Paulo timezone
     */
    private static function convert_to_timestamp($time_string) {
        try {
            $timezone = self::get_timezone();
            $datetime = new DateTime($time_string, $timezone);
            return $datetime->getTimestamp();
        } catch (Exception $e) {
            self::log('Erro ao converter timestamp: ' . $e->getMessage());
            return time();
        }
    }

    /**
     * Format date with timezone
     */
    private static function format_date_with_timezone($date_string, $format = 'Y-m-d H:i:s') {
        try {
            $timezone = self::get_timezone();
            $date = new DateTime($date_string);
            $date->setTimezone($timezone);
            return $date->format($format);
        } catch (Exception $e) {
            self::log('Erro ao formatar data: ' . $e->getMessage());
            return date($format);
        }
    }

    /**
     * Initialize tracking data in Firebase
     */
    private static function initialize_tracking($tracking_code, $order_note_date = null) {
        try {
            $created_at = $order_note_date ? $order_note_date : self::get_current_datetime();
            
            $tracking_data = array(
                'tracking_code' => $tracking_code,
                'carrier' => 'correios',
                'tracking_status' => 'pending',
                'created_at' => $created_at,
                'has_real_tracking' => false
            );

            if (WCTE_Database::save_tracking_data($tracking_data)) {
                $fictitious_message = self::get_fictitious_message($tracking_code, true);
                if ($fictitious_message) {
                    // Usa a data da nota como base para a primeira mensagem fictícia
                    $fake_date = $created_at;
                    $update_data = array(
                        'message' => $fictitious_message,
                        'timestamp' => self::convert_to_timestamp($created_at),
                        'date' => $fake_date,
                        'datetime' => $fake_date
                    );
                    WCTE_Database::save_fake_update($tracking_code, $update_data);
                }
                return true;
            }
            return false;
        } catch (Exception $e) {
            self::log('Erro ao inicializar rastreamento: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get next scheduled message time
     */
    public static function get_scheduled_message_time($creation_date, $days, $hour) {
        try {
            $timezone = new DateTimeZone('America/Sao_Paulo');
            $base_date = new DateTime($creation_date, $timezone);
            
            // Adiciona os dias configurados
            if ($days > 0) {
                $base_date->modify('+' . intval($days) . ' days');
            }

            // Define a hora configurada apenas se não for zero
            if (!empty($hour) && $hour !== '00:00') {
                list($hour_val, $minute_val) = explode(':', $hour);
                $base_date->setTime((int)$hour_val, (int)$minute_val, 0);
            }
            // Se o horário for zero, mantém o horário original da nota

            return $base_date->getTimestamp();
        } catch (Exception $e) {
            self::log('Erro ao calcular tempo agendado: ' . $e->getMessage());
            return time();
        }
    }

    /**
     * Verifica se devemos gerar uma nova atualização fictícia
     */
    private static function should_generate_fictional_update($tracking_code) {
        $tracking_status = WCTE_Database::get_tracking_status($tracking_code);

        if ($tracking_status) {
            return false;
        }

        $fake_updates = WCTE_Database::get_fake_updates($tracking_code);
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);

        if (!$creation_date) {
            return true;
        }

        $messages = get_option('wcte_fictitious_messages', array());
        $now = self::get_current_time();

        foreach ($messages as $message_data) {
            if (empty($message_data['message']) || !isset($message_data['days']) || empty($message_data['hour'])) {
                continue;
            }

            $scheduled_time = self::get_scheduled_message_time($creation_date, $message_data['days'], $message_data['hour']);

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
 * Obtém todas as mensagens fictícias programadas, independentemente do horário
 */
private static function get_fictitious_message($tracking_code) {
    $messages = get_option('wcte_fictitious_messages', array());
    $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
    global $wcte_order_note_date;
    $base_date = $wcte_order_note_date ?: $creation_date;

    $valid_messages = array();
    $current_time = self::get_current_time();

    foreach ($messages as $message_data) {
        if (empty($message_data['message']) || !isset($message_data['days']) || empty($message_data['hour'])) {
            continue;
        }

        // Adiciona a verificação do 'applies_to'
        if (isset($message_data['applies_to'])) {
            if ($message_data['applies_to'] === 'without_tracking') {
                continue; // Ignora mensagens destinadas apenas a pedidos sem rastreio
            }
            // Inclui mensagens com 'both' ou 'with_tracking'
        }

        // Calcula o horário agendado da mensagem
        $scheduled_time = self::get_scheduled_message_time($base_date, $message_data['days'], $message_data['hour']);
        if ($scheduled_time > $current_time) {
            continue; // Ignora mensagens futuras
        }

        $valid_messages[] = array(
            'date' => date('d/m/Y H:i', $scheduled_time),
            'message' => $message_data['message']
        );
    }

    // Ordena mensagens por data
    usort($valid_messages, function($a, $b) {
        return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
    });

    return $valid_messages;
}






    /**
     * Verifica se a entrada é um email
     */
    private static function is_email($input) {
        return filter_var($input, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Busca pedidos pelo email do cliente
     */
    private static function get_orders_by_email($email) {
        $args = array(
            'limit' => -1,
            'customer' => $email,
            'return' => 'objects',
        );
        $orders = wc_get_orders($args);
        return $orders;
    }


    /**
     * Generate fictitious messages using the order creation date
     */
/**
 * Gera mensagens fictícias usando a data de criação do pedido
 */
public static function get_fictitious_messages_by_order_date($order_date) {
    $messages = get_option('wcte_fictitious_messages', array());
    $current_time = self::get_current_time();
    $valid_messages = array();

    foreach ($fictitious_messages as $msg) {
        $msg_date = strtotime(str_replace('/', '-', $msg['date']));
    
        // Ignora mensagens fictícias com data igual ou posterior a uma atualização real
        $should_skip = false;
        if ($has_real_data) {
            foreach ($real_events as $real_update) {
                $real_date = strtotime(str_replace('/', '-', $real_update['date']));
                if ($real_date <= $msg_date) {
                    $should_skip = true;
                    break;
                }
            }
        }
    
        if (!$should_skip) {
            $formatted_fictitious_updates[] = array(
                'date' => $msg['date'],
                'description' => $msg['message'],
                'location' => 'Brasil'
            );
        }
    }
    

    usort($valid_messages, function ($a, $b) {
        return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
    });

    return $valid_messages;
}





    /**
     * Get tracking info by code
     */
    public static function get_tracking_info_by_code($tracking_code, $order_note_date = null) {
        global $wcte_order_note_date;
        $wcte_order_note_date = $order_note_date;
    
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
        if (!$creation_date) {
            self::initialize_tracking($tracking_code, $order_note_date);
        }
    
        // Obtém informações reais de rastreamento dos Correios
        $tracking_info = self::get_correios_tracking_info($tracking_code);
    
        $has_real_data = false;
        $real_events = array();
        if ($tracking_info['status'] !== 'error' && !empty($tracking_info['data'])) {
            foreach ($tracking_info['data'] as $event) {
                $has_real_data = true;
                $real_events[] = $event;
            }
        }
    
        // Marca como tendo rastreamento real se houver atualizações reais
        if ($has_real_data) {
            WCTE_Database::mark_as_real_tracking($tracking_code);
        }
    
        // Obtém mensagens fictícias
        $fictitious_messages = self::get_fictitious_message($tracking_code);
    
        // Formata as mensagens fictícias
        $formatted_fictitious_updates = array();
        foreach ($fictitious_messages as $msg) {
            $msg_date = strtotime(str_replace('/', '-', $msg['date']));
    
            // Ignora mensagens fictícias com data igual ou posterior a uma atualização real
            $should_skip = false;
            if ($has_real_data) {
                foreach ($real_events as $real_update) {
                    $real_date = strtotime(str_replace('/', '-', $real_update['date']));
                    if ($real_date <= $msg_date) {
                        $should_skip = true;
                        break;
                    }
                }
            }
    
            if (!$should_skip) {
                $formatted_fictitious_updates[] = array(
                    'date' => $msg['date'],
                    'description' => $msg['message'],
                    'location' => 'Brasil'
                );
            }
        }
    
        // Combina as mensagens fictícias com as reais
        if ($has_real_data) {
            $all_updates = array_merge($formatted_fictitious_updates, $tracking_info['data']);
            usort($all_updates, function ($a, $b) {
                return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
            });
    
            $tracking_info['data'] = $all_updates;
            $tracking_info['tracking_code'] = $tracking_code;
            return $tracking_info;
        }
    
        // Se não houver dados reais, retorna apenas as mensagens fictícias
        return array(
            'status' => 'in_transit',
            'message' => end($formatted_fictitious_updates)['description'],
            'data' => $formatted_fictitious_updates,
            'tracking_code' => $tracking_code
        );
    }
    
    

    

    /**
     * Obtém informações de rastreamento ou pedidos
     */
    public static function get_tracking_info($tracking_code_or_email, $order_note_date = null) {
        self::log('Iniciando consulta para: ' . $tracking_code_or_email);
    
        global $wcte_order_note_date;
        $wcte_order_note_date = $order_note_date;
    
        // Obtém a data de criação do rastreamento
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code_or_email);
        if (!$creation_date) {
            self::initialize_tracking($tracking_code_or_email, $order_note_date);
            $creation_date = $order_note_date;
        }
    
        // Obtém mensagens reais dos Correios
        $tracking_info = self::get_correios_tracking_info($tracking_code_or_email);
        $has_real_data = ($tracking_info['status'] !== 'error' && !empty($tracking_info['data']));
    
        // Marca como rastreamento real se houver dados reais
        if ($has_real_data) {
            WCTE_Database::mark_as_real_tracking($tracking_code_or_email);
        }
    
        // Obtém mensagens fictícias
        $fictitious_messages = self::get_fictitious_message($tracking_code_or_email);
    
        // Formata mensagens fictícias para exibição
        $formatted_fictitious_updates = array();
        foreach ($fictitious_messages as $msg) {
            $formatted_fictitious_updates[] = array(
                'date' => $msg['date'],
                'description' => $msg['message'],
                'location' => 'Brasil'
            );
        }
    
        // Combina mensagens reais e fictícias
        $all_updates = array_merge($formatted_fictitious_updates, $tracking_info['data'] ?? []);
        usort($all_updates, function ($a, $b) {
            return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
        });
    
        // Atualiza informações de rastreamento
        return array(
            'status' => $tracking_info['status'] ?? 'in_transit',
            'message' => end($all_updates)['description'] ?? 'Seu pedido está em processamento.',
            'data' => $all_updates,
            'tracking_code' => $tracking_code_or_email
        );
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

        $events = $objeto['eventos'];
        $filtered_events = array();
        $timezone = self::get_timezone();

        foreach ($events as $event) {
            // Ignora eventos com "Objeto postado"
            if (stripos($event['descricao'], 'Objeto postado') !== false) {
                continue;
            }

            $location = '';
            if (isset($event['unidade']['endereco'])) {
                $endereco = $event['unidade']['endereco'];
                $location = sprintf('%s - %s/%s',
                    $endereco['cidade'] ?? '',
                    $endereco['uf'] ?? '',
                    $endereco['pais'] ?? 'BR'
                );
            }

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