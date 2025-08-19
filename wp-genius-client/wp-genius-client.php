<?php
/**
 * Plugin Name: WP Genius Client
 * Plugin URI: https://wp-genius.com
 * Description: Client plugin for WP Genius site management
 * Version: 1.0.0
 * Author: WP Genius
 * License: GPL v2 or later
 * Text Domain: wp-genius-client
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('WP_GENIUS_CLIENT_VERSION', '1.0.0');
define('WP_GENIUS_CLIENT_FILE', __FILE__);

class WP_Genius_Client {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        // Initialize client functionality
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function activate() {
        // Create connection key if it doesn't exist
        if (!get_option('wp_genius_connection_key')) {
            update_option('wp_genius_connection_key', wp_generate_password(32, false));
        }
        
        // Set default settings
        update_option('wp_genius_dashboard_url', '');
        update_option('wp_genius_connected', false);
    }
    
    public function add_admin_menu() {
        add_options_page(
            'WP Genius',
            'WP Genius',
            'manage_options',
            'wp-genius-client',
            array($this, 'settings_page')
        );
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $connection_key = get_option('wp_genius_connection_key');
        $dashboard_url = get_option('wp_genius_dashboard_url');
        $connected = get_option('wp_genius_connected', false);
        
        ?>
        <div class="wrap">
            <h1>WP Genius Client</h1>
            
            <?php if ($connected): ?>
                <div class="notice notice-success">
                    <p><strong>Connected!</strong> This site is being managed by WP Genius.</p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning">
                    <p><strong>Not Connected.</strong> This site is not yet connected to WP Genius dashboard.</p>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?php wp_nonce_field('wp_genius_client_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Connection Key</th>
                        <td>
                            <code><?php echo esc_html($connection_key); ?></code>
                            <p class="description">Use this key when adding this site to your WP Genius dashboard.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Dashboard URL</th>
                        <td>
                            <input type="url" name="dashboard_url" value="<?php echo esc_attr($dashboard_url); ?>" class="regular-text" />
                            <p class="description">URL of your WP Genius dashboard (optional).</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <h3>Site Information</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">WordPress Version</th>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <th scope="row">PHP Version</th>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <th scope="row">Active Plugins</th>
                    <td><?php echo count(get_option('active_plugins', array())); ?></td>
                </tr>
                <tr>
                    <th scope="row">Active Theme</th>
                    <td><?php echo wp_get_theme()->get('Name'); ?></td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wp_genius_client_settings')) {
            return;
        }
        
        update_option('wp_genius_dashboard_url', sanitize_url($_POST['dashboard_url']));
        
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
    
    public function register_rest_routes() {
        // Site info endpoint
        register_rest_route('wp-genius/v1', '/site-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_site_info'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Health check endpoint
        register_rest_route('wp-genius/v1', '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health_check'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Updates endpoint
        register_rest_route('wp-genius/v1', '/updates', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_available_updates'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Perform update endpoint
        register_rest_route('wp-genius/v1', '/update', array(
            'methods' => 'POST',
            'callback' => array($this, 'perform_update'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Backup endpoint
        register_rest_route('wp-genius/v1', '/backup', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_backup'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Connection test endpoint
        register_rest_route('wp-genius/v1', '/connect', array(
            'methods' => 'POST',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => '__return_true' // Allow for initial connection
        ));
    }
    
    public function check_permissions($request) {
        $auth_header = $request->get_header('authorization');
        
        if (!$auth_header) {
            return false;
        }
        
        $token = str_replace('Bearer ', '', $auth_header);
        $connection_key = get_option('wp_genius_connection_key');
        
        return $token === $connection_key;
    }
    
    public function get_site_info($request) {
        global $wp_version;
        
        return new WP_REST_Response(array(
            'wp_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'site_url' => home_url(),
            'admin_email' => get_option('admin_email'),
            'plugins_count' => count(get_option('active_plugins', array())),
            'themes_count' => count(wp_get_themes()),
            'active_theme' => wp_get_theme()->get('Name'),
            'multisite' => is_multisite(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ), 200);
    }
    
    public function health_check($request) {
        $health_data = array();
        
        // Basic WordPress health
        $health_data['wordpress'] = array(
            'version' => get_bloginfo('version'),
            'updates_available' => count(get_core_updates()) > 0
        );
        
        // Plugin health
        $plugin_updates = get_plugin_updates();
        $health_data['plugins'] = array(
            'total' => count(get_option('active_plugins', array())),
            'updates_available' => count($plugin_updates),
            'outdated_plugins' => array_keys($plugin_updates)
        );
        
        // Theme health
        $theme_updates = get_theme_updates();
        $health_data['themes'] = array(
            'total' => count(wp_get_themes()),
            'updates_available' => count($theme_updates),
            'active_theme' => wp_get_theme()->get('Name')
        );
        
        // Server health
        $health_data['server'] = array(
            'php_version' => PHP_VERSION,
            'memory_usage' => $this->get_memory_usage(),
            'disk_space' => $this->get_disk_space()
        );
        
        return new WP_REST_Response($health_data, 200);
    }
    
    public function get_available_updates($request) {
        $updates = array();
        
        // WordPress core updates
        $core_updates = get_core_updates();
        if (!empty($core_updates) && $core_updates[0]->response === 'upgrade') {
            $updates[] = array(
                'type' => 'core',
                'name' => 'WordPress',
                'current_version' => get_bloginfo('version'),
                'new_version' => $core_updates[0]->version,
                'package' => $core_updates[0]->download ?? ''
            );
        }
        
        // Plugin updates
        $plugin_updates = get_plugin_updates();
        foreach ($plugin_updates as $plugin_file => $plugin_data) {
            $updates[] = array(
                'type' => 'plugin',
                'name' => $plugin_data->Name,
                'slug' => dirname($plugin_file),
                'file' => $plugin_file,
                'current_version' => $plugin_data->Version,
                'new_version' => $plugin_data->update->new_version,
                'package' => $plugin_data->update->package ?? ''
            );
        }
        
        // Theme updates
        $theme_updates = get_theme_updates();
        foreach ($theme_updates as $theme_slug => $theme_data) {
            $updates[] = array(
                'type' => 'theme',
                'name' => $theme_data->get('Name'),
                'slug' => $theme_slug,
                'current_version' => $theme_data->get('Version'),
                'new_version' => $theme_data->update['new_version'],
                'package' => $theme_data->update['package'] ?? ''
            );
        }
        
        return new WP_REST_Response($updates, 200);
    }
    
    public function perform_update($request) {
        $update_data = $request->get_json_params();
        
        if (!isset($update_data['type']) || !isset($update_data['name'])) {
            return new WP_Error('invalid_data', 'Update type and name are required', array('status' => 400));
        }
        
        $result = array('success' => false);
        
        switch ($update_data['type']) {
            case 'core':
                $result = $this->update_wordpress_core();
                break;
                
            case 'plugin':
                $result = $this->update_plugin($update_data['file']);
                break;
                
            case 'theme':
                $result = $this->update_theme($update_data['slug']);
                break;
                
            default:
                return new WP_Error('invalid_type', 'Invalid update type', array('status' => 400));
        }
        
        return new WP_REST_Response($result, $result['success'] ? 200 : 500);
    }
    
    public function create_backup($request) {
        // Simple backup implementation
        $backup_data = array(
            'timestamp' => current_time('mysql'),
            'files' => array(),
            'database' => false
        );
        
        // Create uploads backup directory
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/wp-genius-backups';
        
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        $backup_filename = 'backup-' . date('Y-m-d-H-i-s') . '.zip';
        $backup_path = $backup_dir . '/' . $backup_filename;
        
        // Simple file backup (just wp-content for demo)
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($backup_path, ZipArchive::CREATE) === TRUE) {
                $this->add_directory_to_zip($zip, WP_CONTENT_DIR, 'wp-content');
                $zip->close();
                
                $backup_data['success'] = true;
                $backup_data['file_path'] = $backup_path;
                $backup_data['file_size'] = filesize($backup_path);
            } else {
                $backup_data['success'] = false;
                $backup_data['error'] = 'Could not create backup archive';
            }
        } else {
            $backup_data['success'] = false;
            $backup_data['error'] = 'ZipArchive class not available';
        }
        
        return new WP_REST_Response($backup_data, $backup_data['success'] ? 200 : 500);
    }
    
    public function test_connection($request) {
        $data = $request->get_json_params();
        $provided_key = $data['connection_key'] ?? '';
        $connection_key = get_option('wp_genius_connection_key');
        
        if ($provided_key === $connection_key) {
            update_option('wp_genius_connected', true);
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Connection successful',
                'site_info' => array(
                    'name' => get_bloginfo('name'),
                    'url' => home_url(),
                    'wp_version' => get_bloginfo('version'),
                    'php_version' => PHP_VERSION
                )
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid connection key'
            ), 401);
        }
    }
    
    private function update_wordpress_core() {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $updates = get_core_updates();
        if (empty($updates) || $updates[0]->response !== 'upgrade') {
            return array('success' => false, 'error' => 'No core updates available');
        }
        
        // This is simplified - real implementation would be more complex
        $result = wp_update_core($updates[0]);
        
        if (is_wp_error($result)) {
            return array('success' => false, 'error' => $result->get_error_message());
        }
        
        return array('success' => true, 'message' => 'WordPress core updated successfully');
    }
    
    private function update_plugin($plugin_file) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        
        $result = upgrade_plugin($plugin_file);
        
        if (is_wp_error($result)) {
            return array('success' => false, 'error' => $result->get_error_message());
        }
        
        return array('success' => true, 'message' => 'Plugin updated successfully');
    }
    
    private function update_theme($theme_slug) {
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        
        $result = upgrade_theme($theme_slug);
        
        if (is_wp_error($result)) {
            return array('success' => false, 'error' => $result->get_error_message());
        }
        
        return array('success' => true, 'message' => 'Theme updated successfully');
    }
    
    private function add_directory_to_zip($zip, $dir, $zip_path = '') {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file != "." && $file != "..") {
                    $full_path = $dir . '/' . $file;
                    $zip_file_path = $zip_path ? $zip_path . '/' . $file : $file;
                    
                    if (is_dir($full_path)) {
                        $zip->addEmptyDir($zip_file_path);
                        $this->add_directory_to_zip($zip, $full_path, $zip_file_path);
                    } else {
                        $zip->addFile($full_path, $zip_file_path);
                    }
                }
            }
        }
    }
    
    private function get_memory_usage() {
        return array(
            'current' => size_format(memory_get_usage(true)),
            'peak' => size_format(memory_get_peak_usage(true)),
            'limit' => ini_get('memory_limit')
        );
    }
    
    private function get_disk_space() {
        $bytes = disk_free_space(ABSPATH);
        return $bytes ? size_format($bytes) : 'Unknown';
    }
}

// Initialize the client
WP_Genius_Client::instance();
