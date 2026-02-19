<?php
session_start();

define('UPLOAD_DIR',      __DIR__ . '/uploads/');
define('DATA_DIR',        __DIR__ . '/data/');
define('IP_RULES_FILE',   DATA_DIR . 'ip_rules.json');
define('FILES_META_FILE', DATA_DIR . 'files_meta.json');
define('ACCESS_LOG_FILE', DATA_DIR . 'access_log.json');
define('ADMIN_PASSWORD',  'admin123');       // ← Change this!
define('MAX_FILE_SIZE',   100 * 1024 * 1024); // 100 MB

foreach ([UPLOAD_DIR, DATA_DIR] as $dir)
    if (!is_dir($dir)) mkdir($dir, 0755, true);

// ── IP Helpers ────────────────────────────────────────────────────────────────
function getClientIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h)
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    return '0.0.0.0';
}

function ipMatchesRule(string $ip, string $rule): bool {
    if ($ip === $rule) return true;
    if (str_contains($rule, '/')) {                   // CIDR: 192.168.1.0/24
        [$subnet, $bits] = explode('/', $rule, 2);
        $mask = -1 << (32 - (int)$bits);
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
    if (str_contains($rule, '*')) {                   // Wildcard: 192.168.*.*
        $pat = '/^' . str_replace(['.','*'], ['\.','[0-9]{1,3}'], $rule) . '$/';
        return (bool)preg_match($pat, $ip);
    }
    return false;
}

// Returns ['allowed'=>bool, 'reason'=>string]
function checkIPAccess(string $ip, ?string $fileId = null): array {
    $rules = loadIPRules();
    $mode  = $rules['mode'] ?? 'blacklist';   // 'blacklist' | 'whitelist'

    // Per-file overrides (checked first)
    if ($fileId && isset($rules['files'][$fileId])) {
        foreach ($rules['files'][$fileId]['denied']  ?? [] as $r)
            if (ipMatchesRule($ip, $r)) return ['allowed'=>false,'reason'=>'Blocked for this file'];
        foreach ($rules['files'][$fileId]['allowed'] ?? [] as $r)
            if (ipMatchesRule($ip, $r)) return ['allowed'=>true, 'reason'=>'Allowed for this file'];
    }

    $global = $rules['global'] ?? [];

    if ($mode === 'whitelist') {
        if (in_array($ip, ['127.0.0.1','::1']))
            return ['allowed'=>true,'reason'=>'Localhost always allowed'];
        foreach ($global as $r)
            if (ipMatchesRule($ip, $r)) return ['allowed'=>true,'reason'=>'In whitelist'];
        return empty($global)
            ? ['allowed'=>true, 'reason'=>'No rules defined yet']
            : ['allowed'=>false,'reason'=>'Not in whitelist'];
    }

    // Blacklist mode (default)
    foreach ($global as $r)
        if (ipMatchesRule($ip, $r)) return ['allowed'=>false,'reason'=>'Globally blacklisted'];
    return ['allowed'=>true,'reason'=>'OK'];
}

// ── Data Helpers ──────────────────────────────────────────────────────────────
function loadIPRules(): array {
    if (!file_exists(IP_RULES_FILE)) return ['mode'=>'blacklist','global'=>[],'files'=>[]];
    return json_decode(file_get_contents(IP_RULES_FILE), true) ?? ['mode'=>'blacklist','global'=>[],'files'=>[]];
}
function saveIPRules(array $r): void {
    file_put_contents(IP_RULES_FILE, json_encode($r, JSON_PRETTY_PRINT));
}
function loadFilesMeta(): array {
    if (!file_exists(FILES_META_FILE)) return [];
    return json_decode(file_get_contents(FILES_META_FILE), true) ?? [];
}
function saveFilesMeta(array $m): void {
    file_put_contents(FILES_META_FILE, json_encode($m, JSON_PRETTY_PRINT));
}
function logAccess(string $ip, string $action, string $file, bool $granted): void {
    $log = file_exists(ACCESS_LOG_FILE) ? (json_decode(file_get_contents(ACCESS_LOG_FILE),true) ?? []) : [];
    $log[] = ['t'=>date('Y-m-d H:i:s'),'ip'=>$ip,'action'=>$action,'file'=>$file,'ok'=>$granted];
    if (count($log) > 1000) $log = array_slice($log, -1000);
    file_put_contents(ACCESS_LOG_FILE, json_encode($log, JSON_PRETTY_PRINT));
}
function formatBytes(int $bytes): string {
    $u = ['B','KB','MB','GB'];
    $p = $bytes > 0 ? min((int)floor(log($bytes,1024)), 3) : 0;
    return round($bytes / 1024**$p, 2) . ' ' . $u[$p];
}
function fileIcon(string $ext): string {
    return match(strtolower($ext)) {
        'pdf'                                  => '📄',
        'jpg','jpeg','png','gif','webp','svg'  => '🖼️',
        'mp4','mkv','avi','mov','webm'          => '🎬',
        'mp3','wav','flac','ogg'               => '🎵',
        'zip','rar','7z','tar','gz'            => '📦',
        'doc','docx'                           => '📝',
        'xls','xlsx'                           => '📊',
        'txt','md','log'                       => '📃',
        'php','js','ts','py','cs','html','css','json' => '💻',
        'exe','msi','apk'                      => '⚙️',
        default                                => '📁',
    };
}
function isAdmin(): bool {
    return isset($_SESSION['hub_admin']) && $_SESSION['hub_admin'] === true;
}
