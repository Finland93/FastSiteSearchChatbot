=== Fast Site Search Chatbot ===
Contributors: finland93
Donate link: https://github.com/Finland93
Tags: search, chatbot, site-search, faq, no-ai, privacy
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.9.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Fast Site Search Chatbot adds a floating chat-style search box for your WordPress site.
- No external APIs or AI required.
- Private JSON dataset built daily.
- Instant search with fuzzy matching.
- Exclude categories, tags, or posts.
- Secure by design.

== Installation ==
1. Upload plugin files to `/wp-content/plugins/FastSiteSearchChatbot`.
2. Activate the plugin.
3. Go to **Fast Chatbot** in WP admin to configure and build the dataset.

== Screenshots ==
1. Chat widget open with search results.
2. Admin settings panel with exclusions.

== Changelog ==
= 1.9.4 =
* Fixed: the chatbot now works for logged-out visitors. Guests previously sent a custom 'wp_rest_public' nonce in X-WP-Nonce, which WordPress core always validates against 'wp_rest' and rejected with 403 before the plugin ran. Guests now send no nonce and the public dataset is served under the existing same-origin + rate-limit protection (which also fixes caching).

= 1.9.3 =
* Hardening: unslash + sanitize all superglobal input (nonce, REST header, IP, origin/referer).
* Fix: anonymous REST reads no longer write a filename option to the database before the first build.

= 1.9.2 =
* Auto widget, exclude-rules UI pickers, colour options, smart cron rebuild + daily filename rotation.

= 1.8.0 =
* Added floating launcher & close button.
* Results show as list of links (titles only).
* Daily smart rebuild + file rotation.
* Rate limiting and security hardening.

== License ==
GPLv2 or later
