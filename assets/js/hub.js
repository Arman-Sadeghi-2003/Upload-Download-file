const dropZone        = document.getElementById('dropZone');
const fileInput       = document.getElementById('fileInput');
const dropZonePublic  = document.getElementById('dropZonePublic');
const fileInputPublic = document.getElementById('fileInputPublic');
const toast           = document.getElementById('toast');

const qPanel     = document.getElementById('uploadQueue');
const qRows      = document.getElementById('uqRows');
const qSummary   = document.getElementById('uqSummary');
const qTotalBar  = document.getElementById('uqTotalBar');
const qCancelAll = document.getElementById('uqCancelAll');

// One at a time. A named constant because the queue turns this into a real
// tuning knob — see docs/upload-queue-implementation.md §4.2 before raising it.
const MAX_CONCURRENT = 1;

const esc = s => String(s)
  .replace(/&/g,'&amp;').replace(/</g,'&lt;')
  .replace(/>/g,'&gt;').replace(/"/g,'&quot;');

// Mirrors formatBytes() in config.php so client and server agree on wording
function fmtBytes(b) {
  const u = ['B','KB','MB','GB'];
  if (!b || b < 0) return '0 B';
  const p = Math.min(Math.floor(Math.log(b) / Math.log(1024)), 3);
  return (Math.round(b / Math.pow(1024, p) * 100) / 100) + ' ' + u[p];
}

function showToast(msg, type = 'ok') {
  toast.textContent = msg;
  toast.className = 'toast ' + type;
  toast.style.display = 'block';
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toast.style.display = 'none', 3500);
}

// ── Drag & Drop ───────────────────────────────────────────────────────────────
// Without these, a file dropped anywhere outside a zone makes the browser
// navigate away to that file instead of ignoring the drop.
['dragover', 'drop'].forEach(ev =>
  document.addEventListener(ev, e => e.preventDefault())
);

function wireDropZone(zone, input, isPublic) {
  if (!zone) return;

  zone.addEventListener('dragenter', e => { e.preventDefault(); zone.classList.add('over'); });
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('over'); });

  // Ignore dragleave fired when the cursor crosses onto a child element
  zone.addEventListener('dragleave', e => {
    if (!zone.contains(e.relatedTarget)) zone.classList.remove('over');
  });

  zone.addEventListener('drop', e => {
    e.preventDefault();
    e.stopPropagation();
    zone.classList.remove('over');
    const files = [...(e.dataTransfer?.files || [])];
    if (!files.length) { showToast('❌ Nothing to upload', 'err'); return; }
    files.forEach(f => enqueue(f, isPublic));
  });

  input?.addEventListener('change', e => {
    [...e.target.files].forEach(f => enqueue(f, isPublic));
    input.value = '';
  });
}

wireDropZone(dropZone,       fileInput,       false);
wireDropZone(dropZonePublic, fileInputPublic, true);

// ── Upload queue ──────────────────────────────────────────────────────────────
// A job is one file's whole lifetime:
//   { id, file, isPublic, status, loaded, total, xhr, error, fatal, counted, el }
// status: 'queued' | 'uploading' | 'done' | 'error' | 'canceled'
// Enqueuing is deliberately separate from starting: enqueue() only validates and
// appends, pump() is the single place that decides what runs next.
let jobs = [];
let seq  = 0;

function enqueue(file, isPublic) {
  if (file.size === 0)
    return reject(file, isPublic, 'Empty file — nothing to upload');

  // Decided here rather than after pushing gigabytes at a server that will say no
  if (window.HUB_MAX_SIZE && file.size > window.HUB_MAX_SIZE)
    return reject(file, isPublic, `Too large — max ${fmtBytes(window.HUB_MAX_SIZE)} per file`);

  const pending = j => j.status === 'queued' || j.status === 'uploading';
  if (jobs.some(j => pending(j) && j.isPublic === isPublic &&
                     j.file.name === file.name && j.file.size === file.size)) return;

  addJob({ file, isPublic, status: 'queued', loaded: 0, total: file.size });
  pump();
}

// A file that never becomes a request still gets a row, so the rejection is visible
function reject(file, isPublic, msg) {
  addJob({ file, isPublic, status: 'error', loaded: 0, total: file.size, error: msg, fatal: true });
}

function addJob(fields) {
  const job = Object.assign({ id: ++seq, xhr: null, error: '', fatal: false, counted: false }, fields);
  jobs.push(job);
  renderRow(job);
  refresh();
  return job;
}

function pump() {
  const running = jobs.filter(j => j.status === 'uploading').length;
  let free = MAX_CONCURRENT - running;
  for (const job of jobs) {
    if (free <= 0) break;
    if (job.status === 'queued') { startJob(job); free--; }
  }
}

function startJob(job) {
  job.status = 'uploading';
  job.loaded = 0;
  job.error  = '';

  const fd = new FormData();
  fd.append('file', job.file);
  if (job.isPublic) fd.append('public', '1');

  const xhr = new XMLHttpRequest();
  job.xhr = xhr;
  xhr.open('POST', 'upload.php');

  xhr.upload.onprogress = e => {
    if (!e.lengthComputable) return;
    job.loaded = e.loaded;
    job.total  = e.total;
    updateRow(job);
    updateAggregate();
  };

  xhr.onload = () => {
    // IIS request filtering rejects an oversized body before PHP ever runs, so
    // the response is an HTML error page — parsing it as JSON would only report
    // "unexpected response" and hide which limit actually refused the file.
    if (xhr.status === 413)
      return settle(job, 'error', 'Rejected by the server (413) — the IIS / PHP request limits are lower than the hub limit');
    if (xhr.status < 200 || xhr.status >= 300)
      return settle(job, 'error', `Server error ${xhr.status}`);

    let r;
    try { r = JSON.parse(xhr.responseText); }
    catch { return settle(job, 'error', 'Unexpected server response'); }

    if (!r.success) return settle(job, 'error', r.error || 'Upload failed');

    job.loaded = job.total;
    settle(job, 'done', '');
    prependCard(r.file);
  };

  xhr.onerror = () => settle(job, 'error', 'Network error — upload failed');
  xhr.onabort = () => settle(job, 'canceled', 'Canceled');

  updateRow(job);
  updateAggregate();
  xhr.send(fd);
}

function settle(job, status, msg) {
  job.status = status;
  job.error  = msg;
  job.xhr    = null;
  updateRow(job);

  // Successful rows clear themselves; failures stay so they can be retried
  if (status === 'done') job.timer = setTimeout(() => removeJob(job), 4000);

  pump();          // free the slot first, so a still-running batch isn't reported as finished
  updateAggregate();
  announceIfDrained();
}

function cancelJob(job) {
  if (job.status === 'uploading') job.xhr?.abort();   // → onabort → settle()
  else if (job.status === 'queued') settle(job, 'canceled', 'Canceled');
}

function retryJob(job) {
  clearTimeout(job.timer);
  job.status  = 'queued';
  job.loaded  = 0;
  job.error   = '';
  job.counted = false;
  updateRow(job);
  updateAggregate();
  pump();
}

function removeJob(job) {
  clearTimeout(job.timer);
  job.el?.remove();
  jobs = jobs.filter(j => j !== job);
  refresh();
}

// One summary toast per batch instead of one per file, which the old code
// overwrote 3.5s at a time. counted[] keeps a later batch from re-reporting
// failure rows still sitting in the panel.
function announceIfDrained() {
  if (jobs.some(j => j.status === 'queued' || j.status === 'uploading')) return;

  const fresh = jobs.filter(j => !j.counted && j.status !== 'queued');
  if (!fresh.length) return;
  fresh.forEach(j => j.counted = true);

  const n = s => fresh.filter(j => j.status === s).length;
  const parts = [];
  if (n('done'))     parts.push(`${n('done')} uploaded`);
  if (n('error'))    parts.push(`${n('error')} failed`);
  if (n('canceled')) parts.push(`${n('canceled')} canceled`);

  const bad = n('error') > 0;
  showToast(`${bad ? '⚠️' : '✅'} ${parts.join(' · ')}`, bad ? 'err' : 'ok');
}

qCancelAll?.addEventListener('click', () => {
  const active = jobs.filter(j => j.status === 'queued' || j.status === 'uploading');
  if (active.length) active.forEach(cancelJob);
  else jobs.slice().forEach(removeJob);   // nothing running → the button clears instead
});

// ── Queue rendering ───────────────────────────────────────────────────────────
const STATUS_LABEL = {
  queued: 'Queued', uploading: 'Uploading', done: 'Done', error: 'Failed', canceled: 'Canceled',
};

function renderRow(job) {
  if (!qRows) return;
  const row = document.createElement('div');
  row.className = 'uq-row';
  row.id = 'uq-' + job.id;
  row.innerHTML = `
    <div class="uq-icon">${job.isPublic ? '🌍' : '☁️'}</div>
    <div class="uq-main">
      <div class="uq-name" title="${esc(job.file.name)}">${esc(job.file.name)}</div>
      <div class="uq-bg"><div class="uq-bar"></div></div>
      <div class="uq-meta"></div>
    </div>
    <div class="uq-badge"></div>
    <button type="button" class="uq-act" title="Cancel"></button>`;
  qRows.appendChild(row);

  job.el    = row;
  job.elBar = row.querySelector('.uq-bar');
  job.elMet = row.querySelector('.uq-meta');
  job.elBdg = row.querySelector('.uq-badge');
  job.elAct = row.querySelector('.uq-act');

  // One handler for both actions — which one applies depends on current status
  job.elAct.addEventListener('click', () => {
    if (job.status === 'queued' || job.status === 'uploading') cancelJob(job);
    else if (job.fatal) removeJob(job);
    else retryJob(job);
  });

  updateRow(job);
}

// Touches only the parts that change — a 4 GB upload fires onprogress often
// enough that re-rendering the row each time would be visible.
function updateRow(job) {
  if (!job.el) return;
  const pct = job.total ? Math.round(job.loaded / job.total * 100) : 0;

  job.el.dataset.status  = job.status;
  job.elBar.style.width  = (job.status === 'done' ? 100 : pct) + '%';
  job.elBdg.textContent  = STATUS_LABEL[job.status];

  job.elMet.textContent =
      job.status === 'uploading' ? `${pct}% · ${fmtBytes(job.loaded)} of ${fmtBytes(job.total)}`
    : job.status === 'done'      ? fmtBytes(job.total)
    : job.status === 'queued'    ? `Waiting · ${fmtBytes(job.total)}`
    : job.error;

  const retryable = job.status !== 'queued' && job.status !== 'uploading' && !job.fatal;
  job.elAct.textContent = retryable ? '↻' : '✕';
  job.elAct.title       = retryable ? 'Retry'
                        : job.fatal ? 'Dismiss' : 'Cancel';
}

function refresh() {
  if (qPanel) qPanel.style.display = jobs.length ? 'block' : 'none';
  updateAggregate();
}

// Aggregate is byte-based, not file-count-based: with one 6 GB video among nine
// screenshots, "file 3 of 10" would be a lie and "3.1 GB of 8.4 GB" is not.
// Canceled and failed jobs are excluded, otherwise their unsent bytes would pin
// the bar below 100% for the rest of the batch.
function updateAggregate() {
  if (!qSummary) return;
  const counted = jobs.filter(j => j.status !== 'canceled' && j.status !== 'error');
  const total   = counted.reduce((n, j) => n + j.total, 0);
  const loaded  = counted.reduce((n, j) => n + j.loaded, 0);
  const pct     = total ? Math.round(loaded / total * 100) : 0;

  qTotalBar.style.width = pct + '%';

  const active = jobs.filter(j => j.status === 'queued' || j.status === 'uploading').length;
  const done   = jobs.filter(j => j.status === 'done').length;
  const failed = jobs.filter(j => j.status === 'error' || j.status === 'canceled').length;

  if (active) {
    qSummary.textContent =
      `Uploading ${done + 1} of ${done + active} — ${fmtBytes(loaded)} of ${fmtBytes(total)} (${pct}%)`;
    qCancelAll.textContent = 'Cancel all';
  } else {
    const parts = [];
    if (done)   parts.push(`${done} uploaded`);
    if (failed) parts.push(`${failed} not uploaded`);
    qSummary.textContent   = parts.join(' · ');
    qCancelAll.textContent = 'Clear';
  }
}

// ── Prepend new file card ─────────────────────────────────────────────────────
function prependCard(f) {
  const list  = document.getElementById('fileList');
  const empty = list.querySelector('.empty');
  if (empty) empty.remove();

  const ext = (f.name.split('.').pop() || 'FILE').toUpperCase();
  list.insertAdjacentHTML('afterbegin', `
    <div class="file-card" id="fc-${f.id}">
      <div class="fc-icon">${f.icon}</div>
      <div class="fc-info">
        <div class="fc-name">${esc(f.name)}</div>
        <div class="fc-meta">${f.size} &bull; ${f.date} &bull; ${ext}</div>
      </div>
      <div class="fc-actions">
        <a href="download.php?id=${encodeURIComponent(f.id)}" class="btn btn-dl">⬇ Download</a>
      </div>
    </div>`);

  // Server-rendered count — without this a batch of 20 leaves it reading "(0)"
  const cnt = document.getElementById('fileCount');
  if (cnt) cnt.textContent = (parseInt(cnt.textContent, 10) || 0) + 1;
}
