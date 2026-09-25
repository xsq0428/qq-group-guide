<?php
/**
 * 群加群链接实时探测接口（需通过卡密验证）。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

if (!qc_installed()) {
    json_response(['code' => 1, 'msg' => '系统尚未安装完成。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['code' => 1, 'msg' => '请求方式不正确。'], 405);
}
if (!qc_card_valid()) {
    json_response(['code' => 1, 'msg' => '请先验证卡密。'], 403);
}

$raw = (string) ($_POST['ids'] ?? '');
$ids = array_values(array_filter(array_map('intval', explode(',', $raw)), static fn(int $id): bool => $id > 0));
if (!$ids) {
    json_response(['code' => 0, 'data' => []]);
}
$ids = array_slice($ids, 0, 200);
$in = implode(',', $ids);

$table = qc_table('groups');
$groups = qc_db()->query(
    "SELECT `id`, `group_no`, `link` FROM `{$table}` WHERE `id` IN ({$in}) AND `status` = 1"
)->fetchAll();

$statuses = qc_check_groups_status($groups);

$data = [];
foreach ($statuses as $id => $row) {
    $data[] = [
        'id'  => (int) $id,
        'ok'  => (bool) $row['ok'],
        'ms'  => (int) $row['ms'],
        'code' => (int) $row['code'],
    ];
}

json_response(['code' => 0, 'data' => $data]);
