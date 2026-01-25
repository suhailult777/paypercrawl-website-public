<?php
/**
 * WooCommerce Live Sync Connector
 * 
 * Listens to WooCommerce product/stock changes and pushes live events
 * to the PayPerCrawl backend for real-time AI tool access.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CrawlGuard_WooCommerce_Connector {
    
    private $api_client;
    private $enabled = false;
    
    public function __construct() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            return;
        }
        
        // Check if Live Sync is enabled
        $options = get_option('crawlguard_options');
        $this->enabled = !empty($options['live_sync']['enabled']) && !empty($options['live_sync']['woocommerce_enabled']);
        
        if (!$this->enabled) {
            return;
        }
        
        // Load API client
        $this->api_client = new CrawlGuard_Live_Sync_Client();
        
        // Register hooks
        $this->register_hooks();
    }
    
    /**
     * Check if WooCommerce is active
     */
    public function is_woocommerce_active() {
        return class_exists('WooCommerce') || in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
    }
    
    /**
     * Register WooCommerce hooks for live sync
     */
    private function register_hooks() {
        // Product save/update (catches price changes)
        add_action('woocommerce_update_product', array($this, 'on_product_updated'), 10, 2);
        add_action('woocommerce_new_product', array($this, 'on_product_updated'), 10, 2);
        
        // Stock changes
        add_action('woocommerce_product_set_stock', array($this, 'on_stock_changed'), 10, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'on_stock_changed'), 10, 1);
        
        // Stock status changes
        add_action('woocommerce_product_set_stock_status', array($this, 'on_stock_status_changed'), 10, 3);
        
        // Low stock threshold
        add_action('woocommerce_low_stock', array($this, 'on_low_stock'), 10, 1);
        add_action('woocommerce_no_stock', array($this, 'on_no_stock'), 10, 1);
    }
    
    /**
     * Handle product update (price changes, general updates)
     */
    public function on_product_updated($product_id, $product = null) {
        if (!$product) {
            $product = wc_get_product($product_id);
        }
        
        if (!$product) {
            return;
        }
        
        $this->send_product_event($product, 'product.updated');
    }
    
    /**
     * Handle stock quantity change
     */
    public function on_stock_changed($product) {
        if (!$product) {
            return;
        }
        
        $this->send_product_event($product, 'product.stock.updated');
    }
    
    /**
     * Handle stock status change
     */
    public function on_stock_status_changed($product_id, $stock_status, $product) {
        if (!$product) {
            $product = wc_get_product($product_id);
        }
        
        if (!$product) {
            return;
        }
        
        $this->send_product_event($product, 'product.stock_status.updated');
    }
    
    /**
     * Handle low stock notification
     */
    public function on_low_stock($product) {
        $this->send_product_event($product, 'product.stock.low');
    }
    
    /**
     * Handle out of stock notification
     */
    public function on_no_stock($product) {
        $this->send_product_event($product, 'product.stock.out');
    }
    
    /**
     * Build and send product event to backend
     */
    private function send_product_event($product, $event_type) {
        if (!$this->enabled || !$product) {
            return;
        }
        
        // Build event payload
        $event = $this->build_product_event($product, $event_type);
        
        if (!$event) {
            return;
        }
        
        // Send asynchronously if possible, otherwise sync
        $this->api_client->send_live_event($event);
    }
    
    /**
     * Build product event payload matching the API schema
     */
    private function build_product_event($product, $event_type) {
        $product_id = $product->get_id();
        $sku = $product->get_sku();
        
        // Generate idempotency key: product_id + event_type + timestamp (minute precision for dedup)
        $event_id = md5($product_id . '_' . $event_type . '_' . gmdate('Y-m-d\TH:i'));
        
        // Get pricing
        $regular_price = (float) $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $effective_price = (float) $product->get_price();
        
        // Get stock info
        $stock_status = $product->get_stock_status(); // instock, outofstock, onbackorder
        $stock_quantity = $product->get_stock_quantity();
        $backorders = $product->get_backorders(); // no, notify, yes
        
        // Build event
        return array(
            'siteUrl' => get_site_url(),
            'connector' => 'woocommerce',
            'eventType' => 'product.price_or_stock.updated',
            'eventId' => $event_id,
            'occurredAt' => gmdate('c'), // ISO 8601
            'entity' => array(
                'type' => 'product',
                'productId' => $product_id,
                'sku' => $sku ?: 'product-' . $product_id,
                'name' => $product->get_name(),
                'currency' => get_woocommerce_currency(),
                'price' => array(
                    'regular' => $regular_price,
                    'sale' => $sale_price ? (float) $sale_price : null,
                    'effective' => $effective_price,
                ),
                'stock' => array(
                    'status' => $stock_status,
                    'quantity' => $stock_quantity,
                    'backorders' => $backorders,
                ),
                'permalink' => get_permalink($product_id),
            ),
        );
    }
    
    /**
     * Send test event (for admin UI testing)
     */
    public function send_test_event() {
        if (!$this->is_woocommerce_active()) {
            return array(
                'success' => false,
                'error' => 'WooCommerce is not active',
            );
        }
        
        // Get a sample product
        $products = wc_get_products(array(
            'limit' => 1,
            'status' => 'publish',
        ));
        
        if (empty($products)) {
            // Create a mock event for testing
            $test_event = array(
                'siteUrl' => get_site_url(),
                'connector' => 'woocommerce',
                'eventType' => 'product.price_or_stock.updated',
                'eventId' => 'test-' . wp_generate_uuid4(),
                'occurredAt' => gmdate('c'),
                'entity' => array(
                    'type' => 'product',
                    'productId' => 0,
                    'sku' => 'TEST-SKU-001',
                    'name' => 'Test Product',
                    'currency' => get_woocommerce_currency() ?: 'USD',
                    'price' => array(
                        'regular' => 29.99,
                        'sale' => 19.99,
                        'effective' => 19.99,
                    ),
                    'stock' => array(
                        'status' => 'instock',
                        'quantity' => 10,
                        'backorders' => 'no',
                    ),
                    'permalink' => get_site_url() . '/product/test-product/',
                ),
            );
        } else {
            $product = $products[0];
            $test_event = $this->build_product_event($product, 'product.test');
        }
        
        // Temporarily enable to send
        $original_enabled = $this->enabled;
        $this->enabled = true;
        
        $result = $this->api_client->send_live_event($test_event);
        
        $this->enabled = $original_enabled;
        
        return $result;
    }
    
    /**
     * Get connector status for admin display
     */
    public function get_status() {
        return array(
            'woocommerce_active' => $this->is_woocommerce_active(),
            'live_sync_enabled' => $this->enabled,
            'product_count' => $this->is_woocommerce_active() ? (int) wp_count_posts('product')->publish : 0,
        );
    }
}
