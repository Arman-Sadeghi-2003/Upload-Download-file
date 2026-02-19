const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const toast     = document.getElementById('toast');

function showToast(msg, type = 'ok') {
  toast.textContent = msg;
  toast.className = 'toast ' + type;
  toast.style.display = 'block';
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toast.style.display = 'none', 3500);
}

// ── Drag & Drop ───────────────────────────────────────────────────────────────
dropZone?.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('over'); });
dropZone?.addEventListener('dragleave', ()  => dropZone.classList.remove('over'));
dropZone?.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('over');
  [...e.dataTransfer.files].forEach(uploadOne);
});

fileInput?.addEventListener('change', e => {
  [...e.target.files].forEach(uploadOne);
  fileInput.value = '';
});

// ── Upload ────────────────────────────────────────────────────────────────────
function uploadOne(file) {
  const fd   = new FormData();
  fd.append('file', file);

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
      lbl.textContent = `Uploading "${file.name}" — ${p}%`;
    }
  };

  xhr.onload = () => {
    wrap.style.display = 'none';
    bar.style.width = '0%';
    try {
      const r = JSON.parse(xhr.responseText);
      if (r.success) { showToast(`✅ ${file.name} uploaded`); prependCard(r.file); }
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
