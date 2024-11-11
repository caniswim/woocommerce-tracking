<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCTE_API_Handler {

    public static function get_tracking_info( $tracking_code ) {
        // Determina a transportadora
        if ( preg_match( '/^(LP|CN)/i', $tracking_code ) ) {
            $carrier = 'cainiao';
        } else {
            $carrier = 'correios';
        }

        if ( $carrier == 'correios' ) {
            return self::get_correios_tracking_info( $tracking_code );
        } else {
            return self::get_cainiao_tracking_info( $tracking_code );
        }
    }

    private static function get_correios_tracking_info( $tracking_code ) {
        // Obtenha o token antes de fazer a requisição de rastreamento
        $token = self::get_auth_token();
        if ( is_wp_error( $token ) || empty( $token ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Falha ao obter o token de autenticação: ' . ( is_wp_error( $token ) ? $token->get_error_message() : 'Token vazio' ),
            );
        }

        // Endpoint da API dos Correios para rastreamento
        $endpoint = 'https://api.correios.com.br/rastro/v1/objetos/' . urlencode( $tracking_code );

        // Configura os headers
        $args = array(
            'headers' => array(
                'Accept'        => 'application/json',
                'User-Agent'    => 'BlazeeWear/1.0',
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 20,
        );

        // Faz a requisição GET para a API dos Correios
        $response = wp_remote_get( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Erro ao conectar com a API dos Correios: ' . $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code != 200 || empty( $data ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Erro ao obter dados de rastreamento. Código de status: ' . $status_code . '. Resposta: ' . $body,
            );
        }

        if ( empty( $data['objetos'] ) || isset( $data['objetos'][0]['erros'] ) ) {
            return array(
                'status'  => 'error',
                'message' => 'Código de rastreamento não encontrado.',
            );
        }

        // Processa os eventos do rastreamento
        $events = $data['objetos'][0]['eventos'];
        $has_arrived_in_brazil = false;
        $filtered_events = array();

        foreach ( $events as $event ) {
            if ( isset( $event['unidade']['endereco']['codigoPais'] ) && $event['unidade']['endereco']['codigoPais'] == 'BR' ) {
                $has_arrived_in_brazil = true;
            }

            if ( $has_arrived_in_brazil ) {
                $filtered_events[] = array(
                    'date'        => date( 'd/m/Y H:i', strtotime( $event['dtHrCriado'] ) ),
                    'description' => $event['descricao'],
                );
            }
        }

        if ( ! $has_arrived_in_brazil ) {
            $creation_date = self::get_tracking_creation_date( $tracking_code );
            $fictitious_message = self::get_fictitious_message( $tracking_code, $creation_date );

            return array(
                'status'  => 'fictitious',
                'message' => $fictitious_message,
            );
        }

        $last_event = reset( $filtered_events );
        $status = 'in_transit';
        if ( stripos( $last_event['description'], 'entregue ao destinatário' ) !== false ) {
            $status = 'delivered';
        }

        return array(
            'status'  => $status,
            'message' => $last_event['description'],
            'data'    => $filtered_events,
        );
    }

    private static function get_auth_token() {
        $username = get_option( 'wcte_correios_username' );
        $password = get_option( 'wcte_correios_api_key' );
        $stored_token = get_option( 'wcte_correios_token' );
        $token_expiration = get_option( 'wcte_correios_token_expiration' );

        // Verifica se já existe um token válido
        if ( ! empty( $stored_token ) && ! empty( $token_expiration ) && strtotime( $token_expiration ) > time() ) {
            return $stored_token;
        }

        $endpoint = 'https://api.correios.com.br/token/v1/autentica';

        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
                'Accept'        => 'application/json',
                'User-Agent'    => 'BlazeeWear/1.0',
            ),
            'method'  => 'POST',
            'timeout' => 20,
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'auth_error', 'Erro ao conectar com a API de autenticação: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code != 201 || empty( $body['token'] ) || empty( $body['expiraEm'] ) ) {
            $error_message = isset( $body['mensagem'] ) ? $body['mensagem'] : 'Resposta inválida da API de autenticação.';
            return new WP_Error( 'auth_error', 'Erro ao obter o token de autenticação: ' . $error_message );
        }

        // Armazena o novo token e a data de expiração nas opções do WordPress
        update_option( 'wcte_correios_token', $body['token'] );
        update_option( 'wcte_correios_token_expiration', $body['expiraEm'] );

        return $body['token'];
    }

    private static function get_tracking_creation_date( $tracking_code ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcte_order_tracking';

        // Consulta a tabela personalizada para obter a data de criação
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM $table_name WHERE tracking_code = %s",
            $tracking_code
        ) );

        if ( $result ) {
            return $result;
        } else {
            // Se não encontrado, retorna a data atual menos 5 dias como padrão
            return date( 'Y-m-d H:i:s', strtotime( '-5 days' ) );
        }
    }

    private static function get_fictitious_message( $tracking_code, $creation_date ) {
        $messages = get_option( 'wcte_fictitious_messages', array() );
        $now      = current_time( 'timestamp' );
        $current_message = 'Aguardando atualização do rastreamento.';

        foreach ( $messages as $message_data ) {
            $scheduled_time = strtotime( $creation_date . ' +' . $message_data['days'] . ' days ' . $message_data['hour'] );
            if ( $now >= $scheduled_time ) {
                $current_message = $message_data['message'];
            }
        }

        return $current_message;
    }

    private static function get_cainiao_tracking_info( $tracking_code ) {
        $iframe_url = 'https://global.cainiao.com/newDetail.htm?mailNoList=' . urlencode( $tracking_code ) . '&otherMailNoList=';
        return array(
            'status'     => 'cainiao',
            'iframe_url' => $iframe_url,
        );
    }
}
