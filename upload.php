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

$entry = ['id'=>$id,'name'=>$origName,'saveName'=>$saveName,'size'=>$f['size'],'ext'=>$ext,'date'=>date('Y-m-d H:i'),'uploader'=>$ip];
$meta  = loadFilesMeta();
array_unshift($meta, $entry);
saveFilesMeta($meta);
logAccess($ip, 'upload', $origName, true);

echo json_encode(['success'=>true,'file'=>[
    'id'   => $id,
    'name' => $origName,
    'size' => formatBytes($f['size']),
    'date' => $entry['date'],
    'icon' => fileIcon($ext),
]]);
