# Fast Site Search Chatbot – WordPress Instant Search & Chatbot Plugin

A lightweight, **SEO-friendly WordPress search plugin** and **privacy-focused chatbot**.  
Fast Site Search Chatbot indexes your WordPress posts and pages into a **private JSON search index** and displays a **modern floating chat UI**. It provides **instant on-site search** without relying on external APIs or costly AI services.

Optimized for speed, privacy, and search engine visibility, this plugin helps improve **user engagement and site SEO** by making content easier to discover.

---

## Key Features 

- **Instant WordPress search chatbot** with floating UI – improves UX and keeps visitors engaged.
- **No external APIs / No AI costs** – runs entirely on your WordPress server + visitor browser.
- **Improved internal linking for SEO** – provides clickable links to your site content.
- **Inline MiniSearch engine** (fuzzy & prefix matching) for fast, relevant search results.
- **Smart daily cron**: rebuilds dataset only when content changes; rotates filename daily for security.
- **Exclude rules**: choose categories, tags, posts, or pages to exclude from the search index.
- **Responsive & mobile-friendly WordPress search widget.**
- **Customizable floating chat button colors** (WordPress color picker).
- **Granular control**: disable widget on specific pages.
- **Hardened security**:
  - Randomized JSON index filename stored under `/uploads/fssc-dataset/`.
  - Nonce + same-origin validation for REST API.
  - Server & client rate-limiting to prevent abuse.
- **Clean uninstall**: removes all plugin data & files.

---

## Why This Helps Your SEO

- **Better internal search UX** keeps users on your site longer (positive SEO signal).
- **Improved crawlability** – visitors and search engines can easily find deep content.
- **Fast and lightweight** – no render-blocking external JS, improves page speed scores.
- **Privacy-compliant** – no data sent to third parties.

---

## Requirements

- WordPress 5.0+  
- PHP 7.4+  
- Cron enabled (for daily rebuild)  
- Works with Apache, Nginx, LiteSpeed/OpenLiteSpeed servers.

---

## Installation & Setup

1. **Upload plugin** folder `FastSiteSearchChatbot` to `/wp-content/plugins/`.
2. Activate **Fast Site Search Chatbot** from WP Admin → Plugins.
3. In the **Fast Chatbot** admin page:
   - Select widget position (left/right).
   - Pick button colors via color picker.
   - Exclude categories/tags/posts/pages (optional).
   - Disable widget on selected pages (optional).
   - Click **Build Dataset** to index your content.
4. Done! A floating chatbot/search icon appears on the front-end.

---

## How It Works

- On build:
  - Crawls all published posts & pages (respects exclusions).
  - Creates a clean JSON dataset (titles + excerpts).
- On visitor page load:
  - Dataset is loaded once.
  - **Search queries run client-side** — zero extra server load per query.
  - Shows top matching articles as clickable internal links (boosts internal SEO).

---

## Security Best Practices

- Dataset is private and protected:
  - Randomized filename rotated daily.
  - `.htaccess` auto-block for Apache; Nginx users can add:
    ```
    location ~* /wp-content/uploads/fssc-dataset/.*\.json$ { deny all; }
    ```
- Nonce-protected REST endpoint.
- Built-in rate limiting against abuse.

---

## Roadmap / Ideas

- Option to display search result snippets.
- Keyboard shortcut to open chat.
- WP-CLI support for index rebuild.
- Advanced analytics (optional, privacy-friendly).

---

## License & Credits

Licensed under **GPLv2 or later** – see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

Author: [Finland93](https://github.com/Finland93)  
GitHub Repo: [https://github.com/Finland93/FastSiteSearchChatbot](https://github.com/Finland93/FastSiteSearchChatbot)


