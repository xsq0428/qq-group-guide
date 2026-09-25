<?php
/**
 * 卡密导出（CSV）。需后台登录态。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

if (!qc_installed() || qc_admin_id() <= 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '请先登录后台。';
    exit;
}

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['qc_export_token'] ?? ''), $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '导出校验失败，请刷新后台页面后重试。';
    exit;
}

$filter  = (string) ($_GET['filter'] ?? 'all');
$keyword = trim((string) ($_GET['keyword'] ?? ''));

$where = [];
$params = [];
if ($filter === 'used') {
    $where[] = '`used` = 1 AND (`expires_at` IS NULL OR `expires_at` >= NOW())';
} elseif ($filter === 'unused') {
    $where[] = '`used` = 0';
} elseif ($filter === 'expired') {
    $where[] = '`used` = 1 AND `expires_at` < NOW()';
} elseif ($filter === 'disabled') {
    $where[] = '`status` = 0';
}
if ($keyword !== '') {
    $where[] = '`code` LIKE ?';
    $params[] = '%' . $keyword . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$table = qc_table('cards');

$stmt = qc_db()->prepare("SELECT * FROM `{$table}`{$whereSql} ORDER BY `id` DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'cards_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['卡密', '时长', '状态', '激活时间', '到期时间', '激活IP', '创建时间']);

$now = time();
foreach ($rows as $row) {
    $state = '未使用';
    if ((int) $row['status'] !== 1) {
        $state = '已禁用';
    } elseif ((int) $row['used'] === 1) {
        $state = (!empty($row['expires_at']) && strtotime($row['expires_at']) < $now) ? '已过期' : '使用中';
    }
    fputcsv($out, [
        $row['code'],
        qc_format_hours((int) $row['duration_hours']),
        $state,
        $row['activated_at'] ?: '',
        $row['expires_at'] ?: '',
        $row['activated_ip'] ?? '',
        $row['created_at'],
    ]);
}

fclose($out);
qc_log('card_export', '导出卡密 ' . count($rows) . ' 张');
exit;
