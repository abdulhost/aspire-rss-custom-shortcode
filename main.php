<?php
/*
Plugin Name: Aspire Custom Plugin
Description: A shortcode to display RSS feed posts in a carousel dynamically, styled for Listivo theme.
Version: 1.1.0
Author: Grok
License: GPL-2.0+
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Enqueue assets
function lrc_enqueue_assets() {
    if (!is_admin()) {
        // Slick Slider CSS
        wp_enqueue_style('slick-css', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css', array(), '1.8.1');
        // Font Awesome for icons
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css', array(), '6.4.2');
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
        'limit' => 10, // Default to 10 posts
    ), $atts, 'rss_carousel');

    // Sanitize and validate inputs
    $rss_url = esc_url_raw($atts['url']);
    $limit = max(6, min(10, absint($atts['limit']))); // Enforce 6–10 range
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
        <?php
        $count = 0;
        foreach ($xml->channel->item as $item) :
            if ($count >= $limit) break; // Stop after limit
            $count++;
            // Extract data
            $title = esc_html((string)$item->title);
            $link = esc_url((string)$item->link);
            $description = wp_trim_words(strip_tags((string)$item->description), 20, '...');
            
            // Try to find an image
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
                <div class="lrc-item">
                    <?php if ($image) : ?>
                        <div class="lrc-image">
                            <img src="<?php echo $image; ?>" alt="<?php echo $title; ?>">
                        </div>
                    <?php else : ?>
                        <div class="lrc-image lrc-no-image">
                            <!-- Placeholder for no image -->
                            <span>No Image</span>
                        </div>
                    <?php endif; ?>
                    <div class="lrc-content">
                        <h3><?php echo $title; ?></h3>
                        <p><?php echo $description; ?></p>
                        <a href="<?php echo $link; ?>" target="_blank" class="lrc-read-more">Read More</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <style>
        .lrc-rss-carousel {
            margin: 30px 0;
            max-width: 1170px;
            margin-left: auto;
            margin-right: auto;
        }
        .lrc-slide {
            padding: 0 15px;
        }
        .lrc-item {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            min-height: 400px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .lrc-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.15);
        }
        .lrc-image {
            height: 200px;
            overflow: hidden;
        }
        .lrc-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .lrc-no-image {
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 14px;
        }
        .lrc-content {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .lrc-content h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 10px;
            color: #333;
            line-height: 1.4;
        }
        .lrc-content p {
            font-size: 14px;
            color: #666;
            margin: 0 0 15px;
            line-height: 1.6;
        }
        .lrc-read-more {
            display: inline-block;
            color: #0073aa;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.3s;
        }
        .lrc-read-more:hover {
            color: #005177;
        }
        .slick-prev, .slick-next {
            width: 40px;
            height: 40px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 10;
        }
        .slick-prev {
            left: -50px;
        }
        .slick-next {
            right: -50px;
        }
        .slick-prev:before, .slick-next:before {
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: #333;
            font-size: 20px;
        }
        .slick-prev:before {
            content: '\f053';
        }
        .slick-next:before {
            content: '\f054';
        }
        .slick-dots li button:before {
            font-size: 10px;
            color: #0073aa;
        }
        .slick-dots li.slick-active button:before {
            color: #005177;
        }
        @media (max-width: 768px) {
            .slick-prev {
                left: 10px;
            }
            .slick-next {
                right: 10px;
            }
        }
        @media (max-width: 480px) {
            .lrc-item {
                min-height: 350px;
            }
            .lrc-image {
                height: 150px;
            }
        }
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