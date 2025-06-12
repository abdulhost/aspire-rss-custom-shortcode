jQuery(document).ready(function($) {
    // Initialize Slick Slider with accessibility improvements
    $('.lrc-rss-carousel').slick({
        slidesToShow: 3,
        slidesToScroll: 1,
        autoplay: true,
        autoplaySpeed: 3000,
        arrows: true,
        dots: true,
        prevArrow: '<button type="button" class="slick-prev" aria-label="Previous">←</button>',
        nextArrow: '<button type="button" class="slick-next" aria-label="Next">→</button>',
        responsive: [
            {
                breakpoint: 768,
                settings: { slidesToShow: 2 }
            },
            {
                breakpoint: 480,
                settings: { slidesToShow: 1 }
            }
        ],
        accessibility: true,
        focusOnSelect: false
    });

    // Popup functionality
    $('body').on('click', '.lrc-item, .lrc-read-more', function(e) {
        e.preventDefault();
        var $item = $(this).closest('.lrc-item');
        var title = $item.data('title').replace(/^STAT\+:\s*/i, ''); // Remove STAT+: from title
        var url = $item.data('url');
        var image = $item.data('image');
        var pubdate = $item.data('pubdate');

        // Clear any existing popups
        $('.lrc-popup, .lrc-overlay').remove();

        // Create popup with loading state
        var popupHtml = $('<div>').addClass('lrc-overlay').prop('outerHTML') +
            $('<div>').addClass('lrc-popup').append(
                $('<div>').addClass('lrc-popup-content').append(
                    $('<span>').addClass('lrc-popup-close').text('×'),
                    image ? $('<img>').addClass('lrc-popup-image').attr({ src: image, alt: title }) : $('<div>').addClass('lrc-popup-no-image').text('No Image'),
                    $('<h2>').addClass('lrc-popup-title').text(title),
                    pubdate ? $('<p>').addClass('lrc-popup-pubdate').text('Published: ' + pubdate) : '',
                    $('<div>').addClass('lrc-popup-content-text lrc-loading').text('Loading article content...')
                )
            ).prop('outerHTML');

        // Append and show popup
        $('body').append(popupHtml);
        $('.lrc-popup, .lrc-overlay').fadeIn(300);

        // Fetch content via AJAX
        $.ajax({
            url: lrcAjax.ajax_url,
            type: 'POST',
            timeout: 10000,
            data: {
                action: 'lrc_fetch_content',
                nonce: lrcAjax.nonce,
                url: url
            },
            // beforeSend: function() {
                // console.log('Fetching content for URL: ' + url);
            // },
            success: function(response) {
                // console.log('AJAX Success:', response);
                $('.lrc-popup-content-text').removeClass('lrc-loading');
                if (response.success) {
                    $('.lrc-popup-content-text').html(response.data.content);
                    if (response.data.is_paywalled) {
                        $('.lrc-popup-content-text').append('<p><a href="' + url + '" target="_blank">Visit source for full access</a></p>');
                    }
                    // if (response.data.debug_info) {
                    //     console.log('Extraction Debug Info:', response.data.debug_info);
                    // }
                } else {
                    $('.lrc-popup-content-text').html('Error: ' + (response.data.message || 'Unknown error occurred.'));
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX Error:', textStatus, errorThrown);
                $('.lrc-popup-content-text').removeClass('lrc-loading').html('Failed to load article content: ' + textStatus + ' (' + errorThrown + ')');
            }
        });

        // Close popup
        $('body').on('click', '.lrc-popup-close, .lrc-overlay', function() {
            $('.lrc-popup, .lrc-overlay').fadeOut(300, function() {
                $(this).remove();
            });
        });
    });
});