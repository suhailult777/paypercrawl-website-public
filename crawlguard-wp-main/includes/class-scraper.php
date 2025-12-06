<?php
namespace CrawlGuard;

class CrawlGuard_Scraper {

    public static function get_all_content() {
        $args = array(
            'post_type'      => array('post', 'page'),
            'post_status'    => 'publish',
            'posts_per_page' => -1, // Fetch all
        );

        $query = new \WP_Query($args);
        $content_data = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                global $post;

                $clean_content = self::clean_html($post->post_content);
                
                $content_data[] = array(
                    'id' => $post->ID,
                    'title' => get_the_title(),
                    'url' => get_permalink(),
                    'date' => get_the_date('Y-m-d'),
                    'content' => $clean_content,
                    'type' => $post->post_type
                );
            }
            wp_reset_postdata();
        }

        return $content_data;
    }

    public static function clean_html($html) {
        // Remove scripts and styles
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $html);
        
        // Strip tags
        $text = strip_tags($html);
        
        // Decode entities
        $text = html_entity_decode($text);
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }

    public static function generate_payload() {
        $content = self::get_all_content();
        $payload_items = array();

        foreach ($content as $item) {
            // Convert single item to TOON
            $toon_item = Toon_Encoder::encode($item);
            
            $payload_items[] = array(
                'url' => $item['url'],
                'title' => $item['title'],
                'content_toon' => $toon_item,
                'original_json' => $item
            );
        }

        return $payload_items;
    }
}
