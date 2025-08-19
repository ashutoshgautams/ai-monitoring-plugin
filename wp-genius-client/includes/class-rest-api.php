<?php
/**
 * WP Genius REST API
 * Handles API endpoints for the main dashboard plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_REST_API {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        // Get sites
        register_rest_route('wp-genius/v1', '/sites', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_sites'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Add site
        register_rest_route('wp-genius/v1', '/sites', array(
            'methods' => 'POST',
            'callback' => array($this, 'add_site'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Delete site
        register_rest_route('wp-genius/v1', '/sites/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_site'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Check site
        register_rest_route('wp-genius/v1', '/sites/(?P<id>\d+)/check', array(
            'methods' => 'POST',
            'callback' => array($this, 'check_site'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Connect to site
        register_rest_route('wp-genius/v1', '/sites/connect', array(
            'methods' => 'POST',
            'callback' => array($this, 'connect_site'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Generate report
        register_rest_route('wp-genius/v1', '/reports', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_report'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        // Test AI connection
        register_rest_route('wp-genius/v1', '/ai/test', array(
            'methods' => 'POST',
            'callback' => array($this, 'test_ai_connection'),
            'permission_callback' => array($this, 'check_permissions')
        ));
    }
    
    public function check_permissions($request) {
        return current_user_can('manage_options');
    }
    
    public function get_sites($request) {
        $sites = WP_Genius_Database::get_sites();
        
        return new WP_REST_Response(array(
            'success' => true,
            'sites' => $sites
        ), 200);
    }
    
    public function add_site($request) {
        $params = $request->get_json_params();
        
        $site_name = sanitize_text_field($params['site_name'] ?? '');
        $site_url = esc_url_raw($params['site_url'] ?? '');
        
        if (empty($site_name) || empty($site_url)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Site name and URL are required'
            ), 400);
        }
        
        $api_key = wp_generate_password(32, false);
        $site_id = WP_Genius_Database::add_site($site_url, $site_name, $api_key);
        
        if ($site_id) {
            return new WP_REST_Response(array(
                'success' => true,
                'site_id' => $site_id,
                'api_key' => $api_key,
                'message' => 'Site added successfully'
            ), 201);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to add site'
            ), 500);
        }
    }
    
    public function delete_site($request) {
        $site_id = (int) $request['id'];
        
        global $wpdb;
        $sites_table = $wpdb->prefix . 'genius_sites';
        
        $result = $wpdb->delete($sites_table, array('id' => $site_id), array('%d'));
        
        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Site deleted successfully'
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to delete site'
            ), 500);
        }
    }
    
    public function check_site($request) {
        $site_id = (int) $request['id'];
        
        $site_manager = new WP_Genius_Site_Manager();
        $result = $site_manager->check_site($site_id);
        
        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'status' => $result['status'],
                'results' => $result['results'],
                'message' => 'Site checked successfully'
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to check site'
            ), 500);
        }
    }
    
    public function connect_site($request) {
        $params = $request->get_json_params();
        
        $site_url = esc_url_raw($params['site_url'] ?? '');
        $connection_key = sanitize_text_field($params['connection_key'] ?? '');
        
        if (empty($site_url) || empty($connection_key)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Site URL and connection key are required'
            ), 400);
        }
        
        // Test connection to the site
        $connect_url = trailingslashit($site_url) . 'wp-json/wp-genius/v1/connect';
        
        $response = wp_remote_post($connect_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'connection_key' => $connection_key
            ))
        ));
        
        if (is_wp_error($response)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Could not connect to site: ' . $response->get_error_message()
            ), 500);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($response_code === 200 && $data['success']) {
            // Add site to database
            $site_name = $data['site_info']['name'] ?? parse_url($site_url, PHP_URL_HOST);
            $site_id = WP_Genius_Database::add_site($site_url, $site_name, $connection_key);
            
            if ($site_id) {
                // Update with site info
                WP_Genius_Database::update_site_status($site_id, 'active', array(
                    'wp_version' => $data['site_info']['wp_version'] ?? null,
                    'php_version' => $data['site_info']['php_version'] ?? null
                ));
                
                return new WP_REST_Response(array(
                    'success' => true,
                    'site_id' => $site_id,
                    'site_info' => $data['site_info'],
                    'message' => 'Site connected successfully'
                ), 200);
            } else {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Failed to save site to database'
                ), 500);
            }
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $data['message'] ?? 'Connection failed'
            ), 400);
        }
    }
    
    public function generate_report($request) {
        $params = $request->get_json_params();
        
        $site_id = (int) ($params['site_id'] ?? 0);
        $period_start = sanitize_text_field($params['period_start'] ?? '');
        $period_end = sanitize_text_field($params['period_end'] ?? '');
        $ai_enabled = (bool) ($params['ai_enabled'] ?? false);
        
        if (!$site_id || !$period_start || !$period_end) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Site ID, start date, and end date are required'
            ), 400);
        }
        
        $report_generator = new WP_Genius_Report_Generator();
        $result = $report_generator->generate_report($site_id, $period_start, $period_end, $ai_enabled);
        
        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'report_id' => $result['report_id'],
                'download_url' => $result['download_url'],
                'message' => 'Report generated successfully'
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to generate report'
            ), 500);
        }
    }
    
    public function test_ai_connection($request) {
        $ai_processor = new WP_Genius_AI_Processor();
        $result = $ai_processor->test_connection();
        
        return new WP_REST_Response($result, $result['success'] ? 200 : 500);
    }
}
