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

$origName = basename($f['name']);
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$id       = bin2hex(random_bytes(8));
$saveName = $id . ($ext ? '.'.$ext : '');

if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $saveName)) {
    echo json_encode(['success'=>false,'error'=>'Failed to save file']); exit;
}

// Save file metadata
$entry = ['id'=>$id,'name'=>$origName,'saveName'=>$saveName,'size'=>$f['size'],'ext'=>$ext,'date'=>date('Y-m-d H:i'),'uploader'=>$ip];
$meta  = loadFilesMeta();
array_unshift($meta, $entry);
saveFilesMeta($meta);

// Auto-restrict access + visibility: admin (::1 / 127.0.0.1) + uploader only
$rules     = loadIPRules();
$allowed   = ['::1'];
if (!in_array($ip, $allowed)) $allowed[] = $ip;
// visible_to: only uploader (+ localhost) can see this file in the hub index by default
$visibleTo = array_values(array_unique(['::1', '127.0.0.1', $ip]));
$rules['files'][$id] = ['allowed' => $allowed, 'denied' => [], 'visible_to' => $visibleTo];
saveIPRules($rules);

logAccess($ip, 'upload', $origName, true);

echo json_encode(['success'=>true,'file'=>[
    'id'   => $id,
    'name' => $origName,
    'size' => formatBytes($f['size']),
    'date' => $entry['date'],
    'icon' => fileIcon($ext),
]]);
