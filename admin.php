<?php
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['logout']))   { session_destroy(); header('Location: admin.php'); exit; }
    if (isset($_POST['password'])) {
        if ($_POST['password'] === ADMIN_PASSWORD) $_SESSION['hub_admin'] = true;
        else $loginError = 'Incorrect password.';
    }
}

// ── Login Page ────────────────────────────────────────────────────────────────
if (!isAdmin()) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login</title>
  <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-body">
  <div class="login-card">
    <h2>🔐 Admin Login</h2>
    <?php if (!empty($loginError)): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <form method="POST">
      <input type="password" name="password" placeholder="Password" autofocus>
      <button type="submit">Login</button>
    </form>
  </div>
</body>
</html>
<?php exit; }

// ── Admin Panel ───────────────────────────────────────────────────────────────
$rules    = loadIPRules();
$files    = loadFilesMeta();
$clientIP = getClientIP();
$mode     = $rules['mode'] ?? 'blacklist';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Panel – File Hub</title>
  <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<header>
  <h1>⚙️ Admin Panel</h1>
  <div class="hdr-right">
    <span class="badge"><?= htmlspecialchars($clientIP) ?></span>
    <a href="index.php" class="link-btn">Hub</a>
    <form method="POST" style="margin:0">
      <button name="logout" class="link-btn logout-btn">Logout</button>
    </form>
  </div>
</header>

<div class="tabs">
  <div class="tab active" data-tab="global">Global Rules</div>
  <div class="tab" data-tab="files">Per-File Rules</div>
  <div class="tab" data-tab="manager">File Manager</div>
  <div class="tab" data-tab="logs">Access Logs</div>
</div>

<div class="container">

  <!-- GLOBAL RULES -->
  <div class="panel active" id="tab-global">

    <div class="card">
      <div class="card-title">Access Mode</div>
      <div class="mode-row">
        <button class="mode-btn <?= $mode==='blacklist'?'active-bl':'' ?>" id="btnBlacklist" onclick="setMode('blacklist')">🚫 Blacklist Mode</button>
        <button class="mode-btn <?= $mode==='whitelist'?'active-wl':'' ?>" id="btnWhitelist" onclick="setMode('whitelist')">✅ Whitelist Mode</button>
      </div>
      <div class="mode-hint" id="modeHint">
        <?= $mode==='blacklist'
          ? '🚫 <strong>Blacklist:</strong> Everyone can access <em>unless</em> their IP is blocked.'
          : '✅ <strong>Whitelist:</strong> Only listed IPs can access. All others are denied.' ?>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Max Upload Size</div>
      <div class="input-row">
        <input type="number" id="maxUploadMB" min="1" step="1" placeholder="Size in MB (e.g. 100)">
        <button class="btn btn-primary" onclick="saveMaxUpload()">Save</button>
      </div>
      <div class="mode-hint" id="maxUploadHint"></div>
    </div>

    <div class="card">
      <div class="card-title">Add Global IP Rule</div>
      <div class="input-row">
        <input type="text" id="globalIPInput" placeholder="IP, CIDR (192.168.1.0/24), or wildcard (192.168.*)">
        <button class="btn btn-primary" onclick="addGlobalIP()">Add IP</button>
      </div>
      <div class="ip-tags" id="globalTags">
        <?php if (empty($rules['global'])): ?>
          <span class="no-tags" id="noGlobalTags">No IPs added yet.</span>
        <?php else: foreach ($rules['global'] as $ip): ?>
          <div class="ip-tag" id="gtag-<?= md5($ip) ?>">
            <?= htmlspecialchars($ip) ?>
            <span class="rm" onclick="removeGlobalIP('<?= htmlspecialchars($ip) ?>')">✕</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div>

  <!-- PER-FILE RULES -->
  <div class="panel" id="tab-files">
    <?php if (empty($files)): ?>
      <div class="empty">No files uploaded yet.</div>
    <?php else: foreach ($files as $f):
        $ext       = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $fileRules = $rules['files'][$f['id']] ?? ['allowed'=>[],'denied'=>[]];
    ?>
      <div class="adm-file" id="af-<?= $f['id'] ?>">
        <div class="adm-file-header">
          <div class="adm-file-icon"><?= fileIcon($ext) ?></div>
          <div class="adm-file-info">
            <div class="adm-file-name"><?= htmlspecialchars($f['name']) ?></div>
            <div class="adm-file-meta"><?= formatBytes($f['size']) ?> &bull; <?= $f['date'] ?> &bull; <?= htmlspecialchars($f['uploader']) ?></div>
          </div>
          <div class="adm-file-actions">
            <button class="btn btn-primary btn-sm" onclick="toggleFileRules('<?= $f['id'] ?>')">Rules</button>
          </div>
        </div>
        
        <div class="file-rules-section" id="frs-<?= $f['id'] ?>">
          <!-- ── Visibility IPs ───────────────────────────────────────── -->
          <div class="rule-group" style="margin-top:14px;">
              <strong>👁 Visibility IPs</strong>
              <small style="display:block;color:var(--muted);margin-bottom:6px;">
                  If any IP is added here, <em>only those IPs</em> will see this file
                  in the hub index. Leave empty to show to everyone.
              </small>
              <div class="ip-tags" id="vip-tags-<?= $f['id'] ?>">
                  <?php
                  $visibleTo = $rules['files'][$f['id']]['visible_to'] ?? [];
                  if (empty($visibleTo)): ?>
                      <span class="muted-tag" id="vip-no-<?= $f['id'] ?>">— visible to all —</span>
                  <?php else: foreach ($visibleTo as $vip):
                      $vKey = preg_replace('/[^a-zA-Z0-9]/', '', base64_encode($vip)); ?>
                      <span class="ip-tag" id="vip-tag-<?= $f['id'] ?>-<?= $vKey ?>">
                          <?= htmlspecialchars($vip) ?>
                          <button onclick="removeVisibleTo(
                              '<?= $f['id'] ?>',
                              '<?= htmlspecialchars($vip, ENT_QUOTES) ?>',
                              'vip-tag-<?= $f['id'] ?>-<?= $vKey ?>'
                          )">✕</button>

                      </span>
                  <?php endforeach; endif; ?>
              </div>
              <div class="input-row" style="margin-top:6px;">
                  <input type="text"
                        id="vip-input-<?= $f['id'] ?>"
                        placeholder="IP to add to visibility…">
                  <button class="btn btn-primary btn-sm"
                          onclick="addVisibleTo('<?= $f['id'] ?>')">+ Add</button>
              </div>

          </div>

          <div class="rules-sub">Allowed IPs</div>
          <div class="input-row">
            <input type="text" id="fip-allowed-<?= $f['id'] ?>" placeholder="IP / CIDR / wildcard">
            <button class="btn btn-primary btn-sm" onclick="addFileRule('<?= $f['id'] ?>','allowed')">Allow</button>
          </div>
          <div class="ip-tags" id="ftags-allowed-<?= $f['id'] ?>">
            <?php if (empty($fileRules['allowed'])): ?>
              <span class="no-tags">None</span>
            <?php else: foreach ($fileRules['allowed'] as $rip): ?>
              <div class="ip-tag">
                <?= htmlspecialchars($rip) ?>
                <span class="rm" onclick="removeFileRule('<?= $f['id'] ?>','allowed','<?= htmlspecialchars($rip) ?>')">✕</span>
              </div>
            <?php endforeach; endif; ?>
          </div>
          <div class="rules-sub mt">Denied IPs</div>
          <div class="input-row">
            <input type="text" id="fip-denied-<?= $f['id'] ?>" placeholder="IP / CIDR / wildcard">
            <button class="btn btn-danger btn-sm" onclick="addFileRule('<?= $f['id'] ?>','denied')">Deny</button>
          </div>
          <div class="ip-tags" id="ftags-denied-<?= $f['id'] ?>">
            <?php if (empty($fileRules['denied'])): ?>
              <span class="no-tags">None</span>
            <?php else: foreach ($fileRules['denied'] as $rip): ?>
              <div class="ip-tag">
                <?= htmlspecialchars($rip) ?>
                <span class="rm" onclick="removeFileRule('<?= $f['id'] ?>','denied','<?= htmlspecialchars($rip) ?>')">✕</span>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- FILE MANAGER -->
  <div class="panel" id="tab-manager">
    <?php if (empty($files)): ?>
      <div class="empty">No files uploaded yet.</div>
    <?php else: foreach ($files as $f):
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    ?>
      <div class="adm-file" id="mf-<?= $f['id'] ?>">
        <div class="adm-file-header">
          <div class="adm-file-icon"><?= fileIcon($ext) ?></div>
          <div class="adm-file-info">
            <div class="adm-file-name"><?= htmlspecialchars($f['name']) ?></div>
            <div class="adm-file-meta"><?= formatBytes($f['size']) ?> &bull; <?= $f['date'] ?> &bull; By <?= htmlspecialchars($f['uploader']) ?></div>
          </div>
          <div class="adm-file-actions">
            <a href="download.php?id=<?= urlencode($f['id']) ?>" class="btn btn-primary btn-sm">⬇</a>
            <button class="btn btn-danger btn-sm" onclick="deleteFile('<?= $f['id'] ?>','<?= addslashes($f['name']) ?>')">🗑</button>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- LOGS -->
  <div class="panel" id="tab-logs">
    <div class="card log-card">
      <div class="log-header">
        <span class="card-title">Recent Access Logs</span>
        <button class="btn btn-primary btn-sm" onclick="loadLogs()">Refresh</button>
      </div>
      <div class="log-scroll">
        <table class="log-table">
          <thead><tr><th>Time</th><th>IP</th><th>Action</th><th>File</th><th>Result</th></tr></thead>
          <tbody id="logBody">
            <tr><td colspan="5" class="log-empty">Click Refresh to load logs</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<div class="toast" id="toast"></div>
<script src="assets/js/admin.js"></script>
</body>
</html>
