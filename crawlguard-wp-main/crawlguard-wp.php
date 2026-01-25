<?php
/**
 * Plugin Name: CrawlGuard WP - PayPerCrawl Edition
 * Plugin URI: https://paypercrawl.com
 * Description: Monetize AI bot traffic and protect your content with intelligent bot detection and access control. Powered by PayPerCrawl.
 * Version: 2.0.0
 * Author: PayPerCrawl Team
 * License: GPL v2 or later
 * Text Domain: crawlguard-wp
 * Requires API Key: Yes
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CRAWLGUARD_VERSION', '2.0.0');
define('CRAWLGUARD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CRAWLGUARD_PLUGIN_PATH', plugin_dir_path(__FILE__));
class CrawlGuardWP {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('crawlguard-wp', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize core components
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function load_dependencies() {
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-bot-detector.php';
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-api-client.php';
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-admin.php';
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-frontend.php';
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-analytics.php'; // Analytics data handler
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-js-challenge.php'; // JavaScript challenge system
        // New: Optional modules (feature-flagged and safe by default)
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-rate-limiter.php'; // Rate limiting (disabled by default)
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-http-signatures.php'; // HTTP Message Signatures (verification-only)
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-ip-intel.php'; // IP Intelligence (log-only)
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-toon-encoder.php'; // TOON Format Encoder
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-scraper.php'; // Content Scraper
        
        // Live Sync / RAG Tool API modules
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-live-sync-client.php'; // Live Sync API client
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-woocommerce-connector.php'; // WooCommerce connector
    }
    
    private function init_hooks() {
        // Initialize bot detection on every request
        add_action('wp', array($this, 'detect_and_handle_bots'));
        
        // JavaScript Challenge AJAX handler
        add_action('wp_ajax_crawlguard_verify_challenge', array('CrawlGuard_JS_Challenge', 'ajax_verify_challenge'));
        add_action('wp_ajax_nopriv_crawlguard_verify_challenge', array('CrawlGuard_JS_Challenge', 'ajax_verify_challenge'));
        
        // Optionally apply rate limiting early in the request lifecycle (feature-flagged)
        add_action('parse_request', function() {
            $opts = get_option('crawlguard_options');
            if (!empty($opts['feature_flags']['enable_rate_limiting'])) {
                CrawlGuard_RateLimiter::maybe_limit_current_request(); // Non-destructive: returns early on allow
            }
        }, 1);
        
        // Schedule analytics cleanup (keep only 90 days)
        add_action('crawlguard_cleanup_logs', array('CrawlGuard_Analytics', 'cleanup_old_logs'));
        
        // Initialize WooCommerce Live Sync connector (if WooCommerce is active)
        add_action('plugins_loaded', function() {
            new CrawlGuard_WooCommerce_Connector();
        }, 20); // After WooCommerce loads
        
        // Admin interface
        if (is_admin()) {
            new CrawlGuard_Admin();
        } else {
            new CrawlGuard_Frontend();
        }
    }
    
    public function detect_and_handle_bots() {
        $bot_detector = new CrawlGuard_Bot_Detector();
        $bot_detector->process_request();
    }
    
    public function activate() {
        // Create necessary database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Schedule analytics cleanup (daily at 2 AM)
        if (!wp_next_scheduled('crawlguard_cleanup_logs')) {
            wp_schedule_event(strtotime('tomorrow 2:00 AM'), 'daily', 'crawlguard_cleanup_logs');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Remove scheduled cleanup
        wp_clear_scheduled_hook('crawlguard_cleanup_logs');
        
        // Clean up if needed
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for storing bot detection logs
        $table_name = $wpdb->prefix . 'crawlguard_logs';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent text NOT NULL,
            bot_detected tinyint(1) DEFAULT 0 NOT NULL,
            bot_type varchar(50),
            action_taken varchar(20) DEFAULT 'allowed' NOT NULL,
            revenue_generated decimal(10,4) DEFAULT 0.00,
            http_headers text,
            fingerprint_hash varchar(128),
            rate_limited tinyint(1) DEFAULT 0 NOT NULL,
            ip_reputation text NULL,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // New additive table: per-IP/UA counters for rate limiting (non-destructive)
        $rl_table = $wpdb->prefix . 'crawlguard_rate_limits';
        $sql_rl = "CREATE TABLE $rl_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bucket varchar(190) NOT NULL,
            period varchar(20) NOT NULL,
            window_start datetime NOT NULL,
            count int unsigned NOT NULL DEFAULT 0,
            last_ip varchar(45),
            last_ua text,
            PRIMARY KEY (id),
            UNIQUE KEY bucket_period (bucket, period)
        ) $charset_collate;";
        dbDelta($sql_rl);

        // New additive table: registered public keys for HTTP Message Signatures
        $keys_table = $wpdb->prefix . 'crawlguard_signing_keys';
        $sql_keys = "CREATE TABLE $keys_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(190) NOT NULL,
            public_key text NOT NULL,
            algo varchar(50) DEFAULT 'rsa-v1_5-sha256',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) $charset_collate;";
        dbDelta($sql_keys);

        // New additive table: optional fingerprint logs (JA3/headers hash)
        $fp_table = $wpdb->prefix . 'crawlguard_fingerprints';
        $sql_fp = "CREATE TABLE $fp_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            ip varchar(45) NOT NULL,
            ua_hash varchar(64) NOT NULL,
            fp_hash varchar(128) NOT NULL,
            headers text,
            PRIMARY KEY (id),
            KEY ip (ip)
        ) $charset_collate;";
        dbDelta($sql_fp);
    }
    
    private function set_default_options() {
        // New: feature flags and safe defaults; all disabled by default to be non-intrusive
        $default_options = array(
            'api_key' => '',
            'api_key_valid' => false,
            'api_base_url' => 'https://paypercrawl.tech/api',
            'monetization_enabled' => false,
            'detection_sensitivity' => 'medium',
            'allowed_bots' => array('googlebot', 'bingbot'),
            'pricing_per_request' => 0.001,
            'feature_flags' => array(
                'enable_rate_limiting' => false,
                'enable_ip_intel' => false,
                'enable_fingerprinting_log' => false, // log-only, never blocks
                'enable_pow' => false, // proof-of-work (placeholder hooks)
                'enable_http_signatures' => false,
                'enable_privacy_pass' => false // placeholder only
            ),
            // Editable caps (used only if rate limiting enabled)
            'rate_limits' => array(
                'per_ip_per_min' => 120,
                'per_ip_per_hour' => 2000,
                'per_ua_per_min' => 240
            ),
            // Optional IP intelligence providers (log-only)
            'ip_intel' => array(
                'provider' => 'none', // 'none' | 'ipinfo' | 'maxmind'
                'ipinfo_token' => '',
                'maxmind_account' => ''
            ),
            // Live Sync settings (for Live RAG Tool API)
            'live_sync' => array(
                'enabled' => false,
                'woocommerce_enabled' => false,
                'last_event_at' => null,
                'event_count' => 0,
                'last_error' => null,
                'last_error_at' => null,
            )
        );
        
        // Force update the option to ensure clean slate on activation
        update_option('crawlguard_options', $default_options);
    }
}

// Initialize the plugin
CrawlGuardWP::get_instance();
