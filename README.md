# Simple Post Importer

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue)
![Status](https://img.shields.io/badge/status-v0.1.0-orange)

A WordPress plugin that imports posts from a remote WordPress site into the local one. Two modes: **Pull** (scan a remote site's public REST API and import selected posts), and **Push** (ship posts from one install of this plugin to another over HTTPS with a token).

Built with modern PHP (8.0+, strict types, PSR-4) and React via `@wordpress/scripts`. Background processing runs on WP-Cron so imports continue even if you close the browser.

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Pull: scan & import a remote site](#pull-scan--import-a-remote-site)
  - [Push: send local posts to another site](#push-send-local-posts-to-another-site)
  - [Tokens](#tokens)
  - [Settings](#settings)
- [WP-CLI](#wp-cli)
- [REST API](#rest-api)
- [How it works](#how-it-works)
- [Development](#development)
- [Database schema](#database-schema)
- [Out of scope (v1)](#out-of-scope-v1)
- [License](#license)

---

## Features

**Pull import**
- One-URL scan of any public WordPress site via `/wp-json/wp/v2/posts` with `_embed`.
- React admin table with thumbnail, author, categories, tags, publish date, and status.
- Full post preview modal with server-sanitized HTML.
- Featured image sideloading.
- Inline `<img>` sideloading with same-origin guard, `srcset` stripping, and `wp-image-{id}` class injection so WordPress regenerates responsive sizes.
- Category and tag match-or-create by slug.
- Three-strategy author resolution cascade: `?include=…` batched → `/users/:id` direct → `?author=N` redirect to author archive.
- Configurable fallback: when the remote can't give up a username, imports are assigned to a user of your choice.
- Per-session "Clear Imported" rolls back all posts and attachments from one import without touching other sessions.

**Push export (server-to-server)**
- Target site generates a token; source site enters it to connect.
- Bearer-auth handshake validates the target before creating a session.
- Pick posts with live filter and search, or queue all matching a filter in one click.
- Idempotent on `(source_site_url, source_post_id)` — re-pushes update rather than duplicate.
- Sends author info (login/email/display name) so receivers can match or create local users reliably.

**Background processing**
- Scan, import, and push each run as WP-Cron events with time budgets; they reschedule themselves until complete.
- `spawn_cron()` nudges cron on creation so work starts immediately instead of waiting for the next visitor.
- Frontend polls session status for live progress regardless of which tab you're on.

**Other**
- `wp spi *` commands with progress bars for scan, import, clear, and session management.
- Toast notifications instead of static banners.
- Uninstall cleans up all five tables and the settings option.

## Requirements

| | |
|--|--|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| MySQL / MariaDB | 5.7+ / 10.2+ |
| Browser | Any evergreen (uses ES2022 bundle) |

## Installation

**From a zip or a clone:**

1. Download the repo as a zip, or `git clone` into `wp-content/plugins/`.
2. Activate **Simple Post Importer** from the Plugins screen.
3. Go to **Tools → Simple Post Importer**.

No build step, Composer install, or Node install is required — the repo ships with built JS/CSS in `build/` and a PSR-4 fallback autoloader covers the absence of `vendor/`.

## Usage

### Pull: scan & import a remote site

1. **Pull Import → New Scan.** Enter a site URL (e.g. `https://example.com`) and click **Start Scan**. You'll be redirected to **Sessions** where the status updates live.
2. When the scan completes, click **Open** on the session to see the posts table.
3. Tick the posts you want, then click **Import Selected**. Featured images, inline content images, categories, tags, and authors all flow in.
4. Need to undo? **Clear Imported** on the session page deletes just the posts and attachments this session created — other sessions and any manually-created local content are untouched.

### Push: send local posts to another site

Needs the plugin installed on **both** sites.

1. On the **target** site (the one receiving content): go to **Tokens → + Generate Token**, name it, and copy the `spi_…` value. You only see it once.
2. On the **source** site (the one sending content): go to **Push Export → + New Push**. Paste the target URL and token, click **Connect**. A green banner confirms the handshake.
3. You're now in the session detail. Use the post-type dropdown, search box, and checkboxes to queue posts. Or click **Queue all (N)** to add every post matching the current filter.
4. Click **Start Push**. Progress updates live and per-post status appears in the queued list (with direct links to the new posts on the target).

Re-running a push with the same source post simply updates the target post — no duplicates.

### Tokens

- List of every token with name, `preview…` of the first 8 chars, created-at, and last-used-at.
- **Revoke** disables a token immediately; any site still using it gets a 401 on the next request.
- Tokens are stored as SHA-256 hashes; the plaintext never touches the database.

### Settings

- **Default author:** the local user that receives posts whose remote author couldn't be resolved. If left on "Auto", the first administrator is used.

## WP-CLI

All commands are flat under `wp spi`:

```sh
wp spi scan <url>                          # Scan a remote site synchronously
wp spi sessions                            # List all scan sessions
wp spi posts <session_id>                  # List posts in a session
wp spi import <session_id> --all           # Import every selected post
wp spi import <session_id> --ids=3,4,5     # Import specific rows
wp spi clear <session_id> [--yes]          # Remove posts/attachments from this session
wp spi delete <session_id> [--with-imports]
```

`--format=json|csv|yaml` works on listing commands.

## REST API

Namespace: `simple-post-importer/v1`. All endpoints require the `manage_options` capability (WP cookie + nonce), **except** `/push/handshake` and `/push/batch` which use Bearer tokens.

**Pull**
```
POST   /scans                                # create session
POST   /scans/:id/run                        # process one scan page
GET    /sessions                             # list
GET    /sessions/:id                         # detail + counts
DELETE /sessions/:id                         # delete (cascades)
GET    /sessions/:id/posts                   # table rows
GET    /sessions/:id/posts/:postId           # detail + sanitized content
PATCH  /sessions/:id/posts/:postId           # toggle selected
POST   /sessions/:id/posts/bulk-select
POST   /sessions/:id/import                  # start import (schedules cron)
POST   /sessions/:id/import/run              # run one import chunk
DELETE /sessions/:id/imports                 # per-session clear
```

**Push sender (source-side)**
```
GET    /push-candidates                      # local posts available to push
GET    /push-sessions
POST   /push-sessions                        # create + handshake in one call
GET    /push-sessions/:id
DELETE /push-sessions/:id
GET    /push-sessions/:id/items
POST   /push-sessions/:id/items              # queue posts (by ids or all_posts=true)
POST   /push-sessions/:id/start              # schedule cron
POST   /push-sessions/:id/run                # run one push chunk
```

**Push receiver (target-side, Bearer auth)**
```
POST   /push/handshake                       # site info
POST   /push/batch                           # receive a batch of posts
```

**Tokens / settings**
```
GET    /tokens
POST   /tokens                               # returns plaintext exactly once
PUT    /tokens/:id                           # revoke
DELETE /tokens/:id                           # delete
GET    /settings
POST   /settings
```

## How it works

```
┌── Pull ────────────────────────────────────────────────────┐
│  Browser ──POST /scans─→  ScanController ─→ BackgroundScanner │
│                                                 │          │
│                                                 ▼          │
│                                             ScanRunner ──→ RemoteClient ──→ remote /wp-json
│                                                 │          │
│                                                 ▼          │
│                                        spi_remote_posts    │
└────────────────────────────────────────────────────────────┘

┌── Push (source ─→ target) ────────────────────────────────┐
│  Source             Target                                 │
│  ─────────────      ──────────────────                     │
│  PushRunner ──HTTP─→ PushReceiverController                │
│    │                    │                                   │
│    │                    ▼                                   │
│    │              TokenManager::verify                      │
│    │                    │                                   │
│    │                    ▼                                   │
│    │              PostDeserializer                          │
│    │                    │                                   │
│    │                    ▼                                   │
│    │               wp_insert_post + media_handle_sideload   │
│    ▼                                                        │
│  spi_push_items (status per post)                           │
└─────────────────────────────────────────────────────────────┘
```

Key design choices:
- **WP-Cron for background work** keeps scans and pushes moving even when the admin tab is closed. `spawn_cron()` triggers loopback on creation so the first chunk runs immediately.
- **Chunked processing** budgeted by time (20s) and by image count (10 sideloads) so every request finishes within PHP `max_execution_time`.
- **Idempotency by DB unique key** — `UNIQUE (session_id, remote_id)` on scans, `UNIQUE (session_id, local_post_id)` on pushes, and post meta `_spi_push_source_id/url` on receivers — so retries and re-runs never duplicate.
- **No external dependencies at runtime**: no Action Scheduler, no Composer packages, no npm deps beyond what `@wordpress/scripts` bundles.

## Development

```sh
git clone git@github.com:ugurcanbulut/simple-post-importer.git
cd simple-post-importer

# optional — a fallback PSR-4 autoloader works without this
composer install

# required only if you're editing the React UI
npm install
npm run build       # one-off
# or:
npm start           # watches and rebuilds on save
```

Plugin folder layout:

```
simple-post-importer/
├── simple-post-importer.php      main plugin file + headers + autoloader
├── uninstall.php                  drops all tables on deletion
├── src/                           namespaced PHP classes (PSR-4)
│   ├── Admin/                     admin page + asset enqueue
│   ├── Database/                  Schema + repositories
│   ├── Rest/                      REST controllers, one per resource
│   ├── Scanner/                   pull: RemoteClient, ScanRunner, BackgroundScanner
│   ├── Importer/                  pull: PostImporter, MediaImporter, ContentRewriter, etc.
│   ├── Push/                      push: TokenManager, TargetClient, PushRunner, etc.
│   ├── Settings/                  options wrapper
│   └── CLI/                       wp spi commands
├── assets/src/                    React source
│   ├── App.jsx
│   ├── components/
│   │   ├── pull/                  scan → session → import flow
│   │   ├── push/                  target → queue → progress flow
│   │   ├── tokens/                token generation + revoke
│   │   └── settings/              default-author picker
│   └── hooks/                     apiFetch wrapper + polling hook
└── build/                         committed output of `npm run build`
```

## Database schema

Five custom tables, created via `dbDelta` on activation and versioned through `spi_db_version`:

| Table | Purpose |
|-------|---------|
| `spi_sessions` | Scan session meta (source URL, scan + import status, progress counters). |
| `spi_remote_posts` | Per-post scan results; unique on `(session_id, remote_id)` for idempotent chunked scans. |
| `spi_tokens` | Target-side API tokens (SHA-256 hashed, with preview + revoked flag). |
| `spi_push_sessions` | Source-side push session meta (target URL, status, progress). |
| `spi_push_items` | Per-post push state; unique on `(session_id, local_post_id)`. |

## Out of scope (v1)

- CSS `background-image` URL rewriting and `<picture>`/`<source>` sideloading.
- Cross-origin images (external CDNs) — left untouched on both pull and push.
- Custom post meta, comments, menus, widgets, and WordPress options — not transferred.
- Custom taxonomies beyond `category` and `post_tag`.
- Authenticated pulls (e.g. scraping private posts with application passwords).

## License

GPL-2.0-or-later — see the plugin header for the full notice.
