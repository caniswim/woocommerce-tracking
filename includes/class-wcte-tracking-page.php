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
				'1.0.0', // Versão fixa em vez de usar constante
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

            // Verifica se o API Handler foi inicializado
            if (!$this->api_handler) {
                error_log('WCTE - API Handler não inicializado');
                wp_send_json_error('Erro interno: API Handler não inicializado');
                return;
            }

            // Verifica se o parâmetro POST foi enviado
            if (!isset($_POST['tracking_input'])) {
                error_log('WCTE - Parâmetro de tracking ausente');
                wp_send_json_error('Por favor, informe um código de rastreamento.');
                return;
            }

            // Sanitiza a entrada do usuário
            $tracking_input = sanitize_text_field($_POST['tracking_input']);
            error_log('WCTE - Input recebido: ' . $tracking_input);

            // Determina se é um código de rastreio ou número de pedido
            $tracking_codes = array();
            $order_id = null;

            if (is_numeric($tracking_input)) {
                error_log('WCTE - Processando número de pedido: ' . $tracking_input);
                $order_id = intval($tracking_input);
                $order = wc_get_order($order_id);

                if ($order) {
                    $tracking_codes = $this->get_tracking_codes_from_order($order);
                    error_log('WCTE - Códigos encontrados no pedido: ' . print_r($tracking_codes, true));
                } else {
                    error_log('WCTE - Pedido não encontrado: ' . $order_id);
                    wp_send_json_error('Pedido não encontrado.');
                    return;
                }
            } else {
                error_log('WCTE - Processando código de rastreio direto: ' . $tracking_input);
                $tracking_codes = array($tracking_input);
            }

            if (empty($tracking_codes)) {
                error_log('WCTE - Nenhum código de rastreamento encontrado');
                wp_send_json_error('Nenhum código de rastreamento encontrado.');
                return;
            }

            // Processa cada código de rastreio
            $results = array();
            foreach ($tracking_codes as $code) {
                error_log('WCTE - Consultando código: ' . $code);
                $tracking_info = $this->api_handler->get_tracking_info($code);
                
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

            // Verifica o status de entrega e se há códigos pendentes
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

            // Prepara a resposta
            $response = array(
                'tracking_results' => $results,
            );

            if ($is_delivered && !$has_pending_codes && $order_id) {
                error_log('WCTE - Adicionando informações de itens do pedido');
                $response['allow_missing_item_form'] = true;
                $response['order_items'] = $this->get_order_items($order_id);
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
            $tracking_codes = array();

            $order_notes = wc_get_order_notes(array(
                'order_id' => $order->get_id(),
                'type' => 'customer',
            ));

            foreach ($order_notes as $note) {
                if (preg_match('/Tracking number:\s*(\S+)/i', $note->content, $matches)) {
                    $tracking_codes[] = $matches[1];
                }
            }

            error_log('WCTE - Códigos encontrados nas notas: ' . print_r($tracking_codes, true));
            return $tracking_codes;

        } catch (Exception $e) {
            error_log('WCTE - Erro ao buscar códigos do pedido: ' . $e->getMessage());
            return array();
        }
    }

    private function get_order_items($order_id) {
        try {
            $order = wc_get_order($order_id);
            $items = array();

            if ($order) {
                foreach ($order->get_items() as $item) {
                    $items[] = array(
                        'product_id' => $item->get_product_id(),
                        'name' => $item->get_name(),
                    );
                }
            }

            error_log('WCTE - Items do pedido: ' . print_r($items, true));
            return $items;

        } catch (Exception $e) {
            error_log('WCTE - Erro ao buscar items do pedido: ' . $e->getMessage());
            return array();
        }
    }
}