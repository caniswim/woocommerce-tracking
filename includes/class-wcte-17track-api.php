<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe para integração com a API do 17track
 * 
 * Esta classe substitui a integração com a API dos Correios no plugin original.
 * Mantém a compatibilidade com o sistema de mensagens fictícias e a estrutura
 * existente de dados no Firebase.
 */
class WCTE_17Track_API {
    // URL base da API do 17track
    private const API_BASE_URL = 'https://api.17track.net/track/v2.2';
    
    // Códigos das transportadoras no 17track
    private const CARRIER_AUTO_DETECT = 0;
    private const CARRIER_CORREIOS_BRASIL = 2151;
    private const CARRIER_DHL = 13;
    private const CARRIER_CHINA_POST = 3;
    private const CARRIER_CAINIAO = 800;
    private const CARRIER_ALIEXPRESS = 900;
    
    /**
     * Log helper
     */
    private static function log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $log_message = 'WCTE 17Track API: ' . $message;
            if ($data !== null) {
                $log_message .= ' - Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }

    /**
     * Verifica se um código de rastreio já foi registrado no 17track
     * Usa cache local para evitar registros duplicados
     * 
     * @param string $tracking_code Código de rastreamento
     * @return bool
     */
    private static function is_tracking_registered($tracking_code) {
        // Obtém conjunto de códigos já registrados no cache
        $registered_codes = get_option('wcte_17track_registered_codes', array());
        
        // Verifica se o código está no cache
        if (in_array($tracking_code, $registered_codes)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Marca um código de rastreio como registrado no 17track
     * 
     * @param string $tracking_code Código de rastreamento
     * @return void
     */
    private static function mark_tracking_as_registered($tracking_code) {
        // Obtém conjunto de códigos já registrados
        $registered_codes = get_option('wcte_17track_registered_codes', array());
        
        // Adiciona o código ao conjunto se ainda não estiver presente
        if (!in_array($tracking_code, $registered_codes)) {
            $registered_codes[] = $tracking_code;
            update_option('wcte_17track_registered_codes', $registered_codes);
        }
    }

    /**
     * Obtém o token de acesso à API do 17track
     * 
     * @return string|false Token de acesso ou false em caso de erro
     */
    private static function get_api_token() {
        $api_key = get_option('wcte_17track_api_key');
        
        if (empty($api_key)) {
            self::log('API Key não configurada');
            return false;
        }
        
        return $api_key;
    }

    /**
     * Registra um código de rastreio no 17track
     * 
     * @param string $tracking_code Código de rastreamento
     * @param int|null $carrier Código da transportadora (opcional)
     * @return bool|array Resultado do registro ou false em caso de erro
     */
    private static function register_tracking($tracking_code, $carrier = null) {
        // Verifica se o código já foi registrado (cache local)
        if (self::is_tracking_registered($tracking_code)) {
            return true;
        }
        
        $api_key = self::get_api_token();
        if (!$api_key) {
            return false;
        }
        
        // Prepara o payload para registro na API
        $payload = array(
            array(
                'number' => $tracking_code,
                'auto_detection' => true
            )
        );
        
        // Adiciona código de transportadora se fornecido
        if ($carrier !== null) {
            $payload[0]['carrier'] = $carrier;
            $payload[0]['auto_detection'] = false;
        }
        
        // Faz requisição à API do 17track para registrar o código
        $response = wp_remote_post(self::API_BASE_URL . '/register', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                '17token' => $api_key
            ),
            'body' => json_encode($payload)
        ));
        
        if (is_wp_error($response)) {
            self::log('Erro ao registrar código: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || $data['code'] !== 0) {
            self::log('Resposta inválida do 17track ao registrar código', $data);
            return false;
        }
        
        // Verifica se o código foi aceito
        $accepted = false;
        if (isset($data['data']['accepted']) && is_array($data['data']['accepted'])) {
            foreach ($data['data']['accepted'] as $accepted_item) {
                if (isset($accepted_item['number']) && $accepted_item['number'] === $tracking_code) {
                    $accepted = true;
                    // Adiciona ao cache local para evitar registros duplicados
                    self::mark_tracking_as_registered($tracking_code);
                    break;
                }
            }
        }
        
        return $accepted ? $data : false;
    }

    /**
     * Obtém informações de rastreamento do 17track
     * 
     * @param string $tracking_code Código de rastreamento
     * @return array Informações de rastreamento
     */
    public static function get_tracking_info($tracking_code) {
        // Verifica se é código Cainiao logo no início
        if (self::is_cainiao_tracking($tracking_code)) {
            return array(
                'status' => 'cainiao',
                'message' => 'Clique no botão abaixo para acompanhar seu pedido no site da transportadora.',
                'tracking_url' => 'https://parcelsapp.com/pt/tracking/' . urlencode($tracking_code),
                'tracking_code' => $tracking_code,
                'data' => [] // Array vazio para não mostrar mensagens fictícias
            );
        }
        
        $api_key = self::get_api_token();
        if (!$api_key) {
            return array(
                'status' => 'error',
                'message' => 'Não foi possível autenticar na API de rastreamento.',
                'data' => []
            );
        }
        
        // Define o carrier baseado no formato do código de rastreamento
        $carrier = self::detect_carrier($tracking_code);
        
        // Primeiro registra o código de rastreio (se ainda não estiver registrado)
        $register_result = self::register_tracking($tracking_code, $carrier);
        
        // Prepara a requisição para obter informações de rastreamento
        $payload = array(
            array(
                'number' => $tracking_code,
                // Adiciona o parâmetro cacheLevel=1 para obter dados em tempo real
                'cacheLevel' => 1
            )
        );
        
        // Adiciona código de transportadora se detectado
        if ($carrier !== 0) {
            $payload[0]['carrier'] = $carrier;
        }
        
        // Faz requisição à API do 17track para obter informações de rastreamento em tempo real
        $response = wp_remote_post(self::API_BASE_URL . '/gettrackinfo', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                '17token' => $api_key
            ),
            'body' => json_encode($payload),
            'timeout' => 30 // Aumenta o timeout para 30 segundos já que consultas em tempo real podem demorar mais
        ));
        
        if (is_wp_error($response)) {
            self::log('Erro ao rastrear código: ' . $response->get_error_message());
            return array(
                'status' => 'error',
                'message' => 'Erro ao consultar informações de rastreamento.',
                'data' => []
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || $data['code'] !== 0 || !isset($data['data']['accepted']) || empty($data['data']['accepted'])) {
            self::log('Resposta inválida do 17track', $data);
            return array(
                'status' => 'pending',
                'message' => 'Informações de rastreamento ainda não disponíveis.',
                'data' => []
            );
        }
        
        // Processa a resposta - o formato da API v2.2 é diferente da v1
        return self::format_tracking_response_v2($data['data']['accepted'][0], $tracking_code);
    }

    /**
     * Formata a resposta da API v2.2 do 17track para o formato do plugin
     * 
     * @param array $tracking_data Dados de rastreamento do 17track
     * @param string $tracking_code Código de rastreamento
     * @return array Dados formatados
     */
    private static function format_tracking_response_v2($tracking_data, $tracking_code) {
        $events = array();
        $status = 'pending';
        $message = 'Aguardando informações de rastreamento.';
        
        // Verifica se há informações de rastreamento
        if (isset($tracking_data['track_info']['tracking']['providers']) && 
            is_array($tracking_data['track_info']['tracking']['providers'])) {
            
            // Processa eventos de rastreamento
            $providers = $tracking_data['track_info']['tracking']['providers'];
            
            foreach ($providers as $provider) {
                if (isset($provider['events']) && is_array($provider['events'])) {
                    foreach ($provider['events'] as $event) {
                        $time = isset($event['time_utc']) ? strtotime($event['time_utc']) : 0;
                        
                        // Se não tiver time_utc, tenta usar time_iso
                        if (!$time && isset($event['time_iso'])) {
                            $time = strtotime($event['time_iso']);
                        }
                        
                        // Se ainda não tiver tempo, usa time_raw
                        if (!$time && isset($event['time_raw']['date'])) {
                            $time_str = $event['time_raw']['date'];
                            if (isset($event['time_raw']['time'])) {
                                $time_str .= ' ' . $event['time_raw']['time'];
                            }
                            $time = strtotime($time_str);
                        }
                        
                        // Se nenhuma das opções funcionar, usa o tempo atual
                        if (!$time) {
                            $time = time();
                        }
                        
                        // Formata a data
                        $event_date = date('d/m/Y H:i', $time);
                        
                        $events[] = array(
                            'date' => $event_date,
                            'description' => $event['description'] ?? 'Sem descrição',
                            'location' => $event['location'] ?? 'Desconhecido'
                        );
                    }
                }
            }
            
            // Ordena eventos por data (mais recente primeiro)
            usort($events, function ($a, $b) {
                return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
            });
            
            // Define status e mensagem baseado no status da API
            if (isset($tracking_data['track_info']['latest_status']['status'])) {
                $api_status = $tracking_data['track_info']['latest_status']['status'];
                
                // Mapeamento de status da API para status internos
                switch ($api_status) {
                    case 'NotFound':
                        $status = 'pending';
                        $message = 'Informações de rastreamento ainda não disponíveis.';
                        break;
                    case 'InfoReceived':
                        $status = 'pending';
                        $message = 'Informações recebidas pela transportadora.';
                        break;
                    case 'InTransit':
                        $status = 'in_transit';
                        $message = 'Em trânsito.';
                        break;
                    case 'OutForDelivery':
                        $status = 'in_transit';
                        $message = 'Saiu para entrega.';
                        break;
                    case 'Delivered':
                        $status = 'delivered';
                        $message = 'Entregue.';
                        break;
                    case 'DeliveryFailure':
                    case 'Exception':
                        $status = 'problem';
                        $message = 'Problema na entrega.';
                        break;
                    default:
                        $status = 'in_transit';
                        $message = 'Em processamento.';
                }
            }
            
            // Define a mensagem como a descrição do último evento, se disponível
            if (!empty($events)) {
                $message = $events[0]['description'];
            }
        } else if (isset($tracking_data['error'])) {
            // Trata erros da API
            $status = 'error';
            $message = isset($tracking_data['error']['message']) ? $tracking_data['error']['message'] : 'Erro ao rastrear encomenda.';
        }
        
        return array(
            'status' => $status,
            'message' => $message,
            'data' => $events,
            'tracking_code' => $tracking_code
        );
    }

    /**
     * Detecta a transportadora baseado no formato do código de rastreamento
     * 
     * @param string $tracking_code Código de rastreamento
     * @return int Código da transportadora no 17track
     */
    private static function detect_carrier($tracking_code) {
        // Padrão Cainiao - mantém o comportamento especial de redirecionamento
        if (self::is_cainiao_tracking($tracking_code)) {
            return self::CARRIER_CAINIAO;
        }
        
        // Para todos os outros códigos, usa sempre Correios Brasil
        return self::CARRIER_CORREIOS_BRASIL;
    }

    /**
     * Verifica se o código de rastreamento é do tipo Cainiao
     * 
     * @param string $tracking_code Código de rastreamento
     * @return bool
     */
    public static function is_cainiao_tracking($tracking_code) {
        // Padrões Cainiao/AliExpress
        if (
            preg_match('/^LP\d{12,}$/', $tracking_code) || // LP + 12+ dígitos
            preg_match('/^CNBR\d{8,}$/', $tracking_code) || // CNBR + 8+ dígitos
            preg_match('/^YT\d{16}$/', $tracking_code) // YT + 16 dígitos
        ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Obtém informações de rastreamento por código com suporte a mensagens fictícias
     * 
     * Esta função é um substituto para o método get_tracking_info_by_code
     * da classe WCTE_API_Handler
     * 
     * @param string $tracking_code Código de rastreamento
     * @param string $order_note_date Data da nota do pedido
     * @return array Informações de rastreamento
     */
    public static function get_tracking_info_by_code($tracking_code, $order_note_date = null) {
        // Verifica se é código Cainiao logo no início
        if (self::is_cainiao_tracking($tracking_code)) {
            return array(
                'status' => 'cainiao',
                'message' => 'Clique no botão abaixo para acompanhar seu pedido no site da transportadora.',
                'tracking_url' => 'https://parcelsapp.com/pt/tracking/' . urlencode($tracking_code),
                'tracking_code' => $tracking_code,
                'data' => [] // Array vazio para não mostrar mensagens fictícias
            );
        }

        global $wcte_order_note_date;
        $wcte_order_note_date = $order_note_date;
    
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
        if (!$creation_date) {
            self::initialize_tracking($tracking_code, $order_note_date);
        }
    
        // Obtém informações reais de rastreamento do 17track
        $tracking_info = self::get_tracking_info($tracking_code);
    
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
            'message' => !empty($formatted_fictitious_updates) ? end($formatted_fictitious_updates)['description'] : 'Seu pedido está em processamento.',
            'data' => $formatted_fictitious_updates,
            'tracking_code' => $tracking_code
        );
    }
    
    /**
     * Inicializa um rastreamento no Firebase
     * 
     * @param string $tracking_code Código de rastreamento
     * @param string $order_note_date Data da nota do pedido
     * @return bool
     */
    private static function initialize_tracking($tracking_code, $order_note_date = null) {
        try {
            $created_at = $order_note_date ? $order_note_date : date('Y-m-d H:i:s');
            
            $tracking_data = array(
                'tracking_code' => $tracking_code,
                'carrier' => 'correios', // Mantém compatibilidade com a estrutura existente
                'tracking_status' => 'pending',
                'created_at' => $created_at,
                'has_real_tracking' => false
            );

            if (WCTE_Database::save_tracking_data($tracking_data)) {
                // Obtém e salva a primeira mensagem fictícia
                $fictitious_message = self::get_fictitious_first_message();
                if ($fictitious_message) {
                    // Usa a data da nota como base para a primeira mensagem fictícia
                    $fake_date = $created_at;
                    $update_data = array(
                        'message' => $fictitious_message,
                        'timestamp' => strtotime($created_at),
                        'date' => $fake_date,
                        'datetime' => $fake_date
                    );
                    WCTE_Database::save_fake_update($tracking_code, $update_data);
                }
                
                // Registra o código no 17track
                self::register_tracking($tracking_code, self::detect_carrier($tracking_code));
                
                return true;
            }
            return false;
        } catch (Exception $e) {
            self::log('Erro ao inicializar rastreamento: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtém a primeira mensagem fictícia para um novo rastreamento
     * 
     * @return string Mensagem fictícia
     */
    private static function get_fictitious_first_message() {
        $messages = get_option('wcte_fictitious_messages', array());
        
        foreach ($messages as $message_data) {
            if (isset($message_data['days']) && $message_data['days'] == 0) {
                return $message_data['message'];
            }
        }
        
        // Mensagem padrão se não encontrar nenhuma configurada
        return 'Seu pedido foi registrado.';
    }
    
    /**
     * Obtém mensagens fictícias para um código de rastreamento
     * 
     * @param string $tracking_code Código de rastreamento
     * @return array Mensagens fictícias
     */
    private static function get_fictitious_message($tracking_code) {
        // Retorna array vazio se for código Cainiao
        if (self::is_cainiao_tracking($tracking_code)) {
            return array();
        }

        $messages = get_option('wcte_fictitious_messages', array());
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
        global $wcte_order_note_date;
        $base_date = $wcte_order_note_date ?: $creation_date;

        $valid_messages = array();
        $current_time = time();

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

            // Formata a data com o fuso horário correto
            $date = new DateTime('@' . $scheduled_time); // Cria DateTime a partir do timestamp
            $date->setTimezone(new DateTimeZone('America/Sao_Paulo')); // Define o fuso horário para São Paulo
            $formatted_date = $date->format('d/m/Y H:i');

            $valid_messages[] = array(
                'date' => $formatted_date,
                'message' => $message_data['message']
            );
        }

        // Ordena mensagens por data
        usort($valid_messages, function ($a, $b) {
            return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
        });

        return $valid_messages;
    }
    
    /**
     * Calcula o horário agendado para uma mensagem fictícia
     * 
     * @param string $creation_date Data de criação do rastreamento
     * @param int $days Dias a adicionar
     * @param string $hour Hora no formato HH:MM
     * @return int Timestamp
     */
    private static function get_scheduled_message_time($creation_date, $days, $hour) {
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
     * Obtém mensagens fictícias por data do pedido (para pedidos sem rastreio)
     * 
     * @param string $order_date Data do pedido
     * @return array Mensagens fictícias
     */
    public static function get_fictitious_messages_by_order_date($order_date) {
        $messages = get_option('wcte_fictitious_messages', array());
        $current_time = time();
        $valid_messages = array();

        foreach ($messages as $message_data) {
            if (
                empty($message_data['message']) ||
                !isset($message_data['days']) ||
                !isset($message_data['hour'])
            ) {
                continue; // Pula mensagens inválidas
            }

            // Verifica se a mensagem se aplica a pedidos sem rastreio
            if (isset($message_data['applies_to'])) {
                if ($message_data['applies_to'] === 'with_tracking') {
                    continue; // Ignora mensagens destinadas apenas a pedidos com rastreio
                }
                // Inclui mensagens com 'both' ou 'without_tracking'
            }

            $scheduled_time = self::get_scheduled_message_time($order_date, $message_data['days'], $message_data['hour']);

            if ($scheduled_time > $current_time) {
                continue; // Pula mensagens futuras
            }

            // Formata a data com o fuso horário correto
            $date = new DateTime('@' . $scheduled_time); // Cria DateTime a partir do timestamp
            $date->setTimezone(new DateTimeZone('America/Sao_Paulo')); // Define o fuso horário para São Paulo
            $formatted_date = $date->format('d/m/Y H:i');

            $valid_messages[] = array(
                'date' => $formatted_date,
                'description' => $message_data['message'],
                'location' => 'Brasil',
            );
        }

        // Ordena mensagens por data
        usort($valid_messages, function ($a, $b) {
            return strtotime(str_replace('/', '-', $b['date'])) - strtotime(str_replace('/', '-', $a['date']));
        });

        return $valid_messages;
    }

    /**
     * Atualiza informações de rastreamento em lote
     * 
     * Esta função é executada periodicamente pelo cron para atualizar
     * as informações de rastreamento dos pedidos pendentes.
     * 
     * @return void
     */
    public static function batch_update_tracking_info() {
        global $wpdb;
        
        // Verifica se a integração está ativada
        $enabled = get_option('wcte_17track_enabled', true);
        if (!$enabled) {
            return;
        }
        
        // Obtém pedidos recentes com status de processamento ou concluído
        $orders = wc_get_orders(array(
            'limit' => 50,
            'status' => array('processing', 'completed'),
            'date_created' => '>' . date('Y-m-d', strtotime('-60 days')), // Últimos 60 dias
            'return' => 'ids'
        ));
        
        if (empty($orders)) {
            return;
        }
        
        $tracking_codes = array();
        
        // Extrai códigos de rastreamento dos pedidos
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            
            // Verifica código de rastreio nos metadados do pedido
            $tracking_code_meta = $order->get_meta('_tracking_code');
            if ($tracking_code_meta) {
                $tracking_codes[$tracking_code_meta] = $order_id;
            }
            
            // Verifica nas notas do pedido
            $order_notes = wc_get_order_notes(array(
                'order_id' => $order_id,
                'type' => 'any',
            ));
            
            foreach ($order_notes as $note) {
                if (preg_match_all('/\b([A-Z]{2}[0-9]{9,14}[A-Z]{2})\b|\b(LP\d{12,})\b|\b(CNBR\d{8,})\b|\b(YT\d{16})\b|\b(SYRM\d{9,})\b/i', $note->content, $matches)) {
                    foreach ($matches[0] as $code) {
                        $tracking_codes[$code] = $order_id;
                    }
                }
            }
        }
        
        // Se não encontrou códigos, encerra
        if (empty($tracking_codes)) {
            return;
        }
        
        // Divide os códigos em lotes para não sobrecarregar a API
        $batches = array_chunk(array_keys($tracking_codes), 20, true);
        
        foreach ($batches as $batch) {
            // Processa o lote
            self::process_tracking_batch($batch, $tracking_codes);
            
            // Aguarda um pouco entre os lotes para não sobrecarregar a API
            sleep(5);
        }
    }
    
    /**
     * Processa um lote de códigos de rastreamento
     * 
     * @param array $codes Códigos de rastreamento
     * @param array $tracking_codes_map Mapeamento de códigos para IDs de pedidos
     * @return void
     */
    private static function process_tracking_batch($codes, $tracking_codes_map) {
        $api_key = self::get_api_token();
        if (!$api_key) {
            return;
        }
        
        // Prepara a lista de códigos para verificação em lote
        $payload = array();
        foreach ($codes as $code) {
            if (self::is_cainiao_tracking($code)) {
                continue; // Pula códigos Cainiao que são redirecionados para outro site
            }
            
            // Registra os códigos na API primeiro (se ainda não estiverem registrados)
            if (!self::is_tracking_registered($code)) {
                self::register_tracking($code, self::detect_carrier($code));
            }
            
            $payload[] = array(
                'number' => $code,
                'carrier' => self::detect_carrier($code),
                'cacheLevel' => 1 // Usar sempre consulta em tempo real
            );
        }
        
        if (empty($payload)) {
            return;
        }
        
        // Faz requisição à API do 17track para obter informações de rastreamento em lote
        $response = wp_remote_post(self::API_BASE_URL . '/gettrackinfo', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                '17token' => $api_key
            ),
            'body' => json_encode($payload),
            'timeout' => 45 // Aumenta o timeout para 45 segundos já que é um lote de consultas em tempo real
        ));
        
        if (is_wp_error($response)) {
            self::log('Erro ao rastrear lote: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || $data['code'] !== 0 || !isset($data['data']['accepted'])) {
            self::log('Resposta inválida do 17track para lote', $data);
            return;
        }
        
        // Processa os resultados e atualiza o banco de dados
        foreach ($data['data']['accepted'] as $tracking_data) {
            if (!isset($tracking_data['number'])) {
                continue;
            }
            
            $tracking_code = $tracking_data['number'];
            $order_id = isset($tracking_codes_map[$tracking_code]) ? $tracking_codes_map[$tracking_code] : null;
            
            // Formata os dados e verifica status
            $formatted_data = self::format_tracking_response_v2($tracking_data, $tracking_code);
            
            if (!empty($formatted_data['data'])) {
                // Marca como tendo rastreamento real
                WCTE_Database::mark_as_real_tracking($tracking_code);
                
                // Adiciona nota ao pedido se houver uma atualização importante
                if ($order_id && in_array($formatted_data['status'], array('delivered', 'problem'))) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $note = '';
                        switch ($formatted_data['status']) {
                            case 'delivered':
                                $note = 'Pedido entregue segundo o rastreamento: ' . $formatted_data['message'];
                                break;
                            case 'problem':
                                $note = 'Problema identificado no rastreamento: ' . $formatted_data['message'];
                                break;
                        }
                        
                        if (!empty($note)) {
                            $order->add_order_note($note);
                        }
                    }
                }
            }
        }
    }
} 