<?php
require 'config.php';
header('Content-Type: application/json');

if (!isAdmin()) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'set_mode':
        $rules = loadIPRules();
        $rules['mode'] = ($_POST['mode'] === 'whitelist') ? 'whitelist' : 'blacklist';
        saveIPRules($rules);
        echo json_encode(['success'=>true,'mode'=>$rules['mode']]);
        break;

    case 'add_global':
        $ip = trim($_POST['ip'] ?? '');
        if (!$ip) { echo json_encode(['success'=>false,'error'=>'No IP provided']); break; }
        $rules = loadIPRules();
        if (!in_array($ip, $rules['global'])) $rules['global'][] = $ip;
        saveIPRules($rules);
        echo json_encode(['success'=>true]);
        break;

    case 'remove_global':
        $ip = trim($_POST['ip'] ?? '');
        $rules = loadIPRules();
        $rules['global'] = array_values(array_filter($rules['global'], fn($r) => $r !== $ip));
        saveIPRules($rules);
        echo json_encode(['success'=>true]);
        break;

    case 'add_file_rule':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        $type   = in_array($_POST['type'], ['allowed','denied']) ? $_POST['type'] : 'denied';
        if (!$fileId || !$ip) { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
        $rules = loadIPRules();
        if (!isset($rules['files'][$fileId])) $rules['files'][$fileId] = ['allowed'=>[],'denied'=>[]];
        if (!in_array($ip, $rules['files'][$fileId][$type]))
            $rules['files'][$fileId][$type][] = $ip;
        saveIPRules($rules);
        echo json_encode(['success'=>true]);
        break;

    case 'remove_file_rule':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        $type   = in_array($_POST['type'], ['allowed','denied']) ? $_POST['type'] : 'denied';
        $rules  = loadIPRules();
        if (isset($rules['files'][$fileId][$type]))
            $rules['files'][$fileId][$type] = array_values(
                array_filter($rules['files'][$fileId][$type], fn($r) => $r !== $ip)
            );
        saveIPRules($rules);
        echo json_encode(['success'=>true]);
        break;

    case 'delete_file':
        $fileId = $_POST['file_id'] ?? '';
        $meta   = loadFilesMeta();
        foreach ($meta as $f) if ($f['id'] === $fileId) { @unlink(UPLOAD_DIR . $f['saveName']); break; }
        $meta = array_values(array_filter($meta, fn($f) => $f['id'] !== $fileId));
        saveFilesMeta($meta);
        $rules = loadIPRules();
        unset($rules['files'][$fileId]);
        saveIPRules($rules);
        echo json_encode(['success'=>true]);
        break;

    case 'get_logs':
        $log = file_exists(ACCESS_LOG_FILE)
            ? (json_decode(file_get_contents(ACCESS_LOG_FILE), true) ?? []) : [];
        echo json_encode(['success'=>true,'logs'=>array_reverse($log)]);
        break;

    case 'get_settings':
        echo json_encode(['success'=>true,'maxFileSize'=>getMaxFileSize()]);
        break;

    case 'set_max_file_size':
        $bytes = (int)($_POST['bytes'] ?? 0);
        $s = loadSettings();
        $s['maxFileSize'] = $bytes;
        saveSettings($s);
        echo json_encode(['success'=>true,'maxFileSize'=>getMaxFileSize()]);
        break;

    case 'add_visible_to':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        if (!$fileId || !$ip) { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
        $rules = loadIPRules();
        if (!isset($rules['files'][$fileId]))
            $rules['files'][$fileId] = ['allowed'=>[],'denied'=>[],'visible_to'=>[]];
        if (!isset($rules['files'][$fileId]['visible_to']))
            $rules['files'][$fileId]['visible_to'] = [];
        if (!in_array($ip, $rules['files'][$fileId]['visible_to']))
            $rules['files'][$fileId]['visible_to'][] = $ip;
        saveIPRules($rules);
        echo json_encode(['success'=>true]);
        break;

    case 'remove_visible_to':
        $fileId = $_POST['file_id'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        $rules  = loadIPRules();
        if (isset($rules['files'][$fileId]['visible_to']))
            $rules['files'][$fileId]['visible_to'] = array_values(
                array_filter($rules['files'][$fileId]['visible_to'], fn($r) => $r !== $ip)
            );
        saveIPRules($rules);
        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['success'=>false,'error'=>'Unknown action']);
}
