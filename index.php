<?php
require 'config.php';

$clientIP = getClientIP();
$allFiles = loadFilesMeta();
$files    = array_values(
    array_filter($allFiles, fn($f) => checkFileVisibility($clientIP, $f['id']))
);
$access   = checkIPAccess($clientIP);

// A denied IP gets no Upload tab at all, rather than one that opens on nothing
$canUpload = $access['allowed'];

// Files is the resting view — browsing is the ambient act, uploading the
// deliberate one. The exception is an empty hub, where Files has nothing to show
// and the only useful thing to do is upload. A #hash overrides either way.
$defaultTab = (empty($files) && $canUpload) ? 'upload' : 'files';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>File Hub</title>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<header>
  <h1>☁️ File Hub</h1>
  <div class="hdr-right">
    <span class="badge"><?= htmlspecialchars($clientIP) ?></span>
    <a href="admin.php" class="link-btn">Admin Panel</a>
  </div>
</header>

<div class="tabs">
  <?php if ($canUpload): ?>
    <div class="tab <?= $defaultTab === 'upload' ? 'active' : '' ?>" data-tab="upload">☁️ Upload</div>
  <?php endif; ?>
  <div class="tab <?= $defaultTab === 'files' ? 'active' : '' ?>" data-tab="files">
    📂 Files (<span id="fileCount"><?= count($files) ?></span>)
  </div>
</div>

<div class="container">
  <?php if (!$canUpload): ?>
    <div class="blocked">
      <strong>Access denied</strong> for IP <strong><?= htmlspecialchars($clientIP) ?></strong>
      — <?= htmlspecialchars($access['reason']) ?>
    </div>
  <?php else: ?>
    <!-- Deliberately outside both panels: an upload keeps running when you switch
         to Files, so its progress has to stay on screen from either tab. -->
    <div class="upload-queue" id="uploadQueue">
      <div class="uq-head">
        <div class="uq-summary" id="uqSummary"></div>
        <button type="button" class="uq-clear" id="uqCancelAll">Cancel all</button>
      </div>
      <div class="uq-total-bg"><div class="uq-total-bar" id="uqTotalBar"></div></div>
      <div class="uq-rows" id="uqRows"></div>
    </div>

    <div class="panel <?= $defaultTab === 'upload' ? 'active' : '' ?>" id="tab-upload">
      <div class="drop-zone" id="dropZone">
        <div class="dz-icon">☁️</div>
        <p>Drag &amp; drop files here, or <span onclick="document.getElementById('fileInput').click()">browse</span></p>
        <p class="dz-hint">Private — only you and the admin can see it. Max <?= formatBytes(getMaxFileSize()) ?> per file</p>
      </div>
      <input type="file" id="fileInput" multiple>

      <div class="drop-zone public" id="dropZonePublic">
        <div class="dz-icon">🌍</div>
        <p>Drag &amp; drop <strong>public</strong> files here, or <span onclick="document.getElementById('fileInputPublic').click()">browse</span></p>
        <p class="dz-hint">Public — everyone on the network can see and download it. Max <?= formatBytes(getMaxFileSize()) ?> per file</p>
      </div>
      <input type="file" id="fileInputPublic" multiple>
    </div>

    <!-- Lets the queue reject an oversized file before transferring it -->
    <script>window.HUB_MAX_SIZE = <?= getMaxFileSize() ?>;</script>
  <?php endif; ?>

  <div class="panel <?= $defaultTab === 'files' ? 'active' : '' ?>" id="tab-files">
    <div class="file-list" id="fileList">
      <?php if (empty($files)): ?>
        <div class="empty">No files uploaded yet.</div>
      <?php else: foreach ($files as $f):
          $ext    = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
          $canGet = checkIPAccess($clientIP, $f['id'])['allowed'];
      ?>
        <div class="file-card" id="fc-<?= $f['id'] ?>">
          <div class="fc-icon"><?= fileIcon($ext) ?></div>
          <div class="fc-info">
            <div class="fc-name" title="<?= htmlspecialchars($f['name']) ?>"><?= htmlspecialchars($f['name']) ?></div>
            <div class="fc-meta"><?= formatBytes($f['size']) ?> &bull; <?= $f['date'] ?> &bull; <?= strtoupper($ext ?: 'FILE') ?></div>
          </div>
          <div class="fc-actions">
            <?php if ($canGet): ?>
              <a href="download.php?id=<?= urlencode($f['id']) ?>" class="btn btn-dl">⬇ Download</a>
            <?php else: ?>
              <span class="restricted">🚫 Restricted</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Filled by hub.js; stays empty while everything fits on one page -->
    <div class="pager" id="pager"></div>
  </div>
</div>

<?php if ($canUpload): ?>
  <!-- Full-window drop target. Appears on dragenter so a file can be dropped
       from either tab, and splits the window so the zone is chosen by which
       half you release over. -->
  <div class="drop-overlay" id="dropOverlay">
    <div class="do-half" id="doPrivate">
      <div class="do-icon">☁️</div>
      <div class="do-title">Private</div>
      <div class="do-hint">Only you and the admin</div>
    </div>
    <div class="do-half public" id="doPublic">
      <div class="do-icon">🌍</div>
      <div class="do-title">Public</div>
      <div class="do-hint">Everyone on the network</div>
    </div>
  </div>
<?php endif; ?>

<div class="toast" id="toast"></div>
<script src="assets/js/hub.js?v=<?= @filemtime(__DIR__ . '/assets/js/hub.js') ?>"></script>
</body>
</html>
