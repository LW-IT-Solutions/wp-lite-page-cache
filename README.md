=== Lite Page Cache ===
* Contributors: LukasWojcik.com
* Requires at least: 5.0
* Tested up to: 7.1
* Stable tag: 1.1.0
* License: GPLv2 or later
* License URI: http://www.gnu.org/licenses/gpl-2.0.html

A lightweight, blazingly fast caching plugin for static pages and blog posts.

== Description ==

Lite Page Cache is a straightforward, zero-configuration plugin that dramatically speeds up your WordPress site. It works by capturing the output of your pages and single blog posts, saving them as static HTML files.

When a visitor requests a page, the plugin serves the static HTML file instead of processing heavy PHP scripts and database queries. 

Key Features:
* Caches static pages and single posts.
* Bypasses the cache for logged-in users, password-protected posts and visitors with remembered comment data (comment_author_* cookies), so no personalised page is ever stored or served to others.
* Automatically skips URLs with query parameters (e.g., ?utm_source=...).
* Only caches regular 200 responses under the site's own host name.
* Targeted purging: publishing, updating or deleting a post removes only that post's entry and the list pages (home, archives, categories); approving a comment removes the entry of the post it belongs to.
* Every entry expires after one hour (filter `lpc_ttl`, 0 disables expiry) as a safety net for changes WordPress reports no hook for.
* Sends `X-Cache: HIT` / `X-Cache: MISS` headers, so WordPress Site Health recognises the page cache.
* Optional drop-in `advanced-cache.php`: answers cache hits before WordPress, plugins and theme are loaded. It is copied to `wp-content/` on activation and needs `define( 'WP_CACHE', true );` in wp-config.php, which the plugin sets if the file is writable (a backup of wp-config.php is written first). An existing drop-in of another cache plugin is never touched.
* Includes a manual "Clear Cache" button and the drop-in status under Settings > Lite Page Cache.

== Installation ==

1. Upload the `lite-page-cache` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. The plugin works automatically. You can clear the cache manually via `Settings -> Lite Page Cache`.

== Frequently Asked Questions ==

= Does it cache the WooCommerce cart or checkout? =
* No. By default, it only caches singular posts and pages without query parameters. E-commerce endpoints usually require sessions and should not be cached.

= Where are the cache files stored? =
* The HTML files are saved securely in your `/wp-content/cache/lite-page-cache/` directory.

== Changelog ==
* 1.1.0 Targeted purging instead of flushing everything, one-hour expiry, X-Cache headers, host and status checks, optional advanced-cache.php drop-in, no caching for visitors with comment cookies. Fixes the fatal error on activation in the 06.09.2026 commit (activation hooks called methods the class did not have).
* 1.0.1 Added Checks for not serving empty caches
* 1.0.0 Initial release.
