<?php
require 'config.php';
header('Content-Type: application/json');

$ip = getClientIP();
$a  = checkIPAccess($ip);
if (!$a['allowed']) { echo json_encode(['success'=>false,'error'=>'Access denied']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST only']); exit; }
if (empty($_FILES['file']))                { echo json_encode(['success'=>false,'error'=>'No file sent']); exit; }

$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success'=>false,'error'=>'Upload error '.$f['error']]); exit; }

$max = getMaxFileSize();
if ($f['size'] > $max) {
    echo json_encode(['success'=>false,'error'=>'File too large (max '.formatBytes($max).')']);
    exit;
}

$isPublic = !empty($_POST['public']);

$origName = basename($f['name']);
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$id       = bin2hex(random_bytes(8));
$saveName = $id . ($ext ? '.'.$ext : '');

if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $saveName)) {
    echo json_encode(['success'=>false,'error'=>'Failed to save file']); exit;
}

// Save file metadata — public uploads are recorded anonymously
$entry = ['id'=>$id,'name'=>$origName,'saveName'=>$saveName,'size'=>$f['size'],'ext'=>$ext,'date'=>date('Y-m-d H:i'),'uploader'=>$isPublic ? 'public' : $ip];

// One critical section for the whole commit. Without it, two uploads landing at
// once both read the old metadata array and the later write drops the earlier
// entry — leaving a blob in uploads/ that no page can see or delete.
withLock(function () use ($entry, $id, $ip, $isPublic, $origName) {
    $meta = loadFilesMeta();
    array_unshift($meta, $entry);
    saveFilesMeta($meta);

    if (!$isPublic) {
        // Auto-restrict access + visibility to the admin-managed default IPs + uploader
        $rules   = loadIPRules();
        $allowed = array_values(array_unique(array_merge(getDefaultIPs(), [$ip])));
        // visible_to matches allowed: only those IPs see this file in the hub index
        $rules['files'][$id] = ['allowed' => $allowed, 'denied' => [], 'visible_to' => $allowed];
        saveIPRules($rules);
    }
    // Public uploads get no per-file rules at all, so checkFileVisibility() shows the
    // file to everyone and checkIPAccess() falls through to the global rules.

    logAccess($ip, $isPublic ? 'upload (public)' : 'upload', $origName, true);
});

echo json_encode(['success'=>true,'file'=>[
    'id'   => $id,
    'name' => $origName,
    'size' => formatBytes($f['size']),
    'date' => $entry['date'],
    'icon' => fileIcon($ext),
]]);
