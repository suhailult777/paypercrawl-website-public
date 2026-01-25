<?php
/**
 * Live Sync API Client
 * 
 * Handles communication with PayPerCrawl backend for live data ingestion
 */

if (!defined('ABSPATH')) {
    exit;
}

class CrawlGuard_Live_Sync_Client {
    
    private $api_base_url;
    private $api_key;
    private $timeout = 10;
    
    public function __construct() {
        $options = get_option('crawlguard_options');
        
        // Use Next.js API base (not Cloudflare Worker)
        $this->api_base_url = rtrim($options['api_base_url'] ?? 'https://paypercrawl.tech/api', '/');
        $this->api_key = $options['api_key'] ?? '';
    }
    
    /**
     * Send a live event to the backend
     */
    public function send_live_event($event) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => 'API key not configured',
            );
        }
        
        $url = $this->api_base_url . '/plugin/live/ingest';
        
        $response = wp_remote_post($url, array(
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
            ),
            'body' => wp_json_encode($event),
        ));
        
        if (is_wp_error($response)) {
            $this->log_error('send_live_event', $response->get_error_message());
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code >= 400) {
            $error_msg = $data['error'] ?? 'Unknown error';
            $this->log_error('send_live_event', $error_msg . ' (HTTP ' . $status_code . ')');
            return array(
                'success' => false,
                'error' => $error_msg,
                'status_code' => $status_code,
            );
        }
        
        return array(
            'success' => true,
            'data' => $data,
        );
    }
    
    /**
     * Query the Live Tool API (for admin test console)
     */
    public function query_live_tool($params) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => 'API key not configured',
            );
        }
        
        $url = $this->api_base_url . '/tool/live/query';
        
        // Build request payload
        $payload = array(
            'siteUrl' => get_site_url(),
            'question' => $params['question'] ?? null,
            'mode' => $params['mode'] ?? 'hybrid',
            'freshnessSeconds' => intval($params['freshness_seconds'] ?? 900),
        );
        
        // Add optional filters
        if (!empty($params['filters'])) {
            $payload['filters'] = $params['filters'];
        }
        
        $response = wp_remote_post($url, array(
            'timeout' => 15, // Longer timeout for queries
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
            ),
            'body' => wp_json_encode($payload),
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code >= 400) {
            return array(
                'success' => false,
                'error' => $data['error'] ?? 'Query failed',
                'status_code' => $status_code,
            );
        }
        
        return array(
            'success' => true,
            'data' => $data,
        );
    }
    
    /**
     * Get live sync status from backend
     */
    public function get_status() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => 'API key not configured',
            );
        }
        
        // For now, return local status (can be enhanced to call backend later)
        $options = get_option('crawlguard_options');
        $live_sync = $options['live_sync'] ?? array();
        
        return array(
            'success' => true,
            'data' => array(
                'enabled' => !empty($live_sync['enabled']),
                'connectors' => array(
                    'woocommerce' => array(
                        'enabled' => !empty($live_sync['woocommerce_enabled']),
                        'available' => class_exists('WooCommerce'),
                    ),
                ),
                'last_event_at' => $live_sync['last_event_at'] ?? null,
                'event_count' => intval($live_sync['event_count'] ?? 0),
            ),
        );
    }
    
    /**
     * Log errors for debugging
     */
    private function log_error($context, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CrawlGuard Live Sync] ' . $context . ': ' . $message);
        }
        
        // Store last error in options for admin display
        $options = get_option('crawlguard_options');
        if (!isset($options['live_sync'])) {
            $options['live_sync'] = array();
        }
        $options['live_sync']['last_error'] = $message;
        $options['live_sync']['last_error_at'] = current_time('mysql');
        update_option('crawlguard_options', $options);
    }
    
    /**
     * Update event count and last event timestamp
     */
    public function record_event_sent() {
        $options = get_option('crawlguard_options');
        if (!isset($options['live_sync'])) {
            $options['live_sync'] = array();
        }
        $options['live_sync']['last_event_at'] = current_time('mysql');
        $options['live_sync']['event_count'] = intval($options['live_sync']['event_count'] ?? 0) + 1;
        update_option('crawlguard_options', $options);
    }
}
