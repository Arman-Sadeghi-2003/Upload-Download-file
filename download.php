<?php
require 'config.php';

$ip    = getClientIP();
$id    = trim($_GET['id'] ?? '');
$files = loadFilesMeta();
$entry = null;

foreach ($files as $f) if ($f['id'] === $id) { $entry = $f; break; }

if (!$entry)                            { http_response_code(404); exit('404 — File not found'); }

$access = checkIPAccess($ip, $id);
logAccess($ip, 'download', $entry['name'], $access['allowed']);

if (!$access['allowed'])                { http_response_code(403); exit('403 — Access denied: '.$access['reason']); }

$path = UPLOAD_DIR . $entry['saveName'];
if (!file_exists($path))                { http_response_code(404); exit('404 — File missing on disk'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($entry['name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
