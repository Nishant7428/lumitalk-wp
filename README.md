# LumiTalk AI — WordPress Plugin

AI-powered customer service for WordPress. Connect your store — **WooCommerce, Easy Digital Downloads, or any WordPress site** — to [LumiTalk](https://lumitalk.ai) and add an AI chat widget that answers customer questions about your products and content.

## Features

- One-click connect from wp-admin (auto-detects WooCommerce / EDD / plain WordPress)
- Creates your LumiTalk account/application and links your store
- Embedded onboarding + dashboard inside wp-admin (no redirects)
- Auto-injects the AI chat widget on your storefront

## Installation

1. Build a zip of the plugin folder (see **Packaging**), or download a release.
2. In wp-admin: **Plugins → Add New → Upload Plugin** → choose the zip → **Install** → **Activate**.
3. Open **LumiTalk AI** in the sidebar and click **Connect to LumiTalk**.

WooCommerce stores should have WooCommerce active first so the catalog can be linked.

## Requirements

- WordPress 5.8+ (tested up to 7.0)
- PHP 7.4+
- A LumiTalk account (free tier available)

## Configuration

Every LumiTalk URL (connect, API, widget, agent) derives from a single base, so
switching environments is one setting. Override in `wp-config.php`:

```php
define( 'LUMITALK_APP_BASE', 'https://app.lumitalk.ai' );
```

## External service

This plugin connects to the **LumiTalk** SaaS platform. Nothing is sent until you click
**Connect**. See [`readme.txt`](readme.txt) → *External services* for exactly what data
is sent and when.

- Terms of Service: https://lumitalk.ai/terms
- Privacy Policy: https://lumitalk.ai/privacy

## Packaging

The distributable zip must contain a top-level `lumitalk-ai/` folder:

```
lumitalk-ai/
  lumitalk-ai.php
  readme.txt
```

## License

[GPL-2.0-or-later](LICENSE).
