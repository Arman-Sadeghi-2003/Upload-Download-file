<?php
require 'config.php';

$ip    = getClientIP();
$id    = trim($_GET['id'] ?? '');
$files = loadFilesMeta();
$entry = null;

foreach ($files as $f) {
    if ($f['id'] === $id) {
        $entry = $f;
        break;
    }
}

if (!$entry) {
    http_response_code(404);
    exit('404 — File not found');
}

$access = checkIPAccess($ip, $id);
logAccess($ip, 'download', $entry['name'], $access['allowed']);

if (!$access['allowed']) {
    http_response_code(403);
    exit('403 — Access denied: ' . $access['reason']);
}

$path = UPLOAD_DIR . $entry['saveName'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('404 — File missing on disk');
}

$size = filesize($path);
$filename = $entry['name'];
$mime = function_exists('mime_content_type') ? mime_content_type($path) : 'application/octet-stream';

@set_time_limit(0);
ignore_user_abort(true);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$start = 0;
$end = $size - 1;
$statusCode = 200;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int)$m[1];
    }
    if ($m[2] !== '') {
        $end = (int)$m[2];
    }

    if ($m[1] === '' && $m[2] !== '') {
        $suffix = (int)$m[2];
        if ($suffix > 0) {
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        }
    }

    if ($start > $end || $start >= $size || $end >= $size) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        header("Content-Range: bytes */$size");
        exit;
    }

    $statusCode = 206;
}

$length = $end - $start + 1;

if ($statusCode === 206) {
    header('HTTP/1.1 206 Partial Content');
} else {
    header('HTTP/1.1 200 OK');
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($statusCode === 206) {
    header("Content-Range: bytes $start-$end/$size");
}

$chunkSize = 1024 * 1024; // 1 MB
$fp = fopen($path, 'rb');

if ($fp === false) {
    http_response_code(500);
    exit('500 — Failed to open file');
}

fseek($fp, $start);

$bytesLeft = $length;
while ($bytesLeft > 0 && !feof($fp) && connection_status() === CONNECTION_NORMAL) {
    $read = ($bytesLeft > $chunkSize) ? $chunkSize : $bytesLeft;
    $buffer = fread($fp, $read);
    if ($buffer === false) {
        break;
    }

    echo $buffer;
    flush();

    $bytesLeft -= strlen($buffer);
}

fclose($fp);
exit;