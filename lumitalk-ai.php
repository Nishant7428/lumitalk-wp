<?php
/**
 * Plugin Name: LumiTalk AI
 * Plugin URI: https://github.com/Nishant7428/lumitalk-wp
 * Description: AI-powered customer service for WordPress. Connect your store (WooCommerce, Easy Digital Downloads, or any site) to LumiTalk and add an AI chat widget.
 * Version: 1.3.0
 * Author: LumiTalk
 * Author URI: https://lumitalk.ai
 * License: GPL-2.0+
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * Onboarding runs natively in wp-admin (multi-step forms that call the LumiTalk API).
 * The AI agent panel / conversations open in a new tab (link-out, not embedded).
 */

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

// -- Endpoint base (single source of truth) ----------------------------------
// Every LumiTalk URI (connect, app, widget, api, agent) DERIVES from one base — the
// app URL — so switching environments is a single change. Set LUMITALK_APP_BASE (in
// wp-config.php, or the Advanced field) to:
//   https://app.lumitalk.ai      (production)
//   https://appdev.lumitalk.ai   (dev / staging)
//   http://localhost:8080        (local stack)
// Deployed hosts route everything through one host by path; localhost uses the
// per-service dev ports. Individual URIs can be force-overridden with
// LUMITALK_CONNECT_BASE / LUMITALK_API_URL / LUMITALK_WIDGET_SRC / LUMITALK_AGENT_BASE.
if (!defined('LUMITALK_APP_BASE')) {
    define('LUMITALK_APP_BASE', 'https://appdev.lumitalk.ai');
}

define('LUMITALK_OPTION', 'lumitalk_settings');
define('LUMITALK_VER', '1.3.0');

function lumitalk_get_settings() {
    return wp_parse_args(get_option(LUMITALK_OPTION, array()), array(
        'connected'       => false,
        'application_id'  => '',
        'widget_key'      => '',
        'store_url'       => '',
        'email'           => '',
        'sign_in_url'     => '',
        'embed_token'     => '',
        'widget_enabled'  => true,
        'connect_base'    => '',
        'app_base'        => '',
        'onboarded'       => false,
        'source'          => '',
    ));
}

function lumitalk_save_settings($settings) {
    update_option(LUMITALK_OPTION, $settings);
}

// Derive EVERY LumiTalk URI from one base (the app URL).
function lumitalk_endpoints() {
    $s = get_option(LUMITALK_OPTION, array());
    $app = !empty($s['app_base']) ? $s['app_base']
        : ((defined('LUMITALK_APP_BASE') && LUMITALK_APP_BASE) ? LUMITALK_APP_BASE : 'https://appdev.lumitalk.ai');
    $app = untrailingslashit($app);

    $parts  = wp_parse_url($app);
    $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
    $host   = !empty($parts['host'])   ? $parts['host']   : 'appdev.lumitalk.ai';
    $port   = !empty($parts['port'])   ? ':' . $parts['port'] : '';

    if (in_array($host, array('localhost', '127.0.0.1'), true)) {
        $e = array(
            'app'     => $scheme . '://' . $host . ':8080',
            'connect' => $scheme . '://' . $host . ':6000',
            'api'     => $scheme . '://' . $host . ':8000',
            'agent'   => 'https://agentdev.lumitalk.ai',
        );
        $e['widget'] = $e['api'] . '/public/lumi-chat-widget.min.js';
    } else {
        $base  = $scheme . '://' . $host . $port;
        $agent = $scheme . '://' . preg_replace('/^app/', 'agent', $host) . $port;
        $e = array(
            'app'     => $base,
            'connect' => $base,
            'api'     => $base,
            'agent'   => $agent,
            'widget'  => $base . '/public/lumi-chat-widget.min.js',
        );
    }

    if (!empty($s['connect_base']))                                   { $e['connect'] = untrailingslashit($s['connect_base']); }
    elseif (defined('LUMITALK_CONNECT_BASE') && LUMITALK_CONNECT_BASE) { $e['connect'] = untrailingslashit(LUMITALK_CONNECT_BASE); }
    if (defined('LUMITALK_API_URL') && LUMITALK_API_URL)              { $e['api']    = untrailingslashit(LUMITALK_API_URL); }
    if (defined('LUMITALK_WIDGET_SRC') && LUMITALK_WIDGET_SRC)        { $e['widget'] = LUMITALK_WIDGET_SRC; }
    if (defined('LUMITALK_AGENT_BASE') && LUMITALK_AGENT_BASE)        { $e['agent']  = untrailingslashit(LUMITALK_AGENT_BASE); }

    return $e;
}

function lumitalk_connect_base() { $e = lumitalk_endpoints(); return $e['connect']; }
function lumitalk_app_base()     { $e = lumitalk_endpoints(); return $e['app']; }
function lumitalk_agent_base()   { $e = lumitalk_endpoints(); return $e['agent']; }
function lumitalk_api_url()      { $e = lumitalk_endpoints(); return $e['api']; }
function lumitalk_widget_src()   { $e = lumitalk_endpoints(); return $e['widget']; }

// Public HTTPS URL of THIS store that LumiTalk's backend calls to validate the
// WooCommerce key. Defaults to home_url(); override with LUMITALK_STORE_URL.
function lumitalk_store_url() {
    if (defined('LUMITALK_STORE_URL') && LUMITALK_STORE_URL) {
        return untrailingslashit(LUMITALK_STORE_URL);
    }
    return untrailingslashit(home_url());
}

function lumitalk_find_page_url($slugs) {
    foreach ((array) $slugs as $slug) {
        $page = get_page_by_path($slug);
        if ($page) {
            $url = get_permalink($page->ID);
            if ($url) { return $url; }
        }
    }
    return '';
}

// Collect website/store details from WordPress so LumiTalk can prefill onboarding.
function lumitalk_collect_store_details() {
    $details = array(
        'name'         => get_bloginfo('name'),
        'description'  => get_bloginfo('description'),
        'website'      => home_url(),
        'email'        => get_option('woocommerce_email_from_address'),
        'currency'     => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : get_option('woocommerce_currency'),
        'timezone'     => function_exists('wp_timezone_string') ? wp_timezone_string() : get_option('timezone_string'),
        'phone'        => get_option('woocommerce_store_phone', ''),
        'country'      => get_option('woocommerce_default_country', ''),
        'address'      => '',
        'productCount' => 0,
        'orderCount'   => 0,
    );
    if (empty($details['email'])) {
        $details['email'] = wp_get_current_user()->user_email;
    }
    $addr = array(
        get_option('woocommerce_store_address', ''),
        get_option('woocommerce_store_address_2', ''),
        get_option('woocommerce_store_city', ''),
        get_option('woocommerce_store_postcode', ''),
    );
    $details['address'] = trim(implode(', ', array_filter(array_map('trim', $addr))), ', ');
    if (function_exists('wc_get_products')) {
        $p = wc_get_products(array('limit' => 1, 'paginate' => true, 'status' => 'publish', 'return' => 'ids'));
        $details['productCount'] = is_object($p) && isset($p->total) ? (int) $p->total : 0;
    }
    if (function_exists('wc_get_orders')) {
        $o = wc_get_orders(array('limit' => 1, 'paginate' => true, 'return' => 'ids'));
        $details['orderCount'] = is_object($o) && isset($o->total) ? (int) $o->total : 0;
    }
    $ucount = function_exists('count_users') ? count_users() : array('avail_roles' => array());
    $details['customerCount'] = isset($ucount['avail_roles']['customer']) ? (int) $ucount['avail_roles']['customer'] : 0;

    $priv_id = (int) get_option('wp_page_for_privacy_policy');
    $details['privacyPolicyUrl'] = $priv_id ? (get_permalink($priv_id) ?: '') : lumitalk_find_page_url(array('privacy-policy', 'privacy'));
    $terms_id = function_exists('wc_terms_and_conditions_page_id') ? (int) wc_terms_and_conditions_page_id() : (int) get_option('woocommerce_terms_page_id');
    $details['termsOfServiceUrl'] = $terms_id ? (get_permalink($terms_id) ?: '') : lumitalk_find_page_url(array('terms', 'terms-and-conditions', 'terms-of-service'));
    $details['returnPolicyUrl'] = lumitalk_find_page_url(array('refund_returns', 'refunds', 'returns', 'return-policy', 'refund-policy', 'refund-and-returns-policy'));
    $details['shippingPolicyUrl'] = lumitalk_find_page_url(array('shipping', 'shipping-policy', 'delivery', 'shipping-and-returns'));

    if (!function_exists('wc_get_products')) {
        $source = lumitalk_detect_source();
        if ($source === 'edd') {
            if (empty($details['currency']) && function_exists('edd_get_currency')) {
                $details['currency'] = edd_get_currency();
            }
            $dl = wp_count_posts('download');
            $details['productCount'] = ($dl && isset($dl->publish)) ? (int) $dl->publish : 0;
            if (function_exists('edd_count_total_customers')) {
                $details['customerCount'] = (int) edd_count_total_customers();
            }
            if (function_exists('edd_count_payments')) {
                $pc = (array) edd_count_payments();
                $details['orderCount'] = isset($pc['complete']) ? (int) $pc['complete'] : (int) array_sum(array_filter($pc, 'is_numeric'));
            }
        } else {
            $pt = post_type_exists('product') ? 'product' : 'post';
            $cnt = wp_count_posts($pt);
            $details['productCount'] = ($cnt && isset($cnt->publish)) ? (int) $cnt->publish : 0;
        }
        if (empty($details['currency'])) { $details['currency'] = 'USD'; }
        if (empty($details['timezone'])) { $details['timezone'] = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC'; }
    }
    $details['storeType'] = lumitalk_detect_source();

    return $details;
}

// -- Data source detection + catalog collection ------------------------------
function lumitalk_detect_source() {
    if (class_exists('WooCommerce')) {
        return 'woocommerce';
    }
    if (class_exists('Easy_Digital_Downloads') || function_exists('EDD') || post_type_exists('download')) {
        return 'edd';
    }
    return 'wordpress';
}

function lumitalk_source_label($source = null) {
    $source = $source ?: lumitalk_detect_source();
    $labels = array('woocommerce' => 'WooCommerce', 'edd' => 'Easy Digital Downloads', 'wordpress' => 'WordPress content');
    return isset($labels[$source]) ? $labels[$source] : 'WordPress';
}

// Build a normalized catalog (items) from the detected source.
function lumitalk_collect_catalog($source = null, $limit = 200) {
    $source = $source ?: lumitalk_detect_source();
    $items  = array();

    if ($source === 'edd') {
        $q = new WP_Query(array('post_type' => 'download', 'post_status' => 'publish',
            'posts_per_page' => $limit, 'no_found_rows' => true));
        foreach ($q->posts as $post) {
            $price = function_exists('edd_get_download_price') ? edd_get_download_price($post->ID) : get_post_meta($post->ID, 'edd_price', true);
            $cats  = wp_get_post_terms($post->ID, 'download_category', array('fields' => 'names'));
            $items[] = array(
                'external_id' => (string) $post->ID,
                'item_type'   => 'download',
                'name'        => get_the_title($post),
                'description' => wp_strip_all_tags($post->post_excerpt ? $post->post_excerpt : $post->post_content),
                'price'       => is_numeric($price) ? (float) $price : null,
                'currency'    => function_exists('edd_get_currency') ? edd_get_currency() : 'USD',
                'url'         => get_permalink($post->ID),
                'image_url'   => get_the_post_thumbnail_url($post->ID, 'medium') ?: null,
                'category'    => !is_wp_error($cats) && !empty($cats) ? implode(', ', $cats) : null,
                'status'      => 'active',
                'type'        => 'digital',
            );
        }
        wp_reset_postdata();
    } elseif ($source === 'woocommerce' && function_exists('wc_get_products')) {
        $products = wc_get_products(array('limit' => $limit, 'status' => 'publish'));
        foreach ($products as $product) {
            $cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $items[] = array(
                'external_id'        => (string) $product->get_id(),
                'item_type'          => 'product',
                'name'               => $product->get_name(),
                'description'        => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                'sku'                => $product->get_sku(),
                'price'              => $product->get_price() !== '' ? (float) $product->get_price() : null,
                'currency'           => get_woocommerce_currency(),
                'url'                => get_permalink($product->get_id()),
                'image_url'          => wp_get_attachment_url($product->get_image_id()) ?: null,
                'category'           => !is_wp_error($cats) && !empty($cats) ? implode(', ', $cats) : null,
                'quantity_available' => $product->get_stock_quantity(),
                'status'             => $product->get_status() === 'publish' ? 'active' : 'inactive',
                'type'               => $product->is_virtual() ? 'digital' : 'physical',
            );
        }
    } else {
        $pt = post_type_exists('product') ? 'product' : 'page';
        $q  = new WP_Query(array('post_type' => $pt, 'post_status' => 'publish',
            'posts_per_page' => $limit, 'no_found_rows' => true));
        foreach ($q->posts as $post) {
            $price = get_post_meta($post->ID, '_price', true);
            if ($price === '') { $price = get_post_meta($post->ID, 'price', true); }
            $items[] = array(
                'external_id' => (string) $post->ID,
                'item_type'   => $pt === 'product' ? 'product' : 'page',
                'name'        => get_the_title($post),
                'description' => wp_strip_all_tags($post->post_excerpt ? $post->post_excerpt : wp_trim_words($post->post_content, 120)),
                'price'       => is_numeric($price) ? (float) $price : null,
                'url'         => get_permalink($post->ID),
                'image_url'   => get_the_post_thumbnail_url($post->ID, 'medium') ?: null,
                'status'      => 'active',
                'type'        => $pt === 'product' ? 'physical' : 'content',
            );
        }
        wp_reset_postdata();
    }
    foreach ($items as &$it) {
        if (isset($it['name']))        { $it['name']        = html_entity_decode($it['name'], ENT_QUOTES, 'UTF-8'); }
        if (isset($it['description'])) { $it['description'] = html_entity_decode($it['description'], ENT_QUOTES, 'UTF-8'); }
    }
    unset($it);
    return $items;
}

// -- LumiTalk API helpers (token-authenticated; no iframe, no browser token) --
function lumitalk_embed_get($path) {
    $s = lumitalk_get_settings();
    if (empty($s['embed_token'])) { return null; }
    $r = wp_remote_get(lumitalk_connect_base() . $path, array(
        'timeout' => 15,
        'headers' => array('Authorization' => 'Bearer ' . $s['embed_token']),
    ));
    if (is_wp_error($r)) { return null; }
    return json_decode(wp_remote_retrieve_body($r), true);
}

function lumitalk_embed_post($path, $body) {
    $s = lumitalk_get_settings();
    if (empty($s['embed_token'])) { return null; }
    $r = wp_remote_post(lumitalk_connect_base() . $path, array(
        'timeout' => 20,
        'headers' => array('Authorization' => 'Bearer ' . $s['embed_token'], 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($r)) { return null; }
    return json_decode(wp_remote_retrieve_body($r), true);
}

// Fetch the plan catalogue (public endpoint) for the given channels.
function lumitalk_fetch_plans($channels_csv) {
    $url = lumitalk_api_url() . '/api/public/stripe-plans?channels=' . rawurlencode($channels_csv) . '&tenant_id=default&t=' . time();
    $r = wp_remote_get($url, array('timeout' => 15));
    if (is_wp_error($r)) { return array(); }
    $d = json_decode(wp_remote_retrieve_body($r), true);
    return (!empty($d['success']) && !empty($d['plans'])) ? $d['plans'] : array();
}

// Normalize a plan's first price → array(id, display, interval).
function lumitalk_plan_price($plan) {
    $prices = (isset($plan['prices']) && is_array($plan['prices'])) ? $plan['prices'] : array();
    if (empty($prices)) { return array('id' => '', 'display' => 'Free', 'interval' => ''); }
    $p = $prices[0];
    foreach ($prices as $pp) { // prefer a monthly price if present
        if (isset($pp['recurring']['interval']) && $pp['recurring']['interval'] === 'month') { $p = $pp; break; }
    }
    $interval = isset($p['recurring']['interval']) ? $p['recurring']['interval'] : '';
    $display  = isset($p['display_amount']) ? $p['display_amount']
        : (isset($p['unit_amount']) ? '$' . number_format($p['unit_amount'] / 100, 2) : '');
    return array('id' => isset($p['id']) ? $p['id'] : '', 'display' => $display, 'interval' => $interval);
}

// Redirect helpers.
function lumitalk_redirect_with($key, $value) {
    wp_safe_redirect(add_query_arg(array('page' => 'lumitalk-ai', $key => $value), admin_url('admin.php')));
    exit;
}
function lumitalk_go_step($step, $extra = array()) {
    $args = array_merge(array('page' => 'lumitalk-ai', 'step' => $step), $extra);
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

// -- Admin menu --------------------------------------------------------------
add_action('admin_menu', function () {
    add_menu_page('LumiTalk AI', 'LumiTalk AI', 'manage_options', 'lumitalk-ai', 'lumitalk_render_admin_page', 'dashicons-format-chat', 58);
    $s = lumitalk_get_settings();
    if (!empty($s['connected'])) {
        add_submenu_page('lumitalk-ai', 'Dashboard', 'Dashboard', 'manage_options', 'lumitalk-ai', 'lumitalk_render_admin_page');
        add_submenu_page('lumitalk-ai', 'Settings', 'Settings', 'manage_options', 'lumitalk-ai-settings', 'lumitalk_render_settings');
        add_submenu_page('lumitalk-ai', 'Agent Panel', 'Agent Panel', 'manage_options', 'lumitalk-ai-agent', 'lumitalk_render_agent');
    }
});

// -- Enqueue admin CSS/JS (only on our pages) --------------------------------
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'lumitalk-ai') === false) { return; }
    wp_register_style('lumitalk-admin', false, array(), LUMITALK_VER);
    wp_enqueue_style('lumitalk-admin');
    wp_add_inline_style('lumitalk-admin', lumitalk_admin_css());

    wp_register_script('lumitalk-admin', false, array(), LUMITALK_VER, true);
    wp_enqueue_script('lumitalk-admin');
    wp_localize_script('lumitalk-admin', 'lumitalkAdmin', array(
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'agentNonce' => wp_create_nonce('lumitalk_agent_sso'),
    ));
    wp_add_inline_script('lumitalk-admin', lumitalk_admin_js());
});

// -- Connect handler ---------------------------------------------------------
add_action('admin_post_lumitalk_connect', function () {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    check_admin_referer('lumitalk_connect');

    if (!empty($_POST['app_base'])) {
        $s = lumitalk_get_settings();
        $s['app_base'] = esc_url_raw(wp_unslash($_POST['app_base']));
        if (!empty($_POST['connect_base'])) { $s['connect_base'] = esc_url_raw(wp_unslash($_POST['connect_base'])); }
        lumitalk_save_settings($s);
    }
    $connect_base = lumitalk_connect_base();
    $source       = lumitalk_detect_source();
    $store_host   = preg_replace('#^https?://#', '', lumitalk_store_url());
    $user         = wp_get_current_user();

    if ($source === 'woocommerce') {
        global $wpdb;
        $consumer_key    = 'ck_' . wc_rand_hash();
        $consumer_secret = 'cs_' . wc_rand_hash();
        // WooCommerce exposes no API to create a REST key; a direct insert into its own
        // table is the documented approach. One-off write, so no caching applies.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'woocommerce_api_keys',
            array(
                'user_id'         => get_current_user_id(),
                'description'     => 'LumiTalk AI (read-only)',
                'permissions'     => 'read',
                'consumer_key'    => wc_api_hash($consumer_key),
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => substr($consumer_key, -7),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
        if (false === $inserted) {
            lumitalk_redirect_with('lumitalk_error', 'Could not create a WooCommerce API key.');
        }
        $response = wp_remote_post($connect_base . '/marketplace/woocommerce/connect', array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'storeUrl'       => $store_host,
                'consumerKey'    => $consumer_key,
                'consumerSecret' => $consumer_secret,
                'email'          => $user->user_email,
                'siteName'       => get_bloginfo('name'),
                'storeData'      => lumitalk_collect_store_details(),
            )),
        ));
    } else {
        $response = wp_remote_post($connect_base . '/marketplace/wordpress/connect', array(
            'timeout' => 45,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'platform'  => 'wordpress',
                'storeUrl'  => $store_host,
                'email'     => $user->user_email,
                'siteName'  => get_bloginfo('name'),
                'storeData' => lumitalk_collect_store_details(),
                'catalog'   => lumitalk_collect_catalog($source, 500),
            )),
        ));
    }

    if (is_wp_error($response)) {
        lumitalk_redirect_with('lumitalk_error', $response->get_error_message());
    }
    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (200 !== (int) $code || empty($data['success'])) {
        $msg = isset($data['error']) ? $data['error'] : 'LumiTalk connection failed (HTTP ' . $code . ').';
        lumitalk_redirect_with('lumitalk_error', $msg);
    }

    $settings = lumitalk_get_settings();
    $settings['connected']      = true;
    $settings['source']         = ($source === 'woocommerce') ? 'woocommerce' : 'wordpress';
    $settings['application_id'] = isset($data['applicationId']) ? sanitize_text_field($data['applicationId']) : '';
    $settings['widget_key']     = isset($data['widgetKey']) ? sanitize_text_field($data['widgetKey']) : '';
    $settings['store_url']      = $store_host;
    $settings['email']          = $user->user_email;
    $settings['sign_in_url']    = isset($data['signInUrl']) ? esc_url_raw($data['signInUrl']) : '';
    $settings['embed_token']    = isset($data['embedToken']) ? sanitize_text_field($data['embedToken']) : '';
    lumitalk_save_settings($settings);

    lumitalk_go_step('channels', array('lumitalk_connected' => '1'));
});

// -- Disconnect --------------------------------------------------------------
add_action('admin_post_lumitalk_disconnect', function () {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    check_admin_referer('lumitalk_disconnect');
    delete_option(LUMITALK_OPTION);
    lumitalk_redirect_with('lumitalk_disconnected', '1');
});

// -- Toggle storefront widget on/off -----------------------------------------
add_action('admin_post_lumitalk_toggle_widget', function () {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    check_admin_referer('lumitalk_toggle_widget');
    $settings = lumitalk_get_settings();
    $settings['widget_enabled'] = empty($settings['widget_enabled']);
    lumitalk_save_settings($settings);
    lumitalk_redirect_with('lumitalk_saved', '1');
});

// -- Onboarding step: channels ----------------------------------------------
add_action('admin_post_lumitalk_onb_channels', function () {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    check_admin_referer('lumitalk_onb');
    $sel = (isset($_POST['channels']) && is_array($_POST['channels']))
        ? array_map('sanitize_key', wp_unslash($_POST['channels'])) : array();
    $ch = array('chat' => array('enabled' => false), 'voice' => array('enabled' => false),
                'sms' => array('enabled' => false), 'email' => array('enabled' => false));
    foreach ($sel as $c) { if (isset($ch[$c])) { $ch[$c]['enabled'] = true; } }
    $any = false;
    foreach ($ch as $c) { if ($c['enabled']) { $any = true; break; } }
    if (!$any) { $ch['chat']['enabled'] = true; }
    lumitalk_embed_post('/marketplace/embed/save', array('channels' => $ch));
    lumitalk_go_step('plan');
});

// -- Onboarding step: plan (+ Stripe checkout for paid tiers) ----------------
add_action('admin_post_lumitalk_onb_plan', function () {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    check_admin_referer('lumitalk_onb');
    $val   = isset($_POST['plan']) ? sanitize_text_field(wp_unslash($_POST['plan'])) : '';
    $parts = explode('|', $val);
    $plan_id  = isset($parts[0]) ? $parts[0] : '';
    $price_id = isset($parts[1]) ? $parts[1] : '';
    $tier     = isset($parts[2]) ? $parts[2] : '';
    $plan_name = isset($_POST['plan_name']) ? sanitize_text_field(wp_unslash($_POST['plan_name'])) : '';

    if ($plan_id === '') { lumitalk_go_step('plan', array('lumitalk_error' => 'Please choose a plan.')); }

    lumitalk_embed_post('/marketplace/embed/save', array('selectedPlan' => $plan_id));

    if ($tier === 'free' || $price_id === '') {
        lumitalk_go_step('assistant');
    }

    // Paid tier → create a Stripe checkout session and redirect the browser to Stripe.
    $s       = lumitalk_get_settings();
    $success = admin_url('admin.php') . '?page=lumitalk-ai&step=assistant&lumitalk_billing=success&session_id={CHECKOUT_SESSION_ID}';
    $cancel  = admin_url('admin.php') . '?page=lumitalk-ai&step=plan&lumitalk_billing=cancel';
    $r = wp_remote_post(lumitalk_api_url() . '/api/stripe-checkout/create-checkout-session', array(
        'timeout' => 25,
        // The embed token authenticates us past the backend's /api auth gate; the
        // checkout endpoint then derives tenant + email from the applicationId.
        'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $s['embed_token']),
        'body'    => wp_json_encode(array(
            'priceId'       => $price_id,
            'planId'        => $plan_id,
            'planName'      => $plan_name,
            'applicationId' => $s['application_id'],
            'successUrl'    => $success,
            'cancelUrl'     => $cancel,
            'metadata'      => array('application_id' => $s['application_id'], 'price_id' => $price_id, 'plan_id' => $plan_id),
        )),
    ));
    if (!is_wp_error($r)) {
        $d = json_decode(wp_remote_retrieve_body($r), true);
        if (!empty($d['url'])) {
            // Redirect to Stripe-hosted checkout (external PCI provider). Allow the exact
            // host returned by our authenticated API so wp_safe_redirect permits it.
            $rhost = wp_parse_url($d['url'], PHP_URL_HOST);
            if ($rhost) {
                add_filter('allowed_redirect_hosts', function ($h) use ($rhost) { $h[] = $rhost; return $h; });
            }
            wp_safe_redirect($d['url']);
            exit;
        }
        $err = isset($d['error']) ? $d['error'] : 'Could not start checkout.';
        lumitalk_go_step('plan', array('lumitalk_error' => $err));
    }
    lumitalk_go_step('plan', array('lumitalk_error' => 'Checkout request failed. Please try again.'));
});

// -- Onboarding step: AI assistant -------------------------------------------
add_action('admin_post_lumitalk_onb_assistant', function () {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    check_admin_referer('lumitalk_onb');
    $name     = isset($_POST['ai_name']) ? sanitize_text_field(wp_unslash($_POST['ai_name'])) : '';
    $greeting = isset($_POST['ai_greeting']) ? sanitize_textarea_field(wp_unslash($_POST['ai_greeting'])) : '';
    $desc     = isset($_POST['ai_desc']) ? sanitize_textarea_field(wp_unslash($_POST['ai_desc'])) : '';
    $tone     = isset($_POST['ai_tone']) ? sanitize_key(wp_unslash($_POST['ai_tone'])) : 'friendly';
    $traits   = (isset($_POST['traits']) && is_array($_POST['traits']))
        ? array_map('sanitize_key', wp_unslash($_POST['traits'])) : array();
    lumitalk_embed_post('/marketplace/embed/save', array(
        'assistant'         => array('name' => $name, 'greeting' => $greeting, 'business_description' => $desc, 'tone' => $tone),
        'personalityTraits' => $traits,
    ));
    lumitalk_go_step('review');
});

// -- Onboarding step: launch -------------------------------------------------
add_action('admin_post_lumitalk_onb_launch', function () {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    check_admin_referer('lumitalk_onb');
    lumitalk_embed_post('/marketplace/embed/save', array('launched' => true));
    // Pull the freshly-published widget key so the storefront widget goes live.
    $state = lumitalk_embed_get('/marketplace/embed/state');
    $s = lumitalk_get_settings();
    if (is_array($state) && !empty($state['widgetKey'])) {
        $s['widget_key'] = sanitize_text_field($state['widgetKey']);
    }
    $s['onboarded'] = true;
    lumitalk_save_settings($s);
    lumitalk_redirect_with('lumitalk_launched', '1');
});

// AJAX: mint a fresh single-use SSO ticket and return the agent-panel URL.
add_action('wp_ajax_lumitalk_agent_sso', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error('unauthorized', 403); }
    check_ajax_referer('lumitalk_agent_sso');
    $s = lumitalk_get_settings();
    if (empty($s['connected']) || empty($s['embed_token'])) { wp_send_json_error('not connected'); }
    $data = lumitalk_embed_get('/marketplace/embed/agent-sso');
    if (empty($data['token'])) { wp_send_json_error('Could not create a sign-in link'); }
    $app_id = !empty($s['application_id']) ? $s['application_id'] : (isset($data['applicationId']) ? $data['applicationId'] : '');
    $app_q  = 'application_id=' . rawurlencode($app_id);
    $path   = !empty($data['agentId'])
        ? '/ai-agents/convai/' . rawurlencode($data['agentId']) . '?' . $app_q
        : '/ai-agents?' . $app_q;
    $url = lumitalk_agent_base() . '/sso-callback?ticket=' . rawurlencode($data['token'])
         . '&redirect=' . rawurlencode($path) . '&origin=woocommerce';
    wp_send_json_success(array('url' => $url));
});

// ============================================================================
//  ADMIN PAGES (native — no iframe)
// ============================================================================
function lumitalk_render_admin_page() {
    $s = lumitalk_get_settings();

    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display flags set by our own redirects.
    $notice_error  = isset($_GET['lumitalk_error']) ? sanitize_text_field(wp_unslash($_GET['lumitalk_error'])) : '';
    $notice_disc   = isset($_GET['lumitalk_disconnected']);
    $notice_launch = isset($_GET['lumitalk_launched']);
    $billing       = isset($_GET['lumitalk_billing']) ? sanitize_key($_GET['lumitalk_billing']) : '';
    $step          = isset($_GET['step']) ? sanitize_key($_GET['step']) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    if (empty($s['connected'])) {
        lumitalk_render_preconnect($s, $notice_error, $notice_disc);
        return;
    }

    // Connected: fetch live state (channels/assistant/plan/launched/widgetKey).
    $state    = lumitalk_embed_get('/marketplace/embed/state');
    $launched = (is_array($state) && !empty($state['launched'])) || !empty($s['onboarded']);

    // Cache widget key + onboarded flag locally.
    if (is_array($state)) {
        $changed = false;
        if (!empty($state['widgetKey']) && $state['widgetKey'] !== $s['widget_key']) { $s['widget_key'] = sanitize_text_field($state['widgetKey']); $changed = true; }
        if ($launched && empty($s['onboarded'])) { $s['onboarded'] = true; $changed = true; }
        if ($changed) { lumitalk_save_settings($s); }
    }

    if ($launched && $step === '' && $billing === '') {
        lumitalk_render_dashboard($s, $state, $notice_launch);
        return;
    }

    $valid = array('channels', 'plan', 'assistant', 'review');
    if (!in_array($step, $valid, true)) { $step = 'channels'; }
    lumitalk_render_onboarding($s, is_array($state) ? $state : array(), $step, $notice_error, $billing);
}

// -- Pre-connect welcome + Connect button ------------------------------------
function lumitalk_render_preconnect($s, $notice_error, $notice_disc) {
    $source     = lumitalk_detect_source();
    $store_host = preg_replace('#^https?://#', '', lumitalk_store_url());
    $admin_mail = wp_get_current_user()->user_email;
    $logo       = esc_url(lumitalk_app_base() . '/lumitalk_logo.png');
    ?>
    <div class="lumi-app">
        <div class="lumi-hd"><div class="lumi-hd-in">
            <img src="<?php echo esc_url($logo); ?>" alt="LumiTalk" onerror="this.style.display='none'" />
            <div><div class="t">LumiTalk AI</div><div class="s">Connect your store</div></div>
        </div></div>

        <div class="lumi-body"><div class="lumi-panel">
            <?php if ('' !== $notice_error) : ?><div class="lumi-alert err"><?php echo esc_html($notice_error); ?></div><?php endif; ?>
            <?php if ($notice_disc) : ?><div class="lumi-alert ok">Disconnected from LumiTalk.</div><?php endif; ?>

            <h1>Add AI customer service to your store</h1>
            <p class="lumi-sub">Connect your store to LumiTalk and its AI answers customer questions about your products, orders, and content &mdash; with a chat widget live on your storefront in minutes.</p>

            <div class="lumi-alert ok"><strong>Detected data source: <?php echo esc_html(lumitalk_source_label($source)); ?>.</strong>
                <?php if ($source === 'woocommerce') : ?> Your products, orders, and customers sync automatically.
                <?php elseif ($source === 'edd') : ?> Your Easy Digital Downloads products are sent to LumiTalk.
                <?php else : ?> Your site content is sent to LumiTalk so the AI can answer questions about it.
                <?php endif; ?>
            </div>

            <div class="lumi-grid">
                <div class="lumi-tile"><strong>&#128172; AI Chat</strong><span>24/7 chat widget on your storefront</span></div>
                <div class="lumi-tile"><strong>&#128222; AI Voice</strong><span>AI answers your phone line</span></div>
                <div class="lumi-tile"><strong>&#128241; SMS</strong><span>Two-way SMS support</span></div>
                <div class="lumi-tile"><strong>&#9993;&#65039; Email</strong><span>AI-assisted email replies</span></div>
            </div>

            <div class="lumi-meta">
                <span><em>Store</em> <code><?php echo esc_html($store_host); ?></code></span>
                <span><em>Admin</em> <code><?php echo esc_html($admin_mail); ?></code></span>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lumitalk_connect" />
                <?php wp_nonce_field('lumitalk_connect'); ?>
                <button type="submit" class="lumi-b primary" style="width:100%;">Connect to LumiTalk</button>
                <p class="lumi-note">
                    <?php echo ($source === 'woocommerce')
                        ? 'We create a read-only WooCommerce API key for you &mdash; no keys to copy by hand.'
                        : 'Your store data is sent securely to LumiTalk &mdash; nothing to copy by hand.'; ?>
                </p>
                <details class="lumi-adv">
                    <summary>Advanced settings</summary>
                    <label>LumiTalk app URL <small>(leave as-is unless testing)</small></label>
                    <input type="url" name="app_base" value="<?php echo esc_attr(lumitalk_app_base()); ?>" />
                </details>
            </form>
        </div></div>
        <div class="lumi-foot">&copy; <?php echo esc_html(gmdate('Y')); ?> LumiTalk &bull; Need help? <a href="mailto:support@lumitalk.ai">Contact Support</a></div>
    </div>
    <?php
}

// -- Native onboarding wizard (steps) ----------------------------------------
function lumitalk_render_onboarding($s, $state, $step, $notice_error, $billing) {
    $steps = array('channels' => 'Channels', 'plan' => 'Plan', 'assistant' => 'Assistant', 'review' => 'Review');
    $keys  = array_keys($steps);
    $idx   = array_search($step, $keys, true);
    $prev  = ($idx > 0) ? $keys[$idx - 1] : '';
    $ch    = isset($state['channels']) && is_array($state['channels']) ? $state['channels'] : array();
    $logo  = esc_url(lumitalk_app_base() . '/lumitalk_logo.png');
    ?>
    <div class="lumi-app">
        <div class="lumi-hd"><div class="lumi-hd-in">
            <img src="<?php echo esc_url($logo); ?>" alt="LumiTalk" onerror="this.style.display='none'" />
            <div><div class="t">Setup Wizard</div><div class="s">Configure your AI assistant</div></div>
        </div></div>

        <div class="lumi-prog">
            <?php $i = 0; foreach ($steps as $k => $label) :
                $cls = ($i < $idx) ? 'done' : (($i === $idx) ? 'now' : ''); ?>
                <?php if ($i > 0) : ?><div class="lumi-line <?php echo ($i <= $idx) ? 'done' : ''; ?>"></div><?php endif; ?>
                <div class="lumi-st <?php echo esc_attr($cls); ?>">
                    <div class="b"><?php echo ($i < $idx) ? '&#10003;' : esc_html((string) ($i + 1)); ?></div>
                    <div class="l"><?php echo esc_html($label); ?></div>
                </div>
            <?php $i++; endforeach; ?>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('lumitalk_onb'); ?>
            <div class="lumi-body"><div class="lumi-panel">
                <?php if ('' !== $notice_error) : ?><div class="lumi-alert err"><?php echo esc_html($notice_error); ?></div><?php endif; ?>
                <?php if ($billing === 'success') : ?><div class="lumi-alert ok">Subscription active. Let&rsquo;s finish setting up your assistant.</div><?php endif; ?>
                <?php if ($billing === 'cancel') : ?><div class="lumi-alert warn">Checkout canceled &mdash; pick a plan to continue.</div><?php endif; ?>

                <?php if ($step === 'channels') : ?>
                    <input type="hidden" name="action" value="lumitalk_onb_channels" />
                    <h1>Which channels should the AI handle?</h1>
                    <p class="lumi-sub">Chat runs on your storefront. Voice, SMS and email are set up in the LumiTalk dashboard afterward.</p>
                    <?php
                    $opts = array(
                        'chat'  => array('&#128172; AI Chat', 'Chat widget on your storefront'),
                        'voice' => array('&#128222; AI Voice', 'AI answers your phone line'),
                        'sms'   => array('&#128241; SMS', 'Two-way SMS support'),
                        'email' => array('&#9993;&#65039; Email', 'AI-assisted email replies'),
                    );
                    foreach ($opts as $key => $o) :
                        $on = !empty($ch[$key]['enabled']) || ($key === 'chat' && empty($ch)); ?>
                        <label class="lumi-check">
                            <input type="checkbox" name="channels[]" value="<?php echo esc_attr($key); ?>" <?php checked($on); ?> />
                            <span><strong><?php echo wp_kses_post($o[0]); ?></strong><small><?php echo esc_html($o[1]); ?></small></span>
                        </label>
                    <?php endforeach; ?>

                <?php elseif ($step === 'plan') : ?>
                    <input type="hidden" name="action" value="lumitalk_onb_plan" />
                    <input type="hidden" name="plan_name" value="" id="lumi-plan-name" />
                    <h1>Choose your plan</h1>
                    <p class="lumi-sub">Start free and upgrade anytime. Paid plans open secure Stripe checkout.</p>
                    <?php
                    $enabled = array();
                    foreach (array('chat', 'voice', 'sms', 'email') as $c) { if (!empty($ch[$c]['enabled'])) { $enabled[] = $c; } }
                    if (empty($enabled)) { $enabled = array('chat'); }
                    $plans = lumitalk_fetch_plans(implode(',', $enabled));
                    $seen_tiers = array();
                    if (empty($plans)) : ?>
                        <div class="lumi-alert warn">Couldn&rsquo;t load plans right now. <a href="<?php echo esc_url(add_query_arg(array('page' => 'lumitalk-ai', 'step' => 'plan'), admin_url('admin.php'))); ?>">Retry</a>.</div>
                    <?php else : ?>
                        <div class="lumi-plans">
                        <?php foreach ($plans as $plan) :
                            $tier = isset($plan['metadata']['tier']) ? $plan['metadata']['tier'] : '';
                            if ($tier === '' || isset($seen_tiers[$tier])) { continue; }
                            $seen_tiers[$tier] = true;
                            $price = lumitalk_plan_price($plan);
                            $val   = $plan['id'] . '|' . $price['id'] . '|' . $tier;
                            $badge = ($tier === 'professional') ? 'RECOMMENDED' : '';
                            $checked = (!empty($state['selectedPlan']) ? ($state['selectedPlan'] === $plan['id']) : ($tier === 'free')); ?>
                            <label class="lumi-plan<?php echo $checked ? ' sel' : ''; ?>">
                                <?php if ($badge) : ?><span class="badge"><?php echo esc_html($badge); ?></span><?php endif; ?>
                                <input type="radio" name="plan" value="<?php echo esc_attr($val); ?>" data-name="<?php echo esc_attr($plan['name']); ?>" <?php checked($checked); ?> />
                                <div class="lumi-plan-name"><?php echo esc_html(ucfirst($tier)); ?></div>
                                <div class="lumi-plan-price"><?php echo esc_html($price['display'] ? $price['display'] : 'Free'); ?><?php echo $price['interval'] ? '<small>/' . esc_html($price['interval']) . '</small>' : ''; ?></div>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php elseif ($step === 'assistant') : ?>
                    <input type="hidden" name="action" value="lumitalk_onb_assistant" />
                    <?php
                    $a    = isset($state['assistant']) && is_array($state['assistant']) ? $state['assistant'] : array();
                    $prof = isset($state['storeProfile']) && is_array($state['storeProfile']) ? $state['storeProfile'] : array();
                    $name = !empty($a['name']) ? $a['name'] : (get_bloginfo('name') . ' Assistant');
                    $greet = !empty($a['greeting']) ? $a['greeting'] : 'Hi! How can I help you today?';
                    $desc  = !empty($a['business_description']) ? $a['business_description'] : (isset($prof['description']) ? $prof['description'] : get_bloginfo('description'));
                    $tone  = !empty($a['tone']) ? $a['tone'] : 'friendly';
                    $tr    = isset($state['personalityTraits']) && is_array($state['personalityTraits']) ? $state['personalityTraits'] : array('helpful', 'concise');
                    ?>
                    <h1>Set up your AI assistant</h1>
                    <p class="lumi-sub">This is how the AI introduces itself and speaks to your customers.</p>
                    <label class="lumi-field">Assistant name
                        <input type="text" name="ai_name" value="<?php echo esc_attr($name); ?>" maxlength="60" />
                    </label>
                    <label class="lumi-field">Greeting message
                        <textarea name="ai_greeting" rows="2" maxlength="300"><?php echo esc_textarea($greet); ?></textarea>
                    </label>
                    <label class="lumi-field">What does your business do?
                        <textarea name="ai_desc" rows="3" maxlength="600"><?php echo esc_textarea($desc); ?></textarea>
                    </label>
                    <label class="lumi-field">Tone
                        <select name="ai_tone">
                            <?php foreach (array('friendly', 'professional', 'casual', 'enthusiastic', 'formal') as $t) : ?>
                                <option value="<?php echo esc_attr($t); ?>" <?php selected($tone, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="lumi-field">Personality traits
                        <div class="lumi-traits">
                            <?php foreach (array('helpful', 'concise', 'empathetic', 'knowledgeable', 'proactive', 'patient') as $t) : ?>
                                <label class="lumi-tag"><input type="checkbox" name="traits[]" value="<?php echo esc_attr($t); ?>" <?php checked(in_array($t, $tr, true)); ?> /> <?php echo esc_html(ucfirst($t)); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else : // review ?>
                    <input type="hidden" name="action" value="lumitalk_onb_launch" />
                    <?php
                    $a = isset($state['assistant']) && is_array($state['assistant']) ? $state['assistant'] : array();
                    $enabled = array();
                    foreach (array('chat', 'voice', 'sms', 'email') as $c) { if (!empty($ch[$c]['enabled'])) { $enabled[] = ucfirst($c); } }
                    $know = isset($state['knowledge']['productCount']) ? (int) $state['knowledge']['productCount'] : 0;
                    ?>
                    <h1>Review &amp; launch</h1>
                    <p class="lumi-sub">Everything looks good? Launch to make your AI assistant live.</p>
                    <table class="lumi-review">
                        <tr><th>Assistant</th><td><?php echo esc_html(!empty($a['name']) ? $a['name'] : '&mdash;'); ?></td></tr>
                        <tr><th>Channels</th><td><?php echo esc_html($enabled ? implode(', ', $enabled) : 'Chat'); ?></td></tr>
                        <tr><th>Products synced</th><td><?php echo esc_html((string) $know); ?></td></tr>
                    </table>
                <?php endif; ?>
            </div></div>

            <div class="lumi-nav">
                <?php if ($prev) : ?>
                    <a class="lumi-b secondary" href="<?php echo esc_url(add_query_arg(array('page' => 'lumitalk-ai', 'step' => $prev), admin_url('admin.php'))); ?>">&larr; Back</a>
                <?php else : ?><span></span><?php endif; ?>
                <span class="lumi-pill">Step <?php echo esc_html((string) ($idx + 1)); ?> of <?php echo esc_html((string) count($steps)); ?></span>
                <button type="submit" class="lumi-b primary"><?php echo ($step === 'review') ? 'Launch &#128640;' : 'Continue &rarr;'; ?></button>
            </div>
        </form>

        <div class="lumi-foot">&copy; <?php echo esc_html(gmdate('Y')); ?> LumiTalk &bull; Need help? <a href="mailto:support@lumitalk.ai">Contact Support</a></div>
    </div>
    <?php
}

// -- Native dashboard (post-launch) ------------------------------------------
function lumitalk_render_dashboard($s, $state, $notice_launch) {
    $ch = isset($state['channels']) && is_array($state['channels']) ? $state['channels'] : array();
    $enabled = array();
    foreach (array('chat', 'voice', 'sms', 'email') as $c) { if (!empty($ch[$c]['enabled'])) { $enabled[] = ucfirst($c); } }
    $know = isset($state['knowledge']['productCount']) ? (int) $state['knowledge']['productCount'] : 0;
    $app_name = isset($state['applicationName']) ? $state['applicationName'] : get_bloginfo('name');
    $plan = !empty($state['selectedPlan']) ? $state['selectedPlan'] : 'Free';
    $widget_live = !empty($s['widget_enabled']) && !empty($s['widget_key']);
    $toggle = wp_nonce_url(admin_url('admin-post.php?action=lumitalk_toggle_widget'), 'lumitalk_toggle_widget');
    $edit   = add_query_arg(array('page' => 'lumitalk-ai', 'step' => 'channels'), admin_url('admin.php'));
    $logo   = esc_url(lumitalk_app_base() . '/lumitalk_logo.png');
    ?>
    <div class="lumi-app">
        <div class="lumi-hd"><div class="lumi-hd-in">
            <img src="<?php echo esc_url($logo); ?>" alt="LumiTalk" onerror="this.style.display='none'" />
            <div><div class="t">LumiTalk AI</div><div class="s">Dashboard</div></div>
        </div></div>

        <div class="lumi-body"><div class="lumi-panel">
            <?php if ($notice_launch) : ?><div class="lumi-alert ok">&#127881; Your AI assistant is live!</div><?php endif; ?>
            <h1><?php echo esc_html($app_name); ?></h1>
            <p class="lumi-sub">Your assistant is set up. Manage conversations in the LumiTalk agent panel.</p>

            <div class="lumi-stats">
                <div class="lumi-stat"><em>Storefront widget</em><strong class="<?php echo $widget_live ? 'live' : 'off'; ?>"><?php echo $widget_live ? 'Live' : 'Off'; ?></strong></div>
                <div class="lumi-stat"><em>Plan</em><strong><?php echo esc_html(ucfirst($plan)); ?></strong></div>
                <div class="lumi-stat"><em>Channels</em><strong><?php echo esc_html($enabled ? implode(', ', $enabled) : 'Chat'); ?></strong></div>
                <div class="lumi-stat"><em>Products</em><strong><?php echo esc_html((string) $know); ?></strong></div>
            </div>

            <div class="lumi-actions">
                <button id="lumitalk-open-agent" class="lumi-b primary">Open Agent Panel &#8599;</button>
                <a class="lumi-b secondary" href="<?php echo esc_url($edit); ?>">Edit configuration</a>
                <a class="lumi-link" href="<?php echo esc_url($toggle); ?>"><?php echo $widget_live ? 'Turn widget off' : 'Turn widget on'; ?></a>
            </div>
        </div></div>
        <div class="lumi-foot">&copy; <?php echo esc_html(gmdate('Y')); ?> LumiTalk &bull; Need help? <a href="mailto:support@lumitalk.ai">Contact Support</a></div>
    </div>
    <?php
}

// -- Settings sub-tab (native) -----------------------------------------------
function lumitalk_render_settings() {
    $s = lumitalk_get_settings();
    if (empty($s['connected'])) {
        echo '<div class="wrap"><h1>Settings</h1><p>Connect to LumiTalk first from the <strong>Dashboard</strong> tab.</p></div>';
        return;
    }
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $saved = isset($_GET['lumitalk_saved']);
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $disconnect = wp_nonce_url(admin_url('admin-post.php?action=lumitalk_disconnect'), 'lumitalk_disconnect');
    $edit = add_query_arg(array('page' => 'lumitalk-ai', 'step' => 'channels'), admin_url('admin.php'));
    $widget_live = !empty($s['widget_enabled']) && !empty($s['widget_key']);
    $toggle = wp_nonce_url(admin_url('admin-post.php?action=lumitalk_toggle_widget'), 'lumitalk_toggle_widget');
    $logo   = esc_url(lumitalk_app_base() . '/lumitalk_logo.png');
    ?>
    <div class="lumi-app">
        <div class="lumi-hd"><div class="lumi-hd-in">
            <img src="<?php echo esc_url($logo); ?>" alt="LumiTalk" onerror="this.style.display='none'" />
            <div><div class="t">LumiTalk AI</div><div class="s">Settings</div></div>
        </div></div>
        <div class="lumi-body"><div class="lumi-panel">
            <?php if ($saved) : ?><div class="lumi-alert ok">Saved.</div><?php endif; ?>
            <h1>Settings</h1>

            <table class="lumi-review">
                <tr><th>Store</th><td><code><?php echo esc_html($s['store_url']); ?></code></td></tr>
                <tr><th>Data source</th><td><?php echo esc_html(lumitalk_source_label(!empty($s['source']) ? $s['source'] : null)); ?></td></tr>
                <tr><th>Storefront widget</th><td><?php echo $widget_live ? 'Live' : 'Off'; ?> &nbsp; <a class="lumi-link" href="<?php echo esc_url($toggle); ?>"><?php echo $widget_live ? 'Turn off' : 'Turn on'; ?></a></td></tr>
            </table>

            <div class="lumi-actions">
                <a class="lumi-b primary" href="<?php echo esc_url($edit); ?>">Edit AI configuration</a>
            </div>

            <details class="lumi-adv">
                <summary>Advanced &amp; disconnect</summary>
                <p class="lumi-note">LumiTalk app URL: <code><?php echo esc_html(lumitalk_app_base()); ?></code></p>
                <p class="lumi-note">Remove the LumiTalk connection from this store: <a href="<?php echo esc_url($disconnect); ?>" style="color:#b32d2e;">Disconnect</a></p>
            </details>
        </div></div>
        <div class="lumi-foot">&copy; <?php echo esc_html(gmdate('Y')); ?> LumiTalk &bull; Need help? <a href="mailto:support@lumitalk.ai">Contact Support</a></div>
    </div>
    <?php
}

// -- Agent Panel sub-tab (link-out, new tab) ---------------------------------
function lumitalk_render_agent() {
    $s = lumitalk_get_settings();
    if (empty($s['connected'])) {
        echo '<div class="wrap"><h1>Agent Panel</h1><p>Connect to LumiTalk first from the <strong>Dashboard</strong> tab.</p></div>';
        return;
    }
    $logo = esc_url(lumitalk_app_base() . '/lumitalk_logo.png');
    ?>
    <div class="lumi-app">
        <div class="lumi-hd"><div class="lumi-hd-in">
            <img src="<?php echo esc_url($logo); ?>" alt="LumiTalk" onerror="this.style.display='none'" />
            <div><div class="t">LumiTalk AI</div><div class="s">Agent Panel</div></div>
        </div></div>
        <div class="lumi-body"><div class="lumi-panel" style="text-align:center;">
            <h1>Your Agent Dashboard</h1>
            <p class="lumi-sub" style="margin-left:auto;margin-right:auto;">Manage conversations, AI agents, and your inbox in the LumiTalk agent panel. Opens in a new tab, already signed in.</p>
            <div class="lumi-actions" style="justify-content:center;">
                <button id="lumitalk-open-agent" class="lumi-b primary">Open Agent Dashboard &#8599;</button>
            </div>
        </div></div>
        <div class="lumi-foot">&copy; <?php echo esc_html(gmdate('Y')); ?> LumiTalk &bull; Need help? <a href="mailto:support@lumitalk.ai">Contact Support</a></div>
    </div>
    <?php
}

// -- Admin CSS / JS (enqueued as inline on our pages) ------------------------
function lumitalk_admin_css() {
    // Matches the LumiTalk app onboarding (Shopify ConfigWizard): full-bleed slate
    // gradient, sticky header, circular step indicator, rounded-3xl card, slate-900
    // primary buttons, pink-accent plan cards.
    return '
    #wpcontent{padding-left:0!important}#wpbody-content{padding:0!important}
    #wpfooter{display:none!important}#wpbody-content>.notice,#wpbody-content>.update-nag{display:none!important}
    .lumi-app{min-height:calc(100vh - 32px);background:linear-gradient(135deg,#f8fafc 0%,#ffffff 45%,#f1f5f9 100%);
        font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;}
    .lumi-app *{box-sizing:border-box;}
    .lumi-hd{position:sticky;top:32px;z-index:30;background:rgba(255,255,255,.82);backdrop-filter:blur(8px);border-bottom:1px solid #e2e8f0;}
    .lumi-hd-in{max-width:56rem;margin:0 auto;padding:14px 24px;display:flex;align-items:center;gap:12px;}
    .lumi-hd img{height:42px;width:auto;}
    .lumi-hd .t{font-size:14px;font-weight:600;color:#0f172a;line-height:1.2;}
    .lumi-hd .s{font-size:12px;color:#64748b;}
    .lumi-prog{max-width:56rem;margin:0 auto;padding:26px 24px 6px;display:flex;align-items:center;justify-content:center;gap:6px;}
    .lumi-st{display:flex;flex-direction:column;align-items:center;}
    .lumi-st .b{width:40px;height:40px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;background:#f1f5f9;color:#94a3b8;box-shadow:inset 0 0 0 1px #e2e8f0;}
    .lumi-st.now .b{background:#0f172a;color:#fff;box-shadow:0 8px 20px -6px rgba(15,23,42,.45);}
    .lumi-st.done .b{background:#ecfdf5;color:#059669;box-shadow:inset 0 0 0 1px #a7f3d0;}
    .lumi-st .l{margin-top:8px;font-size:11px;font-weight:500;color:#94a3b8;}
    .lumi-st.now .l{color:#0f172a;}.lumi-st.done .l{color:#059669;}
    .lumi-line{height:2px;width:38px;border-radius:2px;background:#e2e8f0;margin:0 2px 22px;}
    .lumi-line.done{background:#a7f3d0;}
    .lumi-body{max-width:48rem;margin:0 auto;padding:18px 24px 8px;}
    .lumi-panel{background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:34px;box-shadow:0 24px 48px -24px rgba(148,163,184,.55);}
    .lumi-panel h1{font-size:22px;font-weight:800;color:#0f172a;margin:0 0 6px;letter-spacing:-.01em;}
    .lumi-sub{font-size:14px;color:#64748b;line-height:1.6;margin:0 0 24px;}
    .lumi-brand{display:none;}
    .lumi-b{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px 22px;font-size:14px;font-weight:600;cursor:pointer;border:0;text-decoration:none;line-height:1.2;transition:.15s;}
    .lumi-b.primary{background:#0f172a;color:#fff;}.lumi-b.primary:hover{background:#1e293b;color:#fff;}
    .lumi-b.secondary{background:#fff;color:#0f172a;box-shadow:inset 0 0 0 1px #e2e8f0;}.lumi-b.secondary:hover{background:#f8fafc;color:#0f172a;}
    .lumi-b:disabled{opacity:.5;cursor:not-allowed;}
    .lumi-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px 22px;font-size:14px;font-weight:600;cursor:pointer;border:0;text-decoration:none;line-height:1.2;background:#0f172a;color:#fff;transition:.15s;}
    .lumi-btn:hover{background:#1e293b;color:#fff;}
    .lumi-btn.ghost{background:#fff;color:#0f172a;box-shadow:inset 0 0 0 1px #e2e8f0;}.lumi-btn.ghost:hover{background:#f8fafc;color:#0f172a;}
    .lumi-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:500;background:#f8fafc;color:#475569;box-shadow:inset 0 0 0 1px #e2e8f0;}
    .lumi-link{color:#475569;text-decoration:none;font-size:13px;font-weight:600;}.lumi-link:hover{color:#0f172a;}
    .lumi-note{font-size:12.5px;color:#94a3b8;margin:14px 0 0;}
    .lumi-nav{max-width:48rem;margin:22px auto 0;padding:0 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;}
    .lumi-actions{display:flex;align-items:center;gap:14px;margin-top:26px;flex-wrap:wrap;}
    .lumi-foot{max-width:48rem;margin:34px auto 0;padding:0 24px 30px;text-align:center;font-size:12px;color:#94a3b8;}
    .lumi-foot a{color:#475569;}
    .lumi-alert{border-radius:12px;padding:12px 16px;font-size:13.5px;margin:0 0 18px;}
    .lumi-alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;}
    .lumi-alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
    .lumi-alert.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
    .lumi-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 22px;}
    .lumi-tile{border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;background:#f8fafc;}
    .lumi-tile strong{display:block;font-size:13.5px;color:#0f172a;}
    .lumi-tile span{font-size:12px;color:#64748b;}
    .lumi-meta{display:flex;flex-wrap:wrap;gap:8px 22px;background:#f8fafc;border-radius:14px;padding:14px 16px;margin:0 0 22px;font-size:13px;}
    .lumi-meta em{color:#94a3b8;font-style:normal;margin-right:6px;}
    .lumi-meta code{background:transparent;color:#0f172a;}
    .lumi-check{display:flex;gap:12px;align-items:center;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px 16px;margin:0 0 10px;cursor:pointer;transition:.15s;}
    .lumi-check:hover{border-color:#cbd5e1;}
    .lumi-check:has(input:checked){border-color:#ec4899;background:#fdf2f8;}
    .lumi-check input{width:18px;height:18px;accent-color:#ec4899;}
    .lumi-check strong{display:block;font-size:14px;color:#0f172a;}
    .lumi-check small{color:#64748b;font-size:12.5px;}
    .lumi-plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:6px 0 4px;}
    .lumi-plan{position:relative;border:2px solid #cbd5e1;border-radius:14px;padding:20px 14px 14px;cursor:pointer;transition:.15s;text-align:center;display:block;}
    .lumi-plan:hover{border-color:#fda4b8;box-shadow:0 8px 18px -8px rgba(0,0,0,.15);}
    .lumi-plan.sel{border-color:#ec4899;background:#fdf2f8;box-shadow:0 10px 22px -8px rgba(236,72,153,.4);}
    .lumi-plan input{position:absolute;opacity:0;pointer-events:none;}
    .lumi-plan .badge{position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:#db2777;color:#fff;font-size:9px;font-weight:800;padding:3px 10px;border-radius:999px;white-space:nowrap;letter-spacing:.04em;}
    .lumi-plan-name{font-size:13px;font-weight:700;color:#0f172a;margin:0 0 4px;}
    .lumi-plan-price{font-size:20px;font-weight:800;color:#db2777;}
    .lumi-plan-price small{font-size:11px;color:#64748b;font-weight:500;}
    .lumi-field{display:block;font-size:13px;font-weight:600;color:#334155;margin:0 0 16px;}
    .lumi-field input[type=text],.lumi-field textarea,.lumi-field select{display:block;width:100%;margin-top:6px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:13.5px;color:#0f172a;font-weight:400;background:#fff;}
    .lumi-field input:focus,.lumi-field textarea:focus,.lumi-field select:focus{outline:none;border-color:#94a3b8;box-shadow:0 0 0 3px rgba(148,163,184,.18);}
    .lumi-traits{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
    .lumi-tag{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;border-radius:999px;padding:7px 13px;font-size:12.5px;font-weight:500;color:#334155;cursor:pointer;}
    .lumi-tag:has(input:checked){border-color:#ec4899;background:#fdf2f8;color:#be185d;}
    .lumi-tag input{accent-color:#ec4899;}
    .lumi-review{width:100%;border-collapse:collapse;margin:4px 0;}
    .lumi-review th{text-align:left;padding:12px 0;color:#64748b;font-size:13px;font-weight:600;width:40%;border-bottom:1px solid #f1f5f9;vertical-align:top;}
    .lumi-review td{padding:12px 0;color:#0f172a;font-size:13.5px;font-weight:500;border-bottom:1px solid #f1f5f9;}
    .lumi-stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:8px 0;}
    .lumi-stat{border:1px solid #e2e8f0;border-radius:14px;padding:16px;background:#f8fafc;}
    .lumi-stat em{display:block;font-style:normal;font-size:12px;color:#94a3b8;margin-bottom:5px;}
    .lumi-stat strong{font-size:18px;color:#0f172a;font-weight:800;}
    .lumi-stat strong.live{color:#059669;}.lumi-stat strong.off{color:#94a3b8;}
    .lumi-adv{margin-top:22px;border-top:1px solid #e2e8f0;padding-top:16px;}
    .lumi-adv summary{cursor:pointer;font-size:13px;color:#64748b;font-weight:600;}
    .lumi-adv label{display:block;font-size:12px;font-weight:600;color:#475569;margin:12px 0 5px;}
    .lumi-adv input{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:13px;}
    @media(max-width:600px){.lumi-grid,.lumi-stats{grid-template-columns:1fr;}.lumi-st .l{display:none;}.lumi-panel{padding:24px;}}
    ';
}

function lumitalk_admin_js() {
    return '(function(){
        var L = window.lumitalkAdmin || {};
        var btn = document.getElementById("lumitalk-open-agent");
        if (btn) {
            btn.addEventListener("click", function(){
                var tab = window.open("about:blank","_blank");
                var orig = btn.innerHTML; btn.disabled = true; btn.textContent = "Opening…";
                var b = new URLSearchParams(); b.set("action","lumitalk_agent_sso"); b.set("_wpnonce", L.agentNonce || "");
                fetch(L.ajaxUrl, {method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:b.toString()})
                    .then(function(r){return r.json();}).then(function(j){
                        btn.disabled=false; btn.innerHTML=orig;
                        if(j&&j.success&&j.data&&j.data.url){ if(tab)tab.location.href=j.data.url; else window.open(j.data.url,"_blank"); }
                        else { if(tab)tab.close(); alert("Could not open the agent panel. Please try again."); }
                    }).catch(function(){ btn.disabled=false; btn.innerHTML=orig; if(tab)tab.close(); });
            });
        }
        var cards = document.querySelectorAll(".lumi-plan");
        var nameField = document.getElementById("lumi-plan-name");
        function sync(){ cards.forEach(function(c){ var r=c.querySelector("input"); if(r&&r.checked){ c.classList.add("sel"); if(nameField)nameField.value=r.getAttribute("data-name")||""; } else { c.classList.remove("sel"); } }); }
        cards.forEach(function(c){ c.addEventListener("click", function(){ var r=c.querySelector("input"); if(r){r.checked=true; sync();} }); });
        sync();
    })();';
}

// -- Inject the chat widget on the storefront (properly enqueued) ------------
add_action('wp_enqueue_scripts', function () {
    $s = lumitalk_get_settings();
    if (empty($s['connected']) || empty($s['widget_enabled']) || empty($s['widget_key'])) {
        return;
    }
    wp_enqueue_script('lumitalk-chat-widget', lumitalk_widget_src(), array(), LUMITALK_VER, true);
    wp_script_add_data('lumitalk-chat-widget', 'lumitalk_widget_key', $s['widget_key']);
    wp_script_add_data('lumitalk-chat-widget', 'lumitalk_api_url', lumitalk_api_url());
});

add_filter('script_loader_tag', function ($tag, $handle) {
    if ('lumitalk-chat-widget' !== $handle) {
        return $tag;
    }
    $scripts = wp_scripts();
    $key = $scripts->get_data('lumitalk-chat-widget', 'lumitalk_widget_key');
    $api = $scripts->get_data('lumitalk-chat-widget', 'lumitalk_api_url');
    $attrs = sprintf(' async data-widget-key="%s" data-api-url="%s"', esc_attr($key), esc_url($api));
    return str_replace(' src=', $attrs . ' src=', $tag);
}, 10, 2);
