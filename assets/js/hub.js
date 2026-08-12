const dropZone        = document.getElementById('dropZone');
const fileInput       = document.getElementById('fileInput');
const dropZonePublic  = document.getElementById('dropZonePublic');
const fileInputPublic = document.getElementById('fileInputPublic');
const toast           = document.getElementById('toast');

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
    files.forEach(f => uploadOne(f, isPublic));
  });

  input?.addEventListener('change', e => {
    [...e.target.files].forEach(f => uploadOne(f, isPublic));
    input.value = '';
  });
}

wireDropZone(dropZone,       fileInput,       false);
wireDropZone(dropZonePublic, fileInputPublic, true);

// ── Upload ────────────────────────────────────────────────────────────────────
function uploadOne(file, isPublic = false) {
  const fd   = new FormData();
  fd.append('file', file);
  if (isPublic) fd.append('public', '1');

  const wrap = document.getElementById('progressWrap');
  const bar  = document.getElementById('progBar');
  const lbl  = document.getElementById('progLabel');
  wrap.style.display = 'block';

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'upload.php');

  xhr.upload.onprogress = e => {
    if (e.lengthComputable) {
      const p = Math.round(e.loaded / e.total * 100);
      bar.style.width = p + '%';
      lbl.textContent = `Uploading ${isPublic ? 'public ' : ''}"${file.name}" — ${p}%`;
    }
  };

  xhr.onload = () => {
    wrap.style.display = 'none';
    bar.style.width = '0%';
    try {
      const r = JSON.parse(xhr.responseText);
      if (r.success) { showToast(`✅ ${file.name} uploaded${isPublic ? ' (public)' : ''}`); prependCard(r.file); }
      else           { showToast(`❌ ${r.error}`, 'err'); }
    } catch {
      showToast('❌ Unexpected server response', 'err');
    }
  };

  xhr.onerror = () => {
    wrap.style.display = 'none';
    showToast('❌ Upload failed', 'err');
  };

  xhr.send(fd);
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
}

const esc = s => String(s)
  .replace(/&/g,'&amp;').replace(/</g,'&lt;')
  .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
