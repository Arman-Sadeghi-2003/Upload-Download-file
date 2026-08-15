# File Hub — Project Overview

**File Hub** is a small, self-hosted file sharing application for local networks. Users on the LAN open one page with two views — **Upload** and **Files** — drag files in, and download what others have shared. Uploads go through one of two drop zones, **private** (restricted to a configurable set of IPs) or **public** (open to everyone). Access is controlled entirely by **client IP address**, managed from a password-protected admin panel.

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
| [`config.php`](../config.php) | The core. Path constants, admin password, settings load/save, client-IP detection, IP rule matching, access decisions, metadata and log persistence, the `withLock()` critical section, and the formatting helpers — `formatBytes()`, `fileIcon()` and `fileCategory()`. |
| [`index.php`](../index.php) | The public hub. Two tabbed views — Upload (the private and public drop zones) and Files (the list, filtered to what the visiting IP is allowed to *see*). |
| [`upload.php`](../upload.php) | Multipart upload endpoint. Validates access and size, stores the blob under a random ID, writes metadata, and — for private uploads only — applies default per-file rules. Returns JSON. |
| [`download.php`](../download.php) | Streams a file back with HTTP Range support, after checking per-file access. |
| [`api.php`](../api.php) | Admin-only JSON API. All rule, settings, deletion, and log operations. |
| [`admin.php`](../admin.php) | Login form plus the four-tab admin panel (Global Rules, Per-File Rules, File Manager, Access Logs). |
| [`assets/js/hub.js`](../assets/js/hub.js) | The whole hub interface: Upload/Files view switching, drag & drop plus the full-window drop overlay, the serialized upload queue with per-file progress, cancel and retry, optimistic file-card insertion, and the Files view's type filter and pagination. |
| [`assets/js/admin.js`](../assets/js/admin.js) | Tab switching and every admin API call, each with optimistic DOM updates; the File Manager's uploader-IP filter and bulk selection; the Access Logs filters. |
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

**`accesslog.json`** — append-only ring buffer, trimmed to the last 1000 entries. Each row: timestamp, IP, action (`upload`, `upload (public)`, `download`, `delete (bulk)`), filename, and whether it was granted. `get_logs` adds a derived `type` to each row on the way out; it is not stored.

Every read-modify-write of these files runs inside `withLock()` (see [`upload-queue-implementation.md`](upload-queue-implementation.md) §2.6), a `flock()` critical section over a single `data/.lock` file. The lock spans the read *and* the write, because that is where the race is — `LOCK_EX` on the write alone would not close it.

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

`hub.js` sends `public=1` in the FormData for files that came from the public zone — or from the public half of the drop overlay; the flag is carried on the job, so a mixed batch keeps each file's choice. When `upload.php` sees it, it skips the `saveIPRules()` call entirely. No new rule syntax was needed: `checkFileVisibility()` already returns `true` for a file with no entry, and `checkIPAccess()` already falls through to the global rules. A public file is therefore identical to one whose IP lists an admin has manually emptied — the state the admin panel labels "— visible to all —".

Note that public does **not** mean unconditional: the global rules still apply. In whitelist mode, a visitor who isn't whitelisted is denied a public file like anything else.

### Default IPs for private uploads

Each private upload is restricted to the **default IP list plus the uploader's own IP**, for both `allowed` and `visible_to`.

That list is managed in the admin panel (Global Rules → *Default IPs for New Uploads*) and stored as `defaultIPs` in `settings.json`. `getDefaultIPs()` always merges `::1` back into whatever is stored, and `api.php` refuses to remove it, so the admin cannot lose reach over files uploaded by others — the ✕ is omitted from that tag in the UI for the same reason. `DEFAULT_ACCESS_IPS` in `config.php` is only the fallback for a fresh install with no settings file.

The list accepts the same exact / CIDR / wildcard forms as every other rule field, since it feeds the same `ipMatchesRule()`.

Changing it affects **new uploads only**. Files already in the hub keep the rules they were given at upload time and are still edited per-file in the admin panel.

---

## Request flows

**Upload.** `hub.js` turns each selected file into a *job* and runs the jobs through a scheduler — one at a time by default — instead of firing a request per file at once. [`upload-queue-implementation.md`](upload-queue-implementation.md) covers why, and the queue's own UI. Each job sends one `XMLHttpRequest` to `upload.php` carrying the `public` flag its zone implies, tracking `upload.onprogress`. The server checks global access, method, `$_FILES` error state, and size against `getMaxFileSize()`; generates the ID; `move_uploaded_file()`s the blob; then, inside a single `withLock()`, prepends the metadata, writes the default rules (private only) and logs the event. It returns the card fields as JSON — including the file's `type` — so the client can insert the new card without a reload.

**Download.** `download.php` looks up the entry by ID, logs the attempt (granted or not), then enforces access. On success it clears all output buffers, disables the time limit, sets `ignore_user_abort`, and streams the file in 1 MB chunks with `flush()` between them — so a multi-gigabyte file never has to fit in memory. `Range` requests are parsed (including suffix ranges like `bytes=-500`) and answered with `206 Partial Content` and a correct `Content-Range`; an unsatisfiable range gets `416`. `Accept-Ranges: bytes` means browsers and download managers can resume and parallelize. Filenames go out both percent-encoded and as RFC 5987 `filename*=UTF-8''…` so non-ASCII names survive.

**Admin.** `admin.php` compares the posted password against the `ADMIN_PASSWORD` constant and sets `$_SESSION['hub_admin']`. Every `api.php` action re-checks `isAdmin()` before doing anything. `admin.js` posts `FormData` with an `action` field; each handler mutates the relevant JSON file and returns `{success: true}` or `{success: false, error}`. The UI updates optimistically on success rather than re-rendering.

`api.php` actions: `set_mode`, `add_global`, `remove_global`, `add_file_rule`, `remove_file_rule`, `add_visible_to`, `remove_visible_to`, `add_default_ip`, `remove_default_ip`, `delete_file`, `delete_files`, `get_logs`, `get_settings`, `set_max_file_size`. Every one that reads-modifies-writes runs inside `withLock()`; `get_logs` and `get_settings` only read.

**Assets.** `index.php` and `admin.php` append `?v=<filemtime>` to their CSS and JS tags, so an edited asset gets a new URL and browsers can't serve a stale copy.

---

## The hub interface

One page, two views, and one rule that shapes all of it: **nothing may cost a page load.** An upload of several gigabytes can be in flight at any moment, and a navigation would abort it. That is why the views are tabs rather than pages, and why filtering and paging happen over cards already in the DOM rather than through `?page=2`.

### Views

Upload and Files are two panels toggled in `hub.js` and reflected in the URL as `#upload` / `#files`, so a refresh lands where the reader left off. The queue panel is rendered *outside* both panels: an upload started under Upload keeps running and stays on screen while you browse Files, and the count in the Files tab rises as each one lands. Files is the resting view, except on an empty hub where it has nothing to show and Upload opens instead. A denied IP gets no Upload tab at all — no tab, no queue, no overlay — and a stale `#upload` bookmark falls back to Files.

### Dropping files

`hub.js` cancels the default `dragover`/`drop` on `document`, so a file dropped outside a zone is ignored instead of making the browser navigate away to it. Because the drop zones sit behind a tab, it also renders a full-window overlay on `dragenter`, split into a private and a public half: a file can be dropped from either view, with the zone chosen by which half it is released over. The overlay counts `dragenter`/`dragleave` depth rather than holding a boolean — both fire for every element the cursor crosses, so a flag would flicker as the pointer moves over children — and it ignores drags carrying no `Files`, such as selected text.

### File-type filter

The Files view narrows to a category: Images, Video, Audio, Documents, Archives, Code, Executables, Other. `fileCategory()` in `config.php` is the **single definition** of those buckets — `index.php` stamps each card with `data-type`, `upload.php` returns a `type` with every new file so an inserted card filters like a rendered one, and `get_logs` tags each log row the same way. No JavaScript repeats the extension lists, so the hub filter and the admin log filter cannot drift apart.

### Pagination

The Files view shows `PAGE_SIZE` (10) cards at a time, sliced from what the filter left. Changing the filter returns to page 1: page 4 of thirty files is not a page of the four that survive a filter. The number strip collapses to `1 … 9 10 11 … 20` so the control keeps its width however many files accumulate, and it is omitted entirely while everything fits on one page. A card arriving from an upload re-runs the pager but holds the current page — a batch finishing in the background should not move the view out from under someone browsing.

The trade is that a large hub ships every card up front. At LAN scale that is a few hundred KB; the point where it stops being worth it is also the point where this view wants a search box more than a pager.

---

## The admin panel

Four tabs — Global Rules, Per-File Rules, File Manager, Access Logs — over the same `api.php`.

### File Manager

Beyond the per-file 🗑, the tab filters the list by **uploader IP** and deletes a whole selection at once. The filter takes the same exact / CIDR / wildcard forms as every other IP field (a bare partial like `192.168.1` also matches as a substring, and `public` matches anonymous uploads). Ticked files go to `delete_files` as a JSON array of IDs, which removes blobs, metadata and per-file rules inside one `withLock()` and returns the IDs it actually removed, so a stale row reports "already gone" instead of failing the batch. IDs are validated against the 16-hex-char shape `random_bytes(8)` produces, and the unlink target still comes from the stored `saveName`, never from the request.

Changing the filter **clears the selection**. Without that, files ticked under one IP would ride along into a delete run under another, removing files the admin can no longer see.

### Access Logs

`get_logs` returns the whole ring buffer in one response, so the tab's three filters — uploader IP, action, and file-type category — run client-side over the fetched rows and apply as you type. The IP box shares its matcher with the File Manager filter. The action list is built from the data rather than hardcoded, so a new action string passed to `logAccess()` appears in the dropdown on its own. Filters survive a Refresh, including the chosen action when it still exists in the new data.

---

## Size limits

The maximum upload size is set from the admin panel and stored in `settings.json`, but `getMaxFileSize()` clamps it to **1 MB – 5 GB** regardless of what is saved. The fallback default is 4 GB.

That figure only governs the application's own check. Three other limits sit above it and will reject an upload first if they are lower:

- `web.config` → `requestFiltering/requestLimits/maxAllowedContentLength` (currently ~4 GB)
- `web.config` → `system.web/httpRuntime/maxRequestLength` (in KB) and `executionTimeout`
- `php.ini` → `upload_max_filesize`, `post_max_size`, `max_execution_time`, `max_input_time`, `memory_limit`

Raising the admin-panel limit alone is not enough; the server-level values have to move with it, and IIS needs an `iisreset` afterwards.

The hub limit also reaches the browser as `window.HUB_MAX_SIZE`, so an oversized file is refused at the moment it is queued rather than after being pushed across the network. The server-level ceilings cannot be checked that way: when one of them rejects an upload, IIS answers **413** before PHP runs at all, and the queue reports that case separately because the fix lies in `web.config` or `php.ini` rather than the admin panel.

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
- **Deletion is immediate and unrecoverable.** There is no trash or undo — the File Manager's bulk delete removes the blobs, the metadata and the per-file rules in one commit. It logs a single `delete (bulk)` audit row; the single-file 🗑 button logs nothing.
- **`data/` and `uploads/` are inside the web root.** Directory browsing is off in `web.config`, but a request for `data/iprules.json` is served as a static file — moving these outside the site root, or blocking them with a request-filtering rule, is the safer arrangement.

The README's own closing note applies: this is built for **local / LAN use**. Exposing it to the internet means adding HTTPS, a real credential, and admin restrictions at minimum.
