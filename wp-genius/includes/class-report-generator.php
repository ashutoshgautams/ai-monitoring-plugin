<?php
/**
 * WP Genius Report Generator
 * Creates simple reports (HTML for now, PDF later)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_Report_Generator {
    
    private $ai_processor;
    
    public function __construct() {
        $this->ai_processor = new WP_Genius_AI_Processor();
    }
    
    /**
     * Generate comprehensive report for a site
     */
    public function generate_report($site_id, $period_start, $period_end, $include_ai = true) {
        $site = WP_Genius_Database::get_site($site_id);
        
        if (!$site) {
            return false;
        }
        
        // Collect technical data
        $technical_data = $this->collect_technical_data($site_id, $period_start, $period_end);
        
        // Generate AI summary if enabled
        $ai_summary = null;
        if ($include_ai && $this->ai_processor->is_configured()) {
            $ai_result = $this->ai_processor->generate_report_summary($technical_data, $site->name);
            if ($ai_result['success']) {
                $ai_summary = $ai_result['summary'];
            }
        }
        
        // Generate HTML report for now (PDF requires external library)
        $html_result = $this->generate_html_report($site, $technical_data, $ai_summary, $period_start, $period_end);
        
        if (!$html_result) {
            return false;
        }
        
        // Save report to database
        $report_id = $this->save_report_to_database($site_id, $period_start, $period_end, $technical_data, $ai_summary, $html_result['file_path']);
        
        return array(
            'report_id' => $report_id,
            'file_path' => $html_result['file_path'],
            'download_url' => $html_result['download_url'],
            'ai_summary' => $ai_summary
        );
    }
    
    /**
     * Collect technical data for the report period
     */
    private function collect_technical_data($site_id, $period_start, $period_end) {
        global $wpdb;
        
        $data = array();
        
        // Site information
        $site = WP_Genius_Database::get_site($site_id);
        $data['site_info'] = array(
            'name' => $site->name,
            'url' => $site->url,
            'wp_version' => $site->wp_version,
            'php_version' => $site->php_version,
            'status' => $site->status
        );
        
        // Monitoring data
        $monitoring_table = $wpdb->prefix . 'genius_monitoring';
        $monitoring_data = $wpdb->get_results($wpdb->prepare("
            SELECT check_type, status, response_time, checked_at
            FROM $monitoring_table 
            WHERE site_id = %d 
            AND checked_at BETWEEN %s AND %s
            ORDER BY checked_at DESC
        ", $site_id, $period_start . ' 00:00:00', $period_end . ' 23:59:59'));
        
        $data['monitoring'] = $this->process_monitoring_data($monitoring_data);
        
        // Updates performed
        $updates_table = $wpdb->prefix . 'genius_updates';
        $updates_data = $wpdb->get_results($wpdb->prepare("
            SELECT component_type, component_name, version_from, version_to, status, created_at
            FROM $updates_table 
            WHERE site_id = %d 
            AND created_at BETWEEN %s AND %s
            ORDER BY created_at DESC
        ", $site_id, $period_start . ' 00:00:00', $period_end . ' 23:59:59'));
        
        $data['updates'] = $updates_data;
        
        // Backup information
        $backups_table = $wpdb->prefix . 'genius_backups';
        $backups_data = $wpdb->get_results($wpdb->prepare("
            SELECT backup_type, file_size, status, created_at
            FROM $backups_table 
            WHERE site_id = %d 
            AND created_at BETWEEN %s AND %s
            ORDER BY created_at DESC
        ", $site_id, $period_start . ' 00:00:00', $period_end . ' 23:59:59'));
        
        $data['backups'] = $backups_data;
        
        // Calculate summary statistics
        $data['summary'] = $this->calculate_summary_stats($data);
        
        return $data;
    }
    
    /**
     * Process monitoring data for better reporting
     */
    private function process_monitoring_data($monitoring_data) {
        $processed = array(
            'uptime' => array('total_checks' => 0, 'up_checks' => 0, 'average_response_time' => 0),
            'performance' => array('total_checks' => 0, 'good_performance' => 0, 'average_response_time' => 0),
            'security' => array('total_checks' => 0, 'no_issues' => 0)
        );
        
        $response_times = array();
        
        foreach ($monitoring_data as $check) {
            $type = $check->check_type;
            
            if (!isset($processed[$type])) {
                continue;
            }
            
            $processed[$type]['total_checks']++;
            
            if ($check->status === 'up') {
                $processed[$type]['up_checks']++;
            }
            
            if ($type === 'performance' && $check->status === 'up') {
                $processed[$type]['good_performance']++;
            }
            
            if ($type === 'security' && $check->status === 'up') {
                $processed[$type]['no_issues']++;
            }
            
            if ($check->response_time) {
                $response_times[$type][] = $check->response_time;
            }
        }
        
        // Calculate averages
        foreach ($response_times as $type => $times) {
            if (!empty($times)) {
                $processed[$type]['average_response_time'] = round(array_sum($times) / count($times), 2);
            }
        }
        
        // Calculate uptime percentage
        foreach ($processed as $type => &$data) {
            if ($data['total_checks'] > 0) {
                $data['uptime_percentage'] = round(($data['up_checks'] / $data['total_checks']) * 100, 2);
            } else {
                $data['uptime_percentage'] = 0;
            }
        }
        
        return $processed;
    }
    
    /**
     * Calculate summary statistics
     */
    private function calculate_summary_stats($data) {
        $summary = array();
        
        // Overall uptime
        if (isset($data['monitoring']['uptime']['uptime_percentage'])) {
            $summary['uptime_percentage'] = $data['monitoring']['uptime']['uptime_percentage'];
        }
        
        // Updates count
        $summary['total_updates'] = count($data['updates']);
        $summary['successful_updates'] = count(array_filter($data['updates'], function($update) {
            return $update->status === 'completed';
        }));
        
        // Backups count
        $summary['total_backups'] = count($data['backups']);
        $summary['successful_backups'] = count(array_filter($data['backups'], function($backup) {
            return $backup->status === 'completed';
        }));
        
        // Average response time
        if (isset($data['monitoring']['uptime']['average_response_time'])) {
            $summary['average_response_time'] = $data['monitoring']['uptime']['average_response_time'];
        }
        
        return $summary;
    }
    
    /**
     * Generate HTML report (simpler than PDF for testing)
     */
    private function generate_html_report($site, $technical_data, $ai_summary, $period_start, $period_end) {
        try {
            $branding = get_option('wp_genius_report_branding', array());
            $company_name = $branding['company_name'] ?? get_bloginfo('name');
            $primary_color = $branding['primary_color'] ?? '#2271b1';
            
            $html = $this->build_html_report($site, $technical_data, $ai_summary, $period_start, $period_end, $company_name, $primary_color);
            
            // Generate filename
            $filename = 'wp-genius-report-' . sanitize_title($site->name) . '-' . date('Y-m-d') . '.html';
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['path'] . '/' . $filename;
            
            // Save HTML to file
            file_put_contents($file_path, $html);
            
            return array(
                'file_path' => $file_path,
                'download_url' => $upload_dir['url'] . '/' . $filename
            );
            
        } catch (Exception $e) {
            error_log('WP Genius: Report generation failed - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Build HTML report content
     */
    private function build_html_report($site, $technical_data, $ai_summary, $period_start, $period_end, $company_name, $primary_color) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Website Maintenance Report - ' . esc_html($site->name) . '</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; border-bottom: 3px solid ' . $primary_color . '; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: ' . $primary_color . '; margin: 0; }
        .summary-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 20px; margin: 20px 0; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-item { text-align: center; background: white; border: 1px solid #ddd; border-radius: 5px; padding: 15px; }
        .stat-number { font-size: 2em; font-weight: bold; color: ' . $primary_color . '; }
        .stat-label { font-size: 0.9em; color: #666; }
        .section { margin: 30px 0; }
        .section h2 { color: ' . $primary_color . '; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f9fa; font-weight: bold; }
        .success { color: #28a745; }
        .warning { color: #ffc107; }
        .error { color: #dc3545; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Website Maintenance Report</h1>
        <p><strong>' . esc_html($company_name) . '</strong></p>
        <p>Site: ' . esc_html($site->name) . '</p>
        <p>Period: ' . date('F j, Y', strtotime($period_start)) . ' - ' . date('F j, Y', strtotime($period_end)) . '</p>
        <p>Generated: ' . date('F j, Y \a\t g:i A') . '</p>
    </div>';
        
        // AI Summary
        if ($ai_summary) {
            $html .= '<div class="section">
                <h2>Executive Summary</h2>
                <div class="summary-box">
                    ' . nl2br(esc_html($ai_summary)) . '
                </div>
            </div>';
        }
        
        // Summary Statistics
        $summary = $technical_data['summary'];
        $html .= '<div class="section">
            <h2>Summary Statistics</h2>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number">' . ($summary['uptime_percentage'] ?? 0) . '%</div>
                    <div class="stat-label">Uptime</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">' . ($summary['successful_updates'] ?? 0) . '/' . ($summary['total_updates'] ?? 0) . '</div>
                    <div class="stat-label">Updates Applied</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">' . ($summary['successful_backups'] ?? 0) . '/' . ($summary['total_backups'] ?? 0) . '</div>
                    <div class="stat-label">Backups Created</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">' . ($summary['average_response_time'] ?? 0) . 'ms</div>
                    <div class="stat-label">Avg Response Time</div>
                </div>
            </div>
        </div>';
        
        // Website Monitoring
        $monitoring = $technical_data['monitoring'];
        $html .= '<div class="section">
            <h2>Website Monitoring</h2>
            <p>During this period, your website was monitored continuously:</p>
            <ul>
                <li>Uptime: ' . ($monitoring['uptime']['uptime_percentage'] ?? 0) . '% (' . ($monitoring['uptime']['up_checks'] ?? 0) . '/' . ($monitoring['uptime']['total_checks'] ?? 0) . ' checks passed)</li>
                <li>Average response time: ' . ($monitoring['uptime']['average_response_time'] ?? 0) . 'ms</li>
                <li>Performance checks: ' . ($monitoring['performance']['good_performance'] ?? 0) . '/' . ($monitoring['performance']['total_checks'] ?? 0) . ' passed</li>
            </ul>
        </div>';
        
        // Updates
        $html .= '<div class="section">
            <h2>Updates Applied</h2>';
        
        if (empty($technical_data['updates'])) {
            $html .= '<p>No updates were applied during this period.</p>';
        } else {
            $html .= '<table>
                <thead>
                    <tr><th>Component</th><th>Type</th><th>Version</th><th>Status</th><th>Date</th></tr>
                </thead>
                <tbody>';
            
            foreach ($technical_data['updates'] as $update) {
                $status_class = $update->status === 'completed' ? 'success' : 'error';
                $html .= '<tr>
                    <td>' . esc_html($update->component_name) . '</td>
                    <td>' . esc_html(ucfirst($update->component_type)) . '</td>
                    <td>' . esc_html($update->version_from . ' → ' . $update->version_to) . '</td>
                    <td class="' . $status_class . '">' . esc_html(ucfirst($update->status)) . '</td>
                    <td>' . date('M j, Y', strtotime($update->created_at)) . '</td>
                </tr>';
            }
            
            $html .= '</tbody></table>';
        }
        
        $html .= '</div>';
        
        // Backups
        $html .= '<div class="section">
            <h2>Backups Created</h2>';
        
        if (empty($technical_data['backups'])) {
            $html .= '<p>No backups were created during this period.</p>';
        } else {
            $html .= '<p>During this period, ' . count($technical_data['backups']) . ' backups were created:</p><ul>';
            
            foreach ($technical_data['backups'] as $backup) {
                $size = $this->format_file_size($backup->file_size);
                $date = date('M j, Y \a\t g:i A', strtotime($backup->created_at));
                $status_class = $backup->status === 'completed' ? 'success' : 'error';
                $html .= '<li>' . ucfirst($backup->backup_type) . ' backup - ' . $size . ' - ' . $date . ' (<span class="' . $status_class . '">' . ucfirst($backup->status) . '</span>)</li>';
            }
            
            $html .= '</ul>';
        }
        
        $html .= '</div>';
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    /**
     * Format file size for display
     */
    private function format_file_size($bytes) {
        if ($bytes === 0) return '0 B';
        
        $units = array('B', 'KB', 'MB', 'GB');
        $i = floor(log($bytes, 1024));
        
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
    
    /**
     * Save report to database
     */
    private function save_report_to_database($site_id, $period_start, $period_end, $technical_data, $ai_summary, $file_path) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_reports';
        
        $result = $wpdb->insert(
            $table,
            array(
                'site_id' => $site_id,
                'period_start' => $period_start,
                'period_end' => $period_end,
                'technical_data' => json_encode($technical_data),
                'ai_summary' => $ai_summary,
                'pdf_path' => $file_path,
                'status' => 'generated'
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
}
