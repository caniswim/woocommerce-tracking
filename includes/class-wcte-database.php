<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCTE_Database {
    private static $firebase_config = null;

    public static function init() {
        // Initialize Firebase configuration
        self::$firebase_config = array(
            'apiKey' => get_option('wcte_firebase_api_key'),
            'databaseURL' => 'https://rastreios-blazee-default-rtdb.firebaseio.com',
            'projectId' => get_option('wcte_firebase_project_id')
        );

        self::log('Firebase config initialized', self::$firebase_config);
    }

    /**
     * Obtém a configuração do Firebase
     * 
     * @return array|null A configuração do Firebase ou null se não inicializada
     */
    public static function get_firebase_config() {
        if (!self::$firebase_config) {
            self::init();
        }
        return self::$firebase_config;
    }

    private static function log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $log_message = 'WCTE Database: ' . $message;
            if ($data !== null) {
                $log_message .= ' - Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }

    public static function install() {
        self::init();
    }

    public static function uninstall() {
        delete_option('wcte_firebase_api_key');
        delete_option('wcte_firebase_database_url');
        delete_option('wcte_firebase_project_id');
    }

    /**
     * Save tracking data to Firebase
     */
    public static function save_tracking_data($data) {
        try {
            if (!self::$firebase_config) {
                self::init();
            }

            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $data['tracking_code'] . '.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                self::log('Missing configuration');
                return false;
            }

            $url .= '?key=' . $api_key;

            self::log('Saving tracking data to URL', $url);

            $tracking_data = array(
                'woocommerce_order_id' => $data['woocommerce_order_id'] ?? null,
                'yampi_order_number' => $data['yampi_order_number'] ?? null,
                'email' => $data['email'] ?? null,
                'tracking_code' => $data['tracking_code'],
                'carrier' => $data['carrier'] ?? 'correios',
                'tracking_status' => $data['tracking_status'] ?? 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'fake_updates' => array(),
                'has_real_tracking' => false
            );

            $args = array(
                'method' => 'PUT',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($tracking_data)
            );

            self::log('Request args', $args);

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                self::log('Error saving tracking data: ' . $response->get_error_message());
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            self::log('Firebase response', array(
                'code' => $response_code,
                'body' => $response_body
            ));

            return $response_code === 200;
        } catch (Exception $e) {
            self::log('Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get tracking creation date from Firebase
     */
    public static function get_tracking_creation_date($tracking_code) {
        try {
            if (!self::$firebase_config) {
                self::init();
            }

            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/created_at.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return null;
            }

            $url .= '?key=' . $api_key;

            self::log('Getting creation date from URL', $url);

            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                self::log('Error getting creation date: ' . $response->get_error_message());
                return null;
            }

            $body = wp_remote_retrieve_body($response);
            $created_at = json_decode($body, true);

            self::log('Creation date response', $created_at);

            return $created_at;
        } catch (Exception $e) {
            self::log('Exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Save fake tracking update to Firebase
     */
    public static function save_fake_update($tracking_code, $update_data) {
        try {
            if (!self::$firebase_config) {
                self::init();
            }

            // First check if real tracking exists
            $has_real_tracking = self::get_tracking_status($tracking_code);
            if ($has_real_tracking === true) {
                return false;
            }

            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/fake_updates.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return false;
            }

            $url .= '?key=' . $api_key;

            self::log('Saving fake update to URL', $url);

            // First get existing updates
            $response = wp_remote_get($url);
            $existing_updates = array();

            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $existing_updates = json_decode($body, true) ?: array();
            }

            // Check if this message already exists
            foreach ($existing_updates as $update) {
                if ($update['message'] === $update_data['message']) {
                    return true; // Message already exists
                }
            }

            // Add new update
            $update_data['timestamp'] = time();
            $existing_updates[] = $update_data;

            // Sort updates by timestamp
            usort($existing_updates, function($a, $b) {
                return $a['timestamp'] - $b['timestamp'];
            });

            // Save all updates
            $args = array(
                'method' => 'PUT',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($existing_updates)
            );

            self::log('Save fake update request', $args);

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                self::log('Error saving fake update: ' . $response->get_error_message());
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            self::log('Firebase response', array(
                'code' => $response_code,
                'body' => $response_body
            ));

            return $response_code === 200;
        } catch (Exception $e) {
            self::log('Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get fake tracking updates from Firebase
     */
    public static function get_fake_updates($tracking_code) {
        try {
            if (!self::$firebase_config) {
                self::init();
            }

            // First check if real tracking exists
            $has_real_tracking = self::get_tracking_status($tracking_code);
            if ($has_real_tracking === true) {
                return array();
            }

            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/fake_updates.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return array();
            }

            $url .= '?key=' . $api_key;

            self::log('Getting fake updates from URL', $url);

            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                self::log('Error getting fake updates: ' . $response->get_error_message());
                return array();
            }

            $body = wp_remote_retrieve_body($response);
            $updates = json_decode($body, true);

            self::log('Fake updates response', $updates);

            return is_array($updates) ? $updates : array();
        } catch (Exception $e) {
            self::log('Exception: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Mark tracking as having real updates without clearing fake ones
     */
    public static function mark_as_real_tracking($tracking_code) {
        try {
            if (!self::$firebase_config) {
                self::init();
            }

            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return false;
            }

            $url .= '?key=' . $api_key;

            self::log('Marcando rastreio como real em URL', $url);

            // Apenas atualiza o status de rastreio real, mantendo as mensagens fictícias
            $update_data = array(
                'has_real_tracking' => true
            );

            $args = array(
                'method' => 'PATCH',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($update_data)
            );

            self::log('Mark as real tracking request', $args);

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                self::log('Erro ao marcar rastreio como real: ' . $response->get_error_message());
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            self::log('Firebase response', array(
                'code' => $response_code,
                'body' => $response_body
            ));

            return $response_code === 200;
        } catch (Exception $e) {
            self::log('Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get tracking status (whether it has real tracking data)
     */
    public static function get_tracking_status($tracking_code) {
        try {
            if (!self::$firebase_config) {
                self::init();
            }

            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/has_real_tracking.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return false;
            }

            $url .= '?key=' . $api_key;

            self::log('Getting tracking status from URL', $url);

            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                self::log('Error getting tracking status: ' . $response->get_error_message());
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $has_real_tracking = json_decode($body, true);

            self::log('Tracking status response', $has_real_tracking);

            return $has_real_tracking === true;
        } catch (Exception $e) {
            self::log('Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Format fake updates to match Correios format
     */
    public static function format_fake_updates($fake_updates) {
        $formatted_updates = array();
        foreach ($fake_updates as $update) {
            // Converte a data para o formato dos Correios (dd/mm/yyyy HH:ii)
            if (isset($update['datetime'])) {
                $date = DateTime::createFromFormat('Y-m-d H:i:s', $update['datetime']);
            } elseif (isset($update['date'])) {
                $date = DateTime::createFromFormat('Y-m-d H:i:s', $update['date']);
            } else {
                $date = new DateTime();
                $date->setTimestamp($update['timestamp']);
            }
            
            if ($date) {
                $formatted_date = $date->format('d/m/Y H:i');
            } else {
                $formatted_date = date('d/m/Y H:i');
            }

            $formatted_updates[] = array(
                'date' => $formatted_date,
                'description' => $update['message'],
                'location' => 'Brasil'
            );
        }
        return $formatted_updates;
    }
}
