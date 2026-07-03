=== Lite Page Cache ===
Contributors: LukasWojcik.com
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A lightweight, blazingly fast caching plugin for static pages and blog posts.

== Description ==

Lite Page Cache is a straightforward, zero-configuration plugin that dramatically speeds up your WordPress site. It works by capturing the output of your pages and single blog posts, saving them as static HTML files.

When a visitor requests a page, the plugin serves the static HTML file instead of processing heavy PHP scripts and database queries. 

Key Features:
* Caches static pages and single posts.
* Bypasses cache for logged-in users to prevent admin bar caching.
* Automatically skips URLs with query parameters (e.g., ?utm_source=...).
* Automatically flushes the entire cache whenever you publish or update a post.
* Includes a manual "Clear Cache" button under Settings > Lite Page Cache.

== Installation ==

1. Upload the `lite-page-cache` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. The plugin works automatically. You can clear the cache manually via `Settings -> Lite Page Cache`.

== Frequently Asked Questions ==

= Does it cache the WooCommerce cart or checkout? =
No. By default, it only caches singular posts and pages without query parameters. E-commerce endpoints usually require sessions and should not be cached.

= Where are the cache files stored? =
The HTML files are saved securely in your `/wp-content/cache/lite-page-cache/` directory.

== Changelog ==

= 1.0.0 =
* Initial release.
