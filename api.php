<?php
require 'config.php';
header('Content-Type: application/json');

if (!isAdmin()) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Every handler that reads-modifies-writes a file in data/ runs inside
// withLock(), so it cannot interleave with a concurrent upload or another admin
// action. Parameter validation stays outside the lock — it touches no state.
switch ($action) {

    case 'set_mode':
        echo json_encode(withLock(function () {
            $rules = loadIPRules();
            $rules['mode'] = ($_POST['mode'] === 'whitelist') ? 'whitelist' : 'blacklist';
            saveIPRules($rules);
            return ['success'=>true,'mode'=>$rules['mode']];
        }));
        break;

    case 'add_global':
        $ip = trim($_POST['ip'] ?? '');
        if (!$ip) { echo json_encode(['success'=>false,'error'=>'No IP provided']); break; }
        echo json_encode(withLock(function () use ($ip) {
            $rules = loadIPRules();
            if (!in_array($ip, $rules['global'])) $rules['global'][] = $ip;
            saveIPRules($rules);
            return ['success'=>true];
        }));
        break;

    case 'remove_global':
        $ip = trim($_POST['ip'] ?? '');
        echo json_encode(withLock(function () use ($ip) {
            $rules = loadIPRules();
            $rules['global'] = array_values(array_filter($rules['global'], fn($r) => $r !== $ip));
            saveIPRules($rules);
            return ['success'=>true];
        }));
        break;

    case 'add_file_rule':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        $type   = in_array($_POST['type'], ['allowed','denied']) ? $_POST['type'] : 'denied';
        if (!$fileId || !$ip) { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
        echo json_encode(withLock(function () use ($fileId, $ip, $type) {
            $rules = loadIPRules();
            if (!isset($rules['files'][$fileId])) $rules['files'][$fileId] = ['allowed'=>[],'denied'=>[]];
            if (!in_array($ip, $rules['files'][$fileId][$type]))
                $rules['files'][$fileId][$type][] = $ip;
            saveIPRules($rules);
            return ['success'=>true];
        }));
        break;

    case 'remove_file_rule':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        $type   = in_array($_POST['type'], ['allowed','denied']) ? $_POST['type'] : 'denied';
        echo json_encode(withLock(function () use ($fileId, $ip, $type) {
            $rules = loadIPRules();
            if (isset($rules['files'][$fileId][$type]))
                $rules['files'][$fileId][$type] = array_values(
                    array_filter($rules['files'][$fileId][$type], fn($r) => $r !== $ip)
                );
            saveIPRules($rules);
            return ['success'=>true];
        }));
        break;

    case 'delete_file':
        $fileId = $_POST['file_id'] ?? '';
        echo json_encode(withLock(function () use ($fileId) {
            $meta = loadFilesMeta();
            foreach ($meta as $f) if ($f['id'] === $fileId) { @unlink(UPLOAD_DIR . $f['saveName']); break; }
            $meta = array_values(array_filter($meta, fn($f) => $f['id'] !== $fileId));
            saveFilesMeta($meta);
            $rules = loadIPRules();
            unset($rules['files'][$fileId]);
            saveIPRules($rules);
            return ['success'=>true];
        }));
        break;

    case 'delete_files':
        $ids = json_decode($_POST['file_ids'] ?? '', true);
        if (!is_array($ids)) { echo json_encode(['success'=>false,'error'=>'No files selected']); break; }
        // IDs are bin2hex(random_bytes(8)) — anything else never came from this app
        $ids = array_values(array_filter($ids,
            fn($i) => is_string($i) && preg_match('/^[a-f0-9]{16}$/', $i)));
        if (!$ids) { echo json_encode(['success'=>false,'error'=>'No valid file IDs']); break; }

        echo json_encode(withLock(function () use ($ids) {
            $meta    = loadFilesMeta();
            $deleted = [];

            // saveName comes from the metadata, never from the request, so the
            // unlink target cannot be steered by what the client sent
            foreach ($meta as $f) {
                if (!in_array($f['id'], $ids, true)) continue;
                @unlink(UPLOAD_DIR . $f['saveName']);
                $deleted[] = $f['id'];
            }
            if (!$deleted) return ['success'=>true,'deleted'=>[]];

            // One rewrite for the whole batch. Deleting 40 files through
            // delete_file would take the lock 40 times and rewrite both JSON
            // files 40 times over.
            saveFilesMeta(array_values(array_filter($meta,
                fn($f) => !in_array($f['id'], $deleted, true))));

            $rules = loadIPRules();
            foreach ($deleted as $id) unset($rules['files'][$id]);
            saveIPRules($rules);

            // Destructive and bulk — worth one audit row. One per batch rather
            // than one per file, so a big delete cannot flush the 1000-entry log.
            logAccess(getClientIP(), 'delete (bulk)', count($deleted) . ' file(s)', true);

            return ['success'=>true,'deleted'=>$deleted];
        }));
        break;

    case 'get_logs':
        $log = file_exists(ACCESS_LOG_FILE)
            ? (json_decode(file_get_contents(ACCESS_LOG_FILE), true) ?? []) : [];
        // Categorise server-side so the log filter and the hub's file filter
        // share one definition of the buckets — see fileCategory() in config.php
        $log = array_map(
            fn($r) => $r + ['type' => fileCategory((string)($r['file'] ?? ''))],
            array_reverse($log)
        );
        echo json_encode(['success'=>true,'logs'=>$log]);
        break;

    case 'get_settings':
        echo json_encode(['success'=>true,'maxFileSize'=>getMaxFileSize(),'defaultIPs'=>getDefaultIPs()]);
        break;

    case 'add_default_ip':
        $ip = trim($_POST['ip'] ?? '');
        if (!$ip) { echo json_encode(['success'=>false,'error'=>'No IP provided']); break; }
        echo json_encode(withLock(function () use ($ip) {
            $list   = getDefaultIPs();
            $list[] = $ip;
            saveDefaultIPs($list);
            return ['success'=>true,'defaultIPs'=>getDefaultIPs()];
        }));
        break;

    case 'remove_default_ip':
        $ip = trim($_POST['ip'] ?? '');
        if ($ip === '::1') {
            echo json_encode(['success'=>false,'error'=>'Localhost cannot be removed']); break;
        }
        echo json_encode(withLock(function () use ($ip) {
            saveDefaultIPs(array_filter(getDefaultIPs(), fn($r) => $r !== $ip));
            return ['success'=>true,'defaultIPs'=>getDefaultIPs()];
        }));
        break;

    case 'set_max_file_size':
        $bytes = (int)($_POST['bytes'] ?? 0);
        echo json_encode(withLock(function () use ($bytes) {
            $s = loadSettings();
            $s['maxFileSize'] = $bytes;
            saveSettings($s);
            return ['success'=>true,'maxFileSize'=>getMaxFileSize()];
        }));
        break;

    case 'add_visible_to':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        if (!$fileId || !$ip) { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
        echo json_encode(withLock(function () use ($fileId, $ip) {
            $rules = loadIPRules();
            if (!isset($rules['files'][$fileId]))
                $rules['files'][$fileId] = ['allowed'=>[],'denied'=>[],'visible_to'=>[]];
            if (!isset($rules['files'][$fileId]['visible_to']))
                $rules['files'][$fileId]['visible_to'] = [];
            if (!in_array($ip, $rules['files'][$fileId]['visible_to']))
                $rules['files'][$fileId]['visible_to'][] = $ip;
            saveIPRules($rules);
            return ['success'=>true];
        }));
        break;

    case 'remove_visible_to':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        echo json_encode(withLock(function () use ($fileId, $ip) {
            $rules = loadIPRules();
            if (isset($rules['files'][$fileId]['visible_to']))
                $rules['files'][$fileId]['visible_to'] = array_values(
                    array_filter($rules['files'][$fileId]['visible_to'], fn($r) => $r !== $ip)
                );
            saveIPRules($rules);
            return ['success'=>true];
        }));
        break;

    default:
        echo json_encode(['success'=>false,'error'=>'Unknown action']);
}
