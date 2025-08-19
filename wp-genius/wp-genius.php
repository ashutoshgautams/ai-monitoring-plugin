<?php
/**
 * Plugin Name: WP Genius
 * Plugin URI: https://wpgenius.com
 * Description: AI-powered WordPress site management with intelligent reporting
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-genius
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WP_GENIUS_VERSION', '1.0.0');
define('WP_GENIUS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_GENIUS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_GENIUS_PLUGIN_FILE', __FILE__);

/**
 * Plugin activation hook
 */
function wp_genius_activate() {
    // Load database class
    require_once WP_GENIUS_PLUGIN_DIR . 'includes/class-database.php';
    
    // Create database tables
    WP_Genius_Database::create_tables();
    
    // Create default settings
    wp_genius_create_default_settings();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Plugin deactivation hook
 */
function wp_genius_deactivate() {
    // Clear scheduled hooks
    wp_clear_scheduled_hook('wp_genius_check_sites');
    wp_clear_scheduled_hook('wp_genius_create_backups');
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Create default settings
 */
function wp_genius_create_default_settings() {
    $defaults = array(
        'backup_frequency' => 'daily',
        'monitor_frequency' => '5', // minutes
        'ai_enabled' => '1',
        'claude_api_key' => '',
        'report_branding' => array(
            'logo' => '',
            'primary_color' => '#2271b1',
            'company_name' => get_bloginfo('name')
        )
    );
    
    foreach ($defaults as $key => $value) {
        if (!get_option('wp_genius_' . $key)) {
            update_option('wp_genius_' . $key, $value);
        }
    }
}

/**
 * Main WP Genius class
 */
class WP_Genius {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('wp-genius', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize admin interface
        if (is_admin()) {
            $this->init_admin();
        }
        
        // Initialize REST API endpoints
        $this->init_rest_api();
        
        // Schedule cron jobs
        $this->init_cron_jobs();
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        $required_files = array(
            'includes/class-database.php',
            'includes/class-site-manager.php',
            'includes/class-backup-handler.php',
            'includes/class-update-manager.php',
            'includes/class-report-generator.php',
            'includes/class-ai-processor.php',
            'includes/class-rest-api.php'
        );
        
        foreach ($required_files as $file) {
            $file_path = WP_GENIUS_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                // Log missing file but don't break
                error_log("WP Genius: Missing file - $file");
            }
        }
        
        if (is_admin()) {
            $admin_file = WP_GENIUS_PLUGIN_DIR . 'admin/class-admin.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
            }
        }
    }
    
    /**
     * Initialize admin interface
     */
    private function init_admin() {
        new WP_Genius_Admin();
    }
    
    /**
     * Initialize REST API
     */
    private function init_rest_api() {
        new WP_Genius_REST_API();
    }
    
    /**
     * Initialize cron jobs
     */
    private function init_cron_jobs() {
        // Schedule monitoring if not already scheduled
        if (!wp_next_scheduled('wp_genius_check_sites')) {
            $frequency = get_option('wp_genius_monitor_frequency', '5');
            wp_schedule_event(time(), 'wp_genius_' . $frequency . 'min', 'wp_genius_check_sites');
        }
        
        // Schedule backups if not already scheduled
        if (!wp_next_scheduled('wp_genius_create_backups')) {
            $frequency = get_option('wp_genius_backup_frequency', 'daily');
            wp_schedule_event(time(), $frequency, 'wp_genius_create_backups');
        }
    }
}

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'wp_genius_activate');
register_deactivation_hook(__FILE__, 'wp_genius_deactivate');

// Initialize the plugin
function wp_genius() {
    return WP_Genius::instance();
}

// Start the plugin
add_action('plugins_loaded', 'wp_genius');
