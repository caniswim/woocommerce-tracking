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
                wp_send_json_error('Por favor, informe um código de rastreamento.');
                return;
            }

            // Sanitiza a entrada e remove o '#' caso esteja presente
            $tracking_input = sanitize_text_field($_POST['tracking_input']);
            $tracking_input = trim($tracking_input, '#');
            error_log('WCTE - Input recebido: ' . $tracking_input);

            $tracking_codes = array();
            $order_id = null;

            if (is_numeric($tracking_input)) {
                error_log('WCTE - Processando número de pedido: ' . $tracking_input);
                $order_id = intval($tracking_input);
                $order = wc_get_order($order_id);

                if ($order) {
                    $tracking_data = $this->get_tracking_codes_from_order($order);
                    error_log('WCTE - Códigos encontrados no pedido: ' . print_r($tracking_data, true));

                    if (empty($tracking_data)) {
                        error_log('WCTE - Nenhum código de rastreamento encontrado nas notas do pedido');
                        wp_send_json_error('Seu pedido está em processamento.');
                        return;
                    }

                    // Inclui informações adicionais do pedido
                    $response['order_number'] = $order->get_order_number();
                    $response['order_items'] = $this->get_order_items($order);
                    $response['other_orders'] = $this->get_other_orders($order->get_billing_email(), $order_id);

                } else {
                    error_log('WCTE - Pedido não encontrado: ' . $order_id);
                    wp_send_json_error('Pedido não encontrado.');
                    return;
                }
            } else {
                error_log('WCTE - Processando código de rastreio direto: ' . $tracking_input);
                $tracking_data = array($tracking_input => date('Y-m-d H:i:s'));
            }

            $results = array();
            foreach ($tracking_data as $code => $note_date) {
                error_log('WCTE - Consultando código: ' . $code . ' com data da nota: ' . $note_date);
                $tracking_info = $this->api_handler->get_tracking_info($code, $note_date);

                if ($tracking_info) {
                    error_log('WCTE - Informações obtidas para ' . $code . ': ' . print_r($tracking_info, true));
                    $tracking_info['tracking_code'] = $code;
                    $results[] = $tracking_info;
                } else {
                    error_log('WCTE - Erro ao obter informações para ' . $code);
                    wp_send_json_error('Erro ao buscar informações para o código: ' . $code);
                    return;
                }
            }

            $is_delivered = true;
            $has_pending_codes = false;

            foreach ($results as $result) {
                if (isset($result['status'])) {
                    error_log('WCTE - Status do resultado: ' . $result['status']);
                    if ($result['status'] !== 'delivered') {
                        $is_delivered = false;
                    } elseif ($result['status'] === 'delivered') {
                        $has_pending_codes = true;
                    }
                }
            }

            // Adiciona resultados de rastreamento e informações do pedido à resposta
            $response['tracking_results'] = $results;

            if ($is_delivered && !$has_pending_codes && $order_id) {
                error_log('WCTE - Adicionando informações de itens do pedido');
                $response['allow_missing_item_form'] = true;
            }

            error_log('WCTE - Enviando resposta de sucesso');
            wp_send_json_success($response);

        } catch (Exception $e) {
            error_log('WCTE - Erro crítico no processamento: ' . $e->getMessage());
            wp_send_json_error('Erro ao processar a requisição: ' . $e->getMessage());
        }
    }

private function get_tracking_codes_from_order($order) {
    try {
        $tracking_data = array();

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

        error_log('WCTE - Códigos e datas encontrados nas notas: ' . print_r($tracking_data, true));
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
