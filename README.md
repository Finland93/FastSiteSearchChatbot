# Fast Site Search Chatbot – Chatbot Plugin for WordPress

A lightweight, privacy-friendly **chatbot for WordPress** that lets visitors quickly find content on your site.  
It scans your posts and pages into a private JSON index and provides an **instant floating chat widget** for user questions — no external APIs or AI required.

---

## Features

- **Floating chat widget for WordPress** – modern UI with open/close button.
- **Instant internal search** – uses a local JSON index for speed.
- **No external services** – runs entirely on your server and the visitor’s browser.
- **Daily automatic index** – cron rebuilds dataset if content changes; rotates filename daily.
- **Exclude options** – choose categories, tags, or individual posts/pages to skip.
- **Disable on selected pages** – control where the widget appears.
- **Customizable button colors** – pick with WordPress color picker.
- **Responsive and mobile-friendly** – works on any device.
- **Built-in security** – randomized filename, nonce and same-origin protection, rate limiting.
- **Clean uninstall** – removes options, dataset, cron jobs.

---

## Who Is It For?

Site owners who want:
- A **simple chatbot for WordPress** that answers with relevant links.
- A **fast, privacy-respecting search assistant** without external API costs.
- To make it easier for visitors to discover content through a conversational interface.

---

## Requirements

- WordPress 5.0 or higher  
- PHP 7.4+  
- WP Cron enabled (for daily rebuilds)  
- Compatible with Apache, Nginx, LiteSpeed/OpenLiteSpeed

---

## Installation

1. Upload the plugin folder `FastSiteSearchChatbot` to `/wp-content/plugins/`.
2. Activate **Fast Site Search Chatbot** in WP Admin → Plugins.
3. Open **Fast Chatbot** settings:
   - Select widget position (left/right).
   - Choose button colors.
   - Optionally exclude posts, categories, tags.
   - Optionally disable on selected pages.
   - Click **Build Dataset** to index your content.
4. The chat icon will now appear on your site.

---

## How It Works

- Builds a JSON dataset of your published posts and pages (title + short text).
- The dataset is loaded client-side; visitor search happens **instantly in the browser**.
- The chatbot shows **top matching pages as clickable links** — no new window, opens in the same tab.
- File is private and rotated daily; requests are nonce-protected and rate-limited.

---

## Security Notes

- Dataset is stored under `wp-content/uploads/fssc-dataset/` with randomized filename.
- Apache `.htaccess` auto-denies access.  
- For Nginx/OpenLiteSpeed, add: `location ~* /wp-content/uploads/fssc-dataset/.*.json$ { deny all; }`

---

## License

GPLv2 or later – see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

**Author:** [Finland93](https://github.com/Finland93)  
Repo: [https://github.com/Finland93/FastSiteSearchChatbot](https://github.com/Finland93/FastSiteSearchChatbot)
