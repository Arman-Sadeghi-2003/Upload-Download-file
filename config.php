<?php
session_start();

define('UPLOAD_DIR',      __DIR__ . '/uploads/');
define('DATA_DIR',        __DIR__ . '/data/');
define('IP_RULES_FILE',   DATA_DIR . 'iprules.json');
define('FILES_META_FILE', DATA_DIR . 'filesmeta.json');
define('ACCESS_LOG_FILE', DATA_DIR . 'accesslog.json');
define('SETTINGS_FILE',   DATA_DIR . 'settings.json');
define('ADMIN_PASSWORD',  'ThisIs-159753'); // Change this!
define('MAX_FILE_SIZE',    4 * 1024 * 1024 * 1024); // 4 GB default fallback

// Fallback only — the live list is managed from the admin panel and stored in
// settings.json. Localhost is always kept so the admin can never be locked out
// of files uploaded by others.
define('DEFAULT_ACCESS_IPS', ['::1']);

foreach ([UPLOAD_DIR, DATA_DIR] as $dir)
    if (!is_dir($dir)) mkdir($dir, 0755, true);

// ── Settings Helpers ──────────────────────────────────────────────────────────
function loadSettings(): array {
    $defaults = ['maxFileSize' => MAX_FILE_SIZE, 'defaultIPs' => DEFAULT_ACCESS_IPS];
    if (!file_exists(SETTINGS_FILE)) return $defaults;
    $s = json_decode(file_get_contents(SETTINGS_FILE), true);
    return is_array($s) ? array_merge($defaults, $s) : $defaults;
}

function saveSettings(array $s): void {
    file_put_contents(SETTINGS_FILE, json_encode($s, JSON_PRETTY_PRINT));
}

function getMaxFileSize(): int {
    $s = loadSettings();
    $v = (int)($s['maxFileSize'] ?? MAX_FILE_SIZE);
    if ($v < 1 * 1024 * 1024)           $v = 1 * 1024 * 1024;           // min 1 MB
    if ($v > 5 * 1024 * 1024 * 1024)    $v = 5 * 1024 * 1024 * 1024;   // max 5 GB
    return $v;
}

// Default IPs granted access + visibility on every new private upload.
// '::1' is always included so the admin keeps reach over every file.
function getDefaultIPs(): array {
    $s    = loadSettings();
    $list = is_array($s['defaultIPs'] ?? null) ? $s['defaultIPs'] : DEFAULT_ACCESS_IPS;
    $list = array_filter(array_map('trim', $list), fn($v) => $v !== '');
    return array_values(array_unique(array_merge(['::1'], $list)));
}

function saveDefaultIPs(array $list): void {
    $s = loadSettings();
    $s['defaultIPs'] = array_values(array_unique(
        array_filter(array_map('trim', $list), fn($v) => $v !== '')
    ));
    saveSettings($s);
}

// ── IP Helpers ────────────────────────────────────────────────────────────────
function getClientIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function ipMatchesRule(string $ip, string $rule): bool {
    if ($ip === $rule) return true;
    if (str_contains($rule, '/')) {
        [$subnet, $bits] = explode('/', $rule, 2);
        $mask = -1 << (32 - (int)$bits);
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
    if (str_contains($rule, '*')) {
        $pat = '/^' . str_replace(['.','*'], ['\.','[0-9]{1,3}'], $rule) . '$/';
        return (bool)preg_match($pat, $ip);
    }
    return false;
}

// Returns ['allowed'=>bool, 'reason'=>string]
function checkIPAccess(string $ip, ?string $fileId = null): array {
    $rules = loadIPRules();
    $mode  = $rules['mode'] ?? 'blacklist';

    if ($fileId && isset($rules['files'][$fileId])) {
        $fileRules   = $rules['files'][$fileId];
        $deniedList  = $fileRules['denied']  ?? [];
        $allowedList = $fileRules['allowed'] ?? [];

        // Always check denied first
        foreach ($deniedList as $r)
            if (ipMatchesRule($ip, $r)) return ['allowed'=>false,'reason'=>'Blocked for this file'];

        // If there are allowed IPs, act as whitelist for this file
        if (!empty($allowedList)) {
            foreach ($allowedList as $r)
                if (ipMatchesRule($ip, $r)) return ['allowed'=>true,'reason'=>'Allowed for this file'];
            return ['allowed'=>false,'reason'=>'Not in allowed list for this file'];
        }
    }

    // No per-file rules → fall through to global rules
    $global = $rules['global'] ?? [];
    if ($mode === 'whitelist') {
        if (in_array($ip, ['127.0.0.1','::1']))
            return ['allowed'=>true,'reason'=>'Localhost always allowed'];
        foreach ($global as $r)
            if (ipMatchesRule($ip, $r)) return ['allowed'=>true,'reason'=>'In whitelist'];
        return empty($global)
            ? ['allowed'=>true,  'reason'=>'No rules defined yet']
            : ['allowed'=>false, 'reason'=>'Not in whitelist'];
    }

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
        'pdf'                                          => '📄',
        'jpg','jpeg','png','gif','webp','svg'           => '🖼️',
        'mp4','mkv','avi','mov','webm'                  => '🎬',
        'mp3','wav','flac','ogg'                        => '🎵',
        'zip','rar','7z','tar','gz'                     => '📦',
        'doc','docx'                                    => '📝',
        'xls','xlsx'                                    => '📊',
        'txt','md','log'                                => '📃',
        'php','js','ts','py','cs','html','css','json'   => '💻',
        'exe','msi','apk'                               => '⚙️',
        default                                         => '📁',
    };
}

function isAdmin(): bool {
    return isset($_SESSION['hub_admin']) && $_SESSION['hub_admin'] === true;
}

// Returns true if the IP can SEE this file in the hub listing
function checkFileVisibility(string $ip, string $fileId): bool {
    $rules     = loadIPRules();
    if (!isset($rules['files'][$fileId])) return true;
    $visibleTo = $rules['files'][$fileId]['visible_to'] ?? [];
    if (empty($visibleTo)) return true;          // no restriction → show to all
    foreach ($visibleTo as $r)
        if (ipMatchesRule($ip, $r)) return true;
    return false;
}
