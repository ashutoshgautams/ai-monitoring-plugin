<?php
/**
 * WP Genius Site Manager
 * Handles site monitoring, updates, and health checks
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_Site_Manager {
    
    /**
     * Check site status and health
     */
    public function check_site($site_id) {
        $site = WP_Genius_Database::get_site($site_id);
        
        if (!$site) {
            return false;
        }
        
        $results = array();
        
        // Basic connectivity check
        $uptime_result = $this->check_uptime($site);
        $results['uptime'] = $uptime_result;
        
        // WordPress API check
        $api_result = $this->check_wp_api($site);
        $results['api'] = $api_result;
        
        // Performance check
        $performance_result = $this->check_performance($site);
        $results['performance'] = $performance_result;
        
        // Determine overall status
        $overall_status = $this->determine_overall_status($results);
        
        // Update site status in database
        $update_data = array(
            'wp_version' => $api_result['wp_version'] ?? null,
            'php_version' => $api_result['php_version'] ?? null
        );
        
        WP_Genius_Database::update_site_status($site_id, $overall_status, $update_data);
        
        // Log monitoring data
        foreach ($results as $check_type => $result) {
            WP_Genius_Database::log_monitoring(
                $site_id,
                $check_type,
                $result['status'],
                $result['response_time'] ?? null,
                $result['response_code'] ?? null,
                $result['error'] ?? null
            );
        }
        
        return array(
            'status' => $overall_status,
            'results' => $results
        );
    }
    
    /**
     * Check site uptime
     */
    private function check_uptime($site) {
        $start_time = microtime(true);
        
        $response = wp_remote_get($site->url, array(
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'WP Genius/1.0'
        ));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000); // milliseconds
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'down',
                'error' => $response->get_error_message(),
                'response_time' => $response_time
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code >= 200 && $response_code < 400) {
            return array(
                'status' => 'up',
                'response_code' => $response_code,
                'response_time' => $response_time
            );
        } else {
            return array(
                'status' => 'warning',
                'response_code' => $response_code,
                'response_time' => $response_time,
                'error' => 'HTTP ' . $response_code
            );
        }
    }
    
    /**
     * Check WordPress REST API
     */
    private function check_wp_api($site) {
        $api_url = trailingslashit($site->url) . 'wp-json/wp/v2/';
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $site->api_key
            )
        ));
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'error' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            // Get site info
            $site_info = $this->get_site_info($site);
            
            return array(
                'status' => 'up',
                'wp_version' => $site_info['wp_version'] ?? null,
                'php_version' => $site_info['php_version'] ?? null,
                'plugins_count' => $site_info['plugins_count'] ?? 0,
                'themes_count' => $site_info['themes_count'] ?? 0
            );
        } else {
            return array(
                'status' => 'error',
                'error' => 'API not accessible: HTTP ' . $response_code
            );
        }
    }
    
    /**
     * Check site performance
     */
    private function check_performance($site) {
        $start_time = microtime(true);
        
        // Check multiple metrics
        $metrics = array();
        
        // Basic load time
        $response = wp_remote_get($site->url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        
        $end_time = microtime(true);
        $load_time = round(($end_time - $start_time) * 1000);
        
        if (!is_wp_error($response)) {
            $metrics['load_time'] = $load_time;
            $metrics['response_code'] = wp_remote_retrieve_response_code($response);
            
            // Check for basic performance indicators
            $body = wp_remote_retrieve_body($response);
            $metrics['page_size'] = strlen($body);
            
            // Check for compression
            $headers = wp_remote_retrieve_headers($response);
            $metrics['gzip_enabled'] = isset($headers['content-encoding']) && 
                                     strpos($headers['content-encoding'], 'gzip') !== false;
        }
        
        // Determine performance status
        $status = 'up';
        if ($load_time > 5000) {
            $status = 'warning'; // Over 5 seconds is slow
        } else if ($load_time > 10000) {
            $status = 'error'; // Over 10 seconds is very slow
        }
        
        return array(
            'status' => $status,
            'response_time' => $load_time,
            'metrics' => $metrics
        );
    }
    
    /**
     * Get detailed site information
     */
    private function get_site_info($site) {
        $info_url = trailingslashit($site->url) . 'wp-json/wp-genius/v1/site-info';
        
        $response = wp_remote_get($info_url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $site->api_key
            )
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return is_array($data) ? $data : array();
    }
    
    /**
     * Determine overall site status from individual checks
     */
    private function determine_overall_status($results) {
        $has_error = false;
        $has_warning = false;
        
        foreach ($results as $result) {
            if ($result['status'] === 'error' || $result['status'] === 'down') {
                $has_error = true;
            } else if ($result['status'] === 'warning') {
                $has_warning = true;
            }
        }
        
        if ($has_error) {
            return 'error';
        } else if ($has_warning) {
            return 'inactive';
        } else {
            return 'active';
        }
    }
    
    /**
     * Get available updates for a site
     */
    public function get_available_updates($site_id) {
        $site = WP_Genius_Database::get_site($site_id);
        
        if (!$site) {
            return false;
        }
        
        $updates_url = trailingslashit($site->url) . 'wp-json/wp-genius/v1/updates';
        
        $response = wp_remote_get($updates_url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $site->api_key
            )
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $updates = json_decode($body, true);
        
        return is_array($updates) ? $updates : array();
    }
    
    /**
     * Perform site updates
     */
    public function perform_updates($site_id, $updates_to_apply) {
        $site = WP_Genius_Database::get_site($site_id);
        
        if (!$site) {
            return false;
        }
        
        // Create backup before updates
        $backup_handler = new WP_Genius_Backup_Handler();
        $backup_result = $backup_handler->create_backup($site_id);
        
        if (!$backup_result) {
            return array(
                'success' => false,
                'error' => 'Failed to create backup before updates'
            );
        }
        
        $results = array();
        
        foreach ($updates_to_apply as $update) {
            $result = $this->apply_single_update($site, $update);
            $results[] = $result;
            
            // Log update in database
            global $wpdb;
            $updates_table = $wpdb->prefix . 'genius_updates';
            
            $wpdb->insert(
                $updates_table,
                array(
                    'site_id' => $site_id,
                    'component_type' => $update['type'],
                    'component_name' => $update['name'],
                    'version_from' => $update['current_version'],
                    'version_to' => $update['new_version'],
                    'status' => $result['success'] ? 'completed' : 'failed',
                    'error_message' => $result['error'] ?? null
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
        
        return $results;
    }
    
    /**
     * Apply a single update
     */
    private function apply_single_update($site, $update) {
        $update_url = trailingslashit($site->url) . 'wp-json/wp-genius/v1/update';
        
        $response = wp_remote_post($update_url, array(
            'timeout' => 120, // Updates can take longer
            'headers' => array(
                'Authorization' => 'Bearer ' . $site->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($update)
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        return is_array($result) ? $result : array('success' => false, 'error' => 'Invalid response');
    }
    
    /**
     * Schedule monitoring for all active sites
     */
    public static function schedule_monitoring() {
        if (!wp_next_scheduled('wp_genius_check_sites')) {
            $frequency = get_option('wp_genius_monitor_frequency', '5');
            wp_schedule_event(time(), 'wp_genius_' . $frequency . 'min', 'wp_genius_check_sites');
        }
    }
    
    /**
     * Unschedule monitoring
     */
    public static function unschedule_monitoring() {
        wp_clear_scheduled_hook('wp_genius_check_sites');
    }
    
    /**
     * Cron job to check all sites
     */
    public static function cron_check_all_sites() {
        $sites = WP_Genius_Database::get_sites('active');
        
        if (empty($sites)) {
            return;
        }
        
        $site_manager = new self();
        
        foreach ($sites as $site) {
            $site_manager->check_site($site->id);
            
            // Small delay to prevent server overload
            sleep(2);
        }
    }
}

// Hook into WordPress cron
add_action('wp_genius_check_sites', array('WP_Genius_Site_Manager', 'cron_check_all_sites'));
