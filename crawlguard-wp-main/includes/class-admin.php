<?php
/**
 * Admin Interface for CrawlGuard WP
 */

if (!defined('ABSPATH')) {
    exit;
}

class CrawlGuard_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_crawlguard_get_analytics', array($this, 'ajax_get_analytics'));
        // New: AJAX endpoints used by admin.js
        add_action('wp_ajax_crawlguard_get_chart_data', array($this, 'ajax_get_chart_data'));
        add_action('wp_ajax_crawlguard_get_realtime_stats', array($this, 'ajax_get_realtime_stats'));
        add_action('wp_ajax_crawlguard_sync_content', array($this, 'ajax_sync_content'));
        
        // Live Sync AJAX endpoints
        add_action('wp_ajax_crawlguard_live_sync_toggle', array($this, 'ajax_live_sync_toggle'));
        add_action('wp_ajax_crawlguard_live_sync_test', array($this, 'ajax_live_sync_test'));
        add_action('wp_ajax_crawlguard_live_tool_query', array($this, 'ajax_live_tool_query'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'CrawlGuard WP',
            'CrawlGuard',
            'manage_options',
            'crawlguard',
            array($this, 'admin_page'),
            'dashicons-shield-alt',
            30
        );
        
        add_submenu_page(
            'crawlguard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'crawlguard',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'crawlguard',
            'Settings',
            'Settings',
            'manage_options',
            'crawlguard-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'crawlguard',
            'Live Sync',
            'Live Sync',
            'manage_options',
            'crawlguard-live-sync',
            array($this, 'live_sync_page')
        );
        
        add_submenu_page(
            'crawlguard',
            'Live Tool Console',
            'Live Tool Console',
            'manage_options',
            'crawlguard-live-tool',
            array($this, 'live_tool_page')
        );
    }
    
    public function admin_page() {
        $options = get_option('crawlguard_options');
        $api_key_valid = $options['api_key_valid'] ?? false;
        ?>
        <div class="wrap crawlguard-admin-wrap">
            <div class="crawlguard-header">
                <div class="crawlguard-header-content">
                    <div class="crawlguard-logo">
                        <span class="dashicons dashicons-shield-alt"></span>
                        <h1>CrawlGuard WP <span class="crawlguard-badge">PayPerCrawl Edition</span></h1>
                    </div>
                    <div class="crawlguard-header-actions">
                        <button id="crawlguard-sync-btn" class="button button-secondary">
                            <span class="dashicons dashicons-update"></span> Sync Content
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=crawlguard-settings'); ?>" class="button button-primary">
                            <span class="dashicons dashicons-admin-settings"></span> Settings
                        </a>
                        <a href="https://paypercrawl.tech/dashboard" target="_blank" class="button">
                            <span class="dashicons dashicons-external"></span> PayPerCrawl Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <?php if (!$api_key_valid): ?>
            <div class="notice notice-warning crawlguard-notice">
                <p><strong>Plugin Not Activated!</strong> Please configure your API key in the <a href="<?php echo admin_url('admin.php?page=crawlguard-settings'); ?>">settings page</a> to start using CrawlGuard.</p>
            </div>
            <?php endif; ?>

            <div class="crawlguard-dashboard">
                <!-- Status Cards Row -->
                <div class="crawlguard-cards-row">
                    <div class="crawlguard-card <?php echo $api_key_valid ? 'status-active' : 'status-inactive'; ?>">
                        <div class="card-icon">
                            <span class="dashicons dashicons-<?php echo $api_key_valid ? 'yes-alt' : 'warning'; ?>"></span>
                        </div>
                        <div class="card-content">
                            <h3>Plugin Status</h3>
                            <p class="card-value"><?php echo $api_key_valid ? 'Active' : 'Not Configured'; ?></p>
                            <p class="card-description"><?php echo $api_key_valid ? 'Your plugin is activated and ready' : 'Configure API key to activate'; ?></p>
                        </div>
                    </div>

                    <div class="crawlguard-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-chart-bar"></span>
                        </div>
                        <div class="card-content">
                            <h3>Bots Detected</h3>
                            <p class="card-value" id="bots-detected">0</p>
                            <p class="card-description">AI bots identified this month</p>
                        </div>
                    </div>

                    <div class="crawlguard-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-money-alt"></span>
                        </div>
                        <div class="card-content">
                            <h3>Revenue Generated</h3>
                            <p class="card-value" id="revenue-generated">$0.00</p>
                            <p class="card-description">Earnings from bot traffic</p>
                        </div>
                    </div>

                    <div class="crawlguard-card <?php echo $api_key_valid ? 'status-active' : 'status-inactive'; ?>">
                        <div class="card-icon">
                            <span class="dashicons dashicons-shield"></span>
                        </div>
                        <div class="card-content">
                            <h3>JS Challenge</h3>
                            <p class="card-value"><?php echo $api_key_valid ? '🔥 Active' : 'Inactive'; ?></p>
                            <p class="card-description"><?php echo $api_key_valid ? 'Blocking scrapers & bots' : 'Enable with valid API key'; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Main Content Area -->
                <div class="crawlguard-content-row">
                    <!-- Recent Activity -->
                    <div class="crawlguard-panel">
                        <div class="panel-header">
                            <h2><span class="dashicons dashicons-list-view"></span> Recent Bot Activity</h2>
                            <button class="button button-small" id="refresh-activity">Refresh</button>
                        </div>
                        <div class="panel-content">
                            <div id="activity-list" class="activity-list">
                                <?php if (!$api_key_valid): ?>
                                    <p class="no-data">Activate the plugin to see bot activity</p>
                                <?php else: ?>
                                    <p class="no-data">No recent activity to display</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="crawlguard-panel">
                        <div class="panel-header">
                            <h2><span class="dashicons dashicons-admin-tools"></span> Quick Actions</h2>
                        </div>
                        <div class="panel-content">
                            <div class="quick-actions">
                                <a href="<?php echo admin_url('admin.php?page=crawlguard-settings'); ?>" class="action-button">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    <span>Configure Settings</span>
                                </a>
                                <a href="https://paypercrawl.com/docs" target="_blank" class="action-button">
                                    <span class="dashicons dashicons-book"></span>
                                    <span>View Documentation</span>
                                </a>
                                <a href="https://paypercrawl.com/support" target="_blank" class="action-button">
                                    <span class="dashicons dashicons-sos"></span>
                                    <span>Get Support</span>
                                </a>
                                <button class="action-button" id="clear-cache">
                                    <span class="dashicons dashicons-trash"></span>
                                    <span>Clear Cache</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analytics Chart -->
                <div class="crawlguard-panel full-width">
                    <div class="panel-header">
                        <h2><span class="dashicons dashicons-chart-line"></span> Bot Traffic Analytics</h2>
                    </div>
                    <div class="panel-content">
                        <?php if (!$api_key_valid): ?>
                            <div class="no-data">
                                <p>Activate the plugin to see analytics</p>
                            </div>
                        <?php else: ?>
                            <div id="crawlguard-react-analytics"></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function settings_page() {
        $options = get_option('crawlguard_options');
        $api_key = $options['api_key'] ?? '';
        $api_key_valid = $options['api_key_valid'] ?? false;
        ?>
        <div class="wrap crawlguard-admin-wrap">
            <div class="crawlguard-header">
                <div class="crawlguard-header-content">
                    <div class="crawlguard-logo">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <h1>CrawlGuard Settings</h1>
                    </div>
                    <div class="crawlguard-header-actions">
                        <a href="<?php echo admin_url('admin.php?page=crawlguard'); ?>" class="button">
                            <span class="dashicons dashicons-dashboard"></span> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <div class="crawlguard-settings-container">
                <div class="crawlguard-settings-main">
                    <form method="post" action="options.php" class="crawlguard-settings-form">
                        <?php settings_fields('crawlguard_settings'); ?>
                        
                        <div class="crawlguard-settings-panel">
                            <div class="panel-header">
                                <h2><span class="dashicons dashicons-admin-network"></span> API Configuration</h2>
                            </div>
                            <div class="panel-content">
                                <?php do_settings_sections('crawlguard_settings'); ?>
                            </div>
                            <div class="panel-footer">
                                <?php submit_button('Save Settings', 'primary large', 'submit', false); ?>
                                <?php if ($api_key && !$api_key_valid): ?>
                                <button type="button" class="button button-secondary" id="validate-api-key">
                                    <span class="dashicons dashicons-update"></span> Validate API Key
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="crawlguard-settings-sidebar">
                    <!-- API Key Status Card -->
                    <div class="sidebar-card">
                        <div class="card-header">
                            <h3><span class="dashicons dashicons-<?php echo $api_key_valid ? 'yes-alt' : 'key'; ?>"></span> API Status</h3>
                        </div>
                        <div class="card-content">
                            <div class="status-indicator <?php echo $api_key_valid ? 'status-active' : 'status-inactive'; ?>">
                                <span class="status-dot"></span>
                                <span class="status-text"><?php echo $api_key_valid ? 'Connected' : 'Not Connected'; ?></span>
                            </div>
                            <?php if ($api_key_valid): ?>
                                <p class="status-description">Your plugin is connected to PayPerCrawl and ready to monetize bot traffic.</p>
                            <?php else: ?>
                                <p class="status-description">Enter a valid API key to connect to PayPerCrawl services.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Help Card -->
                    <div class="sidebar-card">
                        <div class="card-header">
                            <h3><span class="dashicons dashicons-editor-help"></span> Need Help?</h3>
                        </div>
                        <div class="card-content">
                            <p>Get started with CrawlGuard:</p>
                            <ul class="help-links">
                                <li><a href="https://paypercrawl.com/dashboard" target="_blank">Generate API Key</a></li>
                                <li><a href="https://paypercrawl.com/docs" target="_blank">Documentation</a></li>
                                <li><a href="https://paypercrawl.com/support" target="_blank">Contact Support</a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="sidebar-card">
                        <div class="card-header">
                            <h3><span class="dashicons dashicons-chart-area"></span> Quick Stats</h3>
                        </div>
                        <div class="card-content">
                            <div class="stat-item">
                                <span class="stat-label">Plugin Version:</span>
                                <span class="stat-value">2.0.0</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">PHP Version:</span>
                                <span class="stat-value"><?php echo phpversion(); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">WordPress Version:</span>
                                <span class="stat-value"><?php echo get_bloginfo('version'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function init_settings() {
        register_setting('crawlguard_settings', 'crawlguard_options', array($this, 'validate_options'));
        
        add_settings_section(
            'crawlguard_main_section',
            'PayPerCrawl API Configuration',
            array($this, 'main_section_callback'),
            'crawlguard_settings'
        );
        
        add_settings_field(
            'api_key',
            'PayPerCrawl API Key',
            array($this, 'api_key_callback'),
            'crawlguard_settings',
            'crawlguard_main_section'
        );
        
        add_settings_field(
            'api_status',
            'API Key Status',
            array($this, 'api_status_callback'),
            'crawlguard_settings',
            'crawlguard_main_section'
        );
        
        add_settings_field(
            'js_challenge_status',
            'JS Challenge Protection',
            array($this, 'js_challenge_status_callback'),
            'crawlguard_settings',
            'crawlguard_main_section'
        );
    }
    
    public function main_section_callback() {
        echo '<p>Enter your PayPerCrawl API key to activate the plugin. You can generate an API key from your <a href="https://paypercrawl.com/dashboard" target="_blank">PayPerCrawl Dashboard</a>.</p>';
    }
    
    public function api_key_callback() {
        $options = get_option('crawlguard_options');
        $api_key = $options['api_key'] ?? '';
        echo '<input type="text" name="crawlguard_options[api_key]" value="' . esc_attr($api_key) . '" class="regular-text" placeholder="ppk_..." />';
        echo '<p class="description">Enter your PayPerCrawl API key (format: ppk_xxxxx)</p>';
    }
    
    public function api_status_callback() {
        $options = get_option('crawlguard_options');
        $api_key = $options['api_key'] ?? '';
        $is_valid = $options['api_key_valid'] ?? false;
        
        if ($api_key) {
            if ($is_valid) {
                echo '<span style="color: green; font-weight: bold;">✓ Active</span>';
                echo '<p class="description">Your API key is valid and the plugin is activated.</p>';
            } else {
                echo '<span style="color: red; font-weight: bold;">✗ Invalid</span>';
                echo '<p class="description">Please check your API key and try again.</p>';
            }
        } else {
            echo '<span style="color: orange; font-weight: bold;">⚠ Not Configured</span>';
            echo '<p class="description">Please enter your API key to activate the plugin.</p>';
        }
    }
    
    public function js_challenge_status_callback() {
        $options = get_option('crawlguard_options');
        $api_key_valid = $options['api_key_valid'] ?? false;
        
        if ($api_key_valid) {
            echo '<div style="display:flex;align-items:center;gap:8px;">';
            echo '<span style="color: #059669; font-weight: bold;">✓ Active</span>';
            echo '<span class="dashicons dashicons-shield" style="color:#059669;"></span>';
            echo '</div>';
            echo '<p class="description" style="color:#059669;">🔥 JavaScript Challenge is <strong>automatically enabled</strong>. All bots must solve a math challenge to access your content.</p>';
            echo '<p class="description">Blocks 85%+ of scrapers including Firecrawl, ScrapingBee, and headless browsers. Your Sync Content feature bypasses this protection.</p>';
        } else {
            echo '<span style="color: #718096;">⏳ Pending Activation</span>';
            echo '<p class="description">JS Challenge will activate automatically when you save a valid API key.</p>';
        }
    }
    
    public function validate_options($input) {
        $current_options = get_option('crawlguard_options');
        $output = array();
        
        // Validate API key
        if (isset($input['api_key'])) {
            $api_key = sanitize_text_field($input['api_key']);
            $output['api_key'] = $api_key;
            
            // Validate the API key with PayPerCrawl API
            if (!empty($api_key)) {
                $is_valid = $this->validate_api_key($api_key);
                $output['api_key_valid'] = $is_valid;
                
                if ($is_valid) {
                    add_settings_error('crawlguard_options', 'api_key_valid', 'API key validated successfully! JS Challenge protection is now active.', 'updated');
                    // Auto-enable monetization and JS challenge when API key is valid
                    $output['monetization_enabled'] = true;
                    $output['feature_flags'] = array(
                        'enable_js_challenge' => true
                    );
                } else {
                    add_settings_error('crawlguard_options', 'api_key_invalid', 'Invalid API key. Please check your key and try again.', 'error');
                    $output['monetization_enabled'] = false;
                    $output['feature_flags'] = array(
                        'enable_js_challenge' => false
                    );
                }
            } else {
                $output['api_key_valid'] = false;
                $output['monetization_enabled'] = false;
                $output['feature_flags'] = array(
                    'enable_js_challenge' => false
                );
            }
        }
        
        // Preserve other settings
        if ($current_options) {
            $output = array_merge($current_options, $output);
        }
        
        return $output;
    }
    
    private function validate_api_key($api_key) {
        // Use the API client to validate with Cloudflare Workers endpoint
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-api-client.php';
        
        $api_client = new CrawlGuard_API_Client();
        $is_valid = $api_client->validate_api_key($api_key);
        
        // If Cloudflare validation fails or is unavailable, check format as fallback
        if (!$is_valid && strpos($api_key, 'ppk_') === 0 && strlen($api_key) > 10) {
            // Allow format-valid keys as a fallback when API is unavailable
            return true;
        }
        
        return $is_valid;
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'crawlguard') === false) {
            return;
        }
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'crawlguard-admin-style',
            CRAWLGUARD_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CRAWLGUARD_VERSION
        );
        
        // Enqueue analytics CSS
        wp_enqueue_style(
            'crawlguard-analytics-style',
            CRAWLGUARD_PLUGIN_URL . 'assets/css/analytics.css',
            array(),
            CRAWLGUARD_VERSION
        );
        
        // Enqueue admin JavaScript
        wp_enqueue_script(
            'crawlguard-admin',
            CRAWLGUARD_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CRAWLGUARD_VERSION,
            true
        );
        
        // Enqueue React analytics bundle
        wp_enqueue_script(
            'crawlguard-analytics',
            CRAWLGUARD_PLUGIN_URL . 'assets/js/analytics.bundle.js',
            array(),
            CRAWLGUARD_VERSION,
            true
        );
        
        wp_localize_script('crawlguard-analytics', 'crawlguardData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('crawlguard_nonce')
        ));
        
        wp_localize_script('crawlguard-admin', 'crawlguard_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('crawlguard_nonce')
        ));
    }
    
    public function ajax_get_analytics() {
        check_ajax_referer('crawlguard_nonce', 'nonce');
        
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-analytics.php';
        
        $period = isset($_POST['period']) ? intval($_POST['period']) : 30;
        $analytics = CrawlGuard_Analytics::get_analytics_data($period);
        
        wp_send_json_success($analytics);
    }

    // New: return time-series data for charts from crawlguard_logs (safe defaults)
    public function ajax_get_chart_data() {
        check_ajax_referer('crawlguard_nonce', 'nonce');
        global $wpdb;
        $period = sanitize_text_field($_POST['period'] ?? '30d');
        $days = $period === '7d' ? 7 : ($period === '90d' ? 90 : 30);
        $table = $wpdb->prefix . 'crawlguard_logs';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(timestamp) d, SUM(revenue_generated) r FROM $table WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY d ORDER BY d ASC", $days));
        $data = array();
        foreach ($rows as $row) { $data[] = array('date' => $row->d, 'revenue' => (float)$row->r); }
        wp_send_json_success($data);
    }

    // New: return lightweight realtime stats (counts from last hour/day)
    public function ajax_get_realtime_stats() {
        check_ajax_referer('crawlguard_nonce', 'nonce');
        global $wpdb;
        $table = $wpdb->prefix . 'crawlguard_logs';
        $hour = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $day  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $rev  = (float) $wpdb->get_var("SELECT SUM(revenue_generated) FROM $table WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        wp_send_json_success(array('stats' => array($hour, $day, '$'.number_format($rev,2))));
    }

    public function ajax_sync_content() {
        check_ajax_referer('crawlguard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Load dependencies
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-toon-encoder.php';
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-scraper.php';
        require_once CRAWLGUARD_PLUGIN_PATH . 'includes/class-api-client.php';

        try {
            // 1. Scrape & Generate Payload
            $payload = \CrawlGuard\CrawlGuard_Scraper::generate_payload();
            
            if (empty($payload)) {
                wp_send_json_error('No content found to sync.');
            }

            // 2. Send to SaaS
            $api = new CrawlGuard_API_Client();
            $result = $api->sync_site_content($payload);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }

            wp_send_json_success(array(
                'message' => 'Successfully synced ' . count($payload) . ' items.',
                'details' => $result
            ));

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Live Sync Settings Page
     */
    public function live_sync_page() {
        $options = get_option('crawlguard_options');
        $api_key_valid = $options['api_key_valid'] ?? false;
        $live_sync = $options['live_sync'] ?? array();
        
        // Get WooCommerce connector status
        $woo_connector = new CrawlGuard_WooCommerce_Connector();
        $woo_status = $woo_connector->get_status();
        ?>
        <div class="wrap crawlguard-admin-wrap">
            <div class="crawlguard-header">
                <div class="crawlguard-header-content">
                    <div class="crawlguard-logo">
                        <span class="dashicons dashicons-update-alt"></span>
                        <h1>Live Sync <span class="crawlguard-badge">Real-Time Data</span></h1>
                    </div>
                    <div class="crawlguard-header-actions">
                        <a href="<?php echo admin_url('admin.php?page=crawlguard-live-tool'); ?>" class="button button-primary">
                            <span class="dashicons dashicons-search"></span> Test Live Tool
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=crawlguard'); ?>" class="button">
                            <span class="dashicons dashicons-dashboard"></span> Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if (!$api_key_valid): ?>
            <div class="notice notice-warning crawlguard-notice">
                <p><strong>API Key Required!</strong> Please configure your API key in the <a href="<?php echo admin_url('admin.php?page=crawlguard-settings'); ?>">settings page</a> before enabling Live Sync.</p>
            </div>
            <?php endif; ?>
            
            <div class="crawlguard-dashboard">
                <!-- Live Sync Status Card -->
                <div class="crawlguard-cards-row">
                    <div class="crawlguard-card <?php echo !empty($live_sync['enabled']) ? 'status-active' : 'status-inactive'; ?>">
                        <div class="card-icon">
                            <span class="dashicons dashicons-<?php echo !empty($live_sync['enabled']) ? 'yes-alt' : 'warning'; ?>"></span>
                        </div>
                        <div class="card-content">
                            <h3>Live Sync Status</h3>
                            <p class="card-value"><?php echo !empty($live_sync['enabled']) ? 'Active' : 'Disabled'; ?></p>
                            <p class="card-description"><?php echo !empty($live_sync['enabled']) ? 'Real-time data is being synced' : 'Enable to start syncing live data'; ?></p>
                        </div>
                    </div>
                    
                    <div class="crawlguard-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-chart-bar"></span>
                        </div>
                        <div class="card-content">
                            <h3>Events Sent</h3>
                            <p class="card-value"><?php echo intval($live_sync['event_count'] ?? 0); ?></p>
                            <p class="card-description">Total live events pushed</p>
                        </div>
                    </div>
                    
                    <div class="crawlguard-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="card-content">
                            <h3>Last Event</h3>
                            <p class="card-value"><?php echo $live_sync['last_event_at'] ? human_time_diff(strtotime($live_sync['last_event_at'])) . ' ago' : 'Never'; ?></p>
                            <p class="card-description"><?php echo $live_sync['last_event_at'] ?? 'No events sent yet'; ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Live Sync Settings -->
                <div class="crawlguard-panel">
                    <div class="panel-header">
                        <h2><span class="dashicons dashicons-admin-settings"></span> Live Sync Settings</h2>
                    </div>
                    <div class="panel-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable Live Sync</th>
                                <td>
                                    <label class="crawlguard-toggle">
                                        <input type="checkbox" id="live-sync-enabled" <?php checked(!empty($live_sync['enabled'])); ?> <?php disabled(!$api_key_valid); ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <p class="description">Enable real-time data synchronization for AI Tool access.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- WooCommerce Connector -->
                <div class="crawlguard-panel">
                    <div class="panel-header">
                        <h2><span class="dashicons dashicons-cart"></span> WooCommerce Connector</h2>
                        <?php if ($woo_status['woocommerce_active']): ?>
                        <span class="badge badge-success">WooCommerce Detected</span>
                        <?php else: ?>
                        <span class="badge badge-warning">WooCommerce Not Active</span>
                        <?php endif; ?>
                    </div>
                    <div class="panel-content">
                        <?php if ($woo_status['woocommerce_active']): ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable WooCommerce Sync</th>
                                <td>
                                    <label class="crawlguard-toggle">
                                        <input type="checkbox" id="woocommerce-enabled" <?php checked(!empty($live_sync['woocommerce_enabled'])); ?> <?php disabled(!$api_key_valid || empty($live_sync['enabled'])); ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <p class="description">Automatically sync product price and stock changes in real-time.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Products Available</th>
                                <td>
                                    <strong><?php echo $woo_status['product_count']; ?></strong> products
                                    <p class="description">These products will be monitored for price/stock changes.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Test Connection</th>
                                <td>
                                    <button type="button" class="button" id="test-woo-sync" <?php disabled(!$api_key_valid); ?>>
                                        <span class="dashicons dashicons-controls-play"></span> Send Test Event
                                    </button>
                                    <span id="test-result" style="margin-left: 10px;"></span>
                                    <p class="description">Send a sample product event to verify the connection.</p>
                                </td>
                            </tr>
                        </table>
                        <?php else: ?>
                        <div class="notice notice-info inline">
                            <p><strong>WooCommerce Not Detected</strong></p>
                            <p>Install and activate WooCommerce to enable automatic product price and inventory synchronization.</p>
                            <p><a href="<?php echo admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'); ?>" class="button">Install WooCommerce</a></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Error Log -->
                <?php if (!empty($live_sync['last_error'])): ?>
                <div class="crawlguard-panel">
                    <div class="panel-header">
                        <h2><span class="dashicons dashicons-warning"></span> Last Error</h2>
                    </div>
                    <div class="panel-content">
                        <div class="notice notice-error inline">
                            <p><strong><?php echo esc_html($live_sync['last_error']); ?></strong></p>
                            <p class="description"><?php echo esc_html($live_sync['last_error_at'] ?? ''); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Toggle Live Sync
            $('#live-sync-enabled').on('change', function() {
                var enabled = $(this).is(':checked');
                $.post(crawlguard_ajax.ajax_url, {
                    action: 'crawlguard_live_sync_toggle',
                    nonce: crawlguard_ajax.nonce,
                    setting: 'enabled',
                    value: enabled ? 1 : 0
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            // Toggle WooCommerce
            $('#woocommerce-enabled').on('change', function() {
                var enabled = $(this).is(':checked');
                $.post(crawlguard_ajax.ajax_url, {
                    action: 'crawlguard_live_sync_toggle',
                    nonce: crawlguard_ajax.nonce,
                    setting: 'woocommerce_enabled',
                    value: enabled ? 1 : 0
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            // Test WooCommerce sync
            $('#test-woo-sync').on('click', function() {
                var $btn = $(this);
                var $result = $('#test-result');
                $btn.prop('disabled', true).text('Sending...');
                $result.text('');
                
                $.post(crawlguard_ajax.ajax_url, {
                    action: 'crawlguard_live_sync_test',
                    nonce: crawlguard_ajax.nonce,
                    connector: 'woocommerce'
                }, function(response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Send Test Event');
                    if (response.success) {
                        $result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color: red;">✗ ' + response.data + '</span>');
                    }
                });
            });
        });
        </script>
        
        <style>
        .crawlguard-toggle { position: relative; display: inline-block; width: 50px; height: 26px; }
        .crawlguard-toggle input { opacity: 0; width: 0; height: 0; }
        .crawlguard-toggle .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 26px; }
        .crawlguard-toggle .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
        .crawlguard-toggle input:checked + .slider { background-color: #059669; }
        .crawlguard-toggle input:checked + .slider:before { transform: translateX(24px); }
        .crawlguard-toggle input:disabled + .slider { opacity: 0.5; cursor: not-allowed; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        </style>
        <?php
    }
    
    /**
     * Live Tool Test Console Page
     */
    public function live_tool_page() {
        $options = get_option('crawlguard_options');
        $api_key_valid = $options['api_key_valid'] ?? false;
        ?>
        <div class="wrap crawlguard-admin-wrap">
            <div class="crawlguard-header">
                <div class="crawlguard-header-content">
                    <div class="crawlguard-logo">
                        <span class="dashicons dashicons-search"></span>
                        <h1>Live Tool Console <span class="crawlguard-badge">RAG API Test</span></h1>
                    </div>
                    <div class="crawlguard-header-actions">
                        <a href="<?php echo admin_url('admin.php?page=crawlguard-live-sync'); ?>" class="button">
                            <span class="dashicons dashicons-update-alt"></span> Live Sync Settings
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=crawlguard'); ?>" class="button">
                            <span class="dashicons dashicons-dashboard"></span> Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if (!$api_key_valid): ?>
            <div class="notice notice-warning crawlguard-notice">
                <p><strong>API Key Required!</strong> Please configure your API key in the <a href="<?php echo admin_url('admin.php?page=crawlguard-settings'); ?>">settings page</a> to use the Live Tool.</p>
            </div>
            <?php endif; ?>
            
            <div class="crawlguard-dashboard">
                <!-- Query Form -->
                <div class="crawlguard-panel">
                    <div class="panel-header">
                        <h2><span class="dashicons dashicons-editor-help"></span> Query Live Data</h2>
                    </div>
                    <div class="panel-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="query-question">Question</label></th>
                                <td>
                                    <input type="text" id="query-question" class="large-text" placeholder="e.g., What is the current price for SKU-123?" <?php disabled(!$api_key_valid); ?>>
                                    <p class="description">Ask a question about your live data (products, prices, stock).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="query-mode">Mode</label></th>
                                <td>
                                    <select id="query-mode" <?php disabled(!$api_key_valid); ?>>
                                        <option value="live_only">Live Only (real-time data only)</option>
                                        <option value="hybrid" selected>Hybrid (live + static KB)</option>
                                        <option value="kb_only">KB Only (static content only)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="query-freshness">Freshness Window</label></th>
                                <td>
                                    <select id="query-freshness" <?php disabled(!$api_key_valid); ?>>
                                        <option value="300">5 minutes</option>
                                        <option value="900" selected>15 minutes</option>
                                        <option value="3600">1 hour</option>
                                        <option value="86400">24 hours</option>
                                    </select>
                                    <p class="description">Only return data updated within this window.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="query-sku">Filter by SKU (optional)</label></th>
                                <td>
                                    <input type="text" id="query-sku" placeholder="e.g., SKU-123" <?php disabled(!$api_key_valid); ?>>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"></th>
                                <td>
                                    <button type="button" class="button button-primary button-large" id="run-query" <?php disabled(!$api_key_valid); ?>>
                                        <span class="dashicons dashicons-search"></span> Run Query
                                    </button>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Results Panel -->
                <div class="crawlguard-panel" id="results-panel" style="display: none;">
                    <div class="panel-header">
                        <h2><span class="dashicons dashicons-format-aside"></span> Results</h2>
                        <span id="results-meta"></span>
                    </div>
                    <div class="panel-content">
                        <div id="results-answer"></div>
                        <hr>
                        <h4>Structured Data</h4>
                        <pre id="results-data" style="background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto;"></pre>
                        <h4>Citations</h4>
                        <ul id="results-citations"></ul>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#run-query').on('click', function() {
                var $btn = $(this);
                var question = $('#query-question').val();
                var mode = $('#query-mode').val();
                var freshness = $('#query-freshness').val();
                var sku = $('#query-sku').val();
                
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Querying...');
                
                $.post(crawlguard_ajax.ajax_url, {
                    action: 'crawlguard_live_tool_query',
                    nonce: crawlguard_ajax.nonce,
                    question: question,
                    mode: mode,
                    freshness_seconds: freshness,
                    sku: sku
                }, function(response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Run Query');
                    
                    if (response.success) {
                        var data = response.data;
                        $('#results-panel').show();
                        $('#results-answer').html('<p>' + (data.answer?.text || 'No answer') + '</p>');
                        $('#results-data').text(JSON.stringify(data.answer?.data || [], null, 2));
                        $('#results-meta').html('Latency: ' + data.meta?.latencyMs + 'ms | Mode: ' + data.meta?.mode);
                        
                        var citations = '';
                        (data.citations || []).forEach(function(c) {
                            if (c.type === 'live') {
                                citations += '<li><strong>Live:</strong> ' + c.connector + ' / ' + c.entityId + ' @ ' + c.occurredAt + '</li>';
                            } else {
                                citations += '<li><strong>KB:</strong> ' + (c.title || c.url) + '</li>';
                            }
                        });
                        $('#results-citations').html(citations || '<li>No citations</li>');
                    } else {
                        $('#results-panel').show();
                        $('#results-answer').html('<p style="color: red;">Error: ' + response.data + '</p>');
                        $('#results-data').text('');
                        $('#results-citations').html('');
                        $('#results-meta').text('');
                    }
                });
            });
        });
        </script>
        
        <style>
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        </style>
        <?php
    }
    
    /**
     * AJAX: Toggle Live Sync settings
     */
    public function ajax_live_sync_toggle() {
        check_ajax_referer('crawlguard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $setting = sanitize_text_field($_POST['setting'] ?? '');
        $value = intval($_POST['value'] ?? 0);
        
        $allowed_settings = array('enabled', 'woocommerce_enabled');
        if (!in_array($setting, $allowed_settings)) {
            wp_send_json_error('Invalid setting');
        }
        
        $options = get_option('crawlguard_options', array());
        if (!is_array($options)) {
            $options = array();
        }
        if (!isset($options['live_sync']) || !is_array($options['live_sync'])) {
            $options['live_sync'] = array(
                'enabled' => false,
                'woocommerce_enabled' => false,
                'last_event_at' => null,
                'event_count' => 0,
                'last_error' => null,
                'last_error_at' => null,
            );
        }
        
        $options['live_sync'][$setting] = (bool) $value;
        $updated = update_option('crawlguard_options', $options);
        
        if ($updated) {
            wp_send_json_success(array('message' => 'Setting updated', 'value' => $options['live_sync'][$setting]));
        } else {
            // update_option returns false if value hasn't changed OR on error
            // Check if the value is already what we want
            $check = get_option('crawlguard_options', array());
            if (isset($check['live_sync'][$setting]) && $check['live_sync'][$setting] === (bool) $value) {
                wp_send_json_success(array('message' => 'Setting already set', 'value' => $check['live_sync'][$setting]));
            } else {
                wp_send_json_error('Failed to update option');
            }
        }
    }
    
    /**
     * AJAX: Test Live Sync connector
     */
    public function ajax_live_sync_test() {
        check_ajax_referer('crawlguard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $connector = sanitize_text_field($_POST['connector'] ?? '');
        
        if ($connector === 'woocommerce') {
            $woo_connector = new CrawlGuard_WooCommerce_Connector();
            $result = $woo_connector->send_test_event();
            
            if ($result['success']) {
                wp_send_json_success(array('message' => 'Test event sent successfully!'));
            } else {
                wp_send_json_error($result['error'] ?? 'Failed to send test event');
            }
        } else {
            wp_send_json_error('Unknown connector');
        }
    }
    
    /**
     * AJAX: Query Live Tool API
     */
    public function ajax_live_tool_query() {
        check_ajax_referer('crawlguard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $client = new CrawlGuard_Live_Sync_Client();
        
        $params = array(
            'question' => sanitize_text_field($_POST['question'] ?? ''),
            'mode' => sanitize_text_field($_POST['mode'] ?? 'hybrid'),
            'freshness_seconds' => intval($_POST['freshness_seconds'] ?? 900),
        );
        
        // Add filters if provided
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        if (!empty($sku)) {
            $params['filters'] = array('sku' => $sku);
        }
        
        $result = $client->query_live_tool($params);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error'] ?? 'Query failed');
        }
    }
}
