<?php
/*
Plugin Name: Lightweight RSS Carousel
Description: A shortcode to display RSS feed posts in a carousel dynamically.
Version: 1.0.0
Author: Grok
License: GPL-2.0+
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Enqueue Slick Slider assets
function lrc_enqueue_assets() {
    if (!is_admin()) {
        // Slick Slider CSS
        wp_enqueue_style('slick-css', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css', array(), '1.8.1');
        // Slick Slider JS
        wp_enqueue_script('slick-js', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js', array('jquery'), '1.8.1', true);
    }
}
add_action('wp_enqueue_scripts', 'lrc_enqueue_assets');

// Shortcode handler
function lrc_rss_carousel_shortcode($atts) {
    // Shortcode attributes
    $atts = shortcode_atts(array(
        'url' => '',
    ), $atts, 'rss_carousel');

    // Sanitize and validate URL
    $rss_url = esc_url_raw($atts['url']);
    if (empty($rss_url)) {
        return '<p>Error: No RSS feed URL provided.</p>';
    }

    // Fetch RSS feed
    $response = wp_remote_get($rss_url, array('timeout' => 10));
    if (is_wp_error($response)) {
        return '<p>Error: Unable to fetch RSS feed.</p>';
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return '<p>Error: Empty RSS feed.</p>';
    }

    // Parse RSS with SimpleXML
    $xml = @simplexml_load_string($body);
    if ($xml === false || !isset($xml->channel->item)) {
        return '<p>Error: Invalid RSS feed format.</p>';
    }

    // Start output buffering
    ob_start();
    ?>
    <div class="lrc-rss-carousel">
        <?php foreach ($xml->channel->item as $item) : ?>
            <?php
            // Extract data
            $title = esc_html((string)$item->title);
            $link = esc_url((string)$item->link);
            $description = wp_trim_words(strip_tags((string)$item->description), 20, '...');
            
            // Try to find an image (from media:content or enclosure)
            $image = '';
            if (isset($item->children('media', true)->content)) {
                $media = $item->children('media', true)->content;
                if (isset($media->attributes()['url'])) {
                    $image = esc_url((string)$media->attributes()['url']);
                }
            } elseif (isset($item->enclosure) && isset($item->enclosure->attributes()['url'])) {
                $image = esc_url((string)$item->enclosure->attributes()['url']);
            }
            ?>
            <div class="lrc-slide">
                <div class="lrc-item" style="border: 1px solid #ddd; padding: 15px; margin: 10px; background: #fff;">
                    <?php if ($image) : ?>
                        <img src="<?php echo $image; ?>" alt="<?php echo $title; ?>" style="max-width: 100%; height: auto; margin-bottom: 10px;">
                    <?php endif; ?>
                    <h3 style="font-size: 18px; margin: 0 0 10px;"><?php echo $title; ?></h3>
                    <p style="font-size: 14px; margin: 0 0 10px;"><?php echo $description; ?></p>
                    <a href="<?php echo $link; ?>" target="_blank" style="color: #0073aa;">Read More</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <style>
        .lrc-rss-carousel { margin: 20px 0; }
        .lrc-slide { padding: 0 10px; }
        .lrc-item { text-align: center; }
        .slick-prev:before, .slick-next:before { color: #000; }
    </style>

    <script>
        jQuery(document).ready(function($) {
            $('.lrc-rss-carousel').slick({
                slidesToShow: 3,
                slidesToScroll: 1,
                autoplay: true,
                autoplaySpeed: 3000,
                arrows: true,
                dots: true,
                responsive: [
                    {
                        breakpoint: 768,
                        settings: { slidesToShow: 2 }
                    },
                    {
                        breakpoint: 480,
                        settings: { slidesToShow: 1 }
                    }
                ]
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('rss_carousel', 'lrc_rss_carousel_shortcode');
?>