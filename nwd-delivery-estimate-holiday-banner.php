<?php
/*
Plugin Name: Delivery Estimate & Holiday Banner
Description: Adds delivery estimates to checkout and handles holiday closure banners with admin options.
Version: 1.2
Author: Johnny Kalevra
*/

if (!defined('ABSPATH')) {
    exit;
}

const NWD_TIMEZONE = 'Europe/London';
const NWD_CUTOFF_HOUR = 12;
const NWD_HOLIDAY_LOOKAHEAD_DAYS = 5;

function nwd_get_timezone() {
    static $timezone = null;

    if ($timezone === null) {
        $timezone = new DateTimeZone(NWD_TIMEZONE);
    }

    return $timezone;
}

function nwd_now() {
    return new DateTimeImmutable('now', nwd_get_timezone());
}

function nwd_today() {
    return new DateTimeImmutable('today', nwd_get_timezone());
}

function nwd_is_banner_enabled() {
    return get_option('nwd_enable_banner', '0') === '1';
}

function nwd_parse_holiday_date($date_string) {
    $date_string = trim((string) $date_string);

    if ($date_string === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_string, nwd_get_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    $has_errors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    if (!$date || $has_errors || $date->format('Y-m-d') !== $date_string) {
        return null;
    }

    return $date;
}

function nwd_parse_holiday_input($raw_value) {
    $lines = preg_split('/\R/', (string) $raw_value) ?: array();
    $valid_dates = array();
    $invalid_dates = array();

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $date = nwd_parse_holiday_date($line);

        if ($date === null) {
            $invalid_dates[] = $line;
            continue;
        }

        $valid_dates[$date->format('Y-m-d')] = true;
    }

    $normalized_dates = array_keys($valid_dates);
    sort($normalized_dates, SORT_STRING);

    return array(
        'dates' => $normalized_dates,
        'invalid' => $invalid_dates,
    );
}

function nwd_sanitize_banner_enabled($value) {
    return empty($value) ? '0' : '1';
}

function nwd_sanitize_holiday_dates($raw_value) {
    $parsed = nwd_parse_holiday_input($raw_value);

    if (!empty($parsed['invalid'])) {
        $invalid_dates = array_map('sanitize_text_field', $parsed['invalid']);

        add_settings_error(
            'nwd_holiday_dates',
            'nwd_invalid_holiday_dates',
            sprintf(
                'These holiday entries were ignored because they are not valid YYYY-MM-DD dates: %s',
                implode(', ', $invalid_dates)
            ),
            'warning'
        );
    }

    return implode("\n", $parsed['dates']);
}

function nwd_get_holiday_dates() {
    static $cache = array();

    $raw_value = (string) get_option('nwd_holiday_dates', '');

    if (!array_key_exists($raw_value, $cache)) {
        $cache[$raw_value] = nwd_parse_holiday_input($raw_value)['dates'];
    }

    return $cache[$raw_value];
}

function nwd_is_weekend(DateTimeInterface $date) {
    $day_of_week = (int) $date->format('N');

    return $day_of_week >= 6;
}

function nwd_is_closed_day(DateTimeInterface $date, array $holiday_dates) {
    return nwd_is_weekend($date) || in_array($date->format('Y-m-d'), $holiday_dates, true);
}

function nwd_find_open_day(DateTimeImmutable $date, array $holiday_dates, $direction = 1) {
    $modifier = ((int) $direction < 0) ? '-1 day' : '+1 day';

    while (nwd_is_closed_day($date, $holiday_dates)) {
        $date = $date->modify($modifier);
    }

    return $date;
}

function nwd_add_working_days(DateTimeImmutable $date, $days, array $holiday_dates) {
    $date = nwd_find_open_day($date, $holiday_dates, 1);
    $days = max(0, (int) $days);
    $added_days = 0;

    while ($added_days < $days) {
        $date = $date->modify('+1 day');

        if (!nwd_is_closed_day($date, $holiday_dates)) {
            $added_days++;
        }
    }

    return $date;
}

function nwd_get_dispatch_day($now = null, $holiday_dates = null) {
    $now = $now instanceof DateTimeImmutable ? $now : nwd_now();
    $holiday_dates = is_array($holiday_dates) ? $holiday_dates : nwd_get_holiday_dates();
    $dispatch_day = $now;
    $is_after_cutoff = (int) $now->format('G') >= NWD_CUTOFF_HOUR;
    $is_non_dispatch_weekday = (int) $now->format('N') > 4;

    if ($is_after_cutoff || $is_non_dispatch_weekday) {
        $dispatch_day = $dispatch_day->modify('+1 day');
    }

    return nwd_find_open_day($dispatch_day, $holiday_dates, 1);
}

function nwd_get_shipping_schedule($now = null, $holiday_dates = null) {
    $holiday_dates = is_array($holiday_dates) ? $holiday_dates : nwd_get_holiday_dates();
    $dispatch_day = nwd_get_dispatch_day($now, $holiday_dates);

    return array(
        'dispatch' => $dispatch_day,
        'next_delivery' => nwd_add_working_days($dispatch_day, 1, $holiday_dates),
        'standard_from' => nwd_add_working_days($dispatch_day, 3, $holiday_dates),
        'standard_to' => nwd_add_working_days($dispatch_day, 5, $holiday_dates),
    );
}

function nwd_get_upcoming_holiday(array $holiday_dates, $from = null, $within_days = null) {
    if (empty($holiday_dates)) {
        return null;
    }

    $from = $from instanceof DateTimeImmutable ? $from : nwd_today();
    $from_string = $from->format('Y-m-d');
    $end_string = null;

    if ($within_days !== null) {
        $end_string = $from->modify('+' . max(0, (int) $within_days) . ' days')->format('Y-m-d');
    }

    foreach ($holiday_dates as $holiday_date) {
        if ($holiday_date < $from_string) {
            continue;
        }

        if ($end_string !== null && $holiday_date > $end_string) {
            return null;
        }

        return nwd_parse_holiday_date($holiday_date);
    }

    return null;
}

function nwd_get_holiday_closure_details(DateTimeImmutable $holiday_date, array $holiday_dates) {
    $last_dispatch = nwd_find_open_day($holiday_date->modify('-1 day'), $holiday_dates, -1);
    $return_date = nwd_find_open_day($holiday_date->modify('+1 day'), $holiday_dates, 1);

    return array(
        'last_dispatch' => $last_dispatch,
        'return_date' => $return_date,
        'cutoff_time' => ($last_dispatch->format('N') === '5') ? '4pm' : '5pm',
    );
}

function nwd_get_shipping_method_phrases() {
    return array(
        'next' => 'Next Working Day',
        'standard' => 'Standard 3-5',
        'click_collect' => 'Click & Collect',
    );
}

function nwd_matches_shipping_phrase($label, $key) {
    $phrases = nwd_get_shipping_method_phrases();
    $plain_label = wp_strip_all_tags((string) $label);

    if (!isset($phrases[$key])) {
        return false;
    }

    return stripos($plain_label, $phrases[$key]) !== false;
}

add_action('admin_menu', function () {
    add_options_page(
        'Delivery & Holidays',
        'Delivery & Holidays',
        'manage_options',
        'delivery-holiday-settings',
        'nwd_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting(
        'nwd_settings_group',
        'nwd_enable_banner',
        array(
            'type' => 'string',
            'sanitize_callback' => 'nwd_sanitize_banner_enabled',
            'default' => '0',
        )
    );

    register_setting(
        'nwd_settings_group',
        'nwd_holiday_dates',
        array(
            'type' => 'string',
            'sanitize_callback' => 'nwd_sanitize_holiday_dates',
            'default' => '',
        )
    );
});

function nwd_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Delivery & Holiday Settings</h1>
        <?php settings_errors('nwd_holiday_dates'); ?>
        <form method="post" action="options.php">
            <?php settings_fields('nwd_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Enable Holiday Banner</th>
                    <td>
                        <input type="hidden" name="nwd_enable_banner" value="0" />
                        <input
                            type="checkbox"
                            name="nwd_enable_banner"
                            value="1"
                            <?php checked('1', get_option('nwd_enable_banner', '0')); ?>
                        />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Holiday Dates</th>
                    <td>
                        <textarea name="nwd_holiday_dates" rows="6" cols="50" placeholder="Format: YYYY-MM-DD, one per line"><?php echo esc_textarea(implode("\n", nwd_get_holiday_dates())); ?></textarea>
                        <p class="description">Add each holiday or closure date on a new line.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_shortcode('nwd-calc', function () {
    $schedule = nwd_get_shipping_schedule();

    ob_start();
    ?>
    <div>
        <strong>Estimated Dispatch:</strong> <?php echo esc_html($schedule['dispatch']->format('l jS F')); ?><br>
        <strong>Estimated Delivery:</strong> <?php echo esc_html($schedule['next_delivery']->format('l jS F')); ?>
    </div>
    <?php

    return ob_get_clean();
});

add_action('wp_footer', function () {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    $phrases = nwd_get_shipping_method_phrases();
    ?>
    <script>
    (function () {
        const shippingMethodSelector = 'input[name^="shipping_method["]';
        const phrases = <?php echo wp_json_encode($phrases); ?>;
        const normalizedPhrases = {
            next: (phrases.next || '').toLowerCase(),
            standard: (phrases.standard || '').toLowerCase()
        };

        const getLabel = (input) => {
            const item = input.closest('li');
            return item ? item.querySelector('label') : null;
        };

        const getLabelText = (label) => {
            return label ? (label.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase() : '';
        };

        const updateEstimates = () => {
            document.querySelectorAll('.nwd-estimate').forEach((element) => {
                element.style.display = 'none';
            });

            document.querySelectorAll(shippingMethodSelector + ':checked').forEach((input) => {
                const label = getLabel(input);
                const text = getLabelText(label);

                if (!label || !text) {
                    return;
                }

                let estimate = null;

                if (text.includes(normalizedPhrases.next)) {
                    estimate = label.querySelector('.nwd-next');
                } else if (text.includes(normalizedPhrases.standard)) {
                    estimate = label.querySelector('.nwd-standard');
                }

                if (estimate) {
                    estimate.style.display = 'inline';
                }
            });
        };

        const scheduleUpdate = () => {
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(updateEstimates);
                return;
            }

            window.setTimeout(updateEstimates, 0);
        };

        document.addEventListener('change', (event) => {
            if (event.target && event.target.matches(shippingMethodSelector)) {
                scheduleUpdate();
            }
        });

        document.addEventListener('DOMContentLoaded', updateEstimates);

        if (window.jQuery) {
            window.jQuery(function ($) {
                $(document.body).on('updated_checkout updated_shipping_method wc_fragments_loaded', scheduleUpdate);
                $(document.body).on('change', shippingMethodSelector, scheduleUpdate);
            });
        }

        if ('MutationObserver' in window) {
            const target = document.querySelector('form.checkout, .woocommerce-checkout') || document.body;
            let queued = false;

            const observer = new MutationObserver(() => {
                if (queued) {
                    return;
                }

                queued = true;
                scheduleUpdate();
                window.setTimeout(() => {
                    queued = false;
                }, 0);
            });

            observer.observe(target, { childList: true, subtree: true });
        }

        updateEstimates();
    }());
    </script>
    <?php
});

add_filter('woocommerce_cart_shipping_method_full_label', function ($label, $method) {
    $holiday_dates = nwd_get_holiday_dates();
    $schedule = nwd_get_shipping_schedule(null, $holiday_dates);
    $next_holiday = nwd_get_upcoming_holiday($holiday_dates, nwd_today(), NWD_HOLIDAY_LOOKAHEAD_DAYS);

    if (nwd_matches_shipping_phrase($label, 'next')) {
        $label .= '<br><span class="nwd-estimate nwd-next"><small><em>Estimated Delivery: ' . esc_html($schedule['next_delivery']->format('l jS F')) . '</em></small></span>';
    }

    if (nwd_matches_shipping_phrase($label, 'standard')) {
        $label .= '<br><span class="nwd-estimate nwd-standard"><small><em>Estimated Delivery: ' . esc_html($schedule['standard_from']->format('l jS')) . ' - ' . esc_html($schedule['standard_to']->format('l jS F')) . '</em></small></span>';
    }

    if ($next_holiday && !nwd_matches_shipping_phrase($label, 'click_collect')) {
        $tooltip = 'Due to the bank holiday on ' . $next_holiday->format('l jS F') . ', your delivery may be delayed by 1-2 working days.';
        $label .= ' <span class="nwd-holiday-note" style="color:#c7203a; font-size: 0.85em;" title="' . esc_attr($tooltip) . '">(Includes bank holiday delay)</span>';
    }

    return $label;
}, 10, 2);

add_filter('body_class', function ($classes) {
    if (!nwd_is_banner_enabled()) {
        return $classes;
    }

    $next_holiday = nwd_get_upcoming_holiday(nwd_get_holiday_dates(), nwd_today(), NWD_HOLIDAY_LOOKAHEAD_DAYS);

    if ($next_holiday) {
        $classes[] = 'show-banner';
    }

    return $classes;
});

add_shortcode('nwd-holiday-info', function () {
    if (!nwd_is_banner_enabled()) {
        return '';
    }

    $holiday_dates = nwd_get_holiday_dates();
    $next_holiday = nwd_get_upcoming_holiday($holiday_dates, nwd_today());

    if (!$next_holiday) {
        return '';
    }

    $closure = nwd_get_holiday_closure_details($next_holiday, $holiday_dates);

    ob_start();
    ?>
    <h1 style="font-family: Poppins; font-size: 2.2em; font-weight: 600; margin-bottom: 0.5em;">Bank Holiday Delivery Information</h1>

    <h2 style="font-family: Poppins; font-size: 1.5em; font-weight: 500; margin-top: 1.5em;">Opening hours:</h2>
    <p>The Garden Range will be closed from <strong><?php echo esc_html($closure['cutoff_time']); ?> on <?php echo esc_html($closure['last_dispatch']->format('l jS F')); ?></strong> through to <strong>8:30am on <?php echo esc_html($closure['return_date']->format('l jS F')); ?></strong>. We will not be taking enquiries over the telephone or live chat during this time and all emails will be responded to on our return on the <?php echo esc_html($closure['return_date']->format('jS F')); ?>. Orders can still be placed online via our website.</p>

    <h2 style="font-family: Poppins; font-size: 1.5em; font-weight: 500; margin-top: 1.5em;">Delivery:</h2>
    <p>Our last dispatch date is <strong><?php echo esc_html($closure['last_dispatch']->format('l jS F')); ?></strong>. We aim for our orders to be delivered within the specified timeframe on your order, or should you not have placed an order, within the turnaround times displayed on the product page. However, over the bank holiday weekend, this could vary as couriers and hauliers may be closed or not delivering in your area again until <strong><?php echo esc_html($closure['return_date']->format('l jS F')); ?></strong>.</p>

    <p>If it's essential to know when your delivery will arrive or if you require your order before the upcoming bank holiday, please contact our <a href="/contact-us" style="color:#c7203a;">customer services</a> team who will be happy to help with your enquiry.</p>

    <p>Any orders placed between <?php echo esc_html($closure['last_dispatch']->format('jS')); ?> and <?php echo esc_html($closure['return_date']->format('jS F')); ?> will be processed on our return on <?php echo esc_html($closure['return_date']->format('jS F')); ?>.</p>
    <?php

    return ob_get_clean();
});
