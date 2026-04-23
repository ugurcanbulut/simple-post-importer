=== Simple Post Importer ===
Contributors: ugurcanbulut
Tags: import, migration, rest-api, posts, media
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan a remote WordPress site via its public REST API and import selected posts (with featured + inline images, categories, tags, and authors) into your site.

== Description ==

Simple Post Importer lets you point at another WordPress site's URL, scan its public REST API for posts, preview them in a searchable table with thumbnails, and import the ones you want into your own site. The importer sideloads featured images and inline `<img>` tags into your local media library, rewrites URLs, creates or matches categories and tags by slug, and creates or matches authors as WP users.

= Features =

* One-URL async scan with live progress.
* Admin UI with React (powered by `@wordpress/scripts`).
* Checkbox-based post selection, detail modal for full content preview.
* Featured and inline image sideloading with same-origin guard.
* Category and tag import (match-or-create by slug).
* Author import (match-or-create WP user by remote slug).
* Per-session "Clear Imported" to roll back an import cleanly.
* `wp spi` WP-CLI commands for scan, import, and clear.

= Out of scope (v1) =

* CSS `background-image` URL rewriting and `<picture>/<source>` elements are not sideloaded.
* Cross-origin images (hosted on external CDNs) are left untouched.
* Custom post types other than `post` and taxonomies other than `category` and `post_tag`.

== Installation ==

**Quick install (from GitHub release zip or a clone):**

1. Upload the `simple-post-importer` folder to `/wp-content/plugins/` (or install the zip via **Plugins → Add New → Upload Plugin**).
2. Activate **Simple Post Importer** through the Plugins screen.
3. Go to **Tools → Simple Post Importer** to start.

Pre-built assets live under `/build/` and the plugin ships with a PSR-4 fallback autoloader, so no build step is required to run it.

**Developing the plugin:**

```
composer install   # optional — a fallback autoloader runs without it
npm install
npm run build      # or: npm start (watches and rebuilds)
```

== Changelog ==

= 0.1.0 =
* Initial release.
