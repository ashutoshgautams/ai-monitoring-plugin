<?php
/**
 * WP Genius Update Manager
 * Handles plugin, theme, and core updates for managed sites
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_Update_Manager {
    
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
     * Apply updates to a site
     */
    public function apply_updates($site_id, $updates) {
        $site = WP_Genius_Database::get_site($site_id);
        
        if (!$site) {
            return false;
        }
        
        $results = array();
        
        foreach ($updates as $update) {
            $result = $this->apply_single_update($site, $update);
            $results[] = $result;
            
            // Log update attempt
            $this->log_update_attempt($site_id, $update, $result);
        }
        
        return $results;
    }
    
    /**
     * Apply a single update
     */
    private function apply_single_update($site, $update) {
        $update_url = trailingslashit($site->url) . 'wp-json/wp-genius/v1/update';
        
        $response = wp_remote_post($update_url, array(
            'timeout' => 120,
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
     * Log update attempt
     */
    private function log_update_attempt($site_id, $update, $result) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_updates';
        
        $wpdb->insert(
            $table,
            array(
                'site_id' => $site_id,
                'component_type' => $update['type'],
                'component_name' => $update['name'],
                'version_from' => $update['current_version'] ?? '',
                'version_to' => $update['new_version'] ?? '',
                'status' => $result['success'] ? 'completed' : 'failed',
                'error_message' => $result['error'] ?? null
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get update history for a site
     */
    public function get_update_history($site_id, $limit = 20) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_updates';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table
            WHERE site_id = %d
            ORDER BY created_at DESC
            LIMIT %d
        ", $site_id, $limit));
    }
}
