=== LumiTalk AI ===
Contributors: lumitalk
Tags: ai, chatbot, customer service, live chat, woocommerce
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered customer service for WordPress. Connect your store to LumiTalk and add an AI chat widget that answers customer questions.

== Description ==

LumiTalk AI connects your WordPress store to the LumiTalk platform so an AI assistant
can answer customer questions about your products, orders, and content — and adds the
LumiTalk chat widget to your storefront.

It is not limited to WooCommerce: the plugin detects your store's data source and
connects accordingly. WooCommerce stores connect with a read-only REST API key the
plugin generates for you; Easy Digital Downloads, custom product types, or plain
WordPress sites send a normalized catalog to LumiTalk instead. No keys to copy by hand.

**An account on the LumiTalk service is required** (a free tier is available). Creating
the account and connecting happens from wp-admin when you click "Connect to LumiTalk".

= What it does =
* One-click connect from wp-admin (auto-detects WooCommerce / EDD / plain WordPress)
* Creates your LumiTalk account/application and links your store
* Adds the AI chat widget to your storefront
* Enable/disable the widget and open the LumiTalk dashboard from wp-admin

== External services ==

This plugin connects to **LumiTalk**, a third-party SaaS platform, to provide the AI
assistant and chat widget. Connecting is optional and is only initiated when you click
"Connect to LumiTalk" in wp-admin.

What data is sent, and when:

* **When you click Connect (one time / on reconnect):** your store name, the
  administrator email, your site URL, currency, timezone, store address, and your
  product/order/customer counts — used to create your LumiTalk account and pre-fill
  onboarding. For WooCommerce, a read-only WooCommerce REST API key is generated and
  sent so LumiTalk can read your catalog; for other platforms a normalized product
  catalog (titles, descriptions, prices, categories, images) is sent directly.
* **Embedded onboarding/dashboard:** after connecting, the LumiTalk app is shown in an
  iframe inside wp-admin, authenticated with a token issued to your store.
* **Storefront chat widget:** once enabled, a widget script is loaded from LumiTalk on
  your public pages so visitors can chat with the AI. Visitor chat messages are sent to
  LumiTalk to generate replies.

No data is sent to LumiTalk until you connect, and the widget only loads after you
connect and enable it.

This service is provided by LumiTalk. By connecting you agree to their terms:

* Terms of Service: https://lumitalk.ai/terms
* Privacy Policy: https://lumitalk.ai/privacy

== Installation ==

1. Upload the `lumitalk-ai` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate **LumiTalk AI** through the Plugins menu.
3. Go to **LumiTalk AI** in the wp-admin sidebar and click **Connect to LumiTalk**.
4. (WooCommerce stores) Make sure WooCommerce is active first so the catalog can be linked.

== Frequently Asked Questions ==

= Does this plugin require an account or paid service? =
It requires a free LumiTalk account, created when you connect. Paid plans are optional
and are chosen during onboarding.

= What data leaves my site? =
See the "External services" section above. Nothing is sent until you click Connect.

= Does it require WooCommerce? =
No. WooCommerce stores get automatic catalog sync; Easy Digital Downloads, custom
product types, and plain WordPress sites are supported too.

== Changelog ==

= 1.2.2 =
* Compliance & hardening: enqueue the storefront widget via wp_enqueue_script, escape
  all output, sanitize admin-notice query args, timezone-safe date handling, and a full
  external-service data disclosure in this readme.

= 1.2.1 =
* Zero-config endpoints: the plugin works on any domain without setting a URL. All
  LumiTalk URLs derive automatically from a single base. Override with LUMITALK_APP_BASE
  in wp-config.php only for a non-standard environment.

= 1.2.0 =
* No longer requires WooCommerce. Detects your store's data source (WooCommerce, Easy
  Digital Downloads, a custom product type, or plain content) and connects accordingly.

= 1.1.0 =
* Redesigned onboarding: branded welcome screen with channel highlights, one-click
  Connect, and advanced endpoint settings in a collapsible section.

= 1.0.0 =
* Initial release: one-click WooCommerce connect + storefront chat widget.
