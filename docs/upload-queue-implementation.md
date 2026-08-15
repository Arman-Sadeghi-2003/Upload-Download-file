# Upload Queue — Design & Implementation

**Status:** Phase 1 implemented and verified (§5). Phase 2 (chunked / resumable transport) is specified here but not built.

This document covers the multi-file upload queue: why the previous behaviour was wrong, what replaced it, and where it should go next. It assumes familiarity with [`project-overview.md`](project-overview.md).

Two later changes touched the queue without altering its design, and are described here as they now stand rather than as they first landed: the hub was split into Upload and Files views, which moved the queue panel outside both of them, and the file count it updates moved from a heading into the Files tab label. The reason the views are tabs at all is the property §2.1 exists to protect — a navigation would abort every job in flight.

---

## 1. The problem

Multi-file *selection* already worked before this change — both `<input>` elements carry `multiple`, and the drop handler reads the whole `e.dataTransfer.files` list. What was missing was **scheduling**. The old drop handler was:

```js
files.forEach(f => uploadOne(f, isPublic));
```

Every selected file started its own `XMLHttpRequest` in the same tick. Four distinct failures followed from that.

### 1.1 One progress bar, N uploads

`#progressWrap`, `#progBar` and `#progLabel` were module-level singletons. Every in-flight request wrote to the same three elements, so the label flickered between filenames and the percentage moved backwards as different requests reported progress. Worse, the *first* request to finish ran `wrap.style.display = 'none'` — the bar disappeared while the remaining uploads were still running, leaving the page looking idle mid-transfer.

### 1.2 Lost metadata (the real bug)

`upload.php` performed a read-modify-write on `filesmeta.json`:

```php
$meta = loadFilesMeta();      // read
array_unshift($meta, $entry); // modify
saveFilesMeta($meta);         // write
```

…and the same pattern on `iprules.json` and `accesslog.json`. With no lock spanning the read and the write, two requests that overlap both read the same starting array and both write their own version. The later write wins; the earlier entry is gone.

The consequence is not a cosmetic glitch. The blob has already been committed to `uploads/` by `move_uploaded_file()`, so the result is an **orphan**: a file on disk with no metadata row. It is invisible in the hub, absent from the admin File Manager, has no per-file rules entry, and can only be removed by hand from the filesystem.

`project-overview.md` listed this as a theoretical concern. Parallel multi-file upload turned it into the normal case: dropping ten files made ten writers race.

### 1.3 Invisible browser-level queueing

Browsers cap concurrent connections per origin at roughly six. Dropping twenty files started six transfers and left fourteen parked in the browser's internal queue, with no row, no progress, and no way to tell them apart from a hang. Each active request also pins one PHP FastCGI worker and one full-size temporary file on disk for its whole duration.

### 1.4 No cancel, no retry, no per-file error

A failure produced a toast that the next toast overwrote 3.5 seconds later. There was no way to abort a 4 GB upload started by mistake, and no way to retry one that failed without re-selecting the whole batch.

---

## 2. What was built (Phase 1)

### 2.1 Client — job model

`hub.js` now keeps an array of **jobs** rather than firing requests directly:

```js
{
  id,        // local sequence number, used for DOM row ids
  file,      // the File object
  isPublic,  // which drop zone it came from
  status,    // 'queued' | 'uploading' | 'done' | 'error' | 'canceled'
  loaded,    // bytes sent so far
  total,     // file.size
  xhr,       // live request, for abort()
  error      // message shown on the row
}
```

Enqueuing is separated from starting. `enqueue()` validates and appends; `pump()` starts as many `queued` jobs as there are free slots and is re-entered every time a job settles. Because every transition goes through `pump()`, there is exactly one place that decides what runs next.

### 2.2 Serial by default

```js
const MAX_CONCURRENT = 1;
```

One upload at a time, as requested. It is a named constant rather than an inlined `1` because the queue makes the value a genuine tuning knob — see §4.2.

Serialization is also what makes §1.2 survivable in the common single-user case: one writer at a time means no interleaving. It is *not* a fix on its own, which is why the server changed too.

### 2.3 Client — queue panel

The single shared bar is gone. Each job renders a row: icon (☁️ or 🌍, so a mixed batch stays readable), filename, size, a per-job progress bar, a status badge, and one action button whose meaning follows the row's status — ✕ cancels while queued or uploading, ↻ retries after a failure, and ✕ dismisses a file rejected at enqueue, where retrying the same oversized file could only fail again. Above the rows sits an aggregate summary.

The panel sits outside both of the hub's view panels, directly under the tab bar. A job started from the Upload view keeps running when the reader switches to Files, so its progress has to stay on screen from either view; a panel nested inside Upload would hide the evidence of work still going on.

The aggregate is computed **in bytes, not in file count**:

```js
const counted = jobs.filter(j => j.status !== 'canceled' && j.status !== 'error');
const total   = counted.reduce((n, j) => n + j.total, 0);
const loaded  = counted.reduce((n, j) => n + j.loaded, 0);
```

Every file's size is known at enqueue time, so this is exact. "3.1 GB of 8.4 GB" is honest in a way "file 3 of 7" is not, when file 3 happens to be a 6 GB video and the rest are screenshots.

Canceled and failed jobs are excluded from both sums. If they were counted, their unsent bytes would pin the bar below 100% for the rest of the batch — cancelling one file would leave the whole panel looking stuck.

Successful rows remove themselves after a few seconds; failed and canceled ones stay, because they carry the retry button. The panel hides itself once nothing is left. The header button is dual-purpose and relabels itself: **Cancel all** while anything is queued or in flight, **Clear** once everything has settled — otherwise a batch that ended with failures would have no way to dismiss its rows.

### 2.4 Client — reject before transferring

Two checks run at enqueue time, before a single byte moves:

- `file.size === 0` — empty file, rejected immediately.
- `file.size > window.HUB_MAX_SIZE` — over the configured ceiling.

The limit reaches JavaScript through `index.php`, which now emits it alongside the drop zones:

```php
<script>window.HUB_MAX_SIZE = <?= getMaxFileSize() ?>;</script>
```

Previously a 5 GB file was pushed all the way to the server before `upload.php` compared it against `getMaxFileSize()` and returned an error. On a LAN that is minutes of wasted transfer for a rejection that is decidable instantly.

The same enqueue path also skips duplicates — a file whose name and size match a job already pending or uploading in this batch.

### 2.5 Client — HTTP status is checked

The old `xhr.onload` parsed `responseText` as JSON regardless of status code. The most likely large-file failure is a **413** emitted by IIS request filtering, which happens *before* PHP is invoked, so the body is an HTML error page and never JSON. That surfaced as the misleading "Unexpected server response".

`onload` now branches on `xhr.status` and gives 413 its own message pointing at the server-level limits rather than the admin-panel one, since those are the ceilings that actually rejected it (see `project-overview.md` §Size limits).

### 2.6 Server — a real critical section

The important server change. Note that adding `LOCK_EX` to `file_put_contents` **does not fix the race** — the race is between the read and the write, not inside the write. Closing it needs a lock that spans both. `config.php` gained:

```php
function withLock(callable $fn) {
    $fh = fopen(DATA_DIR . '.lock', 'c');
    if ($fh === false) return $fn();   // degrade to old behaviour, never hard-fail an upload
    flock($fh, LOCK_EX);
    try     { return $fn(); }
    finally { flock($fh, LOCK_UN); fclose($fh); }
}
```

A single lock file guards all of `data/`, rather than one lock per JSON file. The three files are related — an upload touches metadata, rules and the log as one logical unit — and per-file locks would introduce lock-ordering concerns for no benefit at this scale.

`upload.php` wraps its metadata + rules + log mutation in one `withLock()` call, so the whole commit is atomic with respect to other requests. The mutating handlers in `api.php` are wrapped the same way; without that, an admin deleting a file while an upload lands could still lose the new entry.

This matters even with `MAX_CONCURRENT = 1`, because the client queue only serializes *one browser*. Two people uploading from two laptops still collide, and the lock is what covers them.

### 2.7 Incidental fixes

- The `📂 Files (N)` count is now updated by `prependCard()`. Previously it kept its server-rendered value, so a batch of twenty uploads left the page reading "Files (0)" above twenty visible cards. It began as a heading above the list and now lives in the Files tab label, which is what makes a completed upload visible while the reader is on the Upload view.
- Toast spam is gone. Individual results live on their rows; a single summary toast fires when the batch drains ("7 uploaded, 1 failed").

---

## 3. Files touched

| File | Change |
|---|---|
| [`assets/js/hub.js`](../assets/js/hub.js) | Job model, `enqueue()` / `pump()` / `startJob()`, queue-panel rendering, cancel & retry, byte-accurate aggregate, status-code handling. `uploadOne()` removed. |
| [`assets/css/style.css`](../assets/css/style.css) | `.upload-queue` panel, `.uq-row` and children, status badge colours. Old `#progressWrap` rules removed. |
| [`index.php`](../index.php) | `#progressWrap` markup replaced by the queue panel container; `window.HUB_MAX_SIZE` emitted. The panel later moved outside the two view panels, where it still sits. |
| [`upload.php`](../upload.php) | Metadata / rules / log commit moved inside `withLock()`. |
| [`config.php`](../config.php) | `withLock()` helper. |
| [`api.php`](../api.php) | Mutating actions wrapped in `withLock()`. |

No change to the data model, the JSON schemas, the access-control layers, or the upload wire format. A queued upload is byte-for-byte the same request `upload.php` always received — only *when* it is sent changed.

---

## 4. Phase 2 and alternatives

### 4.1 Chunked / resumable upload — the genuinely better design

Currently one file is one POST. Slicing it client-side with `file.slice()` and posting 5–10 MB chunks to an endpoint that appends to `uploads/.tmp/<uploadId>.part`, finalizing on the last chunk, is strictly better:

- **The size ceilings stop mattering.** `post_max_size`, `upload_max_filesize`, `maxAllowedContentLength`, `maxRequestLength`, `max_execution_time` and `max_input_time` all constrain a single request. If every request is 10 MB, none of them bind. The entire four-ceiling configuration described in `project-overview.md` — which must be raised in lockstep and needs an `iisreset` — exists *only* because uploads are monolithic.
- **A blip at 95% costs one chunk**, not 4 GB.
- **Metadata is written exactly once**, at finalize, shrinking the contention window to a single small write per file regardless of how long the transfer took.
- **Resume across reload** becomes possible by persisting `uploadId` + byte offset in `localStorage`.

Costs: a finalize/commit step, a sweep for orphaned `.part` files from abandoned uploads, and an integrity check at finalize (size match at minimum, a hash if worth it).

Worth noting the asymmetry this removes: `download.php` already implements Range requests, `206 Partial Content` and resume. Uploads are the half that cannot resume.

The job model from §2.1 was written with this in mind — chunking changes what happens *inside* `startJob()`, and nothing else. Phase 1 is not throwaway work.

### 4.2 Concurrency above 1

Strictly serial is right for large files and measurably slower for many small ones, where per-request overhead dominates the transfer. A size-aware refinement: allow three concurrent while every pending job is under ~100 MB, drop back to one when a large file reaches the front. Only worth doing with measurements — and only now that there is a scheduler to put it in.

### 4.3 Folder upload

Adding `webkitdirectory` to the inputs and walking dropped directories with `DataTransferItem.webkitGetAsEntry()` would flatten a folder into the queue. The enqueue path already exists, so this is a small addition — perhaps thirty lines — whenever it is wanted.

A dropped folder currently arrives in `dataTransfer.files` as a single zero-byte entry, so the empty-file check in §2.4 catches it and the row reads "Empty file — nothing to upload". That is at least visible rather than silent, but it is a misleading explanation of what happened, and it is the first thing folder support should replace.

### 4.4 Considered and rejected

- **`fetch()` instead of `XMLHttpRequest`.** No upload progress events. Request-body streams exist but need HTTP/2 and are effectively Chrome-only. XHR remains the correct choice here.
- **Background Fetch / Service Worker**, so uploads survive tab closure. Real capability, disproportionate complexity for a LAN tool.
- **Client-side zipping of a batch.** Loses per-file metadata, doubles disk churn, and makes the file list worse rather than better.

---

## 5. Verification

Exercised against a throwaway copy of the app on PHP's built-in server, so the real `uploads/` and `data/` were untouched. Files were synthesised in the page and pushed through the actual `<input>` change handler, so the whole path from enqueue to committed metadata ran for real.

| Case | Result |
|---|---|
| Batch of 4 files | All four uploaded, cards prepended, `📂 Files` count tracked 0 → 4 |
| Serial execution | 101 samples taken during a 3 × 100 MB batch — never more than **one** row in `uploading` |
| Byte-accurate aggregate | Advanced monotonically, `0 B → 300 MB`; recalculated to 200 MB the moment one job was canceled |
| Over-limit file | Rejected at enqueue with the limit in the message; **no request issued** (confirmed in the network log) |
| Empty file | Rejected at enqueue, own row, no request |
| Duplicate in the same batch | Second copy skipped — 4 dispatched files produced 3 requests |
| Cancel mid-transfer | Row settled as canceled, the next job started immediately, **no orphan blob** left in `uploads/` |
| Retry after real failure | Server stopped → row showed "Network error" with ↻; server restarted → same `File` re-sent and committed |
| Mixed private + public batch | `uploader` came out as the IP for ☁️ jobs and `public` for 🌍 jobs; per-file rules written only for the private ones |

### 5.1 The lock, measured

§2.6 is the one claim that a single browser cannot demonstrate, since the client queue already serializes it. Ten concurrent PHP processes were run against the same `filesmeta.json`, each performing the read-modify-write that `upload.php` performs:

| Commit path | Entries surviving out of 10 |
|---|---|
| Unlocked (previous behaviour) | **2**, then **3** |
| Inside `withLock()` | **10**, twice |

Seven or eight lost entries per run means seven or eight blobs sitting in `uploads/` that no page can list and no admin can delete. That is the failure §1.2 describes, reproduced deliberately, and closed.

Still worth doing by hand on the real deployment: upload from two machines at once and confirm both batches land — the loopback test cannot exercise IIS/FastCGI's actual process model.
