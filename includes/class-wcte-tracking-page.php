<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCTE_Tracking_Page {
    private $api_handler;

    public function __construct() {
        add_shortcode('wcte_tracking_page', array($this, 'render_tracking_page'));
        add_action('wp_ajax_wcte_track_order', array($this, 'handle_track_order'));
        add_action('wp_ajax_nopriv_wcte_track_order', array($this, 'handle_track_order'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        try {
            $this->api_handler = new WCTE_API_Handler();
            error_log('WCTE - API Handler inicializado com sucesso');
        } catch (Exception $e) {
            error_log('WCTE - Erro ao inicializar API Handler: ' . $e->getMessage());
        }
    }

    public function enqueue_scripts() {
        try {
            wp_enqueue_script(
                'wcte-script',
                WCTE_PLUGIN_URL . 'assets/js/script.js',
                array('jquery'),
                '1.0.0',
                true
            );

            wp_localize_script(
                'wcte-script',
                'wcte_ajax_object',
                array('ajax_url' => admin_url('admin-ajax.php'))
            );
            error_log('WCTE - Scripts carregados com sucesso');
        } catch (Exception $e) {
            error_log('WCTE - Erro ao carregar scripts: ' . $e->getMessage());
        }
    }

    public function render_tracking_page() {
        try {
            ob_start();
            include WCTE_PLUGIN_DIR . 'includes/templates/tracking-page-template.php';
            $content = ob_get_clean();
            error_log('WCTE - Página de rastreamento renderizada com sucesso');
            return $content;
        } catch (Exception $e) {
            error_log('WCTE - Erro ao renderizar página de rastreamento: ' . $e->getMessage());
            return '<p>Erro ao carregar o formulário de rastreamento.</p>';
        }
    }

    public function handle_track_order() {
        try {
            error_log('WCTE - Iniciando processamento de rastreamento');
    
            if (!$this->api_handler) {
                error_log('WCTE - API Handler não inicializado');
                wp_send_json_error('Erro interno: API Handler não inicializado');
                return;
            }
    
            if (!isset($_POST['tracking_input'])) {
                error_log('WCTE - Parâmetro de tracking ausente');
                wp_send_json_error('Por favor, informe um número de pedido, email ou código de rastreamento.');
                return;
            }
    
            // Sanitiza a entrada e remove o '#' caso esteja presente
            $tracking_input = sanitize_text_field($_POST['tracking_input']);
            $tracking_input = trim($tracking_input, '#');
            error_log('WCTE - Input recebido: ' . $tracking_input);
    
            $response = array();
            $results = array();
    
            // Verifica se é um email
            if (is_email($tracking_input)) {
                error_log('WCTE - Processando email: ' . $tracking_input);
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
                    wp_send_json_success($response);
                    return;
                } else {
                    error_log('WCTE - Nenhum pedido encontrado para o email: ' . $tracking_input);
                    wp_send_json_error('Nenhum pedido encontrado para este email.');
                    return;
                }
    
            // Verifica se é um número de pedido
            } elseif (is_numeric($tracking_input)) {
                error_log('WCTE - Processando número de pedido: ' . $tracking_input);
                $order_id = intval($tracking_input);
                $order = wc_get_order($order_id);
    
                if ($order) {
                    $order_status = $order->get_status();
                    $tracking_data = $this->get_tracking_codes_from_order($order);
                    error_log('WCTE - Códigos encontrados no pedido: ' . print_r($tracking_data, true));
    
                    if (!empty($tracking_data)) {
                        // Processa os códigos de rastreamento
                        foreach ($tracking_data as $code => $note_date) {
                            error_log('WCTE - Consultando código: ' . $code . ' com data da nota: ' . $note_date);
                            $tracking_info = WCTE_API_Handler::get_tracking_info_by_code($code, $note_date);
    
                            if ($tracking_info) {
                                error_log('WCTE - Informações obtidas para ' . $code . ': ' . print_r($tracking_info, true));
                                $tracking_info['tracking_code'] = $code;
                                $results[] = $tracking_info;
                            } else {
                                error_log('WCTE - Erro ao obter informações para ' . $code);
                            }
                        }
    
                        // Inclui informações adicionais do pedido
                        $response['order_number'] = $order->get_order_number();
                        $response['order_items'] = $this->get_order_items($order);
                        $response['tracking_results'] = $results;
    
                        wp_send_json_success($response);
                        return;
                    } else {
                        // Trata diferentes status do pedido
                        switch ($order_status) {
                            case 'pending':
                                wp_send_json_error('Seu pedido está pendente de pagamento.');
                                return;
    
                            case 'failed':
                                wp_send_json_error('O pagamento do seu pedido falhou. Por favor, tente novamente.');
                                return;
    
                            case 'cancelled':
                                wp_send_json_error('Este pedido foi cancelado.');
                                return;
    
                            case 'refunded':
                                wp_send_json_error('Este pedido foi reembolsado.');
                                return;
    
                            case 'on-hold':
                                wp_send_json_error('Seu pedido está aguardando confirmação.');
                                return;
    
                            case 'processing':
                            case 'completed':
                            default:
                                // Gera mensagens fictícias para pedidos processando ou concluídos sem rastreio
                                $order_date = $order->get_date_created()->date('Y-m-d H:i:s');
                                $fictitious_messages = WCTE_API_Handler::get_fictitious_messages_by_order_date($order_date);
    
                                if ($fictitious_messages) {
                                    $tracking_result = array(
                                        'status' => 'in_transit',
                                        'message' => end($fictitious_messages)['description'],
                                        'data' => $fictitious_messages,
                                    );
    
                                    $response['order_number'] = $order->get_order_number();
                                    $response['order_items'] = $this->get_order_items($order);
                                    $response['tracking_results'] = array($tracking_result);
    
                                    wp_send_json_success($response);
                                    return;
                                } else {
                                    wp_send_json_error('Não há informações de rastreamento disponíveis para este pedido.');
                                    return;
                                }
                        }
                    }
                } else {
                    wp_send_json_error('Pedido não encontrado.');
                    return;
                }
    
            // Trata o caso de rastreamento direto
            } else {
                $tracking_data = array($tracking_input => date('Y-m-d H:i:s'));
                foreach ($tracking_data as $code => $note_date) {
                    $tracking_info = WCTE_API_Handler::get_tracking_info_by_code($code, $note_date);
    
                    if ($tracking_info) {
                        $tracking_info['tracking_code'] = $code;
                        $results[] = $tracking_info;
                    } else {
                        wp_send_json_error('Erro ao buscar informações para o código: ' . $code);
                        return;
                    }
                }
    
                if (!empty($results)) {
                    $response['tracking_results'] = $results;
                    wp_send_json_success($response);
                    return;
                } else {
                    wp_send_json_error('Nenhuma informação de rastreamento encontrada.');
                    return;
                }
            }
        } catch (Exception $e) {
            error_log('WCTE - Erro crítico no processamento: ' . $e->getMessage());
            wp_send_json_error('Erro ao processar a requisição: ' . $e->getMessage());
        }
    }
    
    
    

    private function get_tracking_codes_from_order($order) {
        try {
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
                if (preg_match_all('/\b([A-Z]{2}[0-9]{9,14}[A-Z]{2})\b|\b(LP\d{12,})\b|\b(CNBR\d{8,})\b|\b(YT\d{16})\b/i', $note->content, $matches)) {
                    foreach ($matches[0] as $code) {
                        $tracking_data[$code] = $note->date_created->date('Y-m-d H:i:s');
                    }
                }
            }
    
            error_log('WCTE - Códigos e datas encontrados nas notas e meta: ' . print_r($tracking_data, true));
            return $tracking_data;
        } catch (Exception $e) {
            error_log('WCTE - Erro ao buscar códigos do pedido: ' . $e->getMessage());
            return array();
        }
    }
    


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

        error_log('WCTE - Items do pedido: ' . print_r($items, true));
        return $items;
    }

    private function get_other_orders($email, $current_order_id) {
        $other_orders = array();
        
        $orders = wc_get_orders(array(
            'billing_email' => $email,
            'exclude' => array($current_order_id),
            'limit' => 5,
        ));

        foreach ($orders as $order) {
            $other_orders[] = array(
                'order_number' => $order->get_order_number(),
                'date' => $order->get_date_created()->date('d/m/Y'),
            );
        }

        error_log('WCTE - Outros pedidos do cliente: ' . print_r($other_orders, true));
        return $other_orders;
    }
}
