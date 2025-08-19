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
    }
    
    public function check_permissions($request) {
        return current_user_can('manage_options');
    }
    
    public function get_sites($request) {
        if (class_exists('WP_Genius_Database')) {
            $sites = WP_Genius_Database::get_sites();
            return new WP_REST_Response(array('success' => true, 'sites' => $sites), 200);
        }
        return new WP_REST_Response(array('success' => false, 'message' => 'Database class not found'), 500);
    }
    
    public function add_site($request) {
        $params = $request->get_json_params();
        $site_name = sanitize_text_field($params['site_name'] ?? '');
        $site_url = esc_url_raw($params['site_url'] ?? '');
        
        if (empty($site_name) || empty($site_url)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Site name and URL required'), 400);
        }
        
        if (class_exists('WP_Genius_Database')) {
            $api_key = wp_generate_password(32, false);
            $site_id = WP_Genius_Database::add_site($site_url, $site_name, $api_key);
            
            if ($site_id) {
                return new WP_REST_Response(array('success' => true, 'site_id' => $site_id), 201);
            }
        }
        
        return new WP_REST_Response(array('success' => false, 'message' => 'Failed to add site'), 500);
    }
    
    public function delete_site($request) {
        $site_id = (int) $request['id'];
        
        global $wpdb;
        $sites_table = $wpdb->prefix . 'genius_sites';
        $result = $wpdb->delete($sites_table, array('id' => $site_id), array('%d'));
        
        if ($result) {
            return new WP_REST_Response(array('success' => true, 'message' => 'Site deleted'), 200);
        }
        
        return new WP_REST_Response(array('success' => false, 'message' => 'Failed to delete site'), 500);
    }
    
    public function check_site($request) {
        $site_id = (int) $request['id'];
        
        if (class_exists('WP_Genius_Site_Manager')) {
            $site_manager = new WP_Genius_Site_Manager();
            $result = $site_manager->check_site($site_id);
            
            if ($result) {
                return new WP_REST_Response(array('success' => true, 'status' => $result['status']), 200);
            }
        }
        
        return new WP_REST_Response(array('success' => false, 'message' => 'Failed to check site'), 500);
    }
}
