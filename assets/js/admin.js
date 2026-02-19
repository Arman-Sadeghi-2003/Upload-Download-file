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
  .replace(/&/g,'&amp;').replace(/</g,'&lt;')
  .replace(/>/g,'&gt;').replace(/"/g,'&quot;');

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

  document.getElementById('btnBlacklist').className =
    'mode-btn' + (mode === 'blacklist' ? ' active-bl' : '');
  document.getElementById('btnWhitelist').className =
    'mode-btn' + (mode === 'whitelist' ? ' active-wl' : '');
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
    tags.insertAdjacentHTML('beforeend', `
      <div class="ip-tag" id="gtag-${key}">
        ${esc(ip)}
        <span class="rm" onclick="removeGlobalIP('${esc(ip)}')">✕</span>
      </div>`);
  }
  inp.value = '';
  showToast(`✅ ${ip} added`);
}

async function removeGlobalIP(ip) {
  const r = await api({ action: 'remove_global', ip });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }

  const key = btoa(unescape(encodeURIComponent(ip))).replace(/[^a-zA-Z0-9]/g, '');
  document.getElementById('gtag-' + key)?.remove();

  const tags = document.getElementById('globalTags');
  if (!tags.querySelector('.ip-tag'))
    tags.insertAdjacentHTML('beforeend', '<span class="no-tags" id="noGlobalTags">No IPs added yet.</span>');

  showToast(`Removed ${ip}`);
}

// ── Per-File Rules ────────────────────────────────────────────────────────────
function toggleFileRules(fileId) {
  document.getElementById('frs-' + fileId).classList.toggle('open');
}

async function addFileRule(fileId, type) {
  const inp = document.getElementById(`fip-${type}-${fileId}`);
  const ip  = inp.value.trim();
  if (!ip) { showToast('Enter an IP first', 'err'); return; }

  const r = await api({ action: 'add_file_rule', file_id: fileId, ip, type });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }

  const container = document.getElementById(`ftags-${type}-${fileId}`);
  container.querySelector('.no-tags')?.remove();
  container.insertAdjacentHTML('beforeend', `
    <div class="ip-tag">
      ${esc(ip)}
      <span class="rm" onclick="removeFileRule('${fileId}','${type}','${esc(ip)}')">✕</span>
    </div>`);

  inp.value = '';
  showToast(`✅ ${ip} → ${type} for this file`);
}

async function removeFileRule(fileId, type, ip) {
  const r = await api({ action: 'remove_file_rule', file_id: fileId, ip, type });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }

  const container = document.getElementById(`ftags-${type}-${fileId}`);
  container.querySelectorAll('.ip-tag').forEach(tag => {
    if (tag.textContent.trim().startsWith(ip)) tag.remove();
  });
  if (!container.querySelector('.ip-tag'))
    container.insertAdjacentHTML('beforeend', '<span class="no-tags">None</span>');

  showToast(`Removed ${ip}`);
}

// ── Delete File ───────────────────────────────────────────────────────────────
async function deleteFile(fileId, name) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;

  const r = await api({ action: 'delete_file', file_id: fileId });
  if (!r.success) { showToast('Error: ' + r.error, 'err'); return; }

  document.getElementById('mf-' + fileId)?.remove();
  document.getElementById('af-' + fileId)?.remove();
  showToast(`🗑 "${name}" deleted`);
}

// ── Access Logs ───────────────────────────────────────────────────────────────
async function loadLogs() {
  const body = document.getElementById('logBody');
  body.innerHTML = '<tr><td colspan="5" class="log-empty">Loading...</td></tr>';

  const r = await api({ action: 'get_logs' });
  if (!r.success) {
    body.innerHTML = '<tr><td colspan="5" class="log-empty" style="color:var(--red)">Failed to load</td></tr>';
    return;
  }
  if (!r.logs.length) {
    body.innerHTML = '<tr><td colspan="5" class="log-empty">No logs yet.</td></tr>';
    return;
  }
  body.innerHTML = r.logs.map(l => `
    <tr>
      <td>${esc(l.t)}</td>
      <td>${esc(l.ip)}</td>
      <td>${esc(l.action)}</td>
      <td>${esc(l.file)}</td>
      <td class="${l.ok ? 'log-ok' : 'log-den'}">${l.ok ? '✅ Granted' : '🚫 Denied'}</td>
    </tr>`).join('');
}
