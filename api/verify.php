<?php
/**
 * 卡密验证接口。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

if (!qc_installed()) {
    json_response(['code' => 1, 'msg' => '系统尚未安装完成。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['code' => 1, 'msg' => '请求方式不正确。'], 405);
}

qc_ensure_schema();

/* 限速：按 IP，连续失败 15 次锁定 10 分钟。 */
$now = time();
$rlKey = 'card:' . qc_ip();
$remain = qc_rate_blocked($rlKey);
if ($remain > 0) {
    json_response(['code' => 1, 'msg' => '尝试次数过多，请 ' . ceil($remain / 60) . ' 分钟后再试。']);
}

$code = strtoupper(trim((string) ($_POST['code'] ?? '')));
$code = preg_replace('/\s+/', '', $code);

if ($code === '' || strlen($code) > 64) {
    json_response(['code' => 1, 'msg' => '请输入有效的卡密。']);
}

$table = qc_table('cards');
$stmt = qc_db()->prepare("SELECT * FROM `{$table}` WHERE `code` = ? LIMIT 1");

$fail = static function (string $msg) use ($rlKey): void {
    qc_rate_hit($rlKey, 15, 600, 600);
    json_response(['code' => 1, 'msg' => $msg]);
};

$stmt->execute([$code]);
$card = $stmt->fetch();

if (!$card) {
    qc_log('card_fail', '卡密不存在：' . $code);
    $fail('卡密不存在，请核对后重试。');
}
if ((int) $card['status'] !== 1) {
    $fail('该卡密已被禁用。');
}

$bind = qc_setting('card_bind', 'none');
$expiresAt = $card['expires_at'];

if ((int) $card['used'] === 0) {
    $hours = max(1, (int) $card['duration_hours']);
    $expiresAt = date('Y-m-d H:i:s', $now + $hours * 3600);
    $update = qc_db()->prepare(
        "UPDATE `{$table}` SET `used` = 1, `activated_at` = NOW(), `expires_at` = ?, `activated_ip` = ?
         WHERE `id` = ? AND `used` = 0"
    );
    $update->execute([$expiresAt, qc_ip(), $card['id']]);

    if ($update->rowCount() > 0) {
        if ($bind === 'device') {
            $token = bin2hex(random_bytes(16));
            $card['bind_token'] = hash('sha256', $token);
            qc_db()->prepare("UPDATE `{$table}` SET `bind_token` = ? WHERE `id` = ?")
                ->execute([$card['bind_token'], $card['id']]);
            qc_set_device_cookie($token);
        }
        qc_bump_stat('activations');
        qc_log('card_activate', '卡密激活：' . $code);
    } else {
        // 并发下已被其他请求激活，重新读取后按已使用处理。
        $stmt->execute([$code]);
        $card = $stmt->fetch() ?: $card;
        $expiresAt = $card['expires_at'];
    }
}

if ((int) $card['used'] === 1) {
    if (!empty($expiresAt) && strtotime($expiresAt) < $now) {
        $fail('该卡密已过期。');
    }

    if ($bind === 'ip') {
        if ($card['activated_ip'] !== '' && $card['activated_ip'] !== qc_ip()) {
            qc_log('card_bind_block', 'IP 绑定拦截：' . $code);
            $fail('该卡密已绑定其他 IP，如需更换请联系管理员。');
        }
    } elseif ($bind === 'device') {
        $bound = (string) ($card['bind_token'] ?? '');
        $token = qc_device_token();
        if ($bound !== '' && ($token === '' || !hash_equals($bound, hash('sha256', $token)))) {
            qc_log('card_bind_block', '设备绑定拦截：' . $code);
            $fail('该卡密已绑定其他设备，如需更换请联系管理员。');
        }
    }
}

qc_rate_clear($rlKey);
$_SESSION['qc_card_id'] = (int) $card['id'];

json_response([
    'code'       => 0,
    'msg'        => '验证成功。',
    'expires_at' => $expiresAt,
]);
