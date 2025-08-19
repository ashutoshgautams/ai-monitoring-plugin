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
     * Schedule regular backups
     */
    public static function schedule_backups() {
        if (!wp_next_scheduled('wp_genius_create_backups')) {
            $frequency = get_option('wp_genius_backup_frequency', 'daily');
            wp_schedule_event(time(), $frequency, 'wp_genius_create_backups');
        }
    }
    
    /**
     * Unschedule backups
     */
    public static function unschedule_backups() {
        wp_clear_scheduled_hook('wp_genius_create_backups');
    }
    
    /**
     * Cron job to create backups for all sites
     */
    public static function cron_create_backups() {
        $sites = WP_Genius_Database::get_sites('active');
        
        if (empty($sites)) {
            return;
        }
        
        $backup_handler = new self();
        
        foreach ($sites as $site) {
            $backup_handler->create_backup($site->id);
            
            // Small delay to prevent server overload
            sleep(10);
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
        
        // Log error for debugging
        error_log("WP Genius: Backup failed for site $site_id - $error_message");
    }
    
    /**
     * Get backup history for a site
     */
    public function get_backup_history($site_id, $limit = 10) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_backups';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table
            WHERE site_id = %d
            ORDER BY created_at DESC
            LIMIT %d
        ", $site_id, $limit));
    }
    
    /**
     * Delete old backups
     */
    public function cleanup_old_backups($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_backups';
        
        // Get old backups
        $old_backups = $wpdb->get_results($wpdb->prepare("
            SELECT id, file_path FROM $table
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
            AND status = 'completed'
        ", $days));
        
        $deleted_count = 0;
        
        foreach ($old_backups as $backup) {
            // Note: In a real implementation, you'd delete the actual backup files
            // from cloud storage here
            
            // Delete database record
            $wpdb->delete($table, array('id' => $backup->id), array('%d'));
            $deleted_count++;
        }
        
        return $deleted_count;
    }
    
    /**
     * Get backup statistics
     */
    public function get_backup_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_backups';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_backups,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_backups,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_backups,
                SUM(CASE WHEN status = 'completed' THEN file_size ELSE 0 END) as total_size
            FROM $table
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        return array(
            'total_backups' => (int) $stats->total_backups,
            'successful_backups' => (int) $stats->successful_backups,
            'failed_backups' => (int) $stats->failed_backups,
            'total_size' => (int) $stats->total_size,
            'success_rate' => $stats->total_backups > 0 ? round(($stats->successful_backups / $stats->total_backups) * 100, 1) : 0
        );
    }
}

// Hook into WordPress cron
add_action('wp_genius_create_backups', array('WP_Genius_Backup_Handler', 'cron_create_backups'));
