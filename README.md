# Fast Site Search Chatbot

A lightweight, privacy-friendly **search chatbot** for WordPress.  
Indexes your posts & pages into a **private JSON dataset** and lets visitors instantly search in a modern floating chat UI — **no external APIs, no AI costs**.

![Demo Screenshot](screenshot.png) <!-- optional screenshot -->

## Features
- **Zero AI/API costs** – runs entirely on your server + visitor’s browser.
- **Modern floating chat widget** with open/close button.
- **Instant search** with fuzzy matching using [MiniSearch](https://github.com/lucaong/minisearch) (inlined).
- **Smart daily cron**: rebuilds dataset only if content changed; rotates filename daily for security.
- **Exclude rules**: pick categories, tags, or specific posts/pages via UI.
- **Responsive & mobile-friendly.**
- **Hardened security**:
  - Dataset stored in uploads under randomized filename.
  - Nonce + same-origin checks.
  - Server & client rate-limiting to prevent abuse.
- **Uninstall cleanup**: removes options and dataset files.

## Requirements
- WordPress 5.0+ / PHP 7.4+
- Cron enabled (for daily rebuild)
- Works on Apache, Nginx, LiteSpeed/OpenLiteSpeed.

## Installation
1. Upload the plugin folder `FastSiteSearchChatbot` to `/wp-content/plugins/`.
2. Activate **Fast Site Search Chatbot** from WP Admin → Plugins.
3. Go to **Fast Chatbot** admin page:
   - Choose widget position (left/right).
   - Select categories/tags/posts to exclude (optional).
   - Click **Build Dataset** (manual build).
4. Done! The chatbot button appears on the front-end.

## How It Works
- On build, the plugin:
  - Crawls all published posts & pages (excluding your chosen rules).
  - Generates a cleaned JSON dataset (titles + excerpts).
- On visitor page load:
  - Loads dataset once.
  - Queries happen **in-browser** — no server load per query.
  - Shows top 3–5 matching articles as clickable links.

## Security
- Dataset file is private:
  - Randomized filename rotated daily.
  - Stored under `/uploads/fssc-dataset/` with `.htaccess` deny (for Apache).
  - Nonce-protected REST endpoint with same-origin & IP rate-limiting.
- Client & server-side request throttling to prevent abuse.

## Roadmap / Ideas
- Option to show snippet excerpts or only titles.
- Custom trigger keywords / hotkey open.
- Optional WP-CLI build command.

## License
GPLv2 or later – see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

**Author:** [Finland93](https://github.com/Finland93)  
GitHub: [https://github.com/Finland93/FastSiteSearchChatbot](https://github.com/Finland93/FastSiteSearchChatbot)

