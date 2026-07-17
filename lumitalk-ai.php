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
    define('LUMITALK_APP_BASE', 'https://app.lumitalk.ai');
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
        : ((defined('LUMITALK_APP_BASE') && LUMITALK_APP_BASE) ? LUMITALK_APP_BASE : 'https://app.lumitalk.ai');
    $app = untrailingslashit($app);

    $parts  = wp_parse_url($app);
    $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
    $host   = !empty($parts['host'])   ? $parts['host']   : 'app.lumitalk.ai';
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
// Pixel-matched to the app onboarding (apps/frontend/src/pages/Shopify/ConfigWizard.jsx):
// slate gradient page, sticky blur header, rounded-2xl step squares, rounded-3xl
// card, pink-600 step headings, pink plan cards with badges + corner check, emoji
// trait cards, 2-col review cards and the green Activate button in a gradient panel.
function lumitalk_render_onboarding($s, $state, $step, $notice_error, $billing) {
    $steps = array(
        'channels'  => 'Choose Channels',
        'plan'      => 'Choose Plan',
        'assistant' => 'Your AI Agent',
        'review'    => 'Review & Activate',
    );
    $keys  = array_keys($steps);
    $idx   = array_search($step, $keys, true);
    $prev  = ($idx > 0) ? $keys[$idx - 1] : '';
    $ch    = isset($state['channels']) && is_array($state['channels']) ? $state['channels'] : array();
    $logo  = esc_url(lumitalk_app_base() . '/lumitalk_logo.png');
    $enabled_keys = array();
    foreach (array('chat', 'voice', 'sms', 'email') as $c) { if (!empty($ch[$c]['enabled'])) { $enabled_keys[] = $c; } }
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
                    <div class="b">
                        <?php if ($i < $idx) : ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
                        <?php else : echo esc_html((string) ($i + 1)); endif; ?>
                    </div>
                    <div class="l"><?php echo esc_html($label); ?></div>
                </div>
            <?php $i++; endforeach; ?>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('lumitalk_onb'); ?>
            <div class="lumi-body"><div class="lumi-panel">
                <?php if ('' !== $notice_error) : ?><div class="lumi-alert err"><?php echo esc_html($notice_error); ?></div><?php endif; ?>
                <?php if ($billing === 'success') : ?><div class="lumi-alert ok"><strong>Subscription Active!</strong> Your subscription is now active. Let&rsquo;s finish setting up your assistant.</div><?php endif; ?>
                <?php if ($billing === 'cancel') : ?><div class="lumi-alert warn"><strong>Checkout Canceled.</strong> You can select the free plan or try a paid plan again.</div><?php endif; ?>

                <?php if ($step === 'channels') : ?>
                    <input type="hidden" name="action" value="lumitalk_onb_channels" />
                    <h2 class="lumi-h">Choose Your Channels</h2>
                    <p class="lumi-sub">Select where your AI assistant talks to customers. Chat goes live on your storefront; voice, SMS and email are finished in the LumiTalk dashboard.</p>
                    <div class="lumi-chs">
                        <?php
                        $opts = array(
                            'chat'  => array('&#128172;', 'Chat', 'Real-time chat widget', ''),
                            'voice' => array('&#128222;', 'Voice', 'AI phone support', 'Omni'),
                            'sms'   => array('&#128241;', 'SMS', 'Text messaging', 'Omni'),
                            'email' => array('&#9993;&#65039;', 'Email', 'Email automation', 'Omni'),
                        );
                        foreach ($opts as $key => $o) :
                            $on = !empty($ch[$key]['enabled']) || ($key === 'chat' && empty($ch)); ?>
                            <label class="lumi-chc">
                                <input type="checkbox" name="channels[]" value="<?php echo esc_attr($key); ?>" <?php checked($on); ?> />
                                <span class="lumi-chc-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg></span>
                                <span class="lumi-chc-ico"><?php echo wp_kses_post($o[0]); ?></span>
                                <?php if ('' !== $o[3]) : ?><span class="lumi-chc-badge"><?php echo esc_html($o[3]); ?></span><?php endif; ?>
                                <span class="lumi-chc-name"><?php echo esc_html($o[1]); ?></span>
                                <span class="lumi-chc-desc"><?php echo esc_html($o[2]); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($step === 'plan') : ?>
                    <input type="hidden" name="action" value="lumitalk_onb_plan" />
                    <input type="hidden" name="plan_name" value="" id="lumi-plan-name" />
                    <h2 class="lumi-h">Choose Your Plan</h2>
                    <p class="lumi-sub">Select the plan that best fits your needs. You can upgrade or downgrade anytime.</p>
                    <?php
                    $channels_for_plans = $enabled_keys ? $enabled_keys : array('chat');
                    $plans = lumitalk_fetch_plans(implode(',', $channels_for_plans));
                    $seen_tiers = array();
                    $cards = array();
                    $has_annual = false;
                    foreach ($plans as $plan) {
                        $tier = isset($plan['metadata']['tier']) ? strtolower($plan['metadata']['tier']) : '';
                        if ($tier === '' || isset($seen_tiers[$tier])) { continue; }
                        $seen_tiers[$tier] = true;
                        $m = lumitalk_plan_price($plan);
                        $y = lumitalk_plan_price_for($plan, 'year');
                        if ($y) { $has_annual = true; }
                        $cards[] = array('plan' => $plan, 'tier' => $tier, 'm' => $m, 'y' => $y);
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
                    foreach ($cards as $cdef) {
                        if ($cdef['tier'] === $sel_tier) {
                            $sel_is_free = ('free' === $cdef['tier'] || '' === $cdef['m']['id']);
                            $sel_amt = ('' !== $cdef['m']['display'] ? $cdef['m']['display'] : '$0')
                                . ($cdef['m']['interval'] ? '/' . $cdef['m']['interval'] : '');
                        }
                    }
                    if (empty($cards)) : ?>
                        <div class="lumi-planerr">
                            <h3>Error Loading Plans</h3>
                            <p>Failed to load pricing plans. Please try again.</p>
                            <a class="lumi-tryagain" href="<?php echo esc_url(add_query_arg(array('page' => 'lumitalk-ai', 'step' => 'plan'), admin_url('admin.php'))); ?>">Try Again</a>
                        </div>
                    <?php else : ?>
                        <?php if ($has_annual) : ?>
                            <div class="lumi-cycle" id="lumi-cycle">
                                <button type="button" class="on" data-cycle="monthly">Monthly</button>
                                <button type="button" data-cycle="annual">Annual <span class="save">Save 20%</span></button>
                            </div>
                        <?php endif; ?>
                        <div class="lumi-plans">
                        <?php foreach ($cards as $cdef) :
                            $plan  = $cdef['plan'];
                            $tier  = $cdef['tier'];
                            $m     = $cdef['m'];
                            $y     = $cdef['y'];
                            $badge = !empty($plan['metadata']['popular']) ? 'MOST POPULAR' : (('professional' === $tier) ? 'RECOMMENDED' : '');
                            $checked = ($tier === $sel_tier);
                            $is_free = ('free' === $tier || '' === $m['id']);
                            $mval  = $plan['id'] . '|' . $m['id'] . '|' . $tier;
                            $yval  = $y ? ($plan['id'] . '|' . $y['id'] . '|' . $tier) : '';
                            $mdisp = ('' !== $m['display']) ? $m['display'] : '$0';
                            $ydisp = $y ? $y['display'] : '';
                            // Suffix from the ACTUAL price interval — some tiers only carry
                            // an annual price, so a hardcoded "/month" would mislabel them.
                            $per   = $m['interval'] ? '/' . $m['interval'] : '';
                            $feats = array();
                            if (!empty($plan['features']) && is_array($plan['features'])) {
                                foreach ($plan['features'] as $f) {
                                    if (is_array($f) && !empty($f['name'])) { $feats[] = $f['name']; }
                                    elseif (is_string($f) && '' !== $f) { $feats[] = $f; }
                                }
                            }
                            $feats = array_slice($feats, 0, 5);
                            ?>
                            <label class="lumi-pc<?php echo $badge ? ' hasbadge' : ''; ?><?php echo $checked ? ' sel' : ''; ?>">
                                <?php if ($badge) : ?><span class="lumi-pc-badge"><?php echo esc_html($badge); ?></span><?php endif; ?>
                                <span class="lumi-pc-corner"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg></span>
                                <input type="radio" name="plan" value="<?php echo esc_attr($mval); ?>"
                                    data-name="<?php echo esc_attr($plan['name']); ?>" data-tier="<?php echo esc_attr($tier); ?>"
                                    data-mval="<?php echo esc_attr($mval); ?>" data-yval="<?php echo esc_attr($yval); ?>"
                                    data-mdisp="<?php echo esc_attr($mdisp); ?>" data-ydisp="<?php echo esc_attr($ydisp); ?>"
                                    data-mper="<?php echo esc_attr($per); ?>"
                                    <?php checked($checked); ?> />
                                <span class="lumi-pc-name"><?php echo esc_html($plan['name']); ?></span>
                                <span class="lumi-pc-price"><span class="lumi-pc-amt"><?php echo esc_html($mdisp); ?></span><span class="lumi-pc-per"><?php echo esc_html($per); ?></span></span>
                                <?php if ($y && 'month' === $m['interval']) : ?><span class="lumi-pc-annual" style="display:none"><?php echo esc_html($mdisp); ?>/mo billed annually</span><?php endif; ?>
                                <?php if (!empty($plan['description'])) : ?><span class="lumi-pc-desc"><?php echo esc_html($plan['description']); ?></span><?php endif; ?>
                                <?php if ($feats) : ?>
                                    <span class="lumi-pc-feats">
                                        <?php foreach ($feats as $f) : ?>
                                            <span class="lumi-pc-feat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg><span><?php echo esc_html($f); ?></span></span>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                                <span class="lumi-pc-btn"><?php echo $checked ? 'Selected' : ($is_free ? 'Free' : 'Select'); ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>

                        <div class="lumi-note-ok" id="lumi-note-free" style="display:<?php echo $sel_is_free ? 'flex' : 'none'; ?>">
                            <span class="lumi-note-ic g"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg></span>
                            <span class="lumi-note-tx"><strong>Free Plan Selected</strong><span>Get started with our free plan! No credit card required. Click &ldquo;Continue&rdquo; to proceed.</span></span>
                        </div>
                        <div class="lumi-note-pk" id="lumi-note-paid" style="display:<?php echo $sel_is_free ? 'none' : 'flex'; ?>">
                            <span class="lumi-note-ic p"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></span>
                            <span class="lumi-note-tx"><strong>Secure Stripe Checkout</strong><span>You&rsquo;ll be redirected to Stripe to authorize the <strong id="lumi-paid-amt"><?php echo esc_html($sel_amt); ?></strong> charge after clicking &ldquo;Continue&rdquo;.</span><span class="tiny">&#128161; The charge can be cancelled anytime from your LumiTalk dashboard.</span></span>
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
                    <h2 class="lumi-h">Choose Your AI Agent</h2>
                    <p class="lumi-sub">This is how the AI introduces itself and speaks to your customers &mdash; wired to your products, orders, and policies.</p>
                    <div class="lumi-f2">
                        <div>
                            <label class="lumi-lb" for="lumi-ai-name">Agent Name</label>
                            <input class="lumi-in" id="lumi-ai-name" type="text" name="ai_name" maxlength="60" value="<?php echo esc_attr($name); ?>" placeholder="e.g., Aria" />
                            <p class="lumi-help">How the agent introduces itself.</p>
                        </div>
                        <div>
                            <label class="lumi-lb" for="lumi-ai-greeting">Greeting Message</label>
                            <textarea class="lumi-in" id="lumi-ai-greeting" name="ai_greeting" rows="2" maxlength="300" placeholder="Hi! How can I help you today?"><?php echo esc_textarea($greet); ?></textarea>
                            <p class="lumi-help">The first message shoppers see.</p>
                        </div>
                    </div>
                    <div class="lumi-f1">
                        <label class="lumi-lb" for="lumi-ai-desc">What does your business do?</label>
                        <textarea class="lumi-in" id="lumi-ai-desc" name="ai_desc" rows="3" maxlength="600"><?php echo esc_textarea($desc); ?></textarea>
                        <p class="lumi-help">Used to ground the AI&rsquo;s answers about your business.</p>
                    </div>
                    <div class="lumi-f2">
                        <div>
                            <label class="lumi-lb" for="lumi-ai-tone">Tone</label>
                            <select class="lumi-in" id="lumi-ai-tone" name="ai_tone">
                                <?php foreach (array('friendly', 'professional', 'casual', 'enthusiastic', 'formal') as $t) : ?>
                                    <option value="<?php echo esc_attr($t); ?>" <?php selected($tone, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="lumi-help">Overall speaking style.</p>
                        </div>
                    </div>
                    <div class="lumi-f1">
                        <span class="lumi-lb">Personality Traits</span>
                        <div class="lumi-trs">
                            <?php foreach (lumitalk_traits() as $tid => $tdef) : ?>
                                <label class="lumi-tr">
                                    <input type="checkbox" name="traits[]" value="<?php echo esc_attr($tid); ?>" <?php checked(in_array($tid, $tr, true)); ?> />
                                    <span class="lumi-tr-ico"><?php echo wp_kses_post($tdef[0]); ?></span>
                                    <span class="lumi-tr-lb"><?php echo esc_html($tdef[1]); ?></span>
                                    <span class="lumi-tr-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php else : // review ?>
                    <input type="hidden" name="action" value="lumitalk_onb_launch" />
                    <?php
                    $a = isset($state['assistant']) && is_array($state['assistant']) ? $state['assistant'] : array();
                    $enabled_labels = array();
                    foreach ($enabled_keys as $c) { $enabled_labels[] = ('sms' === $c) ? 'SMS' : ucfirst($c); }
                    $know = isset($state['knowledge']['productCount']) ? (int) $state['knowledge']['productCount'] : 0;
                    // Resolve the saved plan id → friendly name + price for the summary card.
                    $plan_label = '';
                    $plan_price_disp = '';
                    $plan_is_free = true;
                    if (!empty($state['selectedPlan'])) {
                        $rplans = lumitalk_fetch_plans(implode(',', $enabled_keys ? $enabled_keys : array('chat')));
                        foreach ($rplans as $rp) {
                            if (isset($rp['id']) && $rp['id'] === $state['selectedPlan']) {
                                $plan_label = $rp['name'];
                                $rpp = lumitalk_plan_price($rp);
                                $rtier = isset($rp['metadata']['tier']) ? strtolower($rp['metadata']['tier']) : '';
                                $plan_is_free = ('free' === $rtier || '' === $rpp['id']);
                                $plan_price_disp = ('' !== $rpp['display'] ? $rpp['display'] : '$0') . ($rpp['interval'] ? '/' . $rpp['interval'] : '');
                                break;
                            }
                        }
                        if ('' === $plan_label) { $plan_label = $state['selectedPlan']; $plan_is_free = false; }
                    }
                    $traits_map = lumitalk_traits();
                    $sel_traits = (isset($state['personalityTraits']) && is_array($state['personalityTraits'])) ? $state['personalityTraits'] : array();
                    ?>
                    <h2 class="lumi-h">Review &amp; Activate</h2>
                    <p class="lumi-sub">Review your configuration and activate your AI chat assistant.</p>

                    <div class="lumi-rvgrid">
                        <div class="lumi-rvc">
                            <h3><span class="lumi-ic g"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></span>Plan</h3>
                            <span class="v"><?php echo esc_html('' !== $plan_label ? $plan_label : 'Free'); ?></span>
                            <?php if ('' !== $plan_price_disp) : ?><span class="m"><?php echo esc_html($plan_price_disp); ?></span><?php endif; ?>
                            <?php if ($plan_is_free) : ?><span class="ok">&#127881; Free forever!</span><?php endif; ?>
                        </div>
                        <div class="lumi-rvc">
                            <h3><span class="lumi-ic p"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></span>Channels</h3>
                            <span class="v"><?php echo esc_html($enabled_labels ? implode(', ', $enabled_labels) : 'Chat'); ?></span>
                            <span class="m"><?php echo esc_html((string) $know); ?> products synced</span>
                        </div>
                        <div class="lumi-rvc">
                            <h3><span class="lumi-ic v"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.45 4.55L18 9l-4.55 1.45L12 15l-1.45-4.55L6 9l4.55-1.45L12 3z" /></svg></span>AI Name</h3>
                            <span class="v"><?php echo esc_html(!empty($a['name']) ? $a['name'] : 'Not set'); ?></span>
                        </div>
                        <div class="lumi-rvc">
                            <h3><span class="lumi-ic v"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.45 4.55L18 9l-4.55 1.45L12 15l-1.45-4.55L6 9l4.55-1.45L12 3z" /></svg></span>Traits</h3>
                            <span class="chips">
                                <?php foreach ($sel_traits as $tid) :
                                    $tl = isset($traits_map[$tid]) ? $traits_map[$tid] : array('', ucfirst($tid)); ?>
                                    <span class="lumi-chip"><?php echo wp_kses_post($tl[0]); ?> <?php echo esc_html($tl[1]); ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($sel_traits)) : ?><span class="m">None selected</span><?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="lumi-rvc lumi-rv-greet">
                        <h3><span class="lumi-ic v"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.45 4.55L18 9l-4.55 1.45L12 15l-1.45-4.55L6 9l4.55-1.45L12 3z" /></svg></span>AI Greeting</h3>
                        <span class="q">&ldquo;<?php echo esc_html(!empty($a['greeting']) ? $a['greeting'] : 'Not set'); ?>&rdquo;</span>
                    </div>

                    <div class="lumi-launch">
                        <h3>&#128640; Ready to Launch!</h3>
                        <p>Click &ldquo;Activate&rdquo; to complete setup. Your AI chat widget will be installed on your store instantly!</p>
                        <button type="submit" class="lumi-activate">&#10024; Activate Your AI Assistant</button>
                    </div>

                    <div class="lumi-next">
                        <h4>&#128203; What happens next:</h4>
                        <ol>
                            <li><strong>1.</strong><span>Configuration saved &amp; app activated</span></li>
                            <li><strong>2.</strong><span>The AI chat widget goes live on your storefront</span></li>
                            <li><strong>3.</strong><span>Manage conversations from your LumiTalk dashboard</span></li>
                        </ol>
                    </div>
                <?php endif; ?>
            </div></div>

            <div class="lumi-nav<?php echo ('review' === $step) ? ' final' : ''; ?>">
                <?php if ($prev) : ?>
                    <a class="lumi-b secondary" href="<?php echo esc_url(add_query_arg(array('page' => 'lumitalk-ai', 'step' => $prev), admin_url('admin.php'))); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                        Back
                    </a>
                <?php else : ?>
                    <span class="lumi-b secondary is-disabled">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12" /><polyline points="12 19 5 12 12 5" /></svg>
                        Back
                    </span>
                <?php endif; ?>
                <?php if ('review' !== $step) : ?>
                    <span class="lumi-pill">Step <?php echo esc_html((string) ($idx + 1)); ?> of <?php echo esc_html((string) count($steps)); ?></span>
                    <button type="submit" class="lumi-b primary">
                        Continue
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>
                    </button>
                <?php endif; ?>
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
    // Pixel-matched to the LumiTalk app onboarding (Shopify ConfigWizard + steps):
    // Tailwind values translated 1:1 — slate gradient bg, sticky blur header,
    // 40px rounded-2xl step squares, rounded-3xl card w/ shadow-xl slate-200/50,
    // pink-600 step headings, pink plan/channel/trait cards, slate-900 buttons.
    return '
    #wpcontent{padding-left:0!important}#wpbody-content{padding:0!important}
    #wpfooter{display:none!important}#wpbody-content>.notice,#wpbody-content>.update-nag{display:none!important}
    .lumi-app{min-height:calc(100vh - 32px);background:linear-gradient(135deg,#f8fafc 0%,#ffffff 50%,#f1f5f9 100%);
        font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;}
    .lumi-app *{box-sizing:border-box;}
    .lumi-app svg{display:block;}
    /* Sticky header (bg-white/80 backdrop-blur border-b border-slate-200) */
    .lumi-hd{position:sticky;top:32px;z-index:30;background:rgba(255,255,255,.8);backdrop-filter:blur(8px);border-bottom:1px solid #e2e8f0;}
    .lumi-hd-in{max-width:56rem;margin:0 auto;padding:16px 24px;display:flex;align-items:center;gap:12px;}
    .lumi-hd img{height:48px;width:auto;}
    .lumi-hd .t{font-size:14px;font-weight:600;color:#0f172a;line-height:1.25;}
    .lumi-hd .s{font-size:12px;color:#64748b;}
    /* Step indicator (w-10 h-10 rounded-2xl ring-1; active slate-900, done emerald) */
    .lumi-prog{max-width:56rem;margin:0 auto;padding:24px;display:flex;align-items:flex-start;justify-content:center;gap:12px;}
    .lumi-st{display:flex;flex-direction:column;align-items:center;}
    .lumi-st .b{width:40px;height:40px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;background:#f1f5f9;color:#94a3b8;box-shadow:inset 0 0 0 1px #e2e8f0;transition:all .2s;}
    .lumi-st.now .b{background:#0f172a;color:#fff;box-shadow:inset 0 0 0 1px #0f172a,0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);}
    .lumi-st.done .b{background:#ecfdf5;color:#059669;box-shadow:inset 0 0 0 1px #a7f3d0;}
    .lumi-st .b svg{width:20px;height:20px;}
    .lumi-st .l{margin-top:8px;font-size:12px;font-weight:500;color:#64748b;transition:color .2s;}
    .lumi-st.now .l{color:#0f172a;}.lumi-st.done .l{color:#059669;}
    .lumi-line{height:2px;width:40px;border-radius:999px;background:#e2e8f0;margin-top:19px;flex:none;transition:background .2s;}
    .lumi-line.done{background:#6ee7b7;}
    /* Card panel (max-w-3xl rounded-3xl border-slate-200 p-8 shadow-xl shadow-slate-200/50) */
    .lumi-body{max-width:48rem;margin:0 auto;padding:0 24px;}
    .lumi-panel{background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:32px;box-shadow:0 20px 25px -5px rgba(226,232,240,.5),0 8px 10px -6px rgba(226,232,240,.5);}
    .lumi-panel h1{font-size:22px;font-weight:800;color:#0f172a;margin:0 0 6px;letter-spacing:-.01em;}
    .lumi-h{font-size:16px;font-weight:700;color:#db2777;margin:0 0 4px;}
    .lumi-sub{font-size:12px;color:#4b5563;line-height:1.6;margin:0 0 16px;}
    /* Buttons (rounded-xl px-5 py-3 text-sm font-semibold; primary slate-900) */
    .lumi-b{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:12px;padding:12px 20px;font-size:14px;font-weight:600;cursor:pointer;border:0;text-decoration:none;line-height:1.25;transition:all .15s;}
    .lumi-b svg{width:16px;height:16px;}
    .lumi-b.primary{background:#0f172a;color:#fff;}.lumi-b.primary:hover{background:#1e293b;color:#fff;}
    .lumi-b.secondary{background:#fff;color:#0f172a;box-shadow:inset 0 0 0 1px #e2e8f0;}.lumi-b.secondary:hover{background:#f8fafc;color:#0f172a;}
    .lumi-b:disabled,.lumi-b.is-disabled{opacity:.5;cursor:not-allowed;pointer-events:none;}
    .lumi-pill{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:500;background:#f8fafc;color:#334155;box-shadow:inset 0 0 0 1px #e2e8f0;}
    .lumi-link{color:#475569;text-decoration:none;font-size:13px;font-weight:600;}.lumi-link:hover{color:#0f172a;}
    .lumi-note{font-size:12.5px;color:#94a3b8;margin:14px 0 0;}
    .lumi-nav{max-width:48rem;margin:24px auto 0;padding:0 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;}
    .lumi-nav.final{justify-content:flex-start;}
    .lumi-actions{display:flex;align-items:center;gap:14px;margin-top:26px;flex-wrap:wrap;}
    .lumi-foot{max-width:48rem;margin:40px auto 0;padding:0 24px 32px;text-align:center;font-size:12px;color:#64748b;}
    .lumi-foot a{color:#334155;text-decoration:underline;}.lumi-foot a:hover{text-decoration:none;}
    /* Alerts (billing callback banners: rounded-xl border p-4) */
    .lumi-alert{border-radius:12px;padding:14px 16px;font-size:13px;margin:0 0 16px;border:1px solid transparent;}
    .lumi-alert strong{display:block;font-weight:600;margin-bottom:2px;}
    .lumi-alert.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534;}
    .lumi-alert.err{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
    .lumi-alert.warn{background:#fffbeb;border-color:#fde68a;color:#92400e;}
    /* Pre-connect tiles/meta (unchanged pages) */
    .lumi-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 22px;}
    .lumi-tile{border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;background:#f8fafc;}
    .lumi-tile strong{display:block;font-size:13.5px;color:#0f172a;}
    .lumi-tile span{font-size:12px;color:#64748b;}
    .lumi-meta{display:flex;flex-wrap:wrap;gap:8px 22px;background:#f8fafc;border-radius:14px;padding:14px 16px;margin:0 0 22px;font-size:13px;}
    .lumi-meta em{color:#94a3b8;font-style:normal;margin-right:6px;}
    .lumi-meta code{background:transparent;color:#0f172a;}
    /* Channel cards (ChannelSelector: grid-cols-4, border-2, pink selected, icon tile) */
    .lumi-chs{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
    .lumi-chc{position:relative;display:flex;flex-direction:column;align-items:center;padding:8px;border:2px solid #d1d5db;border-radius:8px;background:#fff;text-align:center;cursor:pointer;transition:all .2s;}
    .lumi-chc:hover{border-color:#f472b6;box-shadow:0 1px 2px 0 rgba(0,0,0,.05);}
    .lumi-chc:has(input:checked){border-color:#ec4899;background:#fdf2f8;box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);}
    .lumi-chc input{position:absolute;opacity:0;pointer-events:none;}
    .lumi-chc-check{display:none;position:absolute;top:4px;right:4px;width:16px;height:16px;background:#db2777;border-radius:999px;color:#fff;align-items:center;justify-content:center;}
    .lumi-chc-check svg{width:10px;height:10px;}
    .lumi-chc:has(input:checked) .lumi-chc-check{display:flex;}
    .lumi-chc-ico{padding:8px;border-radius:8px;background:#e5e7eb;margin-bottom:4px;font-size:20px;line-height:1;transition:background .2s;}
    .lumi-chc:has(input:checked) .lumi-chc-ico{background:#fbcfe8;}
    .lumi-chc-badge{padding:2px 6px;font-size:10px;font-weight:600;background:#e5e7eb;color:#374151;border-radius:999px;margin-bottom:4px;}
    .lumi-chc-name{font-size:12px;font-weight:700;color:#111827;margin-bottom:2px;}
    .lumi-chc-desc{font-size:10px;color:#4b5563;line-height:1.25;}
    /* Billing cycle toggle (bg-gray-100 rounded-lg, active pink-600) */
    .lumi-cycle{display:flex;align-items:center;justify-content:center;gap:8px;padding:8px;background:#f3f4f6;border-radius:8px;width:fit-content;margin:0 auto 12px;}
    .lumi-cycle button{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;border:0;background:transparent;color:#4b5563;cursor:pointer;transition:all .2s;}
    .lumi-cycle button:hover{color:#111827;}
    .lumi-cycle button.on{background:#db2777;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);}
    .lumi-cycle .save{font-size:10px;padding:1px 4px;background:#dcfce7;color:#15803d;border-radius:4px;font-weight:600;}
    /* Plan cards (PlanCard: p-3 rounded-lg border-2, pink selected, badge, corner check) */
    .lumi-plans{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 12px;}
    .lumi-pc{position:relative;display:block;padding:12px;border:2px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;text-align:center;transition:all .2s;overflow:hidden;}
    .lumi-pc.hasbadge{padding-top:24px;}
    .lumi-pc:hover{border-color:#f472b6;box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);}
    .lumi-pc.sel{border-color:#ec4899;background:#fdf2f8;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);}
    .lumi-pc input{position:absolute;opacity:0;pointer-events:none;}
    .lumi-pc-badge{position:absolute;top:4px;left:50%;transform:translateX(-50%);padding:2px 8px;background:#db2777;color:#fff;font-size:10px;font-weight:700;border-radius:999px;white-space:nowrap;z-index:2;}
    .lumi-pc-corner{display:none;position:absolute;top:0;right:0;width:0;height:0;border-top:30px solid #db2777;border-left:30px solid transparent;}
    .lumi-pc.sel .lumi-pc-corner{display:block;}
    .lumi-pc-corner svg{position:absolute;top:-28px;right:2px;width:12px;height:12px;color:#fff;}
    .lumi-pc-name{display:block;font-size:12px;font-weight:700;color:#111827;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.1;}
    .lumi-pc-price{display:flex;align-items:baseline;justify-content:center;gap:2px;}
    .lumi-pc-amt{font-size:18px;font-weight:700;color:#db2777;}
    .lumi-pc-per{font-size:9px;color:#4b5563;}
    .lumi-pc-annual{display:block;font-size:8px;color:#16a34a;font-weight:600;margin-top:2px;}
    .lumi-pc-desc{display:block;font-size:9px;color:#4b5563;margin:4px 0 6px;line-height:1.3;}
    .lumi-pc-feats{display:block;text-align:left;margin-bottom:8px;}
    .lumi-pc-feat{display:flex;align-items:flex-start;gap:4px;font-size:9px;color:#374151;line-height:1.3;margin-bottom:2px;}
    .lumi-pc-feat svg{width:8px;height:8px;color:#16a34a;flex:none;margin-top:2px;}
    .lumi-pc-btn{display:block;width:100%;padding:4px 8px;border-radius:8px;font-size:9px;font-weight:600;background:#f3f4f6;color:#374151;transition:all .2s;}
    .lumi-pc:hover .lumi-pc-btn{background:#e5e7eb;}
    .lumi-pc.sel .lumi-pc-btn{background:#db2777;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);}
    /* Plan-load error (Step2Pricing error state) */
    .lumi-planerr{text-align:center;padding:40px 0;}
    .lumi-planerr h3{font-size:18px;font-weight:700;color:#111827;margin:0 0 8px;}
    .lumi-planerr p{font-size:13px;color:#4b5563;margin:0 0 16px;}
    .lumi-tryagain{display:inline-block;padding:12px 24px;background:#db2777;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;}
    .lumi-tryagain:hover{background:#be185d;color:#fff;}
    /* Free / paid notices (gradient panels under the plan grid) */
    .lumi-note-ok,.lumi-note-pk{display:flex;gap:8px;align-items:flex-start;padding:12px;border-radius:8px;}
    .lumi-note-ok{background:linear-gradient(to right,#f0fdf4,#ecfdf5);border:1px solid #bbf7d0;}
    .lumi-note-pk{background:linear-gradient(to right,#fdf2f8,#faf5ff);border:1px solid #fbcfe8;margin-top:8px;}
    .lumi-note-ic{width:24px;height:24px;border-radius:999px;display:flex;align-items:center;justify-content:center;flex:none;}
    .lumi-note-ic svg{width:16px;height:16px;}
    .lumi-note-ic.g{background:#dcfce7;color:#16a34a;}
    .lumi-note-ic.p{background:#fce7f3;color:#db2777;}
    .lumi-note-tx{display:flex;flex-direction:column;gap:2px;}
    .lumi-note-ok .lumi-note-tx strong{font-size:12px;font-weight:700;color:#14532d;}
    .lumi-note-ok .lumi-note-tx span{font-size:11px;color:#15803d;}
    .lumi-note-pk .lumi-note-tx strong{font-size:12px;font-weight:700;color:#831843;}
    .lumi-note-pk .lumi-note-tx span{font-size:11px;color:#be185d;}
    .lumi-note-pk .lumi-note-tx .tiny{font-size:10px;color:#db2777;}
    /* Form fields (label text-xs font-semibold; input px-3 py-2 text-xs rounded-lg, pink focus ring) */
    .lumi-f2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
    .lumi-f1{margin-bottom:12px;}
    .lumi-lb{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;}
    .lumi-in{display:block;width:100%;padding:8px 12px;font-size:12px;color:#111827;background:#fff;border:1px solid #d1d5db;border-radius:8px;line-height:1.4;}
    .lumi-in:focus{outline:none;border-color:transparent;box-shadow:0 0 0 2px #ec4899;}
    .lumi-help{font-size:11px;color:#6b7280;margin:4px 0 0;}
    /* Personality trait cards (grid-cols-4, emoji, pink selected, dot check) */
    .lumi-trs{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:4px;}
    .lumi-tr{position:relative;display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px;border:2px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;transition:all .2s;}
    .lumi-tr:hover{border-color:#f472b6;}
    .lumi-tr:has(input:checked){border-color:#ec4899;background:#fdf2f8;box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);}
    .lumi-tr input{position:absolute;opacity:0;pointer-events:none;}
    .lumi-tr-ico{font-size:20px;line-height:1;}
    .lumi-tr-lb{font-size:10px;font-weight:600;color:#374151;}
    .lumi-tr:has(input:checked) .lumi-tr-lb{color:#be185d;}
    .lumi-tr-dot{display:none;width:12px;height:12px;background:#db2777;border-radius:999px;color:#fff;align-items:center;justify-content:center;}
    .lumi-tr-dot svg{width:8px;height:8px;}
    .lumi-tr:has(input:checked) .lumi-tr-dot{display:flex;}
    /* Review summary cards (Step5Review: 2-col grid of p-2 bordered cards) */
    .lumi-rvgrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;}
    .lumi-rvc{padding:8px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;}
    .lumi-rvc h3{display:flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:#111827;margin:0 0 4px;}
    .lumi-ic{display:inline-flex;}
    .lumi-ic svg{width:12px;height:12px;}
    .lumi-ic.g{color:#16a34a;}.lumi-ic.p{color:#db2777;}.lumi-ic.v{color:#9333ea;}
    .lumi-rvc .v{display:block;font-size:12px;font-weight:700;color:#111827;}
    .lumi-rvc .m{display:block;font-size:10px;color:#4b5563;font-weight:400;}
    .lumi-rvc .ok{display:block;font-size:10px;color:#16a34a;margin-top:2px;}
    .lumi-rvc .chips{display:flex;flex-wrap:wrap;gap:4px;}
    .lumi-chip{display:inline-block;padding:2px 6px;background:#f3e8ff;color:#7e22ce;border-radius:4px;font-size:10px;font-weight:500;}
    .lumi-rv-greet{margin-bottom:8px;}
    .lumi-rv-greet .q{display:block;font-size:10px;color:#374151;font-style:italic;}
    /* Launch panel (gradient pink-50 → green-50, green activate button) */
    .lumi-launch{padding:8px;border-radius:8px;background:linear-gradient(to right,#fdf2f8,#f0fdf4);border:1px solid #fbcfe8;margin-bottom:8px;}
    .lumi-launch h3{font-size:12px;font-weight:700;color:#111827;margin:0 0 4px;}
    .lumi-launch p{font-size:10px;color:#374151;margin:0 0 8px;}
    .lumi-activate{display:block;width:100%;padding:8px 12px;background:#16a34a;color:#fff;border:0;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;}
    .lumi-activate:hover{background:#15803d;}
    .lumi-activate:disabled{opacity:.5;cursor:not-allowed;}
    /* What happens next (blue info card) */
    .lumi-next{padding:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;}
    .lumi-next h4{font-size:10px;font-weight:700;color:#1e3a8a;margin:0 0 4px;}
    .lumi-next ol{list-style:none;margin:0;padding:0;}
    .lumi-next li{display:flex;align-items:flex-start;gap:4px;font-size:10px;color:#1e40af;margin-bottom:2px;}
    .lumi-next li strong{font-weight:700;}
    /* Dashboard / settings (unchanged pages) */
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
    @media(max-width:640px){
        .lumi-grid,.lumi-stats,.lumi-rvgrid{grid-template-columns:1fr;}
        .lumi-chs,.lumi-plans,.lumi-trs{grid-template-columns:repeat(2,1fr);}
        .lumi-f2{grid-template-columns:1fr;}
        .lumi-st .l{display:none;}
        .lumi-line{margin-top:19px;width:24px;}
        .lumi-panel{padding:24px;}
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
        // Plan step: card selection, Selected/Select button labels, free vs paid
        // notice panels, and the Monthly/Annual billing-cycle toggle (mirrors the
        // app wizard Step2Pricing + PlanCard behavior).
        var cards = Array.prototype.slice.call(document.querySelectorAll(".lumi-pc"));
        var nameField = document.getElementById("lumi-plan-name");
        var freeNote = document.getElementById("lumi-note-free");
        var paidNote = document.getElementById("lumi-note-paid");
        var paidAmt = document.getElementById("lumi-paid-amt");
        var cycleWrap = document.getElementById("lumi-cycle");
        var cycle = "monthly";
        function planSync(){
            cards.forEach(function(c){
                var r = c.querySelector("input[name=plan]"); if (!r) { return; }
                var b = c.querySelector(".lumi-pc-btn");
                var isFree = r.getAttribute("data-tier") === "free";
                if (r.checked) {
                    c.classList.add("sel");
                    if (b) { b.textContent = "Selected"; }
                    if (nameField) { nameField.value = r.getAttribute("data-name") || ""; }
                    if (freeNote) { freeNote.style.display = isFree ? "flex" : "none"; }
                    if (paidNote) { paidNote.style.display = isFree ? "none" : "flex"; }
                    if (paidAmt) {
                        paidAmt.textContent = (cycle === "annual" && r.getAttribute("data-ydisp"))
                            ? r.getAttribute("data-ydisp") + "/year"
                            : r.getAttribute("data-mdisp") + (r.getAttribute("data-mper") || "");
                    }
                } else {
                    c.classList.remove("sel");
                    if (b) { b.textContent = isFree ? "Free" : "Select"; }
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
                var per = c.querySelector(".lumi-pc-per");
                var ann = c.querySelector(".lumi-pc-annual");
                var yval = r.getAttribute("data-yval");
                if (cy === "annual" && yval) {
                    r.value = yval;
                    if (amt) { amt.textContent = r.getAttribute("data-ydisp"); }
                    if (per) { per.textContent = "/year"; }
                    if (ann) { ann.style.display = "block"; }
                } else {
                    r.value = r.getAttribute("data-mval");
                    if (amt) { amt.textContent = r.getAttribute("data-mdisp"); }
                    if (per) { per.textContent = r.getAttribute("data-mper") || ""; }
                    if (ann) { ann.style.display = "none"; }
                }
            });
            planSync();
        }
        if (cycleWrap) {
            Array.prototype.forEach.call(cycleWrap.querySelectorAll("button"), function(x){
                x.addEventListener("click", function(){ setCycle(x.getAttribute("data-cycle") || "monthly"); });
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
