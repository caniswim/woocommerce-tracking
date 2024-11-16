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
                'fake_updates' => array(),
                'has_real_tracking' => false
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
     * Clear fake updates when real tracking begins
     */
    public static function clear_fake_updates($tracking_code) {
        try {
            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return false;
            }

            $url .= '?key=' . $api_key;

            // Update tracking data to mark as having real tracking and clear fake updates
            $update_data = array(
                'fake_updates' => array(),
                'has_real_tracking' => true
            );

            $args = array(
                'method' => 'PATCH',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($update_data)
            );

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                error_log('WCTE Firebase - Error clearing fake updates: ' . $response->get_error_message());
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log('WCTE Firebase - Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get tracking status (whether it has real tracking data)
     */
    public static function get_tracking_status($tracking_code) {
        try {
            $url = self::$firebase_config['databaseURL'] . '/tracking/' . $tracking_code . '/has_real_tracking.json';
            $api_key = self::$firebase_config['apiKey'];

            if (empty($url) || empty($api_key)) {
                return false;
            }

            $url .= '?key=' . $api_key;

            $response = wp_remote_get($url);

            if (is_wp_error($response)) {
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $has_real_tracking = json_decode($body, true);

            return $has_real_tracking === true;
        } catch (Exception $e) {
            error_log('WCTE Firebase - Exception: ' . $e->getMessage());
            return false;
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
                'location' => 'Brasil'
            );
        }
        return $formatted_updates;
    }
}
