<?php

if (!defined('ABSPATH')) {
    exit;
}

class WCTE_API_Handler {
    // URLs da API de Homologação
    private const CWS_TOKEN_URL = 'https://api.correios.com.br/token/v1/autentica/cartaopostagem';
    private const API_RASTRO_URL = 'https://api.correios.com.br/srorastro/v1/objetos/';

    /**
     * Log helper
     */
    private static function log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $log_message = 'WCTE API: ' . $message;
            if ($data !== null) {
                $log_message .= ' - Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }

    /**
     * Get WordPress timezone offset in seconds
     */
    private static function get_timezone_offset() {
        $timezone_string = get_option('timezone_string');
        $gmt_offset = get_option('gmt_offset');

        if ($timezone_string) {
            $timezone = new DateTimeZone($timezone_string);
            $datetime = new DateTime('now', $timezone);
            return $timezone->getOffset($datetime);
        } else if ($gmt_offset) {
            return $gmt_offset * HOUR_IN_SECONDS;
        }

        return 0;
    }

    /**
     * Get current time in WordPress timezone
     */
    private static function get_current_time() {
        return time() + self::get_timezone_offset();
    }

    /**
     * Convert time string to timestamp in WordPress timezone
     */
    private static function convert_to_timestamp($time_string) {
        $timezone_string = get_option('timezone_string');
        if ($timezone_string) {
            $datetime = new DateTime($time_string, new DateTimeZone($timezone_string));
            return $datetime->getTimestamp();
        }
        
        // Fallback to GMT offset
        $timestamp = strtotime($time_string);
        $gmt_offset = get_option('gmt_offset', 0);
        return $timestamp + ($gmt_offset * HOUR_IN_SECONDS);
    }

    /**
     * Initialize tracking data in Firebase
     */
    private static function initialize_tracking($tracking_code) {
        $tracking_data = array(
            'tracking_code' => $tracking_code,
            'carrier' => 'correios',
            'tracking_status' => 'pending',
            'created_at' => date('Y-m-d H:i:s', self::get_current_time()),
            'has_real_tracking' => false
        );

        // Save initial tracking data
        if (WCTE_Database::save_tracking_data($tracking_data)) {
            // Generate first fictional message immediately
            $fictitious_message = self::get_fictitious_message($tracking_code, true);
            if ($fictitious_message) {
                $update_data = array(
                    'message' => $fictitious_message,
                    'timestamp' => self::get_current_time()
                );
                WCTE_Database::save_fake_update($tracking_code, $update_data);
            }
            return true;
        }
        return false;
    }

    /**
     * Obtém informações de rastreamento
     */
    public static function get_tracking_info($tracking_code) {
        self::log('Iniciando consulta para código: ' . $tracking_code);

        // Check if tracking exists in Firebase, if not initialize it
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
        if (!$creation_date) {
            self::initialize_tracking($tracking_code);
        }

        if (preg_match('/^(LP|CN)/i', $tracking_code)) {
            return self::get_cainiao_tracking_info($tracking_code);
        } else {
            $tracking_info = self::get_correios_tracking_info($tracking_code);
            
            // Only use fake updates if we don't have real tracking data
            if ($tracking_info['status'] === 'error' || empty($tracking_info['data'])) {
                // Check for new fictional updates that should be added
                if (self::should_generate_fictional_update($tracking_code)) {
                    $fictitious_message = self::get_fictitious_message($tracking_code);
                    if ($fictitious_message) {
                        $update_data = array(
                            'message' => $fictitious_message,
                            'timestamp' => self::get_current_time()
                        );
                        WCTE_Database::save_fake_update($tracking_code, $update_data);
                    }
                }

                // Get all fake updates
                $fake_updates = WCTE_Database::get_fake_updates($tracking_code);
                if (!empty($fake_updates)) {
                    $formatted_updates = WCTE_Database::format_fake_updates($fake_updates);
                    return array(
                        'status' => 'in_transit',
                        'message' => end($formatted_updates)['description'],
                        'data' => $formatted_updates
                    );
                }
            } else {
                // If we have real tracking data, clear fictional updates
                self::maybe_clear_fictional_updates($tracking_code);
            }
            
            return $tracking_info;
        }
    }

    /**
     * Verifica se devemos gerar uma nova atualização fictícia
     */
    private static function should_generate_fictional_update($tracking_code) {
        $tracking_status = WCTE_Database::get_tracking_status($tracking_code);
        
        // Don't generate if we already have real tracking data
        if ($tracking_status) {
            return false;
        }

        $fake_updates = WCTE_Database::get_fake_updates($tracking_code);
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);
        
        if (!$creation_date) {
            return true;
        }

        // Get the next scheduled message
        $messages = get_option('wcte_fictitious_messages', array());
        $now = self::get_current_time();
        $creation_timestamp = self::convert_to_timestamp($creation_date);

        foreach ($messages as $message_data) {
            if (empty($message_data['message']) || !isset($message_data['days']) || empty($message_data['hour'])) {
                continue;
            }

            $scheduled_time = strtotime($creation_date . ' +' . intval($message_data['days']) . ' days ' . $message_data['hour']);
            
            // If this message should be shown now and we haven't shown it yet
            if ($now >= $scheduled_time) {
                $message_exists = false;
                foreach ($fake_updates as $update) {
                    if ($update['message'] === $message_data['message']) {
                        $message_exists = true;
                        break;
                    }
                }
                
                if (!$message_exists) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Obtém a mensagem fictícia com base no tempo decorrido
     */
    private static function get_fictitious_message($tracking_code, $first_message = false) {
        $messages = get_option('wcte_fictitious_messages', array());
        $creation_date = WCTE_Database::get_tracking_creation_date($tracking_code);

        if (!$creation_date) {
            return 'Seu pedido está em processamento.';
        }

        $now = self::get_current_time();
        $current_message = null;

        // If this is the first message, return the day 0 message
        if ($first_message) {
            foreach ($messages as $message_data) {
                if (isset($message_data['days']) && $message_data['days'] == 0 && !empty($message_data['message'])) {
                    return $message_data['message'];
                }
            }
        }

        // Get all messages that should be shown by now
        $valid_messages = array();
        foreach ($messages as $message_data) {
            if (empty($message_data['message']) || !isset($message_data['days']) || empty($message_data['hour'])) {
                continue;
            }

            $scheduled_time = strtotime($creation_date . ' +' . intval($message_data['days']) . ' days ' . $message_data['hour']);
            
            if ($now >= $scheduled_time) {
                // Check if this message has already been used
                $fake_updates = WCTE_Database::get_fake_updates($tracking_code);
                $message_exists = false;
                foreach ($fake_updates as $update) {
                    if ($update['message'] === $message_data['message']) {
                        $message_exists = true;
                        break;
                    }
                }
                
                if (!$message_exists) {
                    $valid_messages[] = array(
                        'message' => $message_data['message'],
                        'time' => $scheduled_time
                    );
                }
            }
        }

        // Sort by scheduled time and get the earliest message
        if (!empty($valid_messages)) {
            usort($valid_messages, function($a, $b) {
                return $a['time'] - $b['time'];
            });
            return $valid_messages[0]['message'];
        }

        return null;
    }

    // ... rest of the class methods remain unchanged ...
}
