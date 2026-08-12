<?php
require 'config.php';

$clientIP = getClientIP();
$allFiles = loadFilesMeta();
$files    = array_values(
    array_filter($allFiles, fn($f) => checkFileVisibility($clientIP, $f['id']))
);
$access   = checkIPAccess($clientIP);
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

<div class="container">
  <?php if (!$access['allowed']): ?>
    <div class="blocked">
      <strong>Access denied</strong> for IP <strong><?= htmlspecialchars($clientIP) ?></strong>
      — <?= htmlspecialchars($access['reason']) ?>
    </div>
  <?php else: ?>
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

    <div id="progressWrap">
      <div class="prog-bg"><div class="prog-bar" id="progBar"></div></div>
      <div id="progLabel">0%</div>
    </div>
  <?php endif; ?>

  <div class="sec-title">📂 Files (<?= count($files) ?>)</div>
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
</div>

<div class="toast" id="toast"></div>
<script src="assets/js/hub.js?v=<?= @filemtime(__DIR__ . '/assets/js/hub.js') ?>"></script>
</body>
</html>
