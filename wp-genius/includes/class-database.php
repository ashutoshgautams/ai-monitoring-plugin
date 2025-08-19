<?php
/**
 * WP Genius Database Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_Database {
    
    /**
     * Create plugin database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Sites table
        $sites_table = $wpdb->prefix . 'genius_sites';
        $sites_sql = "CREATE TABLE $sites_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url varchar(255) NOT NULL,
            name varchar(255) NOT NULL,
            api_key varchar(64) NOT NULL,
            status enum('active','inactive','error') DEFAULT 'inactive',
            last_check datetime DEFAULT NULL,
            wp_version varchar(20) DEFAULT NULL,
            php_version varchar(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY api_key (api_key),
            KEY url (url),
            KEY status (status)
        ) $charset_collate;";
        
        // Backups table
        $backups_table = $wpdb->prefix . 'genius_backups';
        $backups_sql = "CREATE TABLE $backups_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            file_path varchar(500) NOT NULL,
            file_size bigint(20) unsigned DEFAULT 0,
            backup_type enum('full','incremental') DEFAULT 'incremental',
            status enum('pending','completed','failed') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_id (site_id),
            KEY status (status),
            KEY created_at (created_at),
            CONSTRAINT fk_backup_site FOREIGN KEY (site_id) REFERENCES $sites_table (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Updates table
        $updates_table = $wpdb->prefix . 'genius_updates';
        $updates_sql = "CREATE TABLE $updates_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            component_type enum('core','plugin','theme') NOT NULL,
            component_name varchar(255) NOT NULL,
            version_from varchar(50) DEFAULT NULL,
            version_to varchar(50) NOT NULL,
            status enum('pending','completed','failed','rolled_back') DEFAULT 'pending',
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_id (site_id),
            KEY status (status),
            KEY created_at (created_at),
            CONSTRAINT fk_update_site FOREIGN KEY (site_id) REFERENCES $sites_table (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Reports table
        $reports_table = $wpdb->prefix . 'genius_reports';
        $reports_sql = "CREATE TABLE $reports_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            period_start date NOT NULL,
            period_end date NOT NULL,
            technical_data longtext NOT NULL,
            ai_summary text DEFAULT NULL,
            pdf_path varchar(500) DEFAULT NULL,
            status enum('pending','generated','sent') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_id (site_id),
            KEY period_start (period_start),
            KEY status (status),
            CONSTRAINT fk_report_site FOREIGN KEY (site_id) REFERENCES $sites_table (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Monitoring table
        $monitoring_table = $wpdb->prefix . 'genius_monitoring';
        $monitoring_sql = "CREATE TABLE $monitoring_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            check_type enum('uptime','performance','security') NOT NULL,
            status enum('up','down','warning','error') NOT NULL,
            response_time int unsigned DEFAULT NULL,
            response_code int unsigned DEFAULT NULL,
            error_message text DEFAULT NULL,
            checked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_id (site_id),
            KEY check_type (check_type),
            KEY status (status),
            KEY checked_at (checked_at),
            CONSTRAINT fk_monitoring_site FOREIGN KEY (site_id) REFERENCES $sites_table (id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Settings table for user-specific configurations
        $settings_table = $wpdb->prefix . 'genius_settings';
        $settings_sql = "CREATE TABLE $settings_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            setting_key varchar(100) NOT NULL,
            setting_value longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_setting (user_id, setting_key),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sites_sql);
        dbDelta($backups_sql);
        dbDelta($updates_sql);
        dbDelta($reports_sql);
        dbDelta($monitoring_sql);
        dbDelta($settings_sql);
        
        // Update database version
        update_option('wp_genius_db_version', WP_GENIUS_VERSION);
    }
    
    /**
     * Check if database needs upgrade
     */
    public static function needs_upgrade() {
        $current_version = get_option('wp_genius_db_version', '0.0.0');
        return version_compare($current_version, WP_GENIUS_VERSION, '<');
    }
    
    /**
     * Get site data
     */
    public static function get_site($site_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'genius_sites';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $site_id));
    }
    
    /**
     * Get all sites
     */
    public static function get_sites($status = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'genius_sites';
        
        if ($status) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status = %s ORDER BY name ASC", $status));
        }
        
        return $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");
    }
    
    /**
     * Add new site
     */
    public static function add_site($url, $name, $api_key) {
        global $wpdb;
        $table = $wpdb->prefix . 'genius_sites';
        
        $result = $wpdb->insert(
            $table,
            array(
                'url' => esc_url_raw($url),
                'name' => sanitize_text_field($name),
                'api_key' => sanitize_text_field($api_key),
                'status' => 'inactive'
            ),
            array('%s', '%s', '%s', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Update site status
     */
    public static function update_site_status($site_id, $status, $additional_data = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'genius_sites';
        
        $update_data = array(
            'status' => $status,
            'last_check' => current_time('mysql')
        );
        
        // Merge any additional data
        $update_data = array_merge($update_data, $additional_data);
        
        $formats = array_fill(0, count($update_data), '%s');
        
        return $wpdb->update(
            $table,
            $update_data,
            array('id' => $site_id),
            $formats,
            array('%d')
        );
    }
    
    /**
     * Log monitoring check
     */
    public static function log_monitoring($site_id, $check_type, $status, $response_time = null, $response_code = null, $error_message = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'genius_monitoring';
        
        return $wpdb->insert(
            $table,
            array(
                'site_id' => $site_id,
                'check_type' => $check_type,
                'status' => $status,
                'response_time' => $response_time,
                'response_code' => $response_code,
                'error_message' => $error_message
            ),
            array('%d', '%s', '%s', '%d', '%d', '%s')
        );
    }
    
    /**
     * Get recent monitoring data
     */
    public static function get_monitoring_data($site_id, $check_type = null, $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . 'genius_monitoring';
        
        $sql = "SELECT * FROM $table WHERE site_id = %d";
        $params = array($site_id);
        
        if ($check_type) {
            $sql .= " AND check_type = %s";
            $params[] = $check_type;
        }
        
        $sql .= " ORDER BY checked_at DESC LIMIT %d";
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
}
