const toast = document.getElementById('toast');

// ── Helpers ───────────────────────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  toast.textContent = msg;
  toast.className = 'toast ' + type;
  toast.style.display = 'block';
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toast.style.display = 'none', 3000);
}

async function api(params) {
  const fd = new FormData();
  Object.entries(params).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('api.php', { method: 'POST', body: fd });
  return r.json();
}

const esc = s => String(s)
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

// ── Tab Switching ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.tab, .panel').forEach(el => el.classList.remove('active'));
  t.classList.add('active');
  document.getElementById('tab-' + t.dataset.tab).classList.add('active');
  if (t.dataset.tab === 'logs') loadLogs();
}));

// ── Mode ──────────────────────────────────────────────────────────────────────
async function setMode(mode) {
  const r = await api({ action: 'set_mode', mode });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  document.getElementById('btnBlacklist').className = 'mode-btn' + (mode === 'blacklist' ? ' active-bl' : '');
  document.getElementById('btnWhitelist').className = 'mode-btn' + (mode === 'whitelist' ? ' active-wl' : '');
  document.getElementById('modeHint').innerHTML = mode === 'blacklist'
    ? '🚫 <strong>Blacklist:</strong> Everyone can access <em>unless</em> their IP is blocked.'
    : '✅ <strong>Whitelist:</strong> Only listed IPs can access. All others are denied.';
  showToast(`Mode set to ${mode}`);
}

// ── Global IP Rules ───────────────────────────────────────────────────────────
async function addGlobalIP() {
  const inp = document.getElementById('globalIPInput');
  const ip  = inp.value.trim();
  if (!ip) { showToast('Enter an IP first', 'err'); return; }
  const r = await api({ action: 'add_global', ip });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  const tags  = document.getElementById('globalTags');
  const noTag = document.getElementById('noGlobalTags');
  if (noTag) noTag.remove();
  const key = btoa(unescape(encodeURIComponent(ip))).replace(/[^a-zA-Z0-9]/g, '');
  if (!document.getElementById('gtag-' + key)) {
    tags.insertAdjacentHTML('beforeend',
      `<div class="ip-tag" id="gtag-${key}">${esc(ip)}<span class="rm" onclick="removeGlobalIP('${esc(ip)}')">✕</span></div>`);
  }
  inp.value = '';
  showToast('IP added');
}

async function removeGlobalIP(ip) {
  const r = await api({ action: 'remove_global', ip });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  const key = btoa(unescape(encodeURIComponent(ip))).replace(/[^a-zA-Z0-9]/g, '');
  document.getElementById('gtag-' + key)?.remove();
  if (!document.querySelector('#globalTags .ip-tag'))
    document.getElementById('globalTags').innerHTML = '<span class="no-tags" id="noGlobalTags">No IPs added yet.</span>';
  showToast('IP removed');
}

// ── Default IPs for New Uploads ───────────────────────────────────────────────
function renderDefaultTags(list) {
  document.getElementById('defaultTags').innerHTML = list.map(ip => {
    const key = btoa(unescape(encodeURIComponent(ip))).replace(/[^a-zA-Z0-9]/g, '');
    const rm  = ip === '::1' ? '' : `<span class="rm" onclick="removeDefaultIP('${esc(ip)}')">✕</span>`;
    return `<div class="ip-tag" id="dtag-${key}">${esc(ip)}${rm}</div>`;
  }).join('');
}

async function addDefaultIP() {
  const inp = document.getElementById('defaultIPInput');
  const ip  = inp.value.trim();
  if (!ip) { showToast('Enter an IP first', 'err'); return; }
  const r = await api({ action: 'add_default_ip', ip });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  renderDefaultTags(r.defaultIPs);
  inp.value = '';
  showToast('Default IP added');
}

async function removeDefaultIP(ip) {
  const r = await api({ action: 'remove_default_ip', ip });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  renderDefaultTags(r.defaultIPs);
  showToast('Default IP removed');
}

// ── Per-File Rules ────────────────────────────────────────────────────────────
function toggleFileRules(fileId) {
  const el = document.getElementById('frs-' + fileId);
  el.style.display = el.style.display === 'block' ? 'none' : 'block';
}

async function addFileRule(fileId, type) {
  const inp = document.getElementById(`fip-${type}-${fileId}`);
  const ip  = inp.value.trim();
  if (!ip) { showToast('Enter an IP first', 'err'); return; }
  const r = await api({ action: 'add_file_rule', file_id: fileId, ip, type });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  const tags = document.getElementById(`ftags-${type}-${fileId}`);
  tags.querySelector('.no-tags')?.remove();
  tags.insertAdjacentHTML('beforeend',
    `<div class="ip-tag">${esc(ip)}<span class="rm" onclick="removeFileRule('${fileId}','${type}','${esc(ip)}')">✕</span></div>`);
  inp.value = '';
  showToast('Rule added');
}

async function removeFileRule(fileId, type, ip) {
  const r = await api({ action: 'remove_file_rule', file_id: fileId, ip, type });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  const tags = document.getElementById(`ftags-${type}-${fileId}`);
  [...tags.querySelectorAll('.ip-tag')].forEach(el => {
    if (el.textContent.replace('✕','').trim() === ip) el.remove();
  });
  if (!tags.querySelector('.ip-tag'))
    tags.innerHTML = '<span class="no-tags">None</span>';
  showToast('Rule removed');
}

// ── File Manager ──────────────────────────────────────────────────────────────
async function deleteFile(fileId, name) {
  if (!confirm(`Delete "${name}"?`)) return;
  const r = await api({ action: 'delete_file', file_id: fileId });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  document.getElementById('mf-' + fileId)?.remove();
  document.getElementById('af-' + fileId)?.remove();
  refreshManager();
  showToast('File deleted');
}

// ── File Manager: filter by uploader IP + bulk delete ─────────────────────────
const mgrSearch = document.getElementById('mgrSearch');
const mgrList   = document.getElementById('mgrList');

const mgrRows      = () => [...(mgrList?.querySelectorAll('.adm-file') || [])];
const mgrShown     = () => mgrRows().filter(r => r.style.display !== 'none');
const mgrSelected  = () => mgrRows().filter(r => r.querySelector('.mf-check').checked);

function fmtBytes(b) {
  const u = ['B','KB','MB','GB'];
  if (!b || b < 0) return '0 B';
  const p = Math.min(Math.floor(Math.log(b) / Math.log(1024)), 3);
  return (Math.round(b / Math.pow(1024, p) * 100) / 100) + ' ' + u[p];
}

function ip2long(ip) {
  const parts = ip.split('.');
  if (parts.length !== 4) return null;
  let n = 0;
  for (const o of parts) {
    if (!/^\d{1,3}$/.test(o) || +o > 255) return null;
    n = ((n << 8) | +o) >>> 0;
  }
  return n;
}

// Mirrors ipMatchesRule() in config.php for the CIDR and wildcard forms, so this
// box accepts the same syntax as every other IP field in the panel. Anything
// without / or * falls back to substring, which is what lets a half-typed
// address narrow the list as you go.
function uploaderMatches(uploader, q) {
  if (!q) return true;

  if (q.includes('/')) {
    const [subnet, bits] = q.split('/');
    const n = ip2long(uploader), s = ip2long(subnet), b = parseInt(bits, 10);
    if (n === null || s === null || !(b >= 0 && b <= 32)) return false;
    const mask = b === 0 ? 0 : (-1 << (32 - b)) >>> 0;
    return ((n & mask) >>> 0) === ((s & mask) >>> 0);
  }

  if (q.includes('*')) {
    const pat = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\*/g, '[0-9]{1,3}');
    return new RegExp('^' + pat + '$').test(uploader);
  }

  return uploader.toLowerCase().includes(q.toLowerCase());
}

function applyMgrFilter() {
  const q = mgrSearch.value.trim();
  mgrRows().forEach(row => {
    const hit = uploaderMatches(row.dataset.uploader, q);
    row.style.display = hit ? '' : 'none';
    // A row that scrolls out of the filter must lose its tick too. Otherwise a
    // selection made under one IP would be carried into a delete run under
    // another, removing files the admin can no longer see on screen.
    if (!hit) setChecked(row, false);
  });
  document.getElementById('mgrNoMatch').style.display = mgrShown().length ? 'none' : 'block';
  updateMgrStatus();
}

function setChecked(row, on) {
  row.querySelector('.mf-check').checked = on;
  row.classList.toggle('selected', on);
}

function selectAllShown() {
  const rows = mgrShown();
  if (!rows.length) { showToast('Nothing to select', 'err'); return; }
  rows.forEach(r => setChecked(r, true));
  updateMgrStatus();
}

function clearSelection() {
  mgrRows().forEach(r => setChecked(r, false));
  updateMgrStatus();
}

function updateMgrStatus() {
  const sel   = mgrSelected();
  const bytes = sel.reduce((n, r) => n + (+r.dataset.size || 0), 0);
  const btn   = document.getElementById('mgrDeleteBtn');

  btn.disabled    = sel.length === 0;
  btn.textContent = sel.length ? `🗑 Delete selected (${sel.length})` : '🗑 Delete selected';

  document.getElementById('mgrStatus').textContent = sel.length
    ? `${mgrShown().length} of ${mgrRows().length} shown · ${sel.length} selected · ${fmtBytes(bytes)}`
    : `${mgrShown().length} of ${mgrRows().length} shown · nothing selected`;
}

async function deleteSelected() {
  const rows = mgrSelected();
  if (!rows.length) return;

  const ids   = rows.map(r => r.querySelector('.mf-check').value);
  const bytes = rows.reduce((n, r) => n + (+r.dataset.size || 0), 0);
  const who   = [...new Set(rows.map(r => r.dataset.uploader))];
  const from  = who.length === 1 ? `\nUploader: ${who[0]}` : `\nUploaders: ${who.length}`;

  if (!confirm(`Permanently delete ${ids.length} file${ids.length > 1 ? 's' : ''} (${fmtBytes(bytes)})?${from}\n\nThis cannot be undone.`)) return;

  const btn = document.getElementById('mgrDeleteBtn');
  btn.disabled = true;

  // One request for the whole selection, not one per file: the server deletes
  // them inside a single lock and rewrites the JSON once.
  const r = await api({ action: 'delete_files', file_ids: JSON.stringify(ids) });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); updateMgrStatus(); return; }

  r.deleted.forEach(id => {
    document.getElementById('mf-' + id)?.remove();
    document.getElementById('af-' + id)?.remove();   // matching Per-File Rules card
  });

  refreshManager();

  const missed = ids.length - r.deleted.length;
  showToast(missed
    ? `${r.deleted.length} deleted · ${missed} already gone`
    : `${r.deleted.length} file${r.deleted.length > 1 ? 's' : ''} deleted`);
}

// Keeps the empty states honest after any deletion, single or bulk
function refreshManager() {
  if (!mgrList) return;

  if (!mgrRows().length) {
    document.getElementById('mgrTools').style.display = 'none';
    document.getElementById('mgrEmpty').style.display = '';
    if (!document.querySelector('#tab-files .adm-file'))
      document.getElementById('tab-files').innerHTML = '<div class="empty">No files uploaded yet.</div>';
    return;
  }
  applyMgrFilter();
}

if (mgrList) {
  mgrSearch.addEventListener('input', applyMgrFilter);
  // Delegated so the handler survives rows being removed, and so the row
  // highlight and the counter can never disagree with the checkbox.
  mgrList.addEventListener('change', e => {
    const box = e.target.closest('.mf-check');
    if (!box) return;
    box.closest('.adm-file').classList.toggle('selected', box.checked);
    updateMgrStatus();
  });
  updateMgrStatus();
}

// ── Logs ──────────────────────────────────────────────────────────────────────
async function loadLogs() {
  const r = await api({ action: 'get_logs' });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  const tbody = document.getElementById('logBody');
  if (!r.logs.length) { tbody.innerHTML = '<tr><td colspan="5" class="log-empty">No logs yet.</td></tr>'; return; }
  tbody.innerHTML = r.logs.map(l =>
    `<tr>
      <td>${esc(l.t)}</td>
      <td>${esc(l.ip)}</td>
      <td>${esc(l.action)}</td>
      <td>${esc(l.file)}</td>
      <td class="${l.ok ? 'log-ok' : 'log-deny'}">${l.ok ? '✅' : '🚫'}</td>
    </tr>`
  ).join('');
}

// ── Max Upload Size ───────────────────────────────────────────────────────────
async function loadMaxUpload() {
  const r = await fetch('api.php?action=get_settings');
  const j = await r.json();
  if (!j.success) return;
  const mb = Math.floor(j.maxFileSize / (1024 * 1024));
  document.getElementById('maxUploadMB').value = mb;
  document.getElementById('maxUploadHint').textContent = `Current limit: ${mb} MB`;
}

async function saveMaxUpload() {
  const mb = parseInt(document.getElementById('maxUploadMB').value || '0', 10);
  if (!mb || mb < 1) { showToast('Enter a valid size in MB', 'err'); return; }
  const r = await api({ action: 'set_max_file_size', bytes: String(mb * 1024 * 1024) });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
  const newMb = Math.floor(r.maxFileSize / (1024 * 1024));
  document.getElementById('maxUploadHint').textContent = `Saved! Current limit: ${newMb} MB`;
  showToast('Max upload size saved');
}

// ── Per-File Visibility IPs ───────────────────────────────────────────────────
async function addVisibleTo(fileId) {
    const inp = document.getElementById('vip-input-' + fileId);
    const ip  = inp.value.trim();
    if (!ip) { showToast('Enter an IP first', 'err'); return; }
    const r = await api({ action: 'add_visible_to', file_id: fileId, ip });
    if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
    inp.value = '';
    const tags  = document.getElementById('vip-tags-' + fileId);
    const noTag = document.getElementById('vip-no-' + fileId);
    if (noTag) noTag.remove();
    const key   = btoa(unescape(encodeURIComponent(ip))).replace(/[^a-zA-Z0-9]/g, '');
    const tagId = `vip-tag-${fileId}-${key}`;
    if (!document.getElementById(tagId))
        tags.insertAdjacentHTML('beforeend',
            `<span class="ip-tag" id="${tagId}">${esc(ip)} ` +
            `<button onclick="removeVisibleTo('${esc(fileId)}','${esc(ip)}','${tagId}')">✕</button></span>`);
    showToast(`👁 ${ip} added to visibility list`);
}

async function removeVisibleTo(fileId, ip, tagId) {
    const r = await api({ action: 'remove_visible_to', file_id: fileId, ip });
    if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }
    document.getElementById(tagId)?.remove();
    const tags = document.getElementById('vip-tags-' + fileId);
    if (!tags.querySelector('.ip-tag'))
        tags.insertAdjacentHTML('beforeend',
            `<span class="muted-tag" id="vip-no-${fileId}">— visible to all —</span>`);
    showToast(`👁 ${ip} removed from visibility list`);
}

// ── Init ──────────────────────────────────────────────────────────────────────
loadMaxUpload();
