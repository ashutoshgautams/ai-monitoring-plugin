<?php
/**
 * WP Genius Admin Interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wp_genius_add_site', array($this, 'ajax_add_site'));
        add_action('wp_ajax_wp_genius_check_site', array($this, 'ajax_check_site'));
        add_action('wp_ajax_wp_genius_delete_site', array($this, 'ajax_delete_site'));
        add_action('wp_ajax_wp_genius_check_all_sites', array($this, 'ajax_check_all_sites'));
        add_action('wp_ajax_wp_genius_generate_report', array($this, 'ajax_generate_report'));
        add_action('wp_ajax_wp_genius_get_sites', array($this, 'ajax_get_sites'));
        add_action('wp_ajax_wp_genius_connect_site', array($this, 'ajax_connect_site'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        $capability = 'manage_options';
        
        // Main menu
        add_menu_page(
            __('WP Genius', 'wp-genius'),
            __('WP Genius', 'wp-genius'),
            $capability,
            'wp-genius',
            array($this, 'dashboard_page'),
            'dashicons-admin-site-alt3',
            30
        );
        
        // Dashboard submenu
        add_submenu_page(
            'wp-genius',
            __('Dashboard', 'wp-genius'),
            __('Dashboard', 'wp-genius'),
            $capability,
            'wp-genius',
            array($this, 'dashboard_page')
        );
        
        // Sites submenu
        add_submenu_page(
            'wp-genius',
            __('Sites', 'wp-genius'),
            __('Sites', 'wp-genius'),
            $capability,
            'wp-genius-sites',
            array($this, 'sites_page')
        );
        
        // Reports submenu
        add_submenu_page(
            'wp-genius',
            __('Reports', 'wp-genius'),
            __('Reports', 'wp-genius'),
            $capability,
            'wp-genius-reports',
            array($this, 'reports_page')
        );
        
        // Settings submenu
        add_submenu_page(
            'wp-genius',
            __('Settings', 'wp-genius'),
            __('Settings', 'wp-genius'),
            $capability,
            'wp-genius-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'wp-genius') === false) {
            return;
        }
        
        // WordPress native styles
        wp_enqueue_style('wp-genius-admin', WP_GENIUS_PLUGIN_URL . 'assets/css/admin.css', array(), WP_GENIUS_VERSION);
        
        // WordPress native scripts
        wp_enqueue_script('wp-genius-admin', WP_GENIUS_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'wp-api'), WP_GENIUS_VERSION, true);
        
        // Localize script for AJAX
        wp_localize_script('wp-genius-admin', 'wpGenius', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_genius_nonce'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this site?', 'wp-genius'),
                'checking_site' => __('Checking site...', 'wp-genius'),
                'generating_report' => __('Generating report...', 'wp-genius')
            )
        ));
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $sites = WP_Genius_Database::get_sites();
        $total_sites = count($sites);
        $active_sites = count(array_filter($sites, function($site) { return $site->status === 'active'; }));
        $inactive_sites = $total_sites - $active_sites;
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('WP Genius Dashboard', 'wp-genius'); ?></h1>
            
            <!-- Stats Cards -->
            <div class="wp-genius-stats-grid">
                <div class="wp-genius-stat-card">
                    <div class="wp-genius-stat-number"><?php echo $total_sites; ?></div>
                    <div class="wp-genius-stat-label"><?php _e('Total Sites', 'wp-genius'); ?></div>
                </div>
                
                <div class="wp-genius-stat-card wp-genius-stat-success">
                    <div class="wp-genius-stat-number"><?php echo $active_sites; ?></div>
                    <div class="wp-genius-stat-label"><?php _e('Active Sites', 'wp-genius'); ?></div>
                </div>
                
                <div class="wp-genius-stat-card wp-genius-stat-warning">
                    <div class="wp-genius-stat-number"><?php echo $inactive_sites; ?></div>
                    <div class="wp-genius-stat-label"><?php _e('Inactive Sites', 'wp-genius'); ?></div>
                </div>
                
                <div class="wp-genius-stat-card wp-genius-stat-info">
                    <div class="wp-genius-stat-number">24/7</div>
                    <div class="wp-genius-stat-label"><?php _e('Monitoring', 'wp-genius'); ?></div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="wp-genius-dashboard-grid">
                <div class="wp-genius-card">
                    <h2><?php _e('Recent Sites Activity', 'wp-genius'); ?></h2>
                    <?php $this->render_recent_activity(); ?>
                </div>
                
                <div class="wp-genius-card">
                    <h2><?php _e('Quick Actions', 'wp-genius'); ?></h2>
                    <div class="wp-genius-quick-actions">
                        <a href="<?php echo admin_url('admin.php?page=wp-genius-sites&action=add'); ?>" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt"></span>
                            <?php _e('Add New Site', 'wp-genius'); ?>
                        </a>
                        
                        <a href="<?php echo admin_url('admin.php?page=wp-genius-reports'); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-chart-area"></span>
                            <?php _e('Generate Reports', 'wp-genius'); ?>
                        </a>
                        
                        <button id="wp-genius-check-all-sites" class="button button-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Check All Sites', 'wp-genius'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Sites management page
     */
    public function sites_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        
        switch ($action) {
            case 'add':
                $this->render_add_site_form();
                break;
            case 'edit':
                $this->render_edit_site_form();
                break;
            default:
                $this->render_sites_list();
                break;
        }
    }
    
    /**
     * Reports page
     */
    public function reports_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Reports', 'wp-genius'); ?></h1>
            <a href="#" class="page-title-action" id="wp-genius-generate-new-report">
                <?php _e('Generate New Report', 'wp-genius'); ?>
            </a>
            
            <div class="wp-genius-reports-container">
                <!-- React component will be mounted here for AI-powered reports -->
                <div id="wp-genius-reports-app"></div>
                
                <!-- Fallback for non-JS users -->
                <noscript>
                    <?php $this->render_reports_fallback(); ?>
                </noscript>
            </div>
        </div>
        <?php
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['wp_genius_settings_nonce'], 'wp_genius_settings')) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'wp-genius') . '</p></div>';
        }
        
        $claude_api_key = get_option('wp_genius_claude_api_key', '');
        $ai_enabled = get_option('wp_genius_ai_enabled', '1');
        $backup_frequency = get_option('wp_genius_backup_frequency', 'daily');
        $monitor_frequency = get_option('wp_genius_monitor_frequency', '5');
        
        ?>
        <div class="wrap">
            <h1><?php _e('WP Genius Settings', 'wp-genius'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wp_genius_settings', 'wp_genius_settings_nonce'); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="claude_api_key"><?php _e('Claude API Key', 'wp-genius'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="claude_api_key" name="claude_api_key" 
                                       value="<?php echo esc_attr($claude_api_key); ?>" class="regular-text" />
                                <p class="description">
                                    <?php _e('Enter your Claude API key to enable AI-powered report summaries.', 'wp-genius'); ?>
                                    <a href="https://console.anthropic.com/" target="_blank"><?php _e('Get API Key', 'wp-genius'); ?></a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('AI Features', 'wp-genius'); ?></th>
                            <td>
                                <fieldset>
                                    <label for="ai_enabled">
                                        <input type="checkbox" id="ai_enabled" name="ai_enabled" value="1" 
                                               <?php checked($ai_enabled, '1'); ?> />
                                        <?php _e('Enable AI-powered report summaries', 'wp-genius'); ?>
                                    </label>
                                    <p class="description">
                                        <?php _e('Generate client-friendly explanations of technical maintenance data.', 'wp-genius'); ?>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="backup_frequency"><?php _e('Backup Frequency', 'wp-genius'); ?></label>
                            </th>
                            <td>
                                <select id="backup_frequency" name="backup_frequency">
                                    <option value="hourly" <?php selected($backup_frequency, 'hourly'); ?>><?php _e('Hourly', 'wp-genius'); ?></option>
                                    <option value="daily" <?php selected($backup_frequency, 'daily'); ?>><?php _e('Daily', 'wp-genius'); ?></option>
                                    <option value="weekly" <?php selected($backup_frequency, 'weekly'); ?>><?php _e('Weekly', 'wp-genius'); ?></option>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="monitor_frequency"><?php _e('Monitoring Frequency', 'wp-genius'); ?></label>
                            </th>
                            <td>
                                <select id="monitor_frequency" name="monitor_frequency">
                                    <option value="1" <?php selected($monitor_frequency, '1'); ?>><?php _e('Every minute', 'wp-genius'); ?></option>
                                    <option value="5" <?php selected($monitor_frequency, '5'); ?>><?php _e('Every 5 minutes', 'wp-genius'); ?></option>
                                    <option value="15" <?php selected($monitor_frequency, '15'); ?>><?php _e('Every 15 minutes', 'wp-genius'); ?></option>
                                    <option value="30" <?php selected($monitor_frequency, '30'); ?>><?php _e('Every 30 minutes', 'wp-genius'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render sites list
     */
    private function render_sites_list() {
        $sites = WP_Genius_Database::get_sites();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Sites', 'wp-genius'); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=wp-genius-sites&action=add'); ?>" class="page-title-action">
                <?php _e('Add New Site', 'wp-genius'); ?>
            </a>
            
            <?php if (empty($sites)): ?>
                <div class="wp-genius-empty-state">
                    <div class="wp-genius-empty-icon">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                    </div>
                    <h2><?php _e('No sites added yet', 'wp-genius'); ?></h2>
                    <p><?php _e('Add your first WordPress site to start monitoring and managing it with AI-powered insights.', 'wp-genius'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=wp-genius-sites&action=add'); ?>" class="button button-primary">
                        <?php _e('Add Your First Site', 'wp-genius'); ?>
                    </a>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-name column-primary">
                                <?php _e('Site Name', 'wp-genius'); ?>
                            </th>
                            <th scope="col" class="manage-column column-url">
                                <?php _e('URL', 'wp-genius'); ?>
                            </th>
                            <th scope="col" class="manage-column column-status">
                                <?php _e('Status', 'wp-genius'); ?>
                            </th>
                            <th scope="col" class="manage-column column-version">
                                <?php _e('WP Version', 'wp-genius'); ?>
                            </th>
                            <th scope="col" class="manage-column column-last-check">
                                <?php _e('Last Check', 'wp-genius'); ?>
                            </th>
                            <th scope="col" class="manage-column column-actions">
                                <?php _e('Actions', 'wp-genius'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sites as $site): ?>
                            <tr>
                                <td class="column-name column-primary">
                                    <strong><?php echo esc_html($site->name); ?></strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo admin_url('admin.php?page=wp-genius-sites&action=edit&site_id=' . $site->id); ?>">
                                                <?php _e('Edit', 'wp-genius'); ?>
                                            </a> |
                                        </span>
                                        <span class="check">
                                            <a href="#" data-site-id="<?php echo $site->id; ?>" class="wp-genius-check-site">
                                                <?php _e('Check Now', 'wp-genius'); ?>
                                            </a> |
                                        </span>
                                        <span class="delete">
                                            <a href="#" data-site-id="<?php echo $site->id; ?>" class="wp-genius-delete-site submitdelete">
                                                <?php _e('Delete', 'wp-genius'); ?>
                                            </a>
                                        </span>
                                    </div>
                                    <button type="button" class="toggle-row"><span class="screen-reader-text"><?php _e('Show more details', 'wp-genius'); ?></span></button>
                                </td>
                                <td class="column-url" data-colname="<?php _e('URL', 'wp-genius'); ?>">
                                    <a href="<?php echo esc_url($site->url); ?>" target="_blank">
                                        <?php echo esc_html($site->url); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                </td>
                                <td class="column-status" data-colname="<?php _e('Status', 'wp-genius'); ?>">
                                    <?php $this->render_status_badge($site->status); ?>
                                </td>
                                <td class="column-version" data-colname="<?php _e('WP Version', 'wp-genius'); ?>">
                                    <?php echo $site->wp_version ? esc_html($site->wp_version) : '—'; ?>
                                </td>
                                <td class="column-last-check" data-colname="<?php _e('Last Check', 'wp-genius'); ?>">
                                    <?php 
                                    if ($site->last_check) {
                                        echo human_time_diff(strtotime($site->last_check), current_time('timestamp')) . ' ' . __('ago', 'wp-genius');
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td class="column-actions" data-colname="<?php _e('Actions', 'wp-genius'); ?>">
                                    <button class="button button-small wp-genius-check-site" data-site-id="<?php echo $site->id; ?>">
                                        <span class="dashicons dashicons-update"></span>
                                        <?php _e('Check', 'wp-genius'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render add site form
     */
    private function render_add_site_form() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Add New Site', 'wp-genius'); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=wp-genius-sites'); ?>" class="page-title-action">
                <?php _e('Back to Sites', 'wp-genius'); ?>
            </a>
            
            <div class="wp-genius-form-container">
                <form id="wp-genius-add-site-form" method="post">
                    <?php wp_nonce_field('wp_genius_add_site', 'wp_genius_add_site_nonce'); ?>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="site_name"><?php _e('Site Name', 'wp-genius'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="site_name" name="site_name" class="regular-text" required />
                                    <p class="description"><?php _e('A friendly name to identify this site.', 'wp-genius'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="site_url"><?php _e('Site URL', 'wp-genius'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="url" id="site_url" name="site_url" class="regular-text" placeholder="https://example.com" required />
                                    <p class="description"><?php _e('The full URL of the WordPress site you want to manage.', 'wp-genius'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="wp-genius-setup-instructions">
                        <h3><?php _e('Setup Instructions', 'wp-genius'); ?></h3>
                        <div class="wp-genius-steps">
                            <div class="wp-genius-step">
                                <div class="wp-genius-step-number">1</div>
                                <div class="wp-genius-step-content">
                                    <h4><?php _e('Install the WP Genius Client Plugin', 'wp-genius'); ?></h4>
                                    <p><?php _e('Download and install the WP Genius Client plugin on the site you want to manage.', 'wp-genius'); ?></p>
                                    <a href="#" class="button button-secondary" id="download-client-plugin">
                                        <span class="dashicons dashicons-download"></span>
                                        <?php _e('Download Client Plugin', 'wp-genius'); ?>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="wp-genius-step">
                                <div class="wp-genius-step-number">2</div>
                                <div class="wp-genius-step-content">
                                    <h4><?php _e('Connect Your Site', 'wp-genius'); ?></h4>
                                    <p><?php _e('After installing the client plugin, use this connection key:',  'wp-genius'); ?></p>
                                    <div class="wp-genius-connection-key">
                                        <code id="connection-key"><?php echo wp_generate_password(32, false); ?></code>
                                        <button type="button" class="button button-small" id="copy-connection-key">
                                            <span class="dashicons dashicons-admin-page"></span>
                                            <?php _e('Copy', 'wp-genius'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt"></span>
                            <?php _e('Add Site', 'wp-genius'); ?>
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=wp-genius-sites'); ?>" class="button button-secondary">
                            <?php _e('Cancel', 'wp-genius'); ?>
                        </a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render status badge
     */
    private function render_status_badge($status) {
        $badges = array(
            'active' => array('class' => 'wp-genius-badge-success', 'text' => __('Active', 'wp-genius')),
            'inactive' => array('class' => 'wp-genius-badge-warning', 'text' => __('Inactive', 'wp-genius')),
            'error' => array('class' => 'wp-genius-badge-error', 'text' => __('Error', 'wp-genius'))
        );
        
        $badge = isset($badges[$status]) ? $badges[$status] : $badges['inactive'];
        
        echo sprintf(
            '<span class="wp-genius-badge %s">%s</span>',
            esc_attr($badge['class']),
            esc_html($badge['text'])
        );
    }
    
    /**
     * Render recent activity
     */
    private function render_recent_activity() {
        global $wpdb;
        
        // Get recent monitoring data
        $monitoring_table = $wpdb->prefix . 'genius_monitoring';
        $sites_table = $wpdb->prefix . 'genius_sites';
        
        $recent_activity = $wpdb->get_results("
            SELECT m.*, s.name as site_name 
            FROM $monitoring_table m 
            LEFT JOIN $sites_table s ON m.site_id = s.id 
            ORDER BY m.checked_at DESC 
            LIMIT 10
        ");
        
        if (empty($recent_activity)) {
            echo '<p>' . __('No recent activity found.', 'wp-genius') . '</p>';
            return;
        }
        
        echo '<div class="wp-genius-activity-list">';
        foreach ($recent_activity as $activity) {
            $icon = $activity->status === 'up' ? 'yes-alt' : 'warning';
            $class = $activity->status === 'up' ? 'success' : 'warning';
            
            echo sprintf(
                '<div class="wp-genius-activity-item wp-genius-activity-%s">
                    <span class="dashicons dashicons-%s"></span>
                    <div class="wp-genius-activity-content">
                        <strong>%s</strong> - %s check
                        <div class="wp-genius-activity-time">%s</div>
                    </div>
                </div>',
                esc_attr($class),
                esc_attr($icon),
                esc_html($activity->site_name),
                esc_html(ucfirst($activity->check_type)),
                human_time_diff(strtotime($activity->checked_at), current_time('timestamp')) . ' ' . __('ago', 'wp-genius')
            );
        }
        echo '</div>';
    }
    
    /**
     * Render reports fallback for non-JS users
     */
    private function render_reports_fallback() {
        echo '<div class="notice notice-info">';
        echo '<p>' . __('JavaScript is required for AI-powered report generation. Please enable JavaScript for the full experience.', 'wp-genius') . '</p>';
        echo '</div>';
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        $settings = array(
            'claude_api_key' => sanitize_text_field($_POST['claude_api_key']),
            'ai_enabled' => isset($_POST['ai_enabled']) ? '1' : '0',
            'backup_frequency' => sanitize_text_field($_POST['backup_frequency']),
            'monitor_frequency' => sanitize_text_field($_POST['monitor_frequency'])
        );
        
        foreach ($settings as $key => $value) {
            update_option('wp_genius_' . $key, $value);
        }
    }
    
    /**
     * AJAX: Add new site
     */
    public function ajax_add_site() {
        check_ajax_referer('wp_genius_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wp-genius'));
        }
        
        $site_name = sanitize_text_field($_POST['site_name']);
        $site_url = esc_url_raw($_POST['site_url']);
        $api_key = wp_generate_password(32, false);
        
        if (empty($site_name) || empty($site_url)) {
            wp_send_json_error(__('Site name and URL are required.', 'wp-genius'));
        }
        
        $site_id = WP_Genius_Database::add_site($site_url, $site_name, $api_key);
        
        if ($site_id) {
            wp_send_json_success(array(
                'message' => __('Site added successfully.', 'wp-genius'),
                'site_id' => $site_id,
                'redirect' => admin_url('admin.php?page=wp-genius-sites')
            ));
        } else {
            wp_send_json_error(__('Failed to add site.', 'wp-genius'));
        }
    }
    
    /**
     * AJAX: Check site status
     */
    public function ajax_check_site() {
        check_ajax_referer('wp_genius_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wp-genius'));
        }
        
        $site_id = intval($_POST['site_id']);
        $site = WP_Genius_Database::get_site($site_id);
        
        if (!$site) {
            wp_send_json_error(__('Site not found.', 'wp-genius'));
        }
        
        // Use Site Manager to check site
        $site_manager = new WP_Genius_Site_Manager();
        $result = $site_manager->check_site($site_id);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Site checked successfully.', 'wp-genius'),
                'status' => $result['status']
            ));
        } else {
            wp_send_json_error(__('Failed to check site.', 'wp-genius'));
        }
    }
    
    /**
     * AJAX: Delete site
     */
    public function ajax_delete_site() {
        check_ajax_referer('wp_genius_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wp-genius'));
        }
        
        $site_id = intval($_POST['site_id']);
        
        global $wpdb;
        $sites_table = $wpdb->prefix . 'genius_sites';
        
        $result = $wpdb->delete($sites_table, array('id' => $site_id), array('%d'));
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Site deleted successfully.', 'wp-genius')
            ));
        } else {
            wp_send_json_error(__('Failed to delete site.', 'wp-genius'));
        }
    }
    
    /**
     * AJAX: Check all sites
     */
    public function ajax_check_all_sites() {
        check_ajax_referer('wp_genius_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wp-genius'));
        }
        
        $sites = WP_Genius_Database::get_sites('active');
        $site_manager = new WP_Genius_Site_Manager();
        
        $checked_count = 0;
        foreach ($sites as $site) {
            $result = $site_manager->check_site($site->id);
            if ($result) {
                $checked_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('Checked %d sites successfully.', 'wp-genius'), $checked_count)
        ));
    }
    
    /**
     * AJAX: Get sites for dropdowns
     */
    public function ajax_get_sites() {
        check_ajax_referer('wp_genius_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wp-genius'));
        }
        
        $sites = WP_Genius_Database::get_sites();
        
        wp_send_json_success(array(
            'sites' => $sites
        ));
    }
    
    /**
     * AJAX: Connect site using connection key
     */
    public function ajax_connect_site() {
        check_ajax_referer('wp_genius_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wp-genius'));
        }
        
        $site_url = esc_url_raw($_POST['site_url']);
        $connection_key = sanitize_text_field($_POST['connection_key']);
        
        if (empty($site_url) || empty($connection_key)) {
            wp_send_json_error(__('Site URL and connection key are required.', 'wp-genius'));
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
            wp_send_json_error(__('Could not connect to site: ', 'wp-genius') . $response->get_error_message());
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
                
                wp_send_json_success(array(
                    'message' => __('Site connected successfully.', 'wp-genius'),
                    'site_id' => $site_id,
                    'redirect' => admin_url('admin.php?page=wp-genius-sites')
                ));
            } else {
                wp_send_json_error(__('Failed to save site to database.', 'wp-genius'));
            }
        } else {
            wp_send_json_error($data['message'] ?? __('Connection failed.', 'wp-genius'));
        }
    }
    public function ajax_generate_report() {
        check_ajax_referer('wp_genius_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wp-genius'));
        }
        
        $site_id = intval($_POST['site_id']);
        $period_start = sanitize_text_field($_POST['period_start']);
        $period_end = sanitize_text_field($_POST['period_end']);
        
        $report_generator = new WP_Genius_Report_Generator();
        $result = $report_generator->generate_report($site_id, $period_start, $period_end);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Report generated successfully.', 'wp-genius'),
                'report_id' => $result['report_id'],
                'download_url' => $result['download_url']
            ));
        } else {
            wp_send_json_error(__('Failed to generate report.', 'wp-genius'));
        }
    }
}
