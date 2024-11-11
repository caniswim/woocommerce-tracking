<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_Slack_Integration {

    private $webhook_url;

    public function __construct() {
        // Obtém a URL do webhook do Slack das configurações
        $this->webhook_url = get_option( 'wcte_slack_webhook_url' );

        // Adiciona ação para quando um item faltante for reportado
        add_action( 'wcte_missing_item_reported', array( $this, 'send_slack_notification' ), 10, 2 );
    }

    public function send_slack_notification( $order_id, $missing_items ) {
        if ( empty( $this->webhook_url ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_number = $order->get_order_number();
        $items_list = implode( ', ', $missing_items );

        $message = "*Pedido:* #{$order_number}\n";
        $message .= "*Cliente:* {$customer_name}\n";
        $message .= "*Itens faltantes:* {$items_list}\n";

        $payload = json_encode( array(
            'text' => $message
        ) );

        wp_remote_post( $this->webhook_url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $payload,
        ) );
    }
}

// Inicializa a classe de integração com Slack
new WCTE_Slack_Integration();
