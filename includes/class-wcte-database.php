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
            'databaseURL' => get_option('wcte_firebase_database_url'),
            'projectId' => get_option('wcte_firebase_project_id')
        );

        // Add Firebase settings to WordPress options if they don't exist
        if (!get_option('wcte_firebase_api_key')) {
            add_option('wcte_firebase_api_key', '');
            add_option('wcte_firebase_database_url', '');
            add_option('wcte_firebase_project_id', '');
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
            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $data['tracking_code'] . '.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                error_log('WCTE Firebase - Missing configuration');
                return false;
            }

            $url .= '?key=' . $api_key;

            $tracking_data = array(
                'woocommerce_order_id' => $data['woocommerce_order_id'],
                'yampi_order_number' => $data['yampi_order_number'],
                'email' => $data['email'],
                'tracking_code' => $data['tracking_code'],
                'carrier' => $data['carrier'],
                'tracking_status' => $data['tracking_status'] ?? 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'fake_updates' => array()
            );

            $args = array(
                'method' => 'PUT',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($tracking_data)
            );

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                error_log('WCTE Firebase - Error saving tracking data: ' . $response->get_error_message());
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log('WCTE Firebase - Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get tracking creation date from Firebase
     */
    public static function get_tracking_creation_date($tracking_code) {
        try {
            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/created_at.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return null;
            }

            $url .= '?key=' . $api_key;

            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                return null;
            }

            $body = wp_remote_retrieve_body($response);
            $created_at = json_decode($body, true);

            return $created_at;
        } catch (Exception $e) {
            error_log('WCTE Firebase - Exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Save fake tracking update to Firebase
     */
    public static function save_fake_update($tracking_code, $update_data) {
        try {
            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/fake_updates.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return false;
            }

            $url .= '?key=' . $api_key;

            // First get existing updates
            $response = wp_remote_get($url);
            $existing_updates = array();

            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $existing_updates = json_decode($body, true) ?: array();
            }

            // Add new update
            $update_data['timestamp'] = time();
            $existing_updates[] = $update_data;

            // Save all updates
            $args = array(
                'method' => 'PUT',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($existing_updates)
            );

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                error_log('WCTE Firebase - Error saving fake update: ' . $response->get_error_message());
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log('WCTE Firebase - Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get fake tracking updates from Firebase
     */
    public static function get_fake_updates($tracking_code) {
        try {
            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/fake_updates.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return array();
            }

            $url .= '?key=' . $api_key;

            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                return array();
            }

            $body = wp_remote_retrieve_body($response);
            $updates = json_decode($body, true);

            return is_array($updates) ? $updates : array();
        } catch (Exception $e) {
            error_log('WCTE Firebase - Exception: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Format fake updates to match Correios format
     */
    public static function format_fake_updates($fake_updates) {
        $formatted_updates = array();
        foreach ($fake_updates as $update) {
            $formatted_updates[] = array(
                'date' => date('d/m/Y H:i', $update['timestamp']),
                'description' => $update['message'],
                'location' => 'Sistema',
                'is_fake' => true
            );
        }
        return $formatted_updates;
    }
}
