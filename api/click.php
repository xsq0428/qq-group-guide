<?php
/**
 * 群点击统计接口（需通过卡密验证）。
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

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['code' => 1, 'msg' => '参数错误。']);
}

$table = qc_table('groups');
$stmt = qc_db()->prepare("UPDATE `{$table}` SET `clicks` = `clicks` + 1 WHERE `id` = ? AND `status` = 1");
$stmt->execute([$id]);

if ($stmt->rowCount() > 0) {
    qc_bump_stat('clicks');
}

json_response(['code' => 0, 'msg' => 'ok']);
