<?php
/**
 * WP Genius Backup Handler
 * Simple backup system for managed sites
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_Backup_Handler {
    
    /**
     * Create backup for a site
     */
    public function create_backup($site_id, $backup_type = 'incremental') {
        $site = WP_Genius_Database::get_site($site_id);
        
        if (!$site) {
            return false;
        }
        
        // Call backup endpoint on the client site
        $backup_url = trailingslashit($site->url) . 'wp-json/wp-genius/v1/backup';
        
        $response = wp_remote_post($backup_url, array(
            'timeout' => 300, // 5 minutes for backup
            'headers' => array(
                'Authorization' => 'Bearer ' . $site->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'type' => $backup_type
            ))
        ));
        
        if (is_wp_error($response)) {
            $this->log_backup_error($site_id, $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($response_code === 200 && $data['success']) {
            // Log successful backup
            $backup_id = $this->log_backup_success($site_id, $data, $backup_type);
            return $backup_id;
        } else {
            $error_message = $data['error'] ?? 'Unknown backup error';
            $this->log_backup_error($site_id, $error_message);
            return false;
        }
    }
    
    /**
     * Log successful backup
     */
    private function log_backup_success($site_id, $backup_data, $backup_type) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_backups';
        
        $result = $wpdb->insert(
            $table,
            array(
                'site_id' => $site_id,
                'file_path' => $backup_data['file_path'] ?? '',
                'file_size' => $backup_data['file_size'] ?? 0,
                'backup_type' => $backup_type,
                'status' => 'completed'
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Log backup error
     */
    private function log_backup_error($site_id, $error_message) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_backups';
        
        $wpdb->insert(
            $table,
            array(
                'site_id' => $site_id,
                'file_path' => '',
                'file_size' => 0,
                'backup_type' => 'failed',
                'status' => 'failed'
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
        
        error_log("WP Genius: Backup failed for site $site_id - $error_message");
    }
}
