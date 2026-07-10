<?php
/**
 * Plugin Name: LumiTalk AI
 * Plugin URI: https://github.com/Nishant7428/lumitalk-wp
 * Description: AI-powered omnichannel customer service for WooCommerce. Connects your store to LumiTalk and adds the AI chat widget to your storefront.
 * Version: 1.2.2
 * Author: LumiTalk
 * Author URI: https://lumitalk.ai
 * License: GPL-2.0+
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 *
 * NOTE: WooCommerce is not an OAuth marketplace, so instead of a redirect flow this
 * plugin (running inside wp-admin with admin rights) auto-generates a read-only
 * WooCommerce REST API key and connects it to LumiTalk. No keys are copied by hand.
 */

if (!defined('ABSPATH')) {
    exit; // No direct access.
}

// -- Endpoint base (single source of truth) ----------------------------------
// Every LumiTalk URI (connect, app/embed, widget, api, agent) DERIVES from one
// base — the app URL — so switching environments is a single change. Set
// LUMITALK_APP_BASE (in wp-config.php, or the Advanced field / saved app_base) to:
//   https://app.lumitalk.ai      (production)
//   https://appdev.lumitalk.ai   (dev / staging)
//   http://localhost:8080        (local stack)
// Deployed hosts route everything through one host by path; localhost uses the
// per-service dev ports (frontend 8080 / integrations 6000 / backend 8000). See
// lumitalk_endpoints(). Individual URIs can still be force-overridden with
// LUMITALK_CONNECT_BASE / LUMITALK_API_URL / LUMITALK_WIDGET_SRC /
// LUMITALK_AGENT_BASE for a non-standard setup.
// NOTE: default is dev (appdev) while the marketplace-connect endpoints roll out to
// prod. Flip to https://app.lumitalk.ai once prod has them — no other change needed.
if (!defined('LUMITALK_APP_BASE')) {
    define('LUMITALK_APP_BASE', 'https://appdev.lumitalk.ai');
}

define('LUMITALK_OPTION', 'lumitalk_settings');
define('LUMITALK_VER', '1.2.2');

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
    ));
}

function lumitalk_save_settings($settings) {
    update_option(LUMITALK_OPTION, $settings);
}

// Derive EVERY LumiTalk URI from one base (the app URL). Deployed hosts
// (app.lumitalk.ai / appdev.lumitalk.ai) route connect/api/widget through the same
// host by path; localhost uses the per-service dev ports. The agent host is the app
// host with a leading "app" swapped for "agent" (app->agent, appdev->agentdev).
function lumitalk_endpoints() {
    $s = get_option(LUMITALK_OPTION, array());
    // Base: saved app_base setting > LUMITALK_APP_BASE constant > dev default.
    $app = !empty($s['app_base']) ? $s['app_base']
        : ((defined('LUMITALK_APP_BASE') && LUMITALK_APP_BASE) ? LUMITALK_APP_BASE : 'https://appdev.lumitalk.ai');
    $app = untrailingslashit($app);

    $parts  = wp_parse_url($app);
    $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
    $host   = !empty($parts['host'])   ? $parts['host']   : 'appdev.lumitalk.ai';
    $port   = !empty($parts['port'])   ? ':' . $parts['port'] : '';

    if (in_array($host, array('localhost', '127.0.0.1'), true)) {
        // Local dev: frontend 8080, integrations 6000, backend 8000; agent on dev.
        $e = array(
            'app'     => $scheme . '://' . $host . ':8080',
            'connect' => $scheme . '://' . $host . ':6000',
            'api'     => $scheme . '://' . $host . ':8000',
            'agent'   => 'https://agentdev.lumitalk.ai',
        );
        $e['widget'] = $e['api'] . '/public/lumi-chat-widget.min.js';
    } else {
        // Deployed: one path-routed host; agent host swaps the leading "app".
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

    // Per-URI force overrides (saved setting or wp-config constant) win over derivation.
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
// WooCommerce key (/wp-json). Defaults to home_url(); override with LUMITALK_STORE_URL
// in wp-config.php when the store is only reachable via a tunnel (local testing).
function lumitalk_store_url() {
    if (defined('LUMITALK_STORE_URL') && LUMITALK_STORE_URL) {
        return untrailingslashit(LUMITALK_STORE_URL);
    }
    return untrailingslashit(home_url());
}

// Find a published page URL by trying a list of slugs (best-effort policy lookup).
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

// Collect website/store details from WordPress + WooCommerce so LumiTalk can
// prefill the onboarding wizard (WordPress is the source of website data; tenant/
// application/config live in LumiTalk's DB). Uses WC data-store APIs (HPOS-safe).
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
    // Store base address from WooCommerce settings.
    $addr = array(
        get_option('woocommerce_store_address', ''),
        get_option('woocommerce_store_address_2', ''),
        get_option('woocommerce_store_city', ''),
        get_option('woocommerce_store_postcode', ''),
    );
    $details['address'] = trim(implode(', ', array_filter(array_map('trim', $addr))), ', ');
    // Catalog + order counts via WC data stores (HPOS-safe, cheap paginate=1).
    if (function_exists('wc_get_products')) {
        $p = wc_get_products(array('limit' => 1, 'paginate' => true, 'status' => 'publish', 'return' => 'ids'));
        $details['productCount'] = is_object($p) && isset($p->total) ? (int) $p->total : 0;
    }
    if (function_exists('wc_get_orders')) {
        $o = wc_get_orders(array('limit' => 1, 'paginate' => true, 'return' => 'ids'));
        $details['orderCount'] = is_object($o) && isset($o->total) ? (int) $o->total : 0;
    }
    // Customers (users with the WooCommerce customer role).
    $ucount = function_exists('count_users') ? count_users() : array('avail_roles' => array());
    $details['customerCount'] = isset($ucount['avail_roles']['customer']) ? (int) $ucount['avail_roles']['customer'] : 0;

    // Policy / legal page URLs (WordPress privacy page + WooCommerce terms + best-effort
    // slug lookup for returns/shipping) — prefill the wizard's Store Policies step.
    $priv_id = (int) get_option('wp_page_for_privacy_policy');
    $details['privacyPolicyUrl'] = $priv_id ? (get_permalink($priv_id) ?: '') : lumitalk_find_page_url(array('privacy-policy', 'privacy'));
    $terms_id = function_exists('wc_terms_and_conditions_page_id') ? (int) wc_terms_and_conditions_page_id() : (int) get_option('woocommerce_terms_page_id');
    $details['termsOfServiceUrl'] = $terms_id ? (get_permalink($terms_id) ?: '') : lumitalk_find_page_url(array('terms', 'terms-and-conditions', 'terms-of-service'));
    $details['returnPolicyUrl'] = lumitalk_find_page_url(array('refund_returns', 'refunds', 'returns', 'return-policy', 'refund-policy', 'refund-and-returns-policy'));
    $details['shippingPolicyUrl'] = lumitalk_find_page_url(array('shipping', 'shipping-policy', 'delivery', 'shipping-and-returns'));

    // Source-aware counts/currency. WooCommerce is handled above; fill in the rest
    // for Easy Digital Downloads or a plain WordPress site so the wizard prefills the
    // same way regardless of which e-commerce plugin (if any) is installed.
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
// The plugin is NOT WooCommerce-only. Detect whatever store data lives in this
// WordPress install (WooCommerce, Easy Digital Downloads, a custom product post
// type, or plain content) so the AI can be trained on it.
function lumitalk_detect_source() {
    if (class_exists('WooCommerce')) {
        return 'woocommerce';
    }
    if (class_exists('Easy_Digital_Downloads') || function_exists('EDD') || post_type_exists('download')) {
        return 'edd';
    }
    return 'wordpress';
}

// Human label for the detected source (used in the onboarding UI).
function lumitalk_source_label($source = null) {
    $source = $source ?: lumitalk_detect_source();
    $labels = array('woocommerce' => 'WooCommerce', 'edd' => 'Easy Digital Downloads', 'wordpress' => 'WordPress content');
    return isset($labels[$source]) ? $labels[$source] : 'WordPress';
}

// Build a normalized catalog (items) from the detected source. WooCommerce is synced
// server-side via its REST API, so we only need to PUSH a catalog for the other
// sources — but WC is supported here too as a fallback.
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
        // Plain WordPress: a custom 'product' post type if one exists, else pages as knowledge.
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
    // Decode HTML entities (WordPress titles encode &, ', etc.) so the AI gets clean text.
    foreach ($items as &$it) {
        if (isset($it['name']))        { $it['name']        = html_entity_decode($it['name'], ENT_QUOTES, 'UTF-8'); }
        if (isset($it['description'])) { $it['description'] = html_entity_decode($it['description'], ENT_QUOTES, 'UTF-8'); }
    }
    unset($it);
    return $items;
}

// -- Admin menu --------------------------------------------------------------
add_action('admin_menu', function () {
    add_menu_page(
        'LumiTalk AI',
        'LumiTalk AI',
        'manage_options',
        'lumitalk-ai',
        'lumitalk_render_admin_page',
        'dashicons-format-chat',
        58
    );
    // Sub-tabs appear under "LumiTalk AI" once connected: Dashboard / Settings /
    // Marketplace / Agent. Navigation lives in the WP sidebar (not an in-app header).
    $s = lumitalk_get_settings();
    if (!empty($s['connected'])) {
        add_submenu_page('lumitalk-ai', 'Dashboard', 'Dashboard', 'manage_options', 'lumitalk-ai', 'lumitalk_render_admin_page');
        add_submenu_page('lumitalk-ai', 'Agent', 'Agent', 'manage_options', 'lumitalk-ai-agent', 'lumitalk_render_agent');
        add_submenu_page('lumitalk-ai', 'Settings', 'Settings', 'manage_options', 'lumitalk-ai-settings', 'lumitalk_render_settings');
    }
});

// -- Admin notices for connect results (passed via query args) ---------------
function lumitalk_redirect_with($key, $value) {
    $url = add_query_arg(array('page' => 'lumitalk-ai', $key => rawurlencode($value)), admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
}

// -- Connect handler: generate WC API key -> call LumiTalk -> store result ----
add_action('admin_post_lumitalk_connect', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('lumitalk_connect');

    // Persist overridden endpoints if the admin entered them.
    if (!empty($_POST['connect_base']) || !empty($_POST['app_base'])) {
        $s = lumitalk_get_settings();
        if (!empty($_POST['connect_base'])) { $s['connect_base'] = esc_url_raw(wp_unslash($_POST['connect_base'])); }
        if (!empty($_POST['app_base']))     { $s['app_base']     = esc_url_raw(wp_unslash($_POST['app_base'])); }
        lumitalk_save_settings($s);
    }
    $connect_base = lumitalk_connect_base();
    $source       = lumitalk_detect_source();
    $store_host   = preg_replace('#^https?://#', '', lumitalk_store_url());
    $user         = wp_get_current_user();

    if ($source === 'woocommerce') {
        // WooCommerce exposes a REST API — hand LumiTalk read-only keys and it pulls
        // products/customers/orders itself.
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
                'consumer_key'    => wc_api_hash($consumer_key),     // WC stores the key hashed
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
                // Website data fetched from WordPress so LumiTalk can auto-prefill onboarding.
                'storeData'      => lumitalk_collect_store_details(),
            )),
        ));
    } else {
        // Non-WooCommerce (Easy Digital Downloads / custom product type / plain content):
        // there is no uniform store API to pull, so collect the catalog locally and PUSH
        // it to LumiTalk. No API keys and no public reachability required.
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

    lumitalk_redirect_with('lumitalk_connected', '1');
});

// -- Disconnect handler ------------------------------------------------------
add_action('admin_post_lumitalk_disconnect', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('lumitalk_disconnect');
    delete_option(LUMITALK_OPTION);
    lumitalk_redirect_with('lumitalk_disconnected', '1');
});

// -- Toggle widget on/off ----------------------------------------------------
add_action('admin_post_lumitalk_toggle_widget', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('lumitalk_toggle_widget');
    $settings = lumitalk_get_settings();
    $settings['widget_enabled'] = empty($settings['widget_enabled']);
    lumitalk_save_settings($settings);
    lumitalk_redirect_with('lumitalk_saved', '1');
});

// -- Widget key sync ---------------------------------------------------------
// The chat widget key is generated during onboarding (not at connect), so pull the
// active key from LumiTalk via the embed token and cache it locally. Only runs
// until we have a key (keeps admin loads fast afterward).
function lumitalk_sync_embed_state() {
    $s = lumitalk_get_settings();
    if (empty($s['connected']) || empty($s['embed_token'])) {
        return $s;
    }
    $resp = wp_remote_get(lumitalk_connect_base() . '/marketplace/embed/state', array(
        'timeout' => 6,
        'headers' => array('Authorization' => 'Bearer ' . $s['embed_token']),
    ));
    if (is_wp_error($resp)) {
        return $s;
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data)) {
        return $s;
    }
    // Fresh short-lived token for the iframe URL — keeps the long-lived (30-day)
    // token server-side, never in a browser URL.
    if (!empty($data['embedToken'])) {
        lumitalk_iframe_token($data['embedToken']);
    }
    $changed = false;
    if (!empty($data['widgetKey']) && $data['widgetKey'] !== $s['widget_key']) {
        $s['widget_key'] = sanitize_text_field($data['widgetKey']);
        $changed = true;
    }
    // `launched` marks a finished onboarding — used to show the dashboard (not the
    // wizard) on subsequent visits, so config isn't "lost" on refresh.
    if (array_key_exists('launched', $data)) {
        $onb = !empty($data['launched']);
        if ($onb !== !empty($s['onboarded'])) { $s['onboarded'] = $onb; $changed = true; }
    }
    if ($changed) {
        lumitalk_save_settings($s);
    }
    return $s;
}

// Short-lived embed token for iframe URLs. Set by lumitalk_sync_embed_state() from
// /embed/state; falls back to the stored long-lived token if the refresh is
// unavailable, so the embed still loads. The long-lived token is only ever used
// server-to-server (Bearer header), never placed in a browser URL.
function lumitalk_iframe_token($set = null) {
    static $tok = null;
    if ($set !== null) { $tok = $set; return $tok; }
    if (!empty($tok)) { return $tok; }
    $s = lumitalk_get_settings();
    return !empty($s['embed_token']) ? $s['embed_token'] : '';
}

// AJAX: save the widget key pushed from the embedded app the instant onboarding
// finishes (auto-inject the storefront widget with no reload).
add_action('wp_ajax_lumitalk_save_widget_key', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('lumitalk_widget_key');
    $key = isset($_POST['widget_key']) ? sanitize_text_field(wp_unslash($_POST['widget_key'])) : '';
    if ($key && strpos($key, 'wk_') === 0) {
        $s = lumitalk_get_settings();
        $s['widget_key'] = $key;
        lumitalk_save_settings($s);
        wp_send_json_success(array('saved' => true));
    }
    wp_send_json_error('invalid key');
});

// AJAX: mint a fresh single-use SSO ticket and return the agent-panel URL for the
// "Open Agent" button. Minted on click (tickets are short-lived / single-use).
add_action('wp_ajax_lumitalk_agent_sso', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('unauthorized', 403);
    }
    check_ajax_referer('lumitalk_agent_sso');
    $s = lumitalk_get_settings();
    if (empty($s['connected']) || empty($s['embed_token'])) {
        wp_send_json_error('not connected');
    }
    $resp = wp_remote_get(lumitalk_connect_base() . '/marketplace/embed/agent-sso', array(
        'timeout' => 15,
        'headers' => array('Authorization' => 'Bearer ' . $s['embed_token']),
    ));
    if (is_wp_error($resp)) {
        wp_send_json_error($resp->get_error_message());
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($data['token'])) {
        wp_send_json_error('Could not create a sign-in link');
    }
    $app_id = !empty($s['application_id']) ? $s['application_id'] : (isset($data['applicationId']) ? $data['applicationId'] : '');
    $app_q  = 'application_id=' . rawurlencode($app_id);
    $path   = !empty($data['agentId'])
        ? '/ai-agents/convai/' . rawurlencode($data['agentId']) . '?' . $app_q
        : '/ai-agents?' . $app_q;
    $url = lumitalk_agent_base() . '/sso-callback?ticket=' . rawurlencode($data['token'])
         . '&redirect=' . rawurlencode($path) . '&origin=woocommerce';
    wp_send_json_success(array('url' => $url));
});

// Render the full-bleed embedded LumiTalk app (dashboard or wizard) in wp-admin,
// with the WordPress store-details + widget-key bridges. Shared by the sub-tabs.
function lumitalk_render_embed($url) {
    echo '<style>'
       . '#wpcontent{padding-left:0!important}'
       . '#wpbody-content{padding:0!important}'
       . '#wpfooter{display:none!important}'
       . '#wpbody-content > .notice,#wpbody-content > .update-nag{display:none!important}'
       . 'html{overflow:hidden}'
       . '</style>'
       . '<iframe id="lumitalk-embed" src="' . esc_url($url) . '" title="LumiTalk" '
       . 'style="width:100%;height:calc(100vh - 32px);border:0;margin:0;padding:0;display:block;"></iframe>';
    // Push WordPress/WooCommerce details into the embedded app on load (prefill).
    echo '<script>(function(){'
       . 'var f=document.getElementById("lumitalk-embed");if(!f)return;'
       . 'var d=' . wp_json_encode(lumitalk_collect_store_details()) . ';'
       . 'var o=' . wp_json_encode(lumitalk_app_base()) . ';'
       . 'f.addEventListener("load",function(){try{f.contentWindow.postMessage({type:"lumitalk:wpStoreDetails",data:d},o);}catch(e){}});'
       . '})();</script>';
    // Auto-insert the storefront widget key when onboarding finishes.
    $wk_nonce = wp_create_nonce('lumitalk_widget_key');
    echo '<script>window.addEventListener("message",function(e){'
       . 'var m=e&&e.data;if(!m||m.type!=="lumitalk:widgetKey"||!m.widgetKey)return;'
       . 'var b=new URLSearchParams();b.set("action","lumitalk_save_widget_key");'
       . 'b.set("_wpnonce",' . wp_json_encode($wk_nonce) . ');b.set("widget_key",m.widgetKey);'
       . 'fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",'
       . 'headers:{"Content-Type":"application/x-www-form-urlencoded"},body:b.toString()});'
       . '});</script>';
    // Graceful fallback: if the app iframe doesn't load (down/slow), show a friendly
    // message instead of a blank admin page. Self-corrects if the iframe loads late.
    echo '<div id="lumitalk-fallback" style="display:none;position:fixed;top:32px;left:0;right:0;bottom:0;z-index:99998;'
       . 'background:#fff;align-items:center;justify-content:center;text-align:center;padding:40px;'
       . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
       . '<div style="max-width:440px;"><div style="font-size:34px;margin-bottom:12px;">&#9888;&#65039;</div>'
       . '<h2 style="margin:0 0 8px;font-size:20px;color:#0f172a;">Taking longer than expected</h2>'
       . '<p style="color:#64748b;margin:0 0 22px;line-height:1.6;">We couldn\'t load your LumiTalk dashboard. Check your connection and try again.</p>'
       . '<button onclick="location.reload()" style="background:#fe87a4;color:#fff;border:0;border-radius:10px;padding:11px 22px;font-weight:600;cursor:pointer;margin-right:10px;">Reload</button>'
       . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" style="color:#64748b;font-size:14px;">Open in a new tab &#8599;</a>'
       . '</div></div>';
    echo '<script>(function(){var f=document.getElementById("lumitalk-embed"),fb=document.getElementById("lumitalk-fallback"),ok=false;'
       . 'if(!f)return;function alive(){ok=true;if(fb)fb.style.display="none";}'
       . 'f.addEventListener("load",alive);'
       // Proof of life: any postMessage from the embedded app means it is loading/alive
       // (covers slow first loads / cold dev dynos where the iframe "load" event lags).
       . 'window.addEventListener("message",function(e){var m=e&&e.data;'
       . 'if(m&&typeof m==="object"&&String(m.type||"").indexOf("lumitalk")===0)alive();});'
       . 'setTimeout(function(){if(!ok&&fb)fb.style.display="flex";},30000);})();</script>';
}

// Settings sub-tab: reopen the configuration wizard in the embed.
function lumitalk_render_settings() {
    $s = lumitalk_sync_embed_state();
    if (empty($s['connected']) || empty($s['embed_token'])) {
        echo '<div class="wrap"><h1>Settings</h1><p>Connect to LumiTalk first from the <strong>Dashboard</strong> tab.</p></div>';
        return;
    }
    $src = !empty($s['source']) ? $s['source'] : lumitalk_detect_source();
    lumitalk_render_embed(lumitalk_app_base() . '/embed-app-config?embedToken=' . rawurlencode(lumitalk_iframe_token()) . '&source=' . rawurlencode($src)
        . '&wpReturn=' . rawurlencode(admin_url('admin.php?page=lumitalk-ai')));
}

// Agent sub-tab: a branded page whose button opens the agent / OmniDesk panel in a
// NEW TAB, signed in (a fresh single-use ticket is minted on click via AJAX).
function lumitalk_render_agent() {
    $s = lumitalk_get_settings();
    if (empty($s['connected']) || empty($s['embed_token'])) {
        echo '<div class="wrap"><h1>Agent</h1><p>Connect to LumiTalk first from the <strong>Dashboard</strong> tab.</p></div>';
        return;
    }
    $agent_nonce = wp_create_nonce('lumitalk_agent_sso');
    echo '<style>#wpcontent{padding-left:0!important}#wpbody-content{padding:0!important}#wpfooter{display:none!important}'
       . '.lumi-agent{min-height:calc(100vh - 32px);display:flex;align-items:center;justify-content:center;padding:20px;'
       . 'background:linear-gradient(135deg,#fef2f4 0%,#f0fdf4 100%);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
       . '.lumi-agent-card{max-width:460px;text-align:center;background:#fff;border:1px solid #eef0f3;border-radius:22px;'
       . 'padding:44px 36px;box-shadow:0 20px 45px -20px rgba(15,23,42,.25);}'
       . '.lumi-agent-card h1{font-size:24px;font-weight:800;margin:0 0 10px;color:#0f172a;}'
       . '.lumi-agent-card p{color:#475569;font-size:15px;margin:0 0 26px;line-height:1.6;}'
       . '#lumitalk-open-agent{display:inline-flex;align-items:center;gap:7px;color:#fff;border:0;border-radius:12px;'
       . 'padding:13px 26px;font-size:15px;font-weight:700;cursor:pointer;background:linear-gradient(100deg,#fe87a4,#a855f7);'
       . 'box-shadow:0 12px 26px -10px rgba(168,85,247,.8)}'
       . '#lumitalk-open-agent:hover{transform:translateY(-1px)}#lumitalk-open-agent[disabled]{opacity:.6;cursor:default}</style>';
    echo '<div class="lumi-agent"><div class="lumi-agent-card">'
       . '<h1>Your Agent Dashboard</h1>'
       . '<p>Manage conversations, AI agents, and your inbox in the LumiTalk agent panel. Opens in a new tab, already signed in.</p>'
       . '<button id="lumitalk-open-agent">Open Agent Dashboard &#8599;</button>'
       . '</div></div>';
    echo '<script>(function(){var btn=document.getElementById("lumitalk-open-agent");if(!btn)return;'
       . 'btn.addEventListener("click",function(){'
       . 'var tab=window.open("about:blank","_blank");btn.disabled=true;btn.textContent="Opening…";'
       . 'var b=new URLSearchParams();b.set("action","lumitalk_agent_sso");b.set("_wpnonce",' . wp_json_encode($agent_nonce) . ');'
       . 'fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:b.toString()})'
       . '.then(function(r){return r.json();}).then(function(j){btn.disabled=false;btn.innerHTML="Open Agent Dashboard ↗";'
       . 'if(j&&j.success&&j.data&&j.data.url){if(tab)tab.location.href=j.data.url;else window.open(j.data.url,"_blank");}'
       . 'else{if(tab)tab.close();alert("Could not open the agent dashboard. Please try again.");}})'
       . '.catch(function(){btn.disabled=false;btn.innerHTML="Open Agent Dashboard ↗";if(tab)tab.close();});'
       . '});})();</script>';
}

// -- Admin page UI -----------------------------------------------------------
function lumitalk_render_admin_page() {
    // Sync widget key + onboarded flag from LumiTalk (also decides wizard vs dashboard).
    $s = lumitalk_sync_embed_state();
    $source = lumitalk_detect_source();

    // Connected state with an embed token: render ONLY the embedded LumiTalk app,
    // full-bleed (no plugin header, no footer) -- the Shopify-style in-admin app.
    // Onboarded stores land on the dashboard; new ones on the setup wizard. This is
    // what keeps a completed config from "resetting" to the wizard on refresh.
    // Returning from Stripe checkout? Resume the wizard (with the session id) so it
    // finalizes the subscription and continues from the step the merchant was on —
    // rather than jumping to the dashboard.
    // Read-only admin-notice / return flags set by our own redirects (Stripe return,
    // connect result). These drive display only and change no state, so a nonce does
    // not apply; values are still sanitized before use.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $billing      = isset($_GET['lumitalk_billing']) ? sanitize_text_field(wp_unslash($_GET['lumitalk_billing'])) : '';
    $session_id   = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';
    $notice_error = isset($_GET['lumitalk_error']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['lumitalk_error']))) : '';
    $notice_disc  = isset($_GET['lumitalk_disconnected']);
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    $embed_url = '';
    if (!empty($s['connected']) && !empty($s['embed_token'])) {
        $et        = rawurlencode(lumitalk_iframe_token());
        $wp_return = rawurlencode(admin_url('admin.php?page=lumitalk-ai'));
        $src       = !empty($s['source']) ? $s['source'] : $source;
        $wizard    = lumitalk_app_base() . '/embed-app-config?embedToken=' . $et . '&source=' . rawurlencode($src) . '&wpReturn=' . $wp_return;
        if ($billing && $session_id) {
            $embed_url = $wizard . '&session_id=' . rawurlencode($session_id)
                . ($billing === 'success' ? '&success=true' : '&canceled=true');
        } elseif (!empty($s['onboarded'])) {
            $embed_url = lumitalk_app_base() . '/embed-app-dashboard?embedToken=' . $et;
        } else {
            $embed_url = $wizard;
        }
    }
    if ($embed_url) {
        lumitalk_render_embed($embed_url);
        return;
    }

    // -- Pre-connect onboarding (Shopify-app-style branded welcome) -----------
    $connected  = !empty($s['connected']); // connected but no embed token (older link)
    $logo       = esc_url(lumitalk_app_base() . '/lumitalk_logo.png');
    $store_host = preg_replace('#^https?://#', '', lumitalk_store_url());
    $admin_mail = wp_get_current_user()->user_email;
    $disconnect = wp_nonce_url(admin_url('admin-post.php?action=lumitalk_disconnect'), 'lumitalk_disconnect');

    // Channels the assistant can handle (mirrors the embedded onboarding wizard).
    $channels = array(
        array('emoji' => "\xF0\x9F\x92\xAC", 'label' => 'AI Chat',  'desc' => '24/7 chat widget on your storefront'),
        array('emoji' => "\xF0\x9F\x93\x9E", 'label' => 'AI Voice', 'desc' => 'AI answers your phone line'),
        array('emoji' => "\xF0\x9F\x93\xB1", 'label' => 'SMS',      'desc' => 'Two-way SMS support'),
        array('emoji' => "\xE2\x9C\x89\xEF\xB8\x8F", 'label' => 'Email', 'desc' => 'AI-assisted email replies'),
    );

    // Full-bleed branded canvas -- cancel wp-admin chrome so the onboarding fills
    // the tab the same way the embedded app does (consistent Shopify-style feel).
    ?>
    <style>
        #wpcontent{padding-left:0!important}
        #wpbody-content{padding:0!important}
        #wpfooter{display:none!important}
        #wpbody-content > .notice,#wpbody-content > .update-nag{display:none!important}
        .lumi-onb{min-height:calc(100vh - 32px);box-sizing:border-box;padding:40px 20px;
            background:linear-gradient(135deg,#fef2f4 0%,#f0fdf4 100%);
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;}
        .lumi-onb *{box-sizing:border-box;}
        .lumi-onb-card{max-width:660px;margin:0 auto;background:#fff;border:1px solid #eef0f3;
            border-radius:24px;padding:40px;box-shadow:0 20px 45px -20px rgba(15,23,42,.25);}
        .lumi-onb-logo{height:44px;display:block;margin:0 0 24px;}
        .lumi-onb-eyebrow{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.06em;
            text-transform:uppercase;color:#fe87a4;margin:0 0 10px;}
        .lumi-onb h1{font-size:28px;line-height:1.2;margin:0 0 10px;font-weight:800;color:#0f172a;}
        .lumi-onb .sub{font-size:15px;line-height:1.6;color:#475569;margin:0 0 26px;max-width:52ch;}
        .lumi-onb-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:0 0 28px;}
        .lumi-onb-tile{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border:1px solid #eef0f3;
            border-radius:14px;background:#fbfcfe;}
        .lumi-onb-tile .ic{font-size:20px;line-height:1;flex-shrink:0;}
        .lumi-onb-tile strong{display:block;font-size:14px;color:#0f172a;margin-bottom:2px;}
        .lumi-onb-tile span{font-size:12.5px;color:#64748b;line-height:1.4;}
        .lumi-onb-meta{display:flex;flex-wrap:wrap;gap:8px 24px;padding:16px 18px;background:#f8fafc;
            border-radius:14px;margin:0 0 26px;font-size:13px;}
        .lumi-onb-meta .k{color:#94a3b8;margin-right:8px;}
        .lumi-onb-meta code{background:transparent;color:#0f172a;font-size:13px;padding:0;}
        .lumi-onb-cta{display:inline-flex;align-items:center;gap:8px;background:#fe87a4;color:#fff;
            border:0;border-radius:12px;padding:14px 28px;font-size:15px;font-weight:700;cursor:pointer;
            box-shadow:0 10px 20px -8px rgba(254,135,164,.8);transition:transform .08s ease,box-shadow .15s ease;}
        .lumi-onb-cta:hover{transform:translateY(-1px);box-shadow:0 14px 26px -8px rgba(254,135,164,.9);color:#fff;}
        .lumi-onb-cta:disabled{background:#e2e8f0;color:#94a3b8;box-shadow:none;cursor:not-allowed;transform:none;}
        .lumi-onb-note{font-size:12.5px;color:#94a3b8;margin:14px 0 0;}
        .lumi-onb-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 16px;
            border-radius:12px;font-size:13.5px;margin:0 0 22px;}
        .lumi-onb-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;
            border-radius:12px;font-size:13.5px;margin:0 0 22px;}
        .lumi-onb-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 16px;
            border-radius:12px;font-size:13.5px;margin:0 0 22px;}
        .lumi-onb details{margin-top:22px;border-top:1px solid #eef0f3;padding-top:16px;}
        .lumi-onb summary{cursor:pointer;font-size:13px;color:#64748b;font-weight:600;list-style:none;}
        .lumi-onb summary::-webkit-details-marker{display:none;}
        .lumi-onb summary:before{content:"\203A";margin-right:8px;color:#94a3b8;}
        .lumi-onb details[open] summary:before{content:"\2039";}
        .lumi-onb .adv-field{margin:14px 0 0;}
        .lumi-onb .adv-field label{display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;}
        .lumi-onb .adv-field input{width:100%;padding:9px 12px;border:1px solid #d7dce3;border-radius:9px;
            font-size:13px;color:#0f172a;}
        .lumi-onb-foot{text-align:center;font-size:12px;color:#94a3b8;margin:22px auto 0;max-width:660px;}
        .lumi-onb-foot a{color:#64748b;}
        @media (max-width:600px){.lumi-onb-grid{grid-template-columns:1fr;}.lumi-onb-card{padding:28px 22px;}}
    </style>
    <div class="lumi-onb">
        <div class="lumi-onb-card">
            <img class="lumi-onb-logo" src="<?php echo esc_url($logo); ?>" alt="LumiTalk"
                 onerror="this.style.display='none'" />

            <?php if ('' !== $notice_error) : ?>
                <div class="lumi-onb-err"><?php echo esc_html($notice_error); ?></div>
            <?php endif; ?>
            <?php if ($notice_disc) : ?>
                <div class="lumi-onb-ok">Disconnected from LumiTalk.</div>
            <?php endif; ?>

            <span class="lumi-onb-eyebrow">LumiTalk AI</span>
            <?php if ($connected) : ?>
                <h1>Finish connecting your store</h1>
                <p class="sub">Your store is linked to LumiTalk. Reconnect to load the embedded setup and pick up where you left off.</p>
            <?php else : ?>
                <h1>Add AI customer service to your store</h1>
                <p class="sub">Connect your store and LumiTalk's AI answers customer questions about your products, orders, and content &mdash; with a chat widget live on your storefront in minutes.</p>
            <?php endif; ?>

            <?php $src_label = lumitalk_source_label($source); ?>
            <div class="lumi-onb-ok"><strong>Detected data source: <?php echo esc_html($src_label); ?>.</strong>
                <?php if ($source === 'woocommerce') : ?>
                    Your products, orders, and customers sync to LumiTalk automatically.
                <?php elseif ($source === 'edd') : ?>
                    Your Easy Digital Downloads products are sent to LumiTalk so the AI can answer questions about them.
                <?php else : ?>
                    Your site content is sent to LumiTalk so the AI can answer questions about it. Install WooCommerce or Easy Digital Downloads for full product &amp; order support.
                <?php endif; ?>
            </div>

            <div class="lumi-onb-grid">
                <?php foreach ($channels as $ch) : ?>
                    <div class="lumi-onb-tile">
                        <span class="ic"><?php echo esc_html($ch['emoji']); ?></span>
                        <span>
                            <strong><?php echo esc_html($ch['label']); ?></strong>
                            <span><?php echo esc_html($ch['desc']); ?></span>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="lumi-onb-meta">
                <span><span class="k">Store</span><code><?php echo esc_html($store_host); ?></code></span>
                <span><span class="k">Admin</span><code><?php echo esc_html($admin_mail); ?></code></span>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lumitalk_connect" />
                <?php wp_nonce_field('lumitalk_connect'); ?>

                <button type="submit" class="lumi-onb-cta">
                    <?php echo $connected ? 'Reconnect to LumiTalk' : 'Connect to LumiTalk'; ?>
                </button>
                <p class="lumi-onb-note">
                    <?php if ($source === 'woocommerce') : ?>
                        We create a read-only WooCommerce API key for you &mdash; no keys to copy by hand.
                    <?php else : ?>
                        Your store data is sent securely to LumiTalk &mdash; nothing to copy by hand.
                    <?php endif; ?>
                </p>

                <details>
                    <summary>Advanced settings</summary>
                    <div class="adv-field">
                        <label for="lumitalk_connect_base">LumiTalk connect endpoint <span style="color:#94a3b8;font-weight:400;">(leave as-is unless testing)</span></label>
                        <input type="url" id="lumitalk_connect_base" name="connect_base" value="<?php echo esc_attr(lumitalk_connect_base()); ?>" />
                    </div>
                    <div class="adv-field">
                        <label for="lumitalk_app_base">LumiTalk app URL <span style="color:#94a3b8;font-weight:400;">(hosts the embedded onboarding)</span></label>
                        <input type="url" id="lumitalk_app_base" name="app_base" value="<?php echo esc_attr(lumitalk_app_base()); ?>" />
                    </div>
                </details>
            </form>

            <?php if ($connected) : ?>
                <details>
                    <summary>Disconnect</summary>
                    <p class="lumi-onb-note" style="margin-top:12px;">Remove the LumiTalk connection from this store.
                        <a href="<?php echo esc_url($disconnect); ?>" style="color:#b32d2e;">Disconnect now</a>
                    </p>
                </details>
            <?php endif; ?>
        </div>

        <p class="lumi-onb-foot">&copy; <?php echo esc_html(gmdate('Y')); ?> LumiTalk &bull; Need help? <a href="mailto:support@lumitalk.ai">Contact Support</a></p>
    </div>
    <?php
}

// -- Inject the chat widget on the storefront (properly enqueued) ------------
add_action('wp_enqueue_scripts', function () {
    $s = lumitalk_get_settings();
    if (empty($s['connected']) || empty($s['widget_enabled']) || empty($s['widget_key'])) {
        return;
    }
    wp_enqueue_script('lumitalk-chat-widget', lumitalk_widget_src(), array(), LUMITALK_VER, true);
    // Stashed for the loader-tag filter below (the widget reads these off its own tag).
    wp_script_add_data('lumitalk-chat-widget', 'lumitalk_widget_key', $s['widget_key']);
    wp_script_add_data('lumitalk-chat-widget', 'lumitalk_api_url', lumitalk_api_url());
});

// The widget loader reads its config from data-* attributes on its own <script> tag,
// so add them (and async) to the enqueued tag via the standard loader-tag filter.
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
