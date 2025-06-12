<?php
/*
Plugin Name: Aspire Custom Plugin
Description: A shortcode to display RSS feed posts in a carousel with popup showing full article content fetched from the article's link URL, styled for Listivo theme.
Version: 2.3.5
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
        // Slick Slider JS
        wp_enqueue_script('slick-js', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js', array('jquery'), '1.8.1', true);
        // Custom AJAX script
        wp_enqueue_script('lrc-ajax', plugin_dir_url(__FILE__) . 'lrc-ajax.js', array('jquery'), '2.3.5', true);
        wp_localize_script('lrc-ajax', 'lrcAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lrc_fetch_content')
        ));
    }
}
add_action('wp_enqueue_scripts', 'lrc_enqueue_assets');

// Function to fetch and extract full article content
function lrc_fetch_full_content($url) {
    // Create a unique transient key
    $transient_key = 'lrc_article_' . md5($url);

    // Bypass cache for debugging (remove after testing)
    delete_transient($transient_key);

    // Check for cached content
    $cached_content = get_transient($transient_key);
    if ($cached_content !== false) {
        return $cached_content;
    }

    // Fetch the webpage
    $response = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        ),
        'sslverify' => true
    ));

    if (is_wp_error($response)) {
        return array(
            'content' => 'Unable to fetch article content: ' . $response->get_error_message(),
            'is_paywalled' => false,
            'debug_info' => 'HTTP Error: ' . $response->get_error_message()
        );
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return array(
            'content' => 'Article content is empty.',
            'is_paywalled' => false,
            'debug_info' => 'Empty response body'
        );
    }

    // Parse HTML with DOMDocument
    $doc = new DOMDocument();
    libxml_use_internal_errors(true); // Suppress malformed HTML warnings
    @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $body);
    libxml_clear_errors();
    $content = '';
    $is_paywalled = false;
    $debug_info = '';

    // Stat News-specific selectors
    $selectors = [
        '//div[contains(@class, "entry-content")]',
        '//div[contains(@class, "article-content")]',
        '//div[contains(@class, "article-body")]',
        '//div[contains(@class, "post-content")]',
        '//div[contains(@class, "content-body")]',
        '//div[contains(@class, "story-content")]',
        '//article',
        '//main'
    ];

    $xpath = new DOMXPath($doc);
    foreach ($selectors as $selector) {
        $nodes = $xpath->query($selector);
        if ($nodes->length > 0) {
            foreach ($nodes as $node) {
                // Remove unwanted elements
                $unwanted = $xpath->query('.//div[contains(@class, "ad-") or contains(@class, "related-") or contains(@class, "newsletter-") or contains(@class, "paywall") or contains(@class, "subscribe-")]', $node);
                foreach ($unwanted as $unwanted_node) {
                    $unwanted_node->parentNode->removeChild($unwanted_node);
                }
                // Remove script and style tags
                $scripts = $xpath->query('.//script | .//style', $node);
                foreach ($scripts as $script) {
                    $script->parentNode->removeChild($script);
                }
                $content .= $doc->saveHTML($node);
            }
            $debug_info = 'Content extracted using selector: ' . $selector;
            break;
        }
    }

    // Fallback: Extract all relevant content elements
    if (empty($content)) {
        $elements = $xpath->query('//div[contains(@class, "content")]//p | //div[contains(@class, "content")]//h1 | //div[contains(@class, "content")]//h2 | //div[contains(@class, "content")]//h3 | //div[contains(@class, "content")]//ul | //div[contains(@class, "content")]//ol | //div[contains(@class, "content")]//li | //article//p | //main//p');
        foreach ($elements as $element) {
            $content .= $doc->saveHTML($element);
        }
        if ($content) {
            $debug_info = 'Content extracted using fallback content elements';
        } else {
            $debug_info = 'No content extracted; all selectors and fallback failed';
        }
    }

    // Truncate content before "STAT+ Exclusive Story"
    $stat_plus_pos = stripos($content, 'STAT+ Exclusive Story');
    if ($stat_plus_pos !== false) {
        $content = substr($content, 0, $stat_plus_pos);
        $content = trim($content);
        $debug_info .= '; Content truncated before STAT+ Exclusive Story';
    }

    // Clean up content
    $content = preg_replace('/\s+/', ' ', $content); // Collapse multiple whitespaces
    $content = trim($content);

    // Check for paywall or restricted content
    $paywall_indicators = ['paywall', 'login-required', 'subscribe-now', 'stat-plus', 'premium-content', 'restricted-content', 'membership-required'];
    $body_lower = strtolower($body);
    $paywall_detected = false;
    $paywall_trigger = '';
    foreach ($paywall_indicators as $indicator) {
        if (stripos($body_lower, $indicator) !== false) {
            $paywall_detected = true;
            $paywall_trigger = $indicator;
            break;
        }
    }

    // Override paywall if sufficient content is extracted
    if ($paywall_detected && !empty($content) && strlen(strip_tags($content)) > 500) {
        $is_paywalled = false;
        $debug_info .= '; Paywall override due to content length: ' . strlen(strip_tags($content)) . ' chars';
    } elseif ($paywall_detected) {
        $is_paywalled = true;
        $content = 'This article is behind a paywall or restricted. Please visit the source website for full access.';
        $debug_info .= '; Paywall detected due to: ' . $paywall_trigger;
    }

    // Sanitize content
    $allowed_tags = array(
        'p' => array('class' => array()),
        'strong' => array(),
        'em' => array(),
        'br' => array(),
        'ul' => array(),
        'ol' => array(),
        'li' => array(),
        'h1' => array(),
        'h2' => array(),
        'h3' => array(),
        'a' => array('href' => array(), 'target' => array())
    );
    $content = wp_kses($content, $allowed_tags);
    $content = trim($content);
    $content = $content ?: 'Full article content unavailable. Please visit the source website.';

    $result = array(
        'content' => $content,
        'is_paywalled' => $is_paywalled,
        'debug_info' => $debug_info
    );

    // Cache for 24 hours
    set_transient($transient_key, $result, 24 * HOUR_IN_SECONDS);

    return $result;
}

// AJAX handler for fetching content
function lrc_fetch_content_ajax() {
    check_ajax_referer('lrc_fetch_content', 'nonce');

    if (!isset($_POST['url'])) {
        wp_send_json_error(array('message' => 'No URL provided.'));
    }

    $url = esc_url_raw($_POST['url']);
    $content = lrc_fetch_full_content($url);
    wp_send_json_success($content);
}
add_action('wp_ajax_lrc_fetch_content', 'lrc_fetch_content_ajax');
add_action('wp_ajax_nopriv_lrc_fetch_content', 'lrc_fetch_content_ajax');

// Shortcode handler
function lrc_rss_carousel_shortcode($atts) {
    // Shortcode attributes
    $atts = shortcode_atts(array(
        'url' => '',
        'limit' => 10,
    ), $atts, 'rss_carousel');

    // Sanitize and validate inputs
    $rss_url = esc_url_raw($atts['url']);
    $limit = max(6, min(10, absint($atts['limit'])));
    if (empty($rss_url)) {
        return '<p>Please provide a valid RSS feed URL.</p>';
    }

    // Fetch RSS feed
    $response = wp_remote_get($rss_url, array(
        'timeout' => 10,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        )
    ));
    if (is_wp_error($response)) {
        return '<p>Error fetching RSS feed: ' . $response->get_error_message() . '</p>';
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return '<p>RSS feed is empty.</p>';
    }

    // Parse RSS with SimpleXML
    $xml = @simplexml_load_string($body);
    if ($xml === false || !isset($xml->channel->item)) {
        return '<p>Invalid RSS feed format.</p>';
    }

    // Start output buffering
    ob_start();
    ?>
    <div class="lrc-rss-carousel">
        <?php
        $count = 0;
        foreach ($xml->channel->item as $item) :
            if ($count >= $limit) break;
            $count++;
            // Extract data
            $title = esc_html((string)$item->title);
            $clean_title = preg_replace('/^STAT\+:\s*/i', '', $title); // Remove STAT+: from title
            $link = esc_url((string)$item->link);
            $description = wp_trim_words(strip_tags((string)$item->description), 20, '...');
            $pubdate = isset($item->pubDate) ? esc_html((string)$item->pubDate) : '';
            $formatted_pubdate = $pubdate ? date('F j, Y', strtotime($pubdate)) : '';

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
                <div class="lrc-item" data-title="<?php echo esc_attr($title); ?>" data-url="<?php echo esc_attr($link); ?>" data-image="<?php echo esc_attr($image); ?>" data-pubdate="<?php echo esc_attr($formatted_pubdate); ?>">
                    <?php if ($image) : ?>
                        <div class="lrc-image">
                            <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($clean_title); ?>">
                        </div>
                    <?php else : ?>
                        <div class="lrc-image lrc-no-image">
                            <span>No Image</span>
                        </div>
                    <?php endif; ?>
                    <div class="lrc-content">
                        <h3><?php echo esc_html($clean_title); ?></h3>
                        <p><?php echo esc_html($description); ?></p>
                        <a class="lrc-read-more">Read Full Article</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <style>
        .lrc-rss-carousel {
            margin: 30px auto;
            max-width: 1170px;
            position: relative;
        }
        .lrc-slide {
            padding: 0 15px;
        }
        .lrc-item {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            height: 420px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
        }
        .lrc-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        .lrc-image {
            height: 200px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .lrc-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .lrc-no-image {
            background: #f7f7f7;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 14px;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
        }
        .lrc-content {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .lrc-content h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 12px;
            color: #333;
            line-height: 1.4;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
        }
        .lrc-content p {
            font-size: 14px;
            color: #666;
            margin: 0 0 15px;
            line-height: 1.6;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
        }
        .lrc-read-more {
            display: inline-block;
            color: #0073aa;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.3s ease;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
            cursor: pointer;
        }
        .lrc-read-more:hover {
            color: #005177;
        }
        .slick-prev, .slick-next {
            width: 40px;
            height: 40px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            z-index: 10;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            text-align: center;
            line-height: 40px;
            transition: background 0.3s ease, transform 0.3s ease;
            font-size: 20px;
            color: #333;
        }
        .slick-prev:hover, .slick-next:hover {
            background: #f7f7f7;
            transform: translateY(-50%) scale(1.1);
        }
        .slick-prev {
            left: -40px;
        }
        .slick-next {
            right: -40px;
        }
        .slick-dots {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-top: 20px;
            padding: 0;
            list-style: none;
        }
        .slick-dots li {
            margin: 0 6px;
        }
        .slick-dots li button {
            font-size: 0;
            width: 12px;
            height: 12px;
            background: transparent;
            border: 2px solid #ccc;
            border-radius: 50%;
            padding: 0;
            cursor: pointer;
            transition: background 0.3s ease, border-color 0.3s ease;
        }
        .slick-dots li.slick-active button {
            background: #333;
            border-color: #333;
        }
        .slick-dots li button:hover {
            border-color: #666;
        }
        .slick-dots li button:before {
            content: '';
        }
        .lrc-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 1000;
            padding: 30px;
        }
        .lrc-popup-content {
            position: relative;
        }
        
        .lrc-popup-close {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 24px;
            color: #333;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        .lrc-popup-close:hover {
            color: #0073aa;
        }
        .lrc-popup-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .lrc-popup-no-image {
            background: #f7f7f7;
            height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .lrc-popup-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin: 0 0 15px;
            line-height: 1.4;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
        }
        .lrc-popup-content-text {
            font-size: 16px;
            color: #666;
            margin: 0 0 15px;
            line-height: 1.6;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
        }
        .lrc-popup-content-text p, .lrc-popup-content-text h1, .lrc-popup-content-text h2, .lrc-popup-content-text h3, .lrc-popup-content-text ul, .lrc-popup-content-text ol, .lrc-popup-content-text li {
            margin-bottom: 15px;
        }
        .lrc-popup-pubdate {
            font-size: 14px;
            color: #999;
            margin: 0 0 15px;
            font-style: italic;
            font-family: -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
        }
        .lrc-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 999;
        }
        .lrc-loading {
            text-align: center;
            padding: 20px;
            font-size: 16px;
            color: #666;
        }
        @media (max-width: 768px) {
            .slick-prev {
                left: 10px;
            }
            .slick-next {
                right: 10px;
            }
            .lrc-item {
                height: 380px;
            }
            .lrc-image {
                height: 180px;
            }
            .lrc-popup {
                width: 95%;
            }
            .lrc-popup-title {
                font-size: 20px;
            }
            .lrc-popup-content-text {
                font-size: 14px;
            }
            .lrc-popup-no-image {
                height: 200px;
            }
        }
        @media (max-width: 480px) {
            .lrc-item {
                height: 360px;
            }
            .lrc-image {
                height: 160px;
            }
            .lrc-popup-title {
                font-size: 18px;
            }
            .lrc-popup-content-text {
                font-size: 13px;
            }
            .lrc-popup-no-image {
                height: 150px;
            }
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('rss_carousel', 'lrc_rss_carousel_shortcode');
?>