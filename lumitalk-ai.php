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

// Find a plan price for a specific interval ('month'|'year') → array or null.
function lumitalk_plan_price_for($plan, $interval) {
    $prices = (isset($plan['prices']) && is_array($plan['prices'])) ? $plan['prices'] : array();
    foreach ($prices as $p) {
        $iv = isset($p['recurring']['interval']) ? $p['recurring']['interval'] : '';
        if ($iv !== $interval) { continue; }
        $display = isset($p['display_amount']) ? $p['display_amount']
            : (isset($p['unit_amount']) ? '$' . number_format($p['unit_amount'] / 100, 2) : '');
        return array('id' => isset($p['id']) ? $p['id'] : '', 'display' => $display, 'interval' => $interval);
    }
    return null;
}

// Personality traits (id => [emoji entity, label]) — mirrors the app wizard's set.
function lumitalk_traits() {
    return array(
        'friendly'      => array('&#128522;', 'Friendly'),
        'professional'  => array('&#128188;', 'Professional'),
        'helpful'       => array('&#129309;', 'Helpful'),
        'enthusiastic'  => array('&#9889;', 'Enthusiastic'),
        'patient'       => array('&#129496;', 'Patient'),
        'knowledgeable' => array('&#127891;', 'Knowledgeable'),
        'empathetic'    => array('&#10084;&#65039;', 'Empathetic'),
        'concise'       => array('&#9986;&#65039;', 'Concise'),
    );
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
// Pixel-matched to the app onboarding at /embed-app-config
// (apps/frontend/src/pages/ApplicationConfigWizard.jsx + ApplicationSetup /
// PricingPlansSection / ApplicationConfig): gray-100 page, white rounded-2xl
// shadow cards, 3xl centered title, 32px round pink/green step circles with
// 10px labels, border-2 channel tiles, PricingPlansSection plan cards (blue
// selected ring, pink-to-purple Recommended badge, green conic "% Of Features"
// donut, gray Subscribe button), Global AI Settings form, and the
// "You are all set !" review with gradient card headings + "Edit +" links.
function lumitalk_render_onboarding($s, $state, $step, $notice_error, $billing) {
    $steps = array(
        'channels'  => 'Platform & Channels',
        'plan'      => 'Pricing & Features',
        'assistant' => 'AI & Channel Setup',
        'review'    => 'Review & Activate',
    );
    $keys  = array_keys($steps);
    $idx   = array_search($step, $keys, true);
    $prev  = ($idx > 0) ? $keys[$idx - 1] : '';
    $ch    = isset($state['channels']) && is_array($state['channels']) ? $state['channels'] : array();
    $enabled_keys = array();
    foreach (array('chat', 'voice', 'sms', 'email') as $c) { if (!empty($ch[$c]['enabled'])) { $enabled_keys[] = $c; } }
    $chmeta = array(
        'voice' => array('&#128222;', 'Voice', 'Phone calls with AI agents'),
        'chat'  => array('&#128172;', 'Chat', 'Live chat on your website'),
        'sms'   => array('&#128241;', 'SMS', 'Text message support'),
        'email' => array('&#9993;&#65039;', 'Email', 'Email support automation'),
    );
    ?>
    <div class="lumi-wiz">
        <div class="lumi-wrap">
            <div class="lumi-wcard lumi-whead">
                <div class="lumi-whead-top">
                    <div class="lumi-whead-l">
                        <a class="lumi-cancel" href="<?php echo esc_url(admin_url()); ?>">&larr; Cancel</a>
                    </div>
                    <div class="lumi-whead-c"><h1>Configure Application</h1></div>
                    <div class="lumi-whead-r"></div>
                </div>
                <div class="lumi-prog">
                    <?php $i = 0; $n = count($steps); foreach ($steps as $k => $label) :
                        $cls = ($i < $idx) ? 'done' : (($i === $idx) ? 'now' : ''); ?>
                        <div class="lumi-ps <?php echo esc_attr($cls); ?>">
                            <div class="lumi-ps-row">
                                <div class="lumi-ps-col">
                                    <div class="b"><?php echo ($i < $idx) ? '&#10003;' : esc_html((string) ($i + 1)); ?></div>
                                    <p class="l"><?php echo esc_html($label); ?></p>
                                </div>
                                <?php if ($i < $n - 1) : ?><div class="lumi-pline<?php echo ($i < $idx) ? ' done' : ''; ?>"><div></div></div><?php endif; ?>
                            </div>
                        </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lumitalk_onb'); ?>
                <div class="lumi-wcard lumi-wbody">
                    <?php if ('' !== $notice_error) : ?><div class="lumi-alert err"><?php echo esc_html($notice_error); ?></div><?php endif; ?>
                    <?php if ($billing === 'success') : ?><div class="lumi-alert ok"><strong>Subscription Active!</strong> Your subscription is now active. Continue setting up your application.</div><?php endif; ?>
                    <?php if ($billing === 'cancel') : ?><div class="lumi-alert warn"><strong>Checkout Canceled.</strong> You can select the free plan or try a paid plan again.</div><?php endif; ?>

                    <?php if ($step === 'channels') : ?>
                        <input type="hidden" name="action" value="lumitalk_onb_channels" />
                        <div class="lumi-urlbox">
                            <label class="lumi-flb">Your Store URL</label>
                            <input class="lumi-fin" type="text" value="<?php echo esc_attr(!empty($s['store_url']) ? $s['store_url'] : lumitalk_store_url()); ?>" readonly />
                        </div>
                        <div class="lumi-f1">
                            <span class="lumi-flb" style="margin-bottom:12px;">Select Channels</span>
                            <div class="lumi-chs">
                                <?php foreach ($chmeta as $key => $o) :
                                    $on = !empty($ch[$key]['enabled']) || ('chat' === $key && empty($ch)); ?>
                                    <label class="lumi-chc">
                                        <input type="checkbox" name="channels[]" value="<?php echo esc_attr($key); ?>" <?php checked($on); ?> />
                                        <span class="lumi-chc-ico"><?php echo wp_kses_post($o[0]); ?></span>
                                        <span class="lumi-chc-name"><?php echo esc_html($o[1]); ?></span>
                                        <span class="lumi-chc-desc"><?php echo esc_html($o[2]); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="lumi-chtag">
                                <p class="g">Choose How Your Customers Connect With You</p>
                                <p class="d">Select one or multiple channels. Our AI seamlessly handles conversations across all your chosen platforms, providing consistent, intelligent support everywhere.</p>
                            </div>
                        </div>

                    <?php elseif ($step === 'plan') : ?>
                        <input type="hidden" name="action" value="lumitalk_onb_plan" />
                        <input type="hidden" name="plan_name" value="" id="lumi-plan-name" />
                        <?php
                        $channels_for_plans = $enabled_keys ? $enabled_keys : array('chat');
                        $plans = lumitalk_fetch_plans(implode(',', $channels_for_plans));
                        $seen_tiers = array();
                        $cards = array();
                        $has_annual = false;
                        $tier_feats = array(
                            'free'         => array('10 calls/month', 'Basic AI responses', 'Community support', '1 agent seat'),
                            'starter'      => array('500 calls/month', 'Smart call routing', 'Email support', 'Up to 3 agents'),
                            'professional' => array('5000 calls/month', 'Advanced analytics', 'Priority support', 'Up to 10 agents'),
                            'enterprise'   => array('Unlimited calls', 'Custom AI training', 'Dedicated support', 'Unlimited agents'),
                        );
                        $tier_cov = array('free' => 25, 'starter' => 50, 'professional' => 75, 'enterprise' => 100);
                        foreach ($plans as $plan) {
                            $tier = isset($plan['metadata']['tier']) ? strtolower($plan['metadata']['tier']) : '';
                            if ($tier === '' || isset($seen_tiers[$tier])) { continue; }
                            $seen_tiers[$tier] = true;
                            $m = lumitalk_plan_price($plan);
                            $y = lumitalk_plan_price_for($plan, 'year');
                            $m_amt = null;
                            $y_amt = null;
                            foreach ((array) (isset($plan['prices']) ? $plan['prices'] : array()) as $pp) {
                                $iv = isset($pp['recurring']['interval']) ? $pp['recurring']['interval'] : '';
                                if ('month' === $iv && null === $m_amt && isset($pp['unit_amount'])) { $m_amt = (int) $pp['unit_amount']; }
                                if ('year' === $iv && null === $y_amt && isset($pp['unit_amount'])) { $y_amt = (int) $pp['unit_amount']; }
                            }
                            if ($y) { $has_annual = true; }
                            $cards[] = array('plan' => $plan, 'tier' => $tier, 'm' => $m, 'y' => $y, 'm_amt' => $m_amt, 'y_amt' => $y_amt);
                        }
                        // Selected tier: saved plan if it matches, else Professional (app default), else first.
                        $sel_tier = '';
                        foreach ($cards as $cdef) {
                            if (!empty($state['selectedPlan']) && $state['selectedPlan'] === $cdef['plan']['id']) { $sel_tier = $cdef['tier']; }
                        }
                        if ('' === $sel_tier) {
                            $tiers_present = array();
                            foreach ($cards as $cdef) { $tiers_present[] = $cdef['tier']; }
                            $sel_tier = in_array('professional', $tiers_present, true) ? 'professional' : (isset($tiers_present[0]) ? $tiers_present[0] : '');
                        }
                        $sel_is_free = false;
                        $sel_amt = '';
                        ?>
                        <div class="lumi-planhd">
                            <h2>Choose Your Plan</h2>
                            <?php if ($has_annual) : ?>
                                <div class="lumi-cycle" id="lumi-cycle">
                                    <button type="button" class="on" data-cycle="month">Monthly</button>
                                    <button type="button" data-cycle="year">Annual <span class="save">(Save 15%)</span></button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="lumi-chanchips">
                            <span class="t">Channel<?php echo (count($channels_for_plans) > 1) ? 's' : ''; ?>:</span>
                            <?php foreach (array('voice', 'chat', 'sms', 'email') as $ck) :
                                if (!in_array($ck, $channels_for_plans, true)) { continue; } ?>
                                <span class="c"><?php echo wp_kses_post($chmeta[$ck][0]); ?> <?php echo esc_html($chmeta[$ck][1]); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if (empty($cards)) : ?>
                            <div class="lumi-planerr">
                                <h3>Error Loading Plans</h3>
                                <p>Failed to load pricing plans. Please try again.</p>
                                <a class="lumi-tryagain" href="<?php echo esc_url(add_query_arg(array('page' => 'lumitalk-ai', 'step' => 'plan'), admin_url('admin.php'))); ?>">Try Again</a>
                            </div>
                        <?php else : ?>
                            <div class="lumi-plans">
                            <?php foreach ($cards as $cdef) :
                                $plan  = $cdef['plan'];
                                $tier  = $cdef['tier'];
                                $m     = $cdef['m'];
                                $y     = $cdef['y'];
                                $m_amt = $cdef['m_amt'];
                                $y_amt = $cdef['y_amt'];
                                $badge = ('professional' === $tier) ? 'Recommended' : '';
                                $checked = ($tier === $sel_tier);
                                $is_free = ('free' === $tier || '' === $m['id'] || 0 === $m_amt);
                                $name_parts = explode(' - ', $plan['name']);
                                $short_name = trim($name_parts[0]);
                                $cov = isset($tier_cov[$tier]) ? $tier_cov[$tier] : 50;
                                $feats = isset($tier_feats[$tier]) ? $tier_feats[$tier] : $tier_feats['starter'];
                                // Price displays (app getPriceDisplay): "$0" free, "$X/mo" monthly,
                                // annual cycle shows the monthly equivalent + billed-annually note.
                                if ($is_free) {
                                    $mdisp = '$0';
                                } elseif (null !== $m_amt) {
                                    $mdisp = '$' . rtrim(rtrim(number_format($m_amt / 100, 2, '.', ''), '0'), '.') . '/mo';
                                } else {
                                    $suffix = ('year' === $m['interval']) ? '/yr' : (('month' === $m['interval']) ? '/mo' : '');
                                    $mdisp  = ('' !== $m['display'] ? $m['display'] : 'Custom') . $suffix;
                                }
                                $ydisp = '';
                                $yfull = '';
                                $anote = '';
                                $asave = '';
                                if ($y && null !== $y_amt) {
                                    $ydisp = '$' . round($y_amt / 12 / 100) . '/mo';
                                    $yfull = '$' . rtrim(rtrim(number_format($y_amt / 100, 2, '.', ''), '0'), '.') . '/year';
                                    $anote = 'Billed annually (' . $yfull . ')';
                                    if (null !== $m_amt) {
                                        $sv = (int) round((($m_amt * 12) - $y_amt) / 100);
                                        if ($sv > 0) { $asave = 'Save $' . $sv . '/year'; }
                                    }
                                }
                                $mval = $plan['id'] . '|' . $m['id'] . '|' . $tier;
                                $yval = $y ? ($plan['id'] . '|' . $y['id'] . '|' . $tier) : '';
                                if ($checked) {
                                    $sel_is_free = $is_free;
                                    $sel_amt = $mdisp;
                                }
                                ?>
                                <label class="lumi-pc<?php echo $checked ? ' sel' : ''; ?>">
                                    <?php if ($badge) : ?><span class="lumi-pc-badge"><?php echo esc_html($badge); ?></span><?php endif; ?>
                                    <input type="radio" name="plan" value="<?php echo esc_attr($mval); ?>"
                                        data-name="<?php echo esc_attr($plan['name']); ?>" data-tier="<?php echo esc_attr($tier); ?>"
                                        data-mval="<?php echo esc_attr($mval); ?>" data-yval="<?php echo esc_attr($yval); ?>"
                                        data-mdisp="<?php echo esc_attr($mdisp); ?>" data-ydisp="<?php echo esc_attr($ydisp); ?>"
                                        data-yfull="<?php echo esc_attr($yfull); ?>"
                                        <?php checked($checked); ?> />
                                    <span class="lumi-pc-name"><?php echo esc_html($short_name); ?></span>
                                    <span class="lumi-pc-amt"><?php echo esc_html($mdisp); ?></span>
                                    <?php if ($is_free) : ?><span class="lumi-pc-note">Free for 90 days</span><?php endif; ?>
                                    <?php if ('' !== $anote) : ?>
                                        <span class="lumi-pc-ann" style="display:none"><?php echo esc_html($anote); ?><?php if ('' !== $asave) : ?><em><?php echo esc_html($asave); ?></em><?php endif; ?></span>
                                    <?php endif; ?>
                                    <span class="lumi-donut" style="background:conic-gradient(#22c55e <?php echo esc_attr((string) $cov); ?>%, #e5e7eb <?php echo esc_attr((string) $cov); ?>%)"><span class="in"><?php echo esc_html((string) $cov); ?>%</span></span>
                                    <span class="lumi-donut-cap">Of<br />Features</span>
                                    <span class="lumi-feats">
                                        <?php foreach ($feats as $f) : ?>
                                            <span class="f"><i></i><?php echo esc_html($f); ?></span>
                                        <?php endforeach; ?>
                                    </span>
                                    <span class="lumi-pc-btn">Subscribe</span>
                                </label>
                            <?php endforeach; ?>
                            </div>
                            <div class="lumi-note-free" id="lumi-note-free" style="display:<?php echo $sel_is_free ? 'block' : 'none'; ?>">
                                Free plan selected &mdash; no credit card required. Click &ldquo;Continue&rdquo; to proceed.
                            </div>
                            <div class="lumi-note-paid" id="lumi-note-paid" style="display:<?php echo $sel_is_free ? 'none' : 'block'; ?>">
                                You&rsquo;ll be redirected to Stripe to authorize the <strong id="lumi-paid-amt"><?php echo esc_html($sel_amt); ?></strong> charge after clicking &ldquo;Continue&rdquo;.
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
                        $tr    = (isset($state['personalityTraits']) && is_array($state['personalityTraits']) && $state['personalityTraits'])
                            ? $state['personalityTraits'] : array('friendly', 'professional', 'helpful');
                        ?>
                        <h3 class="lumi-h3">Global AI Settings</h3>
                        <p class="lumi-subtle">Configure your AI&rsquo;s core functionality that will be used across all communication channels &mdash; you&rsquo;ll be able to configure it in more detail later.</p>
                        <div class="lumi-f2">
                            <div>
                                <label class="lumi-flb" for="lumi-ai-name">Agent Name <em>*</em></label>
                                <input class="lumi-fin" id="lumi-ai-name" type="text" name="ai_name" maxlength="60" value="<?php echo esc_attr($name); ?>" placeholder="e.g., Lisa, Alex, Jordan" />
                            </div>
                            <div>
                                <label class="lumi-flb" for="lumi-ai-tone">Tone</label>
                                <select class="lumi-fin" id="lumi-ai-tone" name="ai_tone">
                                    <?php foreach (array('friendly', 'professional', 'casual', 'enthusiastic', 'formal') as $t) : ?>
                                        <option value="<?php echo esc_attr($t); ?>" <?php selected($tone, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="lumi-f1">
                            <label class="lumi-flb" for="lumi-ai-greeting">Default Greeting Message <em>*</em></label>
                            <textarea class="lumi-fin" id="lumi-ai-greeting" name="ai_greeting" rows="3" maxlength="300" placeholder="The first thing your AI says when someone contacts you"><?php echo esc_textarea($greet); ?></textarea>
                        </div>
                        <div class="lumi-f1">
                            <label class="lumi-flb" for="lumi-ai-desc">Business Description <em>*</em></label>
                            <textarea class="lumi-fin" id="lumi-ai-desc" name="ai_desc" rows="6" maxlength="600" placeholder="Tell the AI what your business does &mdash; products, services, and policies"><?php echo esc_textarea($desc); ?></textarea>
                        </div>
                        <div class="lumi-agents">
                            <div class="lumi-agents-h">
                                <h4>Choose Your AI Personality</h4>
                                <span>Shapes the persona &mdash; everything above stays editable</span>
                            </div>
                            <p class="hint">Pick the traits that best fit how your assistant should speak with customers.</p>
                            <div class="lumi-trs">
                                <?php foreach (lumitalk_traits() as $tid => $tdef) : ?>
                                    <label class="lumi-tr">
                                        <input type="checkbox" name="traits[]" value="<?php echo esc_attr($tid); ?>" <?php checked(in_array($tid, $tr, true)); ?> />
                                        <span class="lumi-tr-ico"><?php echo wp_kses_post($tdef[0]); ?></span>
                                        <span class="lumi-tr-lb"><?php echo esc_html($tdef[1]); ?></span>
                                        <span class="lumi-tr-ck">&#10003;</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php else : // review ?>
                        <input type="hidden" name="action" value="lumitalk_onb_launch" />
                        <?php
                        $a = isset($state['assistant']) && is_array($state['assistant']) ? $state['assistant'] : array();
                        $know = isset($state['knowledge']['productCount']) ? (int) $state['knowledge']['productCount'] : 0;
                        $app_name = !empty($state['applicationName']) ? $state['applicationName'] : get_bloginfo('name');
                        $platform_label = (lumitalk_detect_source() === 'woocommerce') ? 'WordPress/WooCommerce' : 'WordPress';
                        // Resolve the saved plan id -> tier + price for the Billing card.
                        $plan_tier_label = 'Free';
                        $plan_total = '$0/month';
                        $plan_freq = 'Monthly';
                        $plan_matched = false;
                        if (!empty($state['selectedPlan'])) {
                            $rplans = lumitalk_fetch_plans(implode(',', $enabled_keys ? $enabled_keys : array('chat')));
                            foreach ($rplans as $rp) {
                                if (isset($rp['id']) && $rp['id'] === $state['selectedPlan']) {
                                    $plan_matched = true;
                                    $rtier = isset($rp['metadata']['tier']) ? strtolower($rp['metadata']['tier']) : '';
                                    $plan_tier_label = ('' !== $rtier) ? ucfirst($rtier) : $rp['name'];
                                    $rpp = lumitalk_plan_price($rp);
                                    if ('year' === $rpp['interval']) { $plan_freq = 'Annual (Save 10%)'; }
                                    $plan_total = ('' !== $rpp['display'] ? $rpp['display'] : '$0')
                                        . ($rpp['interval'] ? '/' . $rpp['interval'] : '/month');
                                    break;
                                }
                            }
                            if (!$plan_matched) {
                                // Unresolvable id (e.g. catalogue changed): show it raw
                                // instead of misreporting the plan as Free.
                                $plan_tier_label = $state['selectedPlan'];
                                $plan_total = '—';
                            }
                        }
                        $traits_map = lumitalk_traits();
                        $sel_traits = (isset($state['personalityTraits']) && is_array($state['personalityTraits'])) ? $state['personalityTraits'] : array();
                        $step_url = function ($k) {
                            return add_query_arg(array('page' => 'lumitalk-ai', 'step' => $k), admin_url('admin.php'));
                        };
                        ?>
                        <div class="lumi-rvhero">
                            <h2>You are all set !</h2>
                            <p>Your AI-powered customer service platform is configured and ready to go. Review your setup below and activate when ready.</p>
                        </div>
                        <div class="lumi-rvgrid">
                            <div class="lumi-rvc">
                                <div class="lumi-rvc-h">
                                    <div class="tt"><span class="ic">&#129513;</span><h4>Application Overview</h4></div>
                                    <a class="edit" href="<?php echo esc_url($step_url('channels')); ?>">Edit +</a>
                                </div>
                                <div class="lumi-rvrow">
                                    <div>
                                        <h5>Application Name :</h5>
                                        <div class="val"><?php echo esc_html($app_name); ?></div>
                                    </div>
                                    <div>
                                        <h5>Channels :</h5>
                                        <div class="lumi-pills">
                                            <?php foreach ($enabled_keys ? $enabled_keys : array('chat') as $ck) : ?>
                                                <span class="lumi-pill2"><?php echo esc_html('sms' === $ck ? 'SMS' : ucfirst($ck)); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="lumi-rvblock">
                                    <h5>Platform :</h5>
                                    <div class="val"><?php echo esc_html($platform_label); ?></div>
                                </div>
                            </div>
                            <div class="lumi-rvc">
                                <div class="lumi-rvc-h">
                                    <div class="tt"><span class="ic">&#128179;</span><h4>Billing &amp; Plan</h4></div>
                                    <a class="edit" href="<?php echo esc_url($step_url('plan')); ?>">Edit +</a>
                                </div>
                                <div class="lumi-rvrow">
                                    <div>
                                        <h5>Plan Name :</h5>
                                        <div class="val"><?php echo esc_html($plan_tier_label); ?></div>
                                    </div>
                                    <div>
                                        <h5>Billing Frequency :</h5>
                                        <div class="val"><?php echo esc_html($plan_freq); ?></div>
                                    </div>
                                </div>
                                <div class="lumi-rvblock">
                                    <h5>Total Monthly :</h5>
                                    <div class="val"><?php echo esc_html($plan_total); ?></div>
                                </div>
                            </div>
                            <div class="lumi-rvc">
                                <div class="lumi-rvc-h">
                                    <div class="tt"><span class="ic">&#127968;</span><h4>Store Connection</h4></div>
                                    <a class="edit" href="<?php echo esc_url($step_url('channels')); ?>">Edit +</a>
                                </div>
                                <div class="lumi-rvrow">
                                    <div>
                                        <h5>Store URL :</h5>
                                        <div class="val brk"><?php echo esc_html(!empty($s['store_url']) ? $s['store_url'] : lumitalk_store_url()); ?></div>
                                    </div>
                                    <div>
                                        <h5>Products Synced :</h5>
                                        <div class="val"><?php echo esc_html((string) $know); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="lumi-rvc">
                                <div class="lumi-rvc-h">
                                    <div class="tt"><span class="ic">&#10024;</span><h4>AI Personality</h4></div>
                                    <a class="edit" href="<?php echo esc_url($step_url('assistant')); ?>">Edit +</a>
                                </div>
                                <div class="lumi-rvrow">
                                    <div>
                                        <h5>Agent Name :</h5>
                                        <div class="val"><?php echo esc_html(!empty($a['name']) ? $a['name'] : 'Not set'); ?></div>
                                    </div>
                                    <div>
                                        <h5>Traits :</h5>
                                        <div class="lumi-pills">
                                            <?php foreach ($sel_traits as $tid) :
                                                $tl = isset($traits_map[$tid]) ? $traits_map[$tid] : array('', ucfirst($tid)); ?>
                                                <span class="lumi-pill2"><?php echo wp_kses_post($tl[0]); ?> <?php echo esc_html($tl[1]); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (empty($sel_traits)) : ?><span class="val">None selected</span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="lumi-rvblock">
                                    <h5>Greeting :</h5>
                                    <div class="val"><?php echo esc_html(!empty($a['greeting']) ? $a['greeting'] : 'Not set'); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="lumi-nav">
                        <?php if ($prev) : ?>
                            <a class="lumi-wb prev" href="<?php echo esc_url(add_query_arg(array('page' => 'lumitalk-ai', 'step' => $prev), admin_url('admin.php'))); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                                Previous
                            </a>
                        <?php else : ?>
                            <span class="lumi-wb prev is-disabled">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                                Previous
                            </span>
                        <?php endif; ?>
                        <?php if ('review' !== $step) : ?>
                            <button type="submit" class="lumi-wb next">
                                Continue
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>
                            </button>
                        <?php else : ?>
                            <button type="submit" class="lumi-wb create">Activate Application</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
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
    // Onboarding wizard (.lumi-wiz*): pixel-matched to the app wizard at
    // /embed-app-config (ApplicationConfigWizard.jsx + ApplicationSetup /
    // PricingPlansSection / ApplicationConfig) — Tailwind values translated 1:1:
    // bg-gray-100 page, max-w-5xl, white rounded-2xl shadow-md bordered cards,
    // text-3xl centered title, w-8 h-8 rounded-full pink-600/green-600 step
    // circles with text-[10px] labels, border-2 channel tiles (pink-50 selected),
    // PricingPlansSection plan cards (rounded-2xl, blue selected ring, pink-to-
    // purple Recommended badge, pink-600 price, conic green donut, gray Subscribe
    // button), Global AI Settings form fields, gradient review card headings.
    // The .lumi-app / .lumi-panel block below keeps the pre-connect, dashboard,
    // settings and agent pages styled (unchanged pages).
    return '
    #wpcontent{padding-left:0!important}#wpbody-content{padding:0!important}
    #wpfooter{display:none!important}#wpbody-content>.notice,#wpbody-content>.update-nag{display:none!important}
    /* ============ Onboarding wizard shell (ApplicationConfigWizard) ============ */
    .lumi-wiz{min-height:calc(100vh - 32px);background:#f3f4f6;padding:24px;color:#111827;
        font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;line-height:1.5;}
    .lumi-wiz *{box-sizing:border-box;}
    .lumi-wiz svg{display:block;}
    .lumi-wrap{max-width:64rem;margin:0 auto;}
    .lumi-wcard{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);margin-bottom:24px;}
    .lumi-whead{padding:8px;}
    .lumi-whead-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding:6px 12px 0;}
    .lumi-whead-l,.lumi-whead-r{width:33.33%;}
    .lumi-whead-c{width:33.33%;text-align:center;}
    .lumi-whead-c h1{font-size:30px;font-weight:600;color:#111827;margin:0;line-height:1.2;white-space:nowrap;}
    .lumi-cancel{display:inline-flex;align-items:center;gap:4px;color:#dc2626;font-size:14px;text-decoration:none;border-radius:8px;}
    .lumi-cancel:hover{color:#b91c1c;}
    /* Step circles (w-8 h-8 rounded-full; active pink-600, done green-600) */
    .lumi-prog{border-top:1px solid #e5e7eb;padding:16px;display:flex;align-items:center;justify-content:center;}
    .lumi-ps{flex:1;max-width:180px;}
    .lumi-ps-row{display:flex;align-items:center;}
    .lumi-ps-col{display:flex;flex-direction:column;align-items:center;}
    .lumi-ps .b{width:32px;height:32px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#e5e7eb;color:#9ca3af;font-size:12px;font-weight:600;transition:background .15s;}
    .lumi-ps.now .b{background:#db2777;color:#fff;}
    .lumi-ps.done .b{background:#16a34a;color:#fff;font-weight:700;}
    .lumi-ps .l{font-size:10px;margin:4px 0 0;text-align:center;white-space:nowrap;color:#9ca3af;}
    .lumi-ps.now .l{color:#db2777;font-weight:500;}
    .lumi-pline{flex:1;margin:16px 4px 0;align-self:flex-start;}
    .lumi-pline>div{height:2px;border-radius:4px;background:#e5e7eb;}
    .lumi-pline.done>div{background:#22c55e;}
    /* Content card + bottom navigation */
    .lumi-wbody{padding:16px;}
    .lumi-nav{display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;}
    .lumi-wb{display:inline-flex;align-items:center;gap:8px;border-radius:8px;font-size:14px;font-weight:400;cursor:pointer;text-decoration:none;line-height:1.5;transition:background .15s,color .15s;}
    .lumi-wb svg{width:20px;height:20px;}
    .lumi-wb.prev{padding:12px 24px;border:1px solid #d1d5db;background:#fff;color:#374151;}
    .lumi-wb.prev:hover{background:#f9fafb;color:#374151;}
    .lumi-wb.prev.is-disabled{opacity:.5;cursor:not-allowed;pointer-events:none;}
    .lumi-wb.next{padding:12px 24px;background:#db2777;color:#fff;border:0;}
    .lumi-wb.next:hover{background:#be185d;}
    .lumi-wb.create{padding:12px 32px;background:#16a34a;color:#fff;border:0;}
    .lumi-wb.create:hover{background:#15803d;}
    /* Alerts (billing callback banners) */
    .lumi-alert{border-radius:12px;padding:14px 16px;font-size:13px;margin:0 0 16px;border:1px solid transparent;}
    .lumi-alert strong{display:block;font-weight:600;margin-bottom:2px;}
    .lumi-alert.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534;}
    .lumi-alert.err{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
    .lumi-alert.warn{background:#fffbeb;border-color:#fde68a;color:#92400e;}
    /* Form fields (label text-sm font-medium; input border rounded-md p-3, pink focus ring) */
    .lumi-flb{display:block;font-size:14px;font-weight:500;color:#111827;margin-bottom:8px;}
    .lumi-flb em{color:#ef4444;font-style:normal;}
    .lumi-fin{display:block;width:100%;border:1px solid #d1d5db;border-radius:6px;padding:12px;font-size:14px;color:#111827;background:#fff;line-height:1.4;}
    .lumi-fin:focus{outline:none;border-color:#ec4899;box-shadow:0 0 0 2px rgba(236,72,153,.4);}
    .lumi-fin[readonly]{color:#374151;}
    .lumi-f2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
    .lumi-f1{margin-bottom:16px;}
    .lumi-h3{font-size:18px;font-weight:600;color:#111827;margin:0 0 8px;}
    .lumi-subtle{font-size:14px;color:#4b5563;margin:0 0 16px;}
    /* Store URL info panel (blue box from wizard step 1) */
    .lumi-urlbox{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px;margin-bottom:24px;}
    .lumi-urlbox .lumi-flb{margin-bottom:8px;}
    /* Channel tiles (Select Channels: p-4 rounded-lg border-2, pink-50 selected) */
    .lumi-chs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;}
    .lumi-chc{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;text-align:center;cursor:pointer;transition:all .15s;}
    .lumi-chc:hover{border-color:#d1d5db;}
    .lumi-chc:has(input:checked){border-color:#ec4899;background:#fdf2f8;box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);}
    .lumi-chc input{position:absolute;opacity:0;pointer-events:none;}
    .lumi-chc-ico{font-size:28px;line-height:1;margin-bottom:8px;}
    .lumi-chc-name{font-size:14px;font-weight:500;color:#111827;margin-bottom:4px;}
    .lumi-chc:has(input:checked) .lumi-chc-name{color:#831843;}
    .lumi-chc-desc{font-size:12px;color:#6b7280;line-height:1.35;}
    /* Gradient tagline under the channel grid */
    .lumi-chtag{text-align:center;margin-top:8px;}
    .lumi-chtag .g{font-size:18px;font-weight:600;margin:0 0 8px;background:linear-gradient(to right,#db2777,#16a34a);-webkit-background-clip:text;background-clip:text;color:transparent;-webkit-text-fill-color:transparent;}
    .lumi-chtag .d{font-size:14px;color:#4b5563;max-width:42rem;margin:0 auto;}
    /* Pricing header + Monthly/Annual toggle (bg-gray-100 p-1, active white pill) */
    .lumi-planhd{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;}
    .lumi-planhd h2{font-size:24px;font-weight:700;color:#111827;letter-spacing:-.025em;margin:0;}
    .lumi-cycle{display:inline-flex;background:#f3f4f6;padding:4px;border-radius:8px;}
    .lumi-cycle button{padding:8px 16px;border-radius:6px;font-size:14px;font-weight:500;color:#4b5563;background:transparent;border:0;cursor:pointer;transition:all .15s;}
    .lumi-cycle button:hover{color:#111827;}
    .lumi-cycle button.on{background:#fff;color:#111827;box-shadow:0 1px 2px 0 rgba(0,0,0,.05);}
    .lumi-cycle .save{margin-left:4px;font-size:12px;color:#16a34a;}
    /* Selected-channel chips row */
    .lumi-chanchips{display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:24px;flex-wrap:wrap;}
    .lumi-chanchips .t{font-size:14px;font-weight:500;color:#374151;}
    .lumi-chanchips .c{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:8px;background:#f3f4f6;border:1px solid rgba(255,255,255,.2);box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);font-size:12px;font-weight:500;color:#374151;}
    /* Plan cards (PricingPlansSection: rounded-2xl border-2, blue selected ring) */
    .lumi-plans{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;}
    .lumi-pc{position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;min-height:400px;padding:16px;border:2px solid #e5e7eb;border-radius:16px;background:#fff;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,.05);transition:all .15s;}
    .lumi-pc:hover{border-color:#93c5fd;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);}
    .lumi-pc.sel{border-color:#3b82f6;background:#eff6ff;box-shadow:0 0 0 2px #60a5fa;}
    .lumi-pc input{position:absolute;opacity:0;pointer-events:none;}
    .lumi-pc-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);padding:4px 8px;background:linear-gradient(to right,#ec4899,#9333ea);color:#fff;font-size:12px;font-weight:500;border-radius:999px;white-space:nowrap;z-index:2;}
    .lumi-pc-name{display:block;font-size:18px;font-weight:600;letter-spacing:-.025em;color:#111827;}
    .lumi-pc-amt{display:block;font-size:24px;font-weight:700;color:#db2777;margin-top:4px;}
    .lumi-pc-note{display:block;font-size:12px;color:#6b7280;font-weight:400;margin-top:4px;}
    .lumi-pc-ann{display:block;font-size:12px;color:#6b7280;font-weight:400;margin-top:2px;}
    .lumi-pc-ann em{display:block;color:#16a34a;font-weight:600;font-style:normal;}
    .lumi-donut{width:72px;height:72px;border-radius:999px;margin-top:12px;display:block;flex:none;}
    .lumi-donut .in{width:50px;height:50px;border-radius:999px;background:#fff;margin:11px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;color:#374151;}
    .lumi-pc.sel .lumi-donut .in{background:#eff6ff;}
    .lumi-donut-cap{display:block;font-size:12px;color:#6b7280;text-align:center;margin-top:8px;line-height:1.25;}
    .lumi-feats{display:block;width:100%;text-align:left;margin:12px 0;flex-grow:1;}
    .lumi-feats .f{display:flex;align-items:center;gap:8px;font-size:12px;color:#374151;margin-bottom:4px;}
    .lumi-feats .f i{width:6px;height:6px;border-radius:999px;background:#16a34a;flex:none;}
    .lumi-pc-btn{display:block;margin-top:auto;margin-bottom:8px;width:100%;border-radius:12px;padding:8px 16px;font-size:14px;font-weight:500;background:#6b7280;color:#fff;transition:background .15s;}
    .lumi-pc:hover .lumi-pc-btn{background:#4b5563;}
    /* Plan-load error state */
    .lumi-planerr{text-align:center;padding:48px 0;}
    .lumi-planerr h3{font-size:18px;font-weight:700;color:#111827;margin:0 0 8px;}
    .lumi-planerr p{font-size:14px;color:#4b5563;margin:0 0 16px;}
    .lumi-tryagain{display:inline-block;padding:12px 24px;background:#db2777;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;}
    .lumi-tryagain:hover{background:#be185d;color:#fff;}
    /* Free / paid selection notices (blue + green info panels) */
    .lumi-note-free{padding:12px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;font-size:14px;color:#166534;}
    .lumi-note-paid{padding:12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;font-size:14px;color:#1d4ed8;}
    /* AI personality panel (gray-50 rounded-xl, gradient heading, trait cards) */
    .lumi-agents{border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#f9fafb;margin-top:16px;}
    .lumi-agents-h{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:4px;gap:12px;}
    .lumi-agents-h h4{font-size:14px;font-weight:700;margin:0;background:linear-gradient(to right,#9333ea,#db2777);-webkit-background-clip:text;background-clip:text;color:transparent;-webkit-text-fill-color:transparent;}
    .lumi-agents-h span{font-size:11px;color:#6b7280;}
    .lumi-agents .hint{font-size:11px;color:#6b7280;margin:0 0 12px;}
    .lumi-trs{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
    .lumi-tr{position:relative;display:flex;align-items:center;gap:8px;padding:10px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;transition:all .15s;text-align:left;}
    .lumi-tr:hover{border-color:#f9a8d4;}
    .lumi-tr:has(input:checked){border-color:#ec4899;background:#fdf2f8;box-shadow:0 0 0 2px #fbcfe8;}
    .lumi-tr input{position:absolute;opacity:0;pointer-events:none;}
    .lumi-tr-ico{font-size:18px;line-height:1;}
    .lumi-tr-lb{font-size:12px;font-weight:600;color:#111827;}
    .lumi-tr-ck{display:none;margin-left:auto;color:#db2777;font-size:12px;font-weight:700;}
    .lumi-tr:has(input:checked) .lumi-tr-ck{display:inline;}
    /* Review step ("You are all set !" hero + gradient card headings) */
    .lumi-rvhero{text-align:center;margin:8px 0 32px;}
    .lumi-rvhero h2{font-size:30px;font-weight:600;margin:8px 0 4px;background:linear-gradient(to right,#9333ea,#db2777);-webkit-background-clip:text;background-clip:text;color:transparent;-webkit-text-fill-color:transparent;}
    .lumi-rvhero p{font-size:15px;color:#111827;max-width:56rem;margin:0 auto;}
    .lumi-rvgrid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
    .lumi-rvc{border:1px solid #d1d5db;border-radius:12px;padding:16px;background:#fff;}
    .lumi-rvc-h{display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid #e5e7eb;padding-bottom:4px;margin-bottom:8px;}
    .lumi-rvc-h .tt{display:flex;align-items:center;gap:8px;}
    .lumi-rvc-h .ic{font-size:24px;line-height:1;}
    .lumi-rvc-h h4{font-size:24px;font-weight:600;margin:0 0 4px;background:linear-gradient(to right,#9333ea,#db2777);-webkit-background-clip:text;background-clip:text;color:transparent;-webkit-text-fill-color:transparent;white-space:nowrap;}
    .lumi-rvc-h .edit{color:#9ca3af;font-size:14px;font-weight:500;text-decoration:none;white-space:nowrap;}
    .lumi-rvc-h .edit:hover{color:#4b5563;}
    .lumi-rvrow{display:flex;justify-content:flex-start;gap:64px;align-items:flex-start;}
    .lumi-rvblock{margin-top:12px;}
    .lumi-rvc h5{color:#9ca3af;font-weight:400;font-size:13px;margin:0 0 4px;}
    .lumi-rvc .val{font-size:14px;color:#111827;}
    .lumi-rvc .val.brk{word-break:break-all;}
    .lumi-pills{display:flex;flex-wrap:wrap;gap:8px;}
    .lumi-pill2{display:inline-block;padding:4px 12px;font-size:14px;border-radius:999px;background:#f3f4f6;border:1px solid #d1d5db;color:#111827;}
    /* ============ Pre-connect / dashboard / settings / agent pages ============ */
    .lumi-app{min-height:calc(100vh - 32px);background:linear-gradient(135deg,#f8fafc 0%,#ffffff 50%,#f1f5f9 100%);
        font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;font-size:14px;}
    .lumi-app *{box-sizing:border-box;}
    .lumi-hd{position:sticky;top:32px;z-index:30;background:rgba(255,255,255,.8);backdrop-filter:blur(8px);border-bottom:1px solid #e2e8f0;}
    .lumi-hd-in{max-width:56rem;margin:0 auto;padding:16px 24px;display:flex;align-items:center;gap:12px;}
    .lumi-hd img{height:48px;width:auto;}
    .lumi-hd .t{font-size:14px;font-weight:600;color:#0f172a;line-height:1.25;}
    .lumi-hd .s{font-size:12px;color:#64748b;}
    .lumi-body{max-width:48rem;margin:0 auto;padding:24px;}
    .lumi-panel{background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:32px;box-shadow:0 20px 25px -5px rgba(226,232,240,.5),0 8px 10px -6px rgba(226,232,240,.5);}
    .lumi-panel h1{font-size:22px;font-weight:800;color:#0f172a;margin:0 0 6px;letter-spacing:-.01em;}
    .lumi-sub{font-size:13px;color:#64748b;line-height:1.6;margin:0 0 20px;}
    .lumi-b{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px 20px;font-size:14px;font-weight:600;cursor:pointer;border:0;text-decoration:none;line-height:1.25;transition:all .15s;}
    .lumi-b.primary{background:#0f172a;color:#fff;}.lumi-b.primary:hover{background:#1e293b;color:#fff;}
    .lumi-b.secondary{background:#fff;color:#0f172a;box-shadow:inset 0 0 0 1px #e2e8f0;}.lumi-b.secondary:hover{background:#f8fafc;color:#0f172a;}
    .lumi-b:disabled{opacity:.5;cursor:not-allowed;}
    .lumi-pill{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:500;background:#f8fafc;color:#334155;box-shadow:inset 0 0 0 1px #e2e8f0;}
    .lumi-link{color:#475569;text-decoration:none;font-size:13px;font-weight:600;}.lumi-link:hover{color:#0f172a;}
    .lumi-note{font-size:12.5px;color:#94a3b8;margin:14px 0 0;}
    .lumi-actions{display:flex;align-items:center;gap:14px;margin-top:26px;flex-wrap:wrap;}
    .lumi-foot{max-width:48rem;margin:0 auto;padding:0 24px 32px;text-align:center;font-size:12px;color:#64748b;}
    .lumi-foot a{color:#334155;}
    .lumi-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 22px;}
    .lumi-tile{border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;background:#f8fafc;}
    .lumi-tile strong{display:block;font-size:13.5px;color:#0f172a;}
    .lumi-tile span{font-size:12px;color:#64748b;}
    .lumi-meta{display:flex;flex-wrap:wrap;gap:8px 22px;background:#f8fafc;border-radius:14px;padding:14px 16px;margin:0 0 22px;font-size:13px;}
    .lumi-meta em{color:#94a3b8;font-style:normal;margin-right:6px;}
    .lumi-meta code{background:transparent;color:#0f172a;}
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
    @media(max-width:900px){
        .lumi-plans{grid-template-columns:repeat(2,1fr);}
        .lumi-rvgrid{grid-template-columns:1fr;}
        .lumi-rvrow{gap:32px;flex-wrap:wrap;}
        .lumi-whead-c h1{font-size:22px;white-space:normal;}
    }
    @media(max-width:640px){
        .lumi-chs,.lumi-trs,.lumi-plans{grid-template-columns:repeat(2,1fr);}
        .lumi-f2,.lumi-grid,.lumi-stats{grid-template-columns:1fr;}
        .lumi-ps .l{display:none;}
        .lumi-planhd{flex-direction:column;gap:12px;align-items:flex-start;}
        .lumi-wiz{padding:12px;}
    }
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
        // Plan step (PricingPlansSection behavior): card selection with the blue
        // ring, free/paid info panels, and the Monthly/Annual toggle that swaps
        // each card to its annual Stripe price + monthly-equivalent display.
        var cards = Array.prototype.slice.call(document.querySelectorAll(".lumi-pc"));
        var nameField = document.getElementById("lumi-plan-name");
        var freeNote = document.getElementById("lumi-note-free");
        var paidNote = document.getElementById("lumi-note-paid");
        var paidAmt = document.getElementById("lumi-paid-amt");
        var cycleWrap = document.getElementById("lumi-cycle");
        var cycle = "month";
        function planSync(){
            cards.forEach(function(c){
                var r = c.querySelector("input[name=plan]"); if (!r) { return; }
                var isFree = r.getAttribute("data-tier") === "free";
                if (r.checked) {
                    c.classList.add("sel");
                    if (nameField) { nameField.value = r.getAttribute("data-name") || ""; }
                    if (freeNote) { freeNote.style.display = isFree ? "block" : "none"; }
                    if (paidNote) { paidNote.style.display = isFree ? "none" : "block"; }
                    if (paidAmt) {
                        paidAmt.textContent = (cycle === "year" && r.getAttribute("data-yfull"))
                            ? r.getAttribute("data-yfull")
                            : r.getAttribute("data-mdisp");
                    }
                } else {
                    c.classList.remove("sel");
                }
            });
        }
        function setCycle(cy){
            cycle = cy;
            if (cycleWrap) {
                Array.prototype.forEach.call(cycleWrap.querySelectorAll("button"), function(x){
                    x.classList.toggle("on", x.getAttribute("data-cycle") === cy);
                });
            }
            cards.forEach(function(c){
                var r = c.querySelector("input[name=plan]"); if (!r) { return; }
                var amt = c.querySelector(".lumi-pc-amt");
                var ann = c.querySelector(".lumi-pc-ann");
                var yval = r.getAttribute("data-yval");
                if (cy === "year" && yval) {
                    r.value = yval;
                    if (amt) { amt.textContent = r.getAttribute("data-ydisp"); }
                    if (ann) { ann.style.display = "block"; }
                } else {
                    r.value = r.getAttribute("data-mval");
                    if (amt) { amt.textContent = r.getAttribute("data-mdisp"); }
                    if (ann) { ann.style.display = "none"; }
                }
            });
            planSync();
        }
        if (cycleWrap) {
            Array.prototype.forEach.call(cycleWrap.querySelectorAll("button"), function(x){
                x.addEventListener("click", function(){ setCycle(x.getAttribute("data-cycle") || "month"); });
            });
        }
        cards.forEach(function(c){
            var r = c.querySelector("input[name=plan]");
            if (r) { r.addEventListener("change", planSync); }
            c.addEventListener("click", function(){ if (r && !r.checked) { r.checked = true; planSync(); } });
        });
        if (cards.length) { planSync(); }
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
