# File Hub — Project Overview

**File Hub** is a small, self-hosted file sharing application for local networks. Users on the LAN open a single page, drag files in, and download files that others have shared. Uploads go through one of two drop zones — **private** (restricted to a configurable set of IPs) or **public** (open to everyone). Access is controlled entirely by **client IP address**, managed from a password-protected admin panel.

It is deliberately dependency-free: plain PHP, plain JavaScript, plain CSS. No framework, no database, no package manager. State lives in JSON files on disk.

- **Language / runtime:** PHP 8.0+ (uses `match`, `str_contains`, arrow functions, typed returns)
- **Web server:** IIS with FastCGI on Windows (see [`web.config`](../web.config)); works on any PHP-capable server if the upload limits are set equivalently
- **Storage:** flat files — uploaded blobs in `uploads/`, metadata and rules as JSON in `data/`
- **License:** GPL v3

---

## Architecture at a glance

```
Browser (index.php)                      Browser (admin.php)
   │  drag & drop                            │  fetch() FormData
   │  XHR POST                               │
   ▼                                         ▼
upload.php ──┐                          api.php  (admin-only, JSON)
             │                               │
download.php ┤────►  config.php  ◄───────────┘
             │      (IP rules, settings,
index.php ───┘       metadata, logging)
                          │
                          ▼
                    data/*.json  +  uploads/
```

Every entry point starts with `require 'config.php'`, which opens the session, defines paths, and provides the shared helper layer. There is no router and no shared template engine — each PHP file renders or responds on its own.

---

## Files

| File | Role |
|---|---|
| [`config.php`](../config.php) | The core. Path constants, admin password, settings load/save, client-IP detection, IP rule matching, access decisions, metadata and log persistence, formatting helpers. |
| [`index.php`](../index.php) | The public hub. Renders the two drop zones (private and public) and the file list, filtered to what the visiting IP is allowed to *see*. |
| [`upload.php`](../upload.php) | Multipart upload endpoint. Validates access and size, stores the blob under a random ID, writes metadata, and — for private uploads only — applies default per-file rules. Returns JSON. |
| [`download.php`](../download.php) | Streams a file back with HTTP Range support, after checking per-file access. |
| [`api.php`](../api.php) | Admin-only JSON API. All rule, settings, deletion, and log operations. |
| [`admin.php`](../admin.php) | Login form plus the four-tab admin panel (Global Rules, Per-File Rules, File Manager, Access Logs). |
| [`assets/js/hub.js`](../assets/js/hub.js) | Drag & drop for both zones, XHR upload with a progress bar, toast notifications, optimistic file-card insertion. |
| [`assets/js/admin.js`](../assets/js/admin.js) | Tab switching and every admin API call, each with optimistic DOM updates. |
| [`assets/css/style.css`](../assets/css/style.css), [`assets/css/admin.css`](../assets/css/admin.css) | Dark-theme styling for the hub and the admin panel. |
| [`web.config`](../web.config) | IIS: FastCGI handler, `index.php` as default document, directory browsing off, request size limit raised. |

`uploads/` and `data/` are git-ignored and created on first run by `config.php`.

---

## Data model

All state is JSON under `data/`.

**`filesmeta.json`** — an array, newest first (`array_unshift` on upload):

```json
[{
  "id": "9f3a…",           // 16 hex chars, from random_bytes(8) — also the public handle
  "name": "video.mp4",     // original filename, shown to users
  "saveName": "9f3a….mp4", // on-disk name in uploads/
  "size": 104857600,
  "ext": "mp4",
  "date": "2026-02-24 10:55",
  "uploader": "192.168.2.55" // literal "public" for public uploads
}]
```

The original filename is never used on disk — the random ID is. That removes path-traversal and collision concerns from the storage layer.

**`iprules.json`** — global mode plus per-file rules:

```json
{
  "mode": "blacklist",
  "global": ["192.168.1.50", "10.0.0.0/8"],
  "files": {
    "9f3a…": {
      "allowed":    ["::1", "192.168.2.55"],
      "denied":     [],
      "visible_to": ["::1", "192.168.2.55"]
    }
  }
}
```

A **public** upload gets no entry in `files` at all — that absence is what makes it public (see below).

**`settings.json`** — everything the admin panel configures:

```json
{
  "maxFileSize": 4294967296,
  "defaultIPs": ["::1", "192.168.2.100", "192.168.2.101"]
}
```

**`accesslog.json`** — append-only ring buffer, trimmed to the last 1000 entries. Each row: timestamp, IP, action (`upload` / `download`), filename, and whether it was granted.

Writes are plain `file_put_contents` with no locking, which is fine for the LAN-scale, low-concurrency use this targets.

---

## Access control

This is the heart of the project, and it has **two independent layers** that are easy to confuse:

### 1. Visibility — *can this IP see the file listed?*

`checkFileVisibility()` reads the file's `visible_to` list. Empty list means visible to everyone; otherwise only matching IPs see the card in the hub index. This is purely cosmetic filtering of `index.php` — it does **not** protect the file. A visitor who knows the ID could still hit `download.php`; that request is stopped (or not) by the second layer.

### 2. Access — *can this IP download the file?*

`checkIPAccess($ip, $fileId)` decides, in order:

1. **Per-file `denied`** — any match denies immediately, always wins.
2. **Per-file `allowed`** — if the list is non-empty it becomes a *whitelist for that file*: match to be allowed, otherwise denied. If empty, fall through.
3. **Global rules** — in `whitelist` mode, localhost is always allowed, then listed IPs are allowed and everyone else denied (with the exception that an *empty* whitelist allows everyone, so an admin can't lock themselves out). In `blacklist` mode (the default) a listed IP is denied and everyone else allowed.

Every decision carries a human-readable `reason`, which is what the 403 page and the hub's "Access denied" banner display.

### Rule syntax

`ipMatchesRule()` accepts three forms:

| Form | Example | Notes |
|---|---|---|
| Exact | `192.168.1.10` | string equality |
| CIDR | `192.168.1.0/24` | IPv4 only — uses `ip2long` and a bitmask |
| Wildcard | `192.168.1.*` | `*` expands to `[0-9]{1,3}` in a regex |

Only exact matching works for IPv6; CIDR and wildcard forms are IPv4-only.

### Client IP detection

`getClientIP()` walks `HTTP_CF_CONNECTING_IP` → `HTTP_X_FORWARDED_FOR` → `HTTP_X_REAL_IP` → `REMOTE_ADDR`, taking the first valid IP. On a LAN with no reverse proxy in front, only `REMOTE_ADDR` is trustworthy — the three header sources are client-supplied and spoofable, so the IP rules are a convenience mechanism rather than a security boundary if the app is ever exposed beyond the LAN.

### Private vs. public uploads

The hub offers two drop zones, and the difference between them is entirely a matter of **whether a per-file rules entry gets written**:

| | Private zone (☁️) | Public zone (🌍) |
|---|---|---|
| Per-file rules entry | written | **none** |
| Who sees it in the hub | default IPs + uploader | everyone |
| Who can download it | default IPs + uploader | anyone the global rules allow |
| `uploader` in metadata | the real IP | the literal string `public` |
| Access log action | `upload` | `upload (public)` |

`hub.js` sends `public=1` in the FormData for the second zone. When `upload.php` sees it, it skips the `saveIPRules()` call entirely. No new rule syntax was needed: `checkFileVisibility()` already returns `true` for a file with no entry, and `checkIPAccess()` already falls through to the global rules. A public file is therefore identical to one whose IP lists an admin has manually emptied — the state the admin panel labels "— visible to all —".

Note that public does **not** mean unconditional: the global rules still apply. In whitelist mode, a visitor who isn't whitelisted is denied a public file like anything else.

### Default IPs for private uploads

Each private upload is restricted to the **default IP list plus the uploader's own IP**, for both `allowed` and `visible_to`.

That list is managed in the admin panel (Global Rules → *Default IPs for New Uploads*) and stored as `defaultIPs` in `settings.json`. `getDefaultIPs()` always merges `::1` back into whatever is stored, and `api.php` refuses to remove it, so the admin cannot lose reach over files uploaded by others — the ✕ is omitted from that tag in the UI for the same reason. `DEFAULT_ACCESS_IPS` in `config.php` is only the fallback for a fresh install with no settings file.

The list accepts the same exact / CIDR / wildcard forms as every other rule field, since it feeds the same `ipMatchesRule()`.

Changing it affects **new uploads only**. Files already in the hub keep the rules they were given at upload time and are still edited per-file in the admin panel.

---

## Request flows

**Upload.** `hub.js` sends one `XMLHttpRequest` per file to `upload.php`, tracking `upload.onprogress` for the progress bar. Both zones share one `wireDropZone()` binding and one progress bar; only the `public` flag differs. The server checks global access, method, `$_FILES` error state, and size against `getMaxFileSize()`; generates the ID; `move_uploaded_file()`s the blob; prepends metadata; writes the default rules (private only); logs the event; and returns the card fields as JSON so the client can insert the new card without a reload.

`hub.js` also cancels the default `dragover`/`drop` on `document`, so a file dropped just outside a zone is ignored instead of making the browser navigate away to it.

**Download.** `download.php` looks up the entry by ID, logs the attempt (granted or not), then enforces access. On success it clears all output buffers, disables the time limit, sets `ignore_user_abort`, and streams the file in 1 MB chunks with `flush()` between them — so a multi-gigabyte file never has to fit in memory. `Range` requests are parsed (including suffix ranges like `bytes=-500`) and answered with `206 Partial Content` and a correct `Content-Range`; an unsatisfiable range gets `416`. `Accept-Ranges: bytes` means browsers and download managers can resume and parallelize. Filenames go out both percent-encoded and as RFC 5987 `filename*=UTF-8''…` so non-ASCII names survive.

**Admin.** `admin.php` compares the posted password against the `ADMIN_PASSWORD` constant and sets `$_SESSION['hub_admin']`. Every `api.php` action re-checks `isAdmin()` before doing anything. `admin.js` posts `FormData` with an `action` field; each handler mutates the relevant JSON file and returns `{success: true}` or `{success: false, error}`. The UI updates optimistically on success rather than re-rendering.

`api.php` actions: `set_mode`, `add_global`, `remove_global`, `add_file_rule`, `remove_file_rule`, `add_visible_to`, `remove_visible_to`, `add_default_ip`, `remove_default_ip`, `delete_file`, `get_logs`, `get_settings`, `set_max_file_size`.

**Assets.** `index.php` and `admin.php` append `?v=<filemtime>` to their CSS and JS tags, so an edited asset gets a new URL and browsers can't serve a stale copy.

---

## Size limits

The maximum upload size is set from the admin panel and stored in `settings.json`, but `getMaxFileSize()` clamps it to **1 MB – 5 GB** regardless of what is saved. The fallback default is 4 GB.

That figure only governs the application's own check. Three other limits sit above it and will reject an upload first if they are lower:

- `web.config` → `requestFiltering/requestLimits/maxAllowedContentLength` (currently ~4 GB)
- `web.config` → `system.web/httpRuntime/maxRequestLength` (in KB) and `executionTimeout`
- `php.ini` → `upload_max_filesize`, `post_max_size`, `max_execution_time`, `max_input_time`, `memory_limit`

Raising the admin-panel limit alone is not enough; the server-level values have to move with it, and IIS needs an `iisreset` afterwards.

---

## Deployment notes

Create an IIS site pointing at the project root with the FastCGI handler configured (`web.config` expects `C:\php\php-cgi.exe` — adjust the path to the actual PHP install). Grant `IIS_IUSRS` **Modify** on `uploads/` and `data/`; without it the app cannot create its state files. Then browse to the site; `uploads/` and `data/` are created automatically on first request.

Other devices reach the hub at `http://<server-lan-ip>:<port>/`.

---

## Things to be aware of

These are properties of the current design rather than a to-do list, but they matter when changing or deploying the project:

- **The admin password is a plaintext constant** in `config.php` (`ThisIs-159753`), committed to the repository, compared with `===`. Change it before any real use, and treat the repo as containing a published credential.
- **There is no CSRF protection** on `api.php` or the admin login. A logged-in admin visiting a hostile page could have rules changed or files deleted.
- **Visibility is not access control.** See the two-layer explanation above — `visible_to` only hides cards from the index.
- **IP headers are spoofable** unless a trusted proxy sets them. On a bare LAN, `REMOTE_ADDR` is the only reliable value.
- **Uploads are unauthenticated.** Anyone whose IP passes the global check can upload; there is no user account system at all. That includes the public zone, so any such visitor can publish a file to everyone on the network without an admin approving it.
- **No file-type or content validation.** Any extension is accepted. `uploads/` sits inside the web root, so ensure the server never executes files from it.
- **JSON writes are unlocked**, so concurrent uploads can in principle interleave and lose a metadata entry.
- **`data/` and `uploads/` are inside the web root.** Directory browsing is off in `web.config`, but a request for `data/iprules.json` is served as a static file — moving these outside the site root, or blocking them with a request-filtering rule, is the safer arrangement.

The README's own closing note applies: this is built for **local / LAN use**. Exposing it to the internet means adding HTTPS, a real credential, and admin restrictions at minimum.
