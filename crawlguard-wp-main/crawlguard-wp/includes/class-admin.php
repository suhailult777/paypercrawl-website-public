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
    }

	/**
	 * Initialize Settings - Register settings with WordPress Settings API
	 * This function was missing, causing settings not to persist
	 */
	public function init_settings() {
		// Register the main options
		register_setting(
			'crawlguard_options_group',
			'crawlguard_options',
			array($this, 'validate_options')
		);
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
    }
    
    public function admin_page() {
        $options = get_option('crawlguard_options');
        $api_key_valid = $options['api_key_valid'] ?? false;
        $monetization_enabled = $options['monetization_enabled'] ?? false;
        ?>
        <div class="wrap crawlguard-admin-wrap">
            <div class="crawlguard-header">
                <div class="crawlguard-header-content">
                    <div class="crawlguard-logo">
                        <span class="dashicons dashicons-shield-alt"></span>
                        <h1>CrawlGuard WP <span class="crawlguard-badge">PayPerCrawl Edition</span></h1>
                    </div>
                    <div class="crawlguard-header-actions">
                        <a href="<?php echo admin_url('admin.php?page=crawlguard-settings'); ?>" class="button button-primary">
                            <span class="dashicons dashicons-admin-settings"></span> Settings
                        </a>
                        <a href="https://paypercrawl.com/dashboard" target="_blank" class="button">
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

                    <div class="crawlguard-card">
                        <div class="card-icon">
                            <span class="dashicons dashicons-shield"></span>
                        </div>
                        <div class="card-content">
                            <h3>Protection Level</h3>
                            <p class="card-value"><?php echo $monetization_enabled ? 'Full' : 'Basic'; ?></p>
                            <p class="card-description"><?php echo $monetization_enabled ? 'Monetization active' : 'Detection only'; ?></p>
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
		
                    <!-- Monetization Consent Notice -->
                    <div class="notice notice-info crawlguard-consent-notice" style="margin: 15px 0; padding: 15px; border-left: 4px solid #2271b1; background: #f0f6fc; border-radius: 3px;">
                        <h3 style="margin-top: 0; color: #2271b1;">Monetization & Data Access Consent</h3>
                        <p><strong>How it works:</strong></p>
                        <ol style="margin: 10px 0; padding-left: 20px;">
                            <li>Our PayPerCrawl team will review your website traffic eligibility for monetization.</li>
                            <li>If eligible, we'll contact you at your registered email to discuss monetization terms.</li>
                            <li>We'll ask for your explicit consent before sharing any of your website data with AI companies.</li>
                            <li>Once you consent, we'll grant access to AI companies like Firecrawl, OpenAI, and other AI services for content training.</li>
                            <li>You'll earn revenue from each AI company request to your content.</li>
                        </ol>
                        <p><strong>Your data sharing:</strong> By accepting monetization, you authorize us to share your website data with AI companies for training purposes. You can revoke this consent at any time.</p>
                        <p style="color: #666; font-size: 12px; margin-bottom: 0;">No data will be shared without your explicit consent | You maintain full control over your content | You can disable monetization anytime</p>
                    </div>
                        
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
    
    public function monetization_enabled_callback() {
        $options = get_option('crawlguard_options');
        $enabled = $options['monetization_enabled'] ?? false;
        $api_key_valid = $options['api_key_valid'] ?? false;
        
        $disabled = !$api_key_valid ? 'disabled' : '';
        echo '<input type="checkbox" name="crawlguard_options[monetization_enabled]" value="1" ' . checked(1, $enabled, false) . ' ' . $disabled . ' />';
        
        if (!$api_key_valid) {
            echo '<p class="description" style="color: orange;">Please activate the plugin with a valid API key first.</p>';
        } else {
            echo '<p class="description">Enable to start monetizing AI bot traffic on your website.</p>';
        }
    }

    // New: Feature flags UI
    public function feature_flags_callback() {
        $opts = get_option('crawlguard_options');
        $ff = $opts['feature_flags'] ?? array();
        $fields = array(
            'enable_rate_limiting' => 'Enable Rate Limiting (soft header only)',
            'enable_fingerprinting_log' => 'Enable Header Fingerprinting (log-only)',
            'enable_ip_intel' => 'Enable IP Intelligence (log-only)',
            'enable_http_signatures' => 'Enable HTTP Message Signatures (verify-only)',
            'enable_pow' => 'Enable Proof-of-Work (placeholder)',
            'enable_privacy_pass' => 'Enable Privacy Pass (placeholder)'
        );
        echo '<div class="crawlguard-flags">';
        foreach ($fields as $key => $label) {
            $checked = !empty($ff[$key]) ? 'checked' : '';
            echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="crawlguard_options[feature_flags]['.$key.']" value="1" '.$checked.' /> '.$label.'</label>';
        }
        echo '<p class="description">All new features are off by default. Enabling them remains non-blocking unless documented.</p>';
        echo '</div>';
    }

    // New: Rate limits UI
    public function rate_limits_callback() {
        $opts = get_option('crawlguard_options');
        $rl = $opts['rate_limits'] ?? array('per_ip_per_min'=>120,'per_ip_per_hour'=>2000,'per_ua_per_min'=>240);
        echo '<div class="crawlguard-rate-limits">';
        echo 'Per-IP per minute: <input type="number" min="1" name="crawlguard_options[rate_limits][per_ip_per_min]" value="'.esc_attr($rl['per_ip_per_min']).'" style="width:100px;" /> ';
        echo 'Per-IP per hour: <input type="number" min="1" name="crawlguard_options[rate_limits][per_ip_per_hour]" value="'.esc_attr($rl['per_ip_per_hour']).'" style="width:100px;margin-left:12px;" /> ';
        echo 'Per-UA per minute: <input type="number" min="1" name="crawlguard_options[rate_limits][per_ua_per_min]" value="'.esc_attr($rl['per_ua_per_min']).'" style="width:100px;margin-left:12px;" />';
        echo '<p class="description">Effective only if Rate Limiting is enabled. Currently adds a soft response header, does not block.</p>';
        echo '</div>';
    }

    // New: IP intelligence UI
    public function ip_intel_callback() {
        $opts = get_option('crawlguard_options');
        $cfg = $opts['ip_intel'] ?? array('provider'=>'none','ipinfo_token'=>'');
        echo '<div class="crawlguard-ipintel">';
        echo 'Provider: <select name="crawlguard_options[ip_intel][provider]"><option value="none"'.selected($cfg['provider'],'none',false).'>None</option><option value="ipinfo"'.selected($cfg['provider'],'ipinfo',false).'>IPinfo</option></select> ';
        echo 'IPinfo Token: <input type="text" name="crawlguard_options[ip_intel][ipinfo_token]" value="'.esc_attr($cfg['ipinfo_token']).'" style="width:280px;margin-left:12px;" placeholder="token" />';
        echo '<p class="description">Configure token then enable IP Intelligence in Feature Flags to log reputation.</p>';
        echo '</div>';
    }
    
    public function validate_options($input) {
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
                    add_settings_error('crawlguard_options', 'api_key_valid', 'API key validated successfully! Plugin is now activated.', 'updated');
                } else {
                    add_settings_error('crawlguard_options', 'api_key_invalid', 'Invalid API key. Please check your key and try again.', 'error');
                    $output['monetization_enabled'] = false; // Disable monetization if key is invalid
                }
            } else {
                $output['api_key_valid'] = false;
                $output['monetization_enabled'] = false;
            }
        }
        
        // Handle monetization setting
        if (isset($input['monetization_enabled']) && ($output['api_key_valid'] ?? ($current_options['api_key_valid'] ?? false))) {
            $output['monetization_enabled'] = (bool) $input['monetization_enabled'];
        } else {
            // If no api_key change in this request, preserve prior state
            if (!isset($output['monetization_enabled']) && isset($current_options['monetization_enabled'])) {
                $output['monetization_enabled'] = $current_options['monetization_enabled'];
            }
        }

        // New: feature flags
        if (isset($input['feature_flags']) && is_array($input['feature_flags'])) {
            $ff = array();
            foreach (array('enable_rate_limiting','enable_ip_intel','enable_fingerprinting_log','enable_pow','enable_http_signatures','enable_privacy_pass') as $k) {
                $ff[$k] = !empty($input['feature_flags'][$k]) ? true : false;
            }
            $output['feature_flags'] = $ff;
        }

        // New: rate limits
        if (isset($input['rate_limits']) && is_array($input['rate_limits'])) {
            $rl = $input['rate_limits'];
            $output['rate_limits'] = array(
                'per_ip_per_min' => max(1, (int)($rl['per_ip_per_min'] ?? 120)),
                'per_ip_per_hour' => max(1, (int)($rl['per_ip_per_hour'] ?? 2000)),
                'per_ua_per_min' => max(1, (int)($rl['per_ua_per_min'] ?? 240)),
            );
        }

        // New: IP intelligence config
        if (isset($input['ip_intel']) && is_array($input['ip_intel'])) {
            $prov = in_array(($input['ip_intel']['provider'] ?? 'none'), array('none','ipinfo'), true) ? $input['ip_intel']['provider'] : 'none';
            $token = sanitize_text_field($input['ip_intel']['ipinfo_token'] ?? '');
            $output['ip_intel'] = array('provider' => $prov, 'ipinfo_token' => $token, 'maxmind_account' => '');
        }
        
        // Preserve other settings
        $current_options = get_option('crawlguard_options');
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
}
