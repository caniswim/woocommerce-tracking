<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe responsável pela API REST do WooCommerce Tracking Enhanced
 * 
 * Esta classe implementa os endpoints REST conforme especificado no plano de desenvolvimento:
 * - GET /wp-json/wcte/v1/orders - Lista pedidos com filtros
 * - GET /wp-json/wcte/v1/orders/{id} - Detalhes de um pedido específico 
 * - GET /wp-json/wcte/v1/tracking/{code} - Informações de rastreamento
 * - GET /wp-json/wcte/v1/tracking/email/{email} - Pedidos por email
 */
class WCTE_V2_REST_API {

    /**
     * Construct da classe
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Registra as rotas da API REST
     */
    public function register_routes() {
        // Registra namespace
        $namespace = 'wcte/v1';

        // Registra a rota para listar pedidos
        register_rest_route($namespace, '/orders', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_orders'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Registra a rota para obter detalhes de um pedido específico
        register_rest_route($namespace, '/orders/(?P<id>\d+)', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_order'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));

        // Registra a rota para obter informações de rastreamento por código
        register_rest_route($namespace, '/tracking/(?P<code>[a-zA-Z0-9]+)', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_tracking_info'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'code' => array(
                    'validate_callback' => function($param) {
                        return !empty($param);
                    }
                ),
            ),
        ));

        // Registra a rota para obter pedidos por email
        register_rest_route($namespace, '/tracking/email/(?P<email>.+)', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_orders_by_email'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'email' => array(
                    'validate_callback' => function($param) {
                        return is_email($param);
                    }
                ),
            ),
        ));
        
        // Registra a rota unificada para processar diferentes tipos de entrada (como o AJAX)
        register_rest_route($namespace, '/track', array(
            'methods'  => 'GET',
            'callback' => array($this, 'track_order'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'tracking_input' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return !empty($param);
                    }
                ),
            ),
        ));
    }

    /**
     * Verifica a permissão de acesso à API
     * 
     * @param WP_REST_Request $request Objeto da requisição
     * @return bool
     */
    public function check_permission($request) {
        // Verifica se a autenticação por API Key está ativada
        $api_key_enabled = get_option('wcte_api_key_enabled', false);
        
        if (!$api_key_enabled) {
            return true; // Se não estiver ativada, permite acesso livre
        }
        
        // Verifica a API Key fornecida
        $api_key = $request->get_header('X-WCTE-API-Key');
        $saved_api_key = get_option('wcte_api_key');
        
        if (empty($api_key) || $api_key !== $saved_api_key) {
            return new WP_Error(
                'rest_forbidden',
                'API Key inválida ou não fornecida',
                array('status' => 401)
            );
        }
        
        return true;
    }

    /**
     * Obtém uma lista de pedidos com filtros
     * 
     * @param WP_REST_Request $request Objeto da requisição
     * @return WP_REST_Response
     */
    public function get_orders($request) {
        // Parâmetros de filtro
        $per_page = $request->get_param('per_page') ? absint($request->get_param('per_page')) : 10;
        $page = $request->get_param('page') ? absint($request->get_param('page')) : 1;
        $status = $request->get_param('status');
        
        // Argumentos para buscar pedidos
        $args = array(
            'limit' => $per_page,
            'page' => $page,
            'return' => 'objects',
        );
        
        // Filtra por status se fornecido
        if (!empty($status)) {
            $args['status'] = $status;
        }
        
        // Busca os pedidos
        $orders = wc_get_orders($args);
        $total_orders = wc_get_orders(array_merge($args, array('limit' => -1, 'return' => 'ids')));
        
        // Formata os dados de resposta
        $response_data = array();
        
        foreach ($orders as $order) {
            $tracking_data = $this->extract_tracking_codes($order);
            
            $response_data[] = array(
                'id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
                'customer_email' => $order->get_billing_email(),
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'tracking_codes' => array_keys($tracking_data),
            );
        }
        
        // Adiciona headers de paginação
        $total_pages = ceil(count($total_orders) / $per_page);
        
        $response = new WP_REST_Response($response_data, 200);
        $response->header('X-WP-Total', count($total_orders));
        $response->header('X-WP-TotalPages', $total_pages);
        
        return $response;
    }

    /**
     * Obtém detalhes de um pedido específico
     * 
     * @param WP_REST_Request $request Objeto da requisição
     * @return WP_REST_Response|WP_Error
     */
    public function get_order($request) {
        $order_id = $request->get_param('id');
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error(
                'wcte_not_found',
                'Pedido não encontrado',
                array('status' => 404)
            );
        }
        
        $tracking_data = $this->extract_tracking_codes($order);
        $tracking_info = array();
        
        // Obtém informações de rastreamento para cada código
        foreach ($tracking_data as $code => $note_date) {
            $tracking_info[$code] = WCTE_17Track_API::get_tracking_info_by_code($code, $note_date);
        }
        
        $order_data = array(
            'id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'customer' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ),
            'shipping' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ),
            'items' => $this->get_order_items($order),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'tracking' => array(
                'codes' => array_keys($tracking_data),
                'info' => $tracking_info,
            ),
        );
        
        return new WP_REST_Response($order_data, 200);
    }

    /**
     * Obtém informações de rastreamento por código
     * 
     * @param WP_REST_Request $request Objeto da requisição
     * @return WP_REST_Response|WP_Error
     */
    public function get_tracking_info($request) {
        $code = $request->get_param('code');
        
        // Busca informações de rastreamento
        $tracking_info = WCTE_17Track_API::get_tracking_info_by_code($code);
        
        if (!$tracking_info) {
            return new WP_Error(
                'wcte_not_found',
                'Código de rastreamento não encontrado',
                array('status' => 404)
            );
        }
        
        return new WP_REST_Response($tracking_info, 200);
    }

    /**
     * Obtém pedidos por email
     * 
     * @param WP_REST_Request $request Objeto da requisição
     * @return WP_REST_Response
     */
    public function get_orders_by_email($request) {
        $email = $request->get_param('email');
        
        // Busca pedidos pelo email
        $orders = wc_get_orders(array(
            'billing_email' => $email,
            'limit' => -1,
            'return' => 'objects',
        ));
        
        $response_data = array();
        
        foreach ($orders as $order) {
            $tracking_data = $this->extract_tracking_codes($order);
            
            $order_data = array(
                'id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'tracking_codes' => array_keys($tracking_data),
            );
            
            $response_data[] = $order_data;
        }
        
        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Extrai códigos de rastreamento de um pedido
     * 
     * @param WC_Order $order Objeto do pedido
     * @return array Códigos de rastreamento e suas datas
     */
    private function extract_tracking_codes($order) {
        $tracking_data = array();
        
        // Verifica código de rastreio nos metadados do pedido
        $tracking_code_meta = $order->get_meta('_tracking_code');
        if ($tracking_code_meta) {
            $tracking_data[$tracking_code_meta] = $order->get_date_created()->date('Y-m-d H:i:s');
        }
        
        // Verifica nas notas do pedido
        $order_notes = wc_get_order_notes(array(
            'order_id' => $order->get_id(),
            'type' => 'any',
        ));
        
        foreach ($order_notes as $note) {
            if (preg_match_all('/\b([A-Z]{2}[0-9]{9,14}[A-Z]{2})\b|\b(LP\d{12,})\b|\b(CNBR\d{8,})\b|\b(YT\d{16})\b|\b(SYRM\d{9,})\b/i', $note->content, $matches)) {
                foreach ($matches[0] as $code) {
                    $tracking_data[$code] = $note->date_created->date('Y-m-d H:i:s');
                }
            }
        }
        
        return $tracking_data;
    }

    /**
     * Processa diferentes tipos de entrada para rastreamento (similar ao AJAX)
     * 
     * @param WP_REST_Request $request Objeto da requisição
     * @return WP_REST_Response
     */
    public function track_order($request) {
        $tracking_input = sanitize_text_field($request->get_param('tracking_input'));
        $tracking_input = trim($tracking_input, '#');
        
        $response = array();
        $results = array();
        
        // Verifica se é um email
        if (is_email($tracking_input)) {
            $orders = wc_get_orders(array(
                'billing_email' => $tracking_input,
                'limit' => -1,
            ));
            
            if (!empty($orders)) {
                // Prepara os dados dos pedidos para exibição
                $order_list = array();
                foreach ($orders as $order) {
                    $tracking_code = $order->get_meta('_tracking_code');
                    $order_list[] = array(
                        'order_id' => $order->get_id(),
                        'order_date' => $order->get_date_created()->date('d/m/Y H:i'),
                        'order_status' => wc_get_order_status_name($order->get_status()),
                        'tracking_code' => $tracking_code ? $tracking_code : '',
                    );
                }
                $response['status'] = 'orders_found';
                $response['message'] = 'Pedidos encontrados para o email.';
                $response['data'] = $order_list;
                
                return $this->rest_success_response($response);
            } else {
                return $this->rest_error_response('Nenhum pedido encontrado para este email.');
            }
        } 
        // Verifica se é um número de pedido
        elseif (is_numeric($tracking_input)) {
            $order_id = intval($tracking_input);
            $order = wc_get_order($order_id);
            
            if ($order) {
                $order_status = $order->get_status();
                $tracking_data = $this->extract_tracking_codes($order);
                
                if (!empty($tracking_data)) {
                    // Processa os códigos de rastreamento
                    foreach ($tracking_data as $code => $note_date) {
                        $tracking_info = WCTE_17Track_API::get_tracking_info_by_code($code, $note_date);
                        
                        if ($tracking_info) {
                            $tracking_info['tracking_code'] = $code;
                            $results[] = $tracking_info;
                        }
                    }
                    
                    // Inclui informações adicionais do pedido
                    $response['order_number'] = $order->get_order_number();
                    $response['order_items'] = $this->get_order_items($order);
                    $response['tracking_results'] = $results;
                    
                    return $this->rest_success_response($response);
                } else {
                    // Trata diferentes status do pedido
                    switch ($order_status) {
                        case 'pending':
                            return $this->rest_error_response('Seu pedido está pendente de pagamento.');
                        case 'failed':
                            return $this->rest_error_response('O pagamento do seu pedido falhou. Por favor, tente novamente.');
                        case 'cancelled':
                            return $this->rest_error_response('Este pedido foi cancelado.');
                        case 'refunded':
                            return $this->rest_error_response('Este pedido foi reembolsado.');
                        case 'on-hold':
                            return $this->rest_error_response('Seu pedido está aguardando confirmação.');
                        case 'processing':
                        case 'completed':
                        default:
                            // Gera mensagens fictícias para pedidos processando ou concluídos sem rastreio
                            $order_date = $order->get_date_created()->date('Y-m-d H:i:s');
                            
                            // Usa a API do 17track para obter mensagens fictícias
                            $fictitious_messages = WCTE_17Track_API::get_fictitious_messages_by_order_date($order_date);
                            
                            if ($fictitious_messages) {
                                $tracking_result = array(
                                    'status' => 'in_transit',
                                    'message' => end($fictitious_messages)['description'],
                                    'data' => $fictitious_messages,
                                );
                                
                                $response['order_number'] = $order->get_order_number();
                                $response['order_items'] = $this->get_order_items($order);
                                $response['tracking_results'] = array($tracking_result);
                                
                                return $this->rest_success_response($response);
                            } else {
                                return $this->rest_error_response('Não há informações de rastreamento disponíveis para este pedido.');
                            }
                    }
                }
            } else {
                return $this->rest_error_response('Pedido não encontrado.');
            }
        } 
        // Trata o caso de rastreamento direto (código de rastreio)
        else {
            $tracking_data = array($tracking_input => date('Y-m-d H:i:s'));
            foreach ($tracking_data as $code => $note_date) {
                $tracking_info = WCTE_17Track_API::get_tracking_info_by_code($code, $note_date);
                
                if ($tracking_info) {
                    $tracking_info['tracking_code'] = $code;
                    $results[] = $tracking_info;
                } else {
                    return $this->rest_error_response('Erro ao buscar informações para o código: ' . $code);
                }
            }
            
            if (!empty($results)) {
                $response['tracking_results'] = $results;
                return $this->rest_success_response($response);
            } else {
                return $this->rest_error_response('Nenhuma informação de rastreamento encontrada.');
            }
        }
    }

    /**
     * Retorna uma resposta de sucesso no mesmo formato do AJAX
     * 
     * @param array $data Dados da resposta
     * @return WP_REST_Response
     */
    private function rest_success_response($data) {
        $response = array(
            'success' => true,
            'data' => $data
        );
        return new WP_REST_Response($response, 200);
    }
    
    /**
     * Retorna uma resposta de erro no mesmo formato do AJAX
     * 
     * @param string $message Mensagem de erro
     * @return WP_REST_Response
     */
    private function rest_error_response($message) {
        $response = array(
            'success' => false,
            'data' => $message
        );
        return new WP_REST_Response($response, 200); // Retorna 200 mesmo em erro para manter compatibilidade com AJAX
    }

    /**
     * Obtém os itens de um pedido com imagens
     * 
     * @param WC_Order $order Objeto do pedido
     * @return array Itens do pedido formatados
     */
    private function get_order_items($order) {
        $items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $image_url = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
                $items[] = array(
                    'product_id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'image' => $image_url,
                );
            }
        }
        
        return $items;
    }
} 