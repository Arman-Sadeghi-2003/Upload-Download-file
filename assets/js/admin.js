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
  showToast('File deleted');
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
