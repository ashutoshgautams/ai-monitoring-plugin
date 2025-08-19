<?php
/**
 * WP Genius AI Processor
 * Handles AI-powered report summaries using Claude API
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Genius_AI_Processor {
    
    private $api_key;
    private $api_url = 'https://api.anthropic.com/v1/messages';
    
    public function __construct() {
        $this->api_key = get_option('wp_genius_claude_api_key', '');
    }
    
    /**
     * Check if AI is properly configured
     */
    public function is_configured() {
        return !empty($this->api_key) && get_option('wp_genius_ai_enabled', '1') === '1';
    }
    
    /**
     * Generate client-friendly summary from technical data
     */
    public function generate_report_summary($technical_data, $site_name = '') {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'error' => 'AI is not configured. Please add Claude API key in settings.'
            );
        }
        
        try {
            $prompt = $this->build_report_prompt($technical_data, $site_name);
            $response = $this->call_claude_api($prompt);
            
            if ($response['success']) {
                return array(
                    'success' => true,
                    'summary' => $response['content'],
                    'tokens_used' => $response['usage'] ?? null
                );
            } else {
                return array(
                    'success' => false,
                    'error' => $response['error']
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'AI processing failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Build prompt for report summary
     */
    private function build_report_prompt($technical_data, $site_name) {
        $site_display = !empty($site_name) ? $site_name : 'your website';
        
        $prompt = "You are a WordPress maintenance expert writing a report summary for a non-technical client. 

Convert the following technical maintenance data into a clear, friendly explanation that a business owner can understand. Focus on:
1. What was done to maintain their website
2. Any security improvements made  
3. Performance optimizations
4. Issues that were fixed
5. Overall health status

Technical Data:
" . json_encode($technical_data, JSON_PRETTY_PRINT) . "

Write a professional but friendly summary for {$site_display} that:
- Uses simple language (avoid technical jargon)
- Explains the business value of the maintenance work
- Highlights any important security or performance improvements
- Mentions any issues that were resolved
- Reassures the client their site is being well-maintained
- Keep it concise (2-3 paragraphs maximum)

Format the response as clean text without markdown or special formatting.";

        return $prompt;
    }
    
    /**
     * Call Claude API
     */
    private function call_claude_api($prompt) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => 'Claude API key not configured'
            );
        }
        
        $headers = array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $this->api_key,
            'anthropic-version' => '2023-06-01'
        );
        
        $body = array(
            'model' => 'claude-3-haiku-20240307', // Use Haiku for cost efficiency
            'max_tokens' => 500, // Keep summaries concise
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        );
        
        $response = wp_remote_post($this->api_url, array(
            'timeout' => 30,
            'headers' => $headers,
            'body' => json_encode($body)
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code !== 200) {
            return array(
                'success' => false,
                'error' => 'API Error: ' . ($data['error']['message'] ?? 'Unknown error')
            );
        }
        
        if (!isset($data['content'][0]['text'])) {
            return array(
                'success' => false,
                'error' => 'Invalid response format from Claude API'
            );
        }
        
        return array(
            'success' => true,
            'content' => trim($data['content'][0]['text']),
            'usage' => $data['usage'] ?? null
        );
    }
    
    /**
     * Generate update recommendations
     */
    public function generate_update_recommendations($updates_data, $site_name = '') {
        if (!$this->is_configured()) {
            return null;
        }
        
        $prompt = "You are a WordPress maintenance expert. Based on the following pending updates, provide a brief recommendation for a website owner:

Updates Available:
" . json_encode($updates_data, JSON_PRETTY_PRINT) . "

Provide a short recommendation (1-2 sentences) about:
- Whether these updates should be applied
- Any potential risks or benefits
- Priority level (urgent, recommended, or optional)

Keep it simple and actionable for a non-technical user.";

        $response = $this->call_claude_api($prompt);
        
        return $response['success'] ? $response['content'] : null;
    }
    
    /**
     * Analyze site health and provide insights
     */
    public function analyze_site_health($health_data, $site_name = '') {
        if (!$this->is_configured()) {
            return null;
        }
        
        $prompt = "Analyze this WordPress site health data and provide a brief, client-friendly assessment:

Health Data:
" . json_encode($health_data, JSON_PRETTY_PRINT) . "

Provide:
1. Overall health status (excellent, good, needs attention, or critical)
2. Top 2-3 recommendations for improvement
3. Any urgent issues that need immediate attention

Keep it concise and actionable for a business owner.";

        $response = $this->call_claude_api($prompt);
        
        return $response['success'] ? $response['content'] : null;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => 'No API key provided'
            );
        }
        
        $test_prompt = "Respond with exactly: 'WP Genius AI connection successful'";
        
        $response = $this->call_claude_api($test_prompt);
        
        if ($response['success']) {
            $expected = 'WP Genius AI connection successful';
            $actual = trim($response['content']);
            
            if (strpos($actual, $expected) !== false) {
                return array(
                    'success' => true,
                    'message' => 'Claude API connection successful'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Unexpected response from Claude API'
                );
            }
        } else {
            return $response;
        }
    }
    
    /**
     * Get estimated API cost for a report
     */
    public function estimate_cost($technical_data) {
        // Rough estimation based on token count
        $prompt = $this->build_report_prompt($technical_data, 'example site');
        $estimated_input_tokens = str_word_count($prompt) * 1.3; // Rough conversion
        $estimated_output_tokens = 300; // Expected output length
        
        // Claude Haiku pricing (as of 2024): $0.25 per 1M input tokens, $1.25 per 1M output tokens
        $input_cost = ($estimated_input_tokens / 1000000) * 0.25;
        $output_cost = ($estimated_output_tokens / 1000000) * 1.25;
        $total_cost = $input_cost + $output_cost;
        
        return array(
            'estimated_cost' => round($total_cost, 6),
            'input_tokens' => round($estimated_input_tokens),
            'output_tokens' => $estimated_output_tokens
        );
    }
    
    /**
     * Log AI usage for tracking costs
     */
    private function log_usage($tokens_used, $cost_estimate) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_ai_usage';
        
        // Create table if it doesn't exist
        $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tokens_used int unsigned NOT NULL,
            estimated_cost decimal(10,6) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        )");
        
        $wpdb->insert(
            $table,
            array(
                'tokens_used' => $tokens_used,
                'estimated_cost' => $cost_estimate
            ),
            array('%d', '%f')
        );
    }
    
    /**
     * Get usage statistics
     */
    public function get_usage_stats($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'genius_ai_usage';
        
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as requests,
                SUM(tokens_used) as total_tokens,
                SUM(estimated_cost) as total_cost
            FROM $table 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days));
        
        return array(
            'requests' => (int) $stats->requests,
            'tokens_used' => (int) $stats->total_tokens,
            'estimated_cost' => (float) $stats->total_cost,
            'period_days' => $days
        );
    }
}
