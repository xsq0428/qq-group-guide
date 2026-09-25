<?php
/**
 * 后台接口。所有写操作要求登录 + CSRF 校验。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

if (!qc_installed()) {
    json_response(['code' => 1, 'msg' => '系统尚未安装完成。']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['code' => 1, 'msg' => '请求方式不正确。'], 405);
}

$action = (string) ($_POST['action'] ?? '');

/* ----------------------------- 登录相关 ----------------------------- */

if ($action === 'login') {
    qc_ensure_schema();
    $rlKey = 'admin:' . qc_ip();
    $remain = qc_rate_blocked($rlKey);
    if ($remain > 0) {
        json_response(['code' => 1, 'msg' => '尝试次数过多，请 ' . ceil($remain / 60) . ' 分钟后再试。']);
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $table = qc_table('admins');
    $stmt = qc_db()->prepare("SELECT * FROM `{$table}` WHERE `username` = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password'])) {
        qc_rate_hit($rlKey, 10, 600, 600);
        qc_log('admin_fail', '登录失败：' . $username);
        json_response(['code' => 1, 'msg' => '账号或密码错误。']);
    }

    qc_rate_clear($rlKey);
    session_regenerate_id(true);
    $_SESSION['qc_admin_id'] = (int) $admin['id'];
    $_SESSION['qc_admin_name'] = $admin['username'];
    $_SESSION['qc_admin_ver'] = (int) ($admin['token_version'] ?? 1);
    $_SESSION['qc_csrf'] = bin2hex(random_bytes(16));

    $update = qc_db()->prepare("UPDATE `{$table}` SET `last_login_at` = NOW(), `last_login_ip` = ? WHERE `id` = ?");
    $update->execute([qc_ip(), $admin['id']]);
    qc_log('admin_login', '登录成功：' . $username);

    json_response(['code' => 0, 'msg' => '登录成功。', 'data' => ['csrf' => $_SESSION['qc_csrf']]]);
}

if ($action === 'logout') {
    qc_log('admin_logout', '退出登录');
    $_SESSION = [];
    session_destroy();
    json_response(['code' => 0, 'msg' => '已退出。']);
}

/* ----------------------------- 鉴权 ----------------------------- */

qc_admin_required();
qc_csrf_check();
qc_ensure_schema();

switch ($action) {
    case 'bootstrap':
        json_response(['code' => 0, 'data' => qc_admin_bootstrap()]);

    case 'save_settings':
        qc_save_settings($_POST);
        json_response(['code' => 0, 'msg' => '配置已保存。']);

    case 'change_password':
        qc_change_password($_POST);
        json_response(['code' => 0, 'msg' => '密码已更新，请重新登录。']);

    case 'group_save':
        json_response(['code' => 0, 'msg' => '群信息已保存。', 'data' => qc_group_save($_POST)]);

    case 'group_delete':
        qc_group_delete((int) ($_POST['id'] ?? 0));
        json_response(['code' => 0, 'msg' => '已删除。']);

    case 'card_generate':
        json_response(['code' => 0, 'msg' => '卡密生成完成。', 'data' => qc_card_generate($_POST)]);

    case 'card_toggle':
        qc_card_toggle((int) ($_POST['id'] ?? 0));
        json_response(['code' => 0, 'msg' => '状态已更新。']);

    case 'card_delete':
        qc_card_delete((int) ($_POST['id'] ?? 0));
        json_response(['code' => 0, 'msg' => '已删除。']);

    case 'card_unbind':
        qc_card_unbind((int) ($_POST['id'] ?? 0));
        json_response(['code' => 0, 'msg' => '已解绑。']);

    case 'card_batch':
        json_response(['code' => 0, 'msg' => '批量操作完成。', 'data' => qc_card_batch($_POST)]);

    case 'card_import':
        json_response(['code' => 0, 'msg' => '导入完成。', 'data' => qc_card_import($_POST)]);

    case 'card_latest_batch':
        json_response(['code' => 0, 'data' => qc_card_latest_batch()]);

    case 'card_list':
        json_response(['code' => 0, 'data' => qc_card_list($_POST)]);

    case 'test_links':
        json_response(['code' => 0, 'msg' => '测试完成。', 'data' => qc_test_links($_POST)]);

    case 'stats':
        json_response(['code' => 0, 'data' => qc_stats((int) ($_POST['days'] ?? 7))]);

    default:
        json_response(['code' => 1, 'msg' => '未知操作。'], 400);
}

/* ------------------------------------------------------------------ */
/* 业务函数                                                            */
/* ------------------------------------------------------------------ */

function qc_admin_bootstrap(): array
{
    qc_ensure_schema();
    $settings = qc_settings();
    $groups = qc_db()->query(
        'SELECT `id`, `name`, `group_no`, `link`, `status`, `sort`, `clicks` FROM `' . qc_table('groups') .
        '` ORDER BY `sort` ASC, `id` ASC'
    )->fetchAll();

    $ids = array_map(static fn(array $g): int => (int) $g['id'], $groups);

    return [
        'settings'   => $settings,
        'groups'     => array_map(static fn(array $g): array => [
            'id'       => (int) $g['id'],
            'name'     => $g['name'],
            'group_no' => $g['group_no'],
            'link'     => $g['link'],
            'status'   => (int) $g['status'],
            'sort'     => (int) $g['sort'],
            'clicks'   => (int) $g['clicks'],
        ], $groups),
        'linkStatus' => qc_cached_group_status($ids),
        'admin'      => ['name' => $_SESSION['qc_admin_name'] ?? 'admin'],
    ];
}

function qc_test_links(array $post): array
{
    $table = qc_table('groups');
    $id = (int) ($post['id'] ?? 0);
    if ($id > 0) {
        $stmt = qc_db()->prepare("SELECT `id`, `group_no`, `link` FROM `{$table}` WHERE `id` = ? AND `status` = 1");
        $stmt->execute([$id]);
        $groups = $stmt->fetchAll();
    } else {
        $groups = qc_db()->query(
            "SELECT `id`, `group_no`, `link` FROM `{$table}` WHERE `status` = 1 ORDER BY `sort` ASC, `id` ASC"
        )->fetchAll();
    }

    $statuses = qc_check_groups_status($groups, true);
    $out = [];
    foreach ($statuses as $groupId => $row) {
        $out[] = [
            'id'         => (int) $groupId,
            'ok'         => (bool) $row['ok'],
            'ms'         => (int) $row['ms'],
            'code'       => (int) $row['code'],
            'checked_at' => $row['checked_at'],
        ];
    }
    qc_log('link_test', '探测群链接 ' . count($out) . ' 个');
    return $out;
}

function qc_save_settings(array $post): void
{
    $allowed = [
        'site_name', 'site_subtitle', 'site_notice', 'theme_color', 'footer_text',
        'show_group_no', 'card_prefix', 'card_suffix_len', 'duration_unit',
        'default_duration', 'gate_title', 'gate_tip', 'join_api_template', 'status_cache_ttl', 'card_bind',
    ];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $post)) {
            continue;
        }
        $value = trim((string) $post[$key]);
        if ($key === 'theme_color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            $value = '#12b7f5';
        }
        if ($key === 'join_api_template' && $value !== '' && !preg_match('#^https?://#i', $value)) {
            $value = 'https://api.tangdouz.com/qqtzq.php?qh={group_no}';
        }
        if ($key === 'status_cache_ttl') {
            $value = (string) max(30, min(3600, (int) $value));
        }
        if ($key === 'card_bind' && !in_array($value, ['none', 'ip', 'device'], true)) {
            $value = 'none';
        }
        if ($key === 'show_group_no') {
            $value = $value === '1' ? '1' : '0';
        }
        if ($key === 'card_prefix') {
            $value = mb_substr(preg_replace('/[^A-Za-z0-9\-_]/', '', $value), 0, 16);
        }
        if ($key === 'card_suffix_len') {
            $value = (string) max(4, min(16, (int) $value));
        }
        if ($key === 'default_duration') {
            $value = (string) max(1, min(3650, (int) $value));
        }
        if ($key === 'duration_unit' && !in_array($value, ['hour', 'day'], true)) {
            $value = 'day';
        }
        qc_set_setting($key, $value);
    }
    qc_log('settings_save', '更新站点配置');
}

function qc_change_password(array $post): void
{
    $old = (string) ($post['old_password'] ?? '');
    $new = (string) ($post['new_password'] ?? '');
    if (strlen($new) < 6) {
        json_response(['code' => 1, 'msg' => '新密码至少 6 位。']);
    }
    $table = qc_table('admins');
    $stmt = qc_db()->prepare("SELECT `password` FROM `{$table}` WHERE `id` = ? LIMIT 1");
    $stmt->execute([qc_admin_id()]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($old, $row['password'])) {
        json_response(['code' => 1, 'msg' => '原密码不正确。']);
    }
    $update = qc_db()->prepare("UPDATE `{$table}` SET `password` = ?, `token_version` = `token_version` + 1 WHERE `id` = ?");
    $update->execute([password_hash($new, PASSWORD_DEFAULT), qc_admin_id()]);
    qc_log('admin_password', '修改管理员密码');
}

function qc_group_save(array $post): array
{
    $id      = (int) ($post['id'] ?? 0);
    $name    = trim((string) ($post['name'] ?? ''));
    $groupNo = trim((string) ($post['group_no'] ?? ''));
    $link    = trim((string) ($post['link'] ?? ''));
    $status  = ((int) ($post['status'] ?? 1)) === 1 ? 1 : 0;
    $sort    = (int) ($post['sort'] ?? 0);

    if ($name === '') {
        json_response(['code' => 1, 'msg' => '群名称不能为空。']);
    }
    if ($groupNo !== '' && !preg_match('/^\d{5,12}$/', $groupNo)) {
        json_response(['code' => 1, 'msg' => '群号格式不正确。']);
    }
    if ($groupNo === '' && $link === '') {
        json_response(['code' => 1, 'msg' => '请填写群号，或填写自定义跳转链接。']);
    }
    if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        json_response(['code' => 1, 'msg' => '跳转链接需以 http:// 或 https:// 开头。']);
    }

    $table = qc_table('groups');
    if ($id > 0) {
        $stmt = qc_db()->prepare(
            "UPDATE `{$table}` SET `name` = ?, `group_no` = ?, `link` = ?, `status` = ?, `sort` = ? WHERE `id` = ?"
        );
        $stmt->execute([$name, $groupNo, $link, $status, $sort, $id]);
    } else {
        $stmt = qc_db()->prepare(
            "INSERT INTO `{$table}` (`name`, `group_no`, `link`, `status`, `sort`, `created_at`)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$name, $groupNo, $link, $status, $sort]);
        $id = (int) qc_db()->lastInsertId();
    }
    qc_log('group_save', '保存群：' . $name);

    return ['id' => $id];
}

function qc_group_delete(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $stmt = qc_db()->prepare('DELETE FROM `' . qc_table('groups') . '` WHERE `id` = ?');
    $stmt->execute([$id]);
    qc_log('group_delete', '删除群 ID：' . $id);
}

function qc_card_generate(array $post): array
{
    $count   = max(1, min(100, (int) ($post['count'] ?? 1)));
    $value   = max(1, min(3650, (int) ($post['duration'] ?? 7)));
    $unit    = ($post['unit'] ?? 'day') === 'hour' ? 'hour' : 'day';
    $prefix  = mb_substr(preg_replace('/[^A-Za-z0-9\-_]/', '', (string) ($post['prefix'] ?? qc_setting('card_prefix', 'VIP-'))), 0, 16);
    $len     = max(4, min(16, (int) ($post['suffix_len'] ?? qc_setting('card_suffix_len', '8'))));
    $hours   = qc_duration_to_hours($value, $unit);

    $table = qc_table('cards');
    $batchNo = date('YmdHis') . bin2hex(random_bytes(3));
    $stmt = qc_db()->prepare(
        "INSERT INTO `{$table}` (`code`, `duration_hours`, `batch_no`, `created_at`) VALUES (?, ?, ?, NOW())"
    );
    $codes = [];
    $guard = 0;
    while (count($codes) < $count && $guard < $count * 5 + 50) {
        $guard++;
        $code = qc_generate_code($prefix, $len);
        try {
            $stmt->execute([$code, $hours, $batchNo]);
            $codes[] = $code;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }
    qc_log('card_generate', '生成卡密 ' . count($codes) . ' 张');

    return ['count' => count($codes), 'codes' => $codes, 'duration_hours' => $hours, 'batch_no' => $batchNo];
}

function qc_card_toggle(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $stmt = qc_db()->prepare('UPDATE `' . qc_table('cards') . '` SET `status` = IF(`status` = 1, 0, 1) WHERE `id` = ?');
    $stmt->execute([$id]);
    qc_log('card_toggle', '切换卡密状态 ID：' . $id);
}

function qc_card_delete(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $stmt = qc_db()->prepare('DELETE FROM `' . qc_table('cards') . '` WHERE `id` = ?');
    $stmt->execute([$id]);
    qc_log('card_delete', '删除卡密 ID：' . $id);
}

function qc_card_unbind(int $id): void
{
    if ($id <= 0) {
        return;
    }
    $stmt = qc_db()->prepare('UPDATE `' . qc_table('cards') . "` SET `activated_ip` = '', `bind_token` = '' WHERE `id` = ?");
    $stmt->execute([$id]);
    qc_log('card_unbind', '解绑卡密 ID：' . $id);
}

function qc_card_batch(array $post): array
{
    $op = (string) ($post['op'] ?? '');
    $ids = array_values(array_filter(
        array_map('intval', explode(',', (string) ($post['ids'] ?? ''))),
        static fn(int $id): bool => $id > 0
    ));
    if (!$ids) {
        json_response(['code' => 1, 'msg' => '请先选择卡密。']);
    }
    $in = implode(',', $ids);
    $table = qc_table('cards');

    switch ($op) {
        case 'enable':
            qc_db()->exec("UPDATE `{$table}` SET `status` = 1 WHERE `id` IN ({$in})");
            $msg = '已启用 ' . count($ids) . ' 张';
            break;
        case 'disable':
            qc_db()->exec("UPDATE `{$table}` SET `status` = 0 WHERE `id` IN ({$in})");
            $msg = '已禁用 ' . count($ids) . ' 张';
            break;
        case 'delete':
            qc_db()->exec("DELETE FROM `{$table}` WHERE `id` IN ({$in})");
            $msg = '已删除 ' . count($ids) . ' 张';
            break;
        case 'renew':
            $value = max(1, min(3650, (int) ($post['renew_value'] ?? 30)));
            $unit = ($post['renew_unit'] ?? 'day') === 'hour' ? 'hour' : 'day';
            $hours = qc_duration_to_hours($value, $unit);
            $stmt = qc_db()->prepare(
                "UPDATE `{$table}` SET `duration_hours` = `duration_hours` + ?,
                 `expires_at` = IF(`expires_at` IS NULL, NULL, DATE_ADD(IF(`expires_at` < NOW(), NOW(), `expires_at`), INTERVAL ? HOUR))
                 WHERE `id` IN ({$in})"
            );
            $stmt->execute([$hours, $hours]);
            $msg = '已为 ' . count($ids) . ' 张卡密续期 ' . qc_format_hours($hours);
            break;
        default:
            json_response(['code' => 1, 'msg' => '未知的批量操作。']);
    }

    qc_log('card_batch', $msg . '，ID：' . implode(',', $ids));
    return ['count' => count($ids), 'msg' => $msg];
}

function qc_card_import(array $post): array
{
    $text = (string) ($post['codes'] ?? '');
    $value = max(1, min(3650, (int) ($post['duration'] ?? 7)));
    $unit = ($post['unit'] ?? 'day') === 'hour' ? 'hour' : 'day';
    $hours = qc_duration_to_hours($value, $unit);

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $added = 0;
    $skipped = 0;
    $invalid = 0;

    $table = qc_table('cards');
    $batchNo = date('YmdHis') . bin2hex(random_bytes(3));
    $check = qc_db()->prepare("SELECT 1 FROM `{$table}` WHERE `code` = ? LIMIT 1");
    $insert = qc_db()->prepare("INSERT INTO `{$table}` (`code`, `duration_hours`, `batch_no`, `created_at`) VALUES (?, ?, ?, NOW())");

    foreach ($lines as $line) {
        $code = strtoupper(trim($line));
        if ($code === '') {
            continue;
        }
        if (!preg_match('/^[A-Z0-9\-_]{4,64}$/', $code)) {
            $invalid++;
            continue;
        }
        $check->execute([$code]);
        if ($check->fetchColumn()) {
            $skipped++;
            continue;
        }
        try {
            $insert->execute([$code, $hours, $batchNo]);
            $added++;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $skipped++;
            } else {
                throw $e;
            }
        }
    }

    qc_log('card_import', "导入卡密：成功 {$added}，重复 {$skipped}，无效 {$invalid}");
    return ['added' => $added, 'skipped' => $skipped, 'invalid' => $invalid, 'duration_hours' => $hours];
}

function qc_card_latest_batch(): array
{
    $table = qc_table('cards');
    $batchNo = (string) qc_db()->query(
        "SELECT `batch_no` FROM `{$table}` WHERE `batch_no` <> '' ORDER BY `id` DESC LIMIT 1"
    )->fetchColumn();
    if ($batchNo === '') {
        return ['batch_no' => '', 'codes' => [], 'count' => 0, 'duration_hours' => 0, 'created_at' => ''];
    }
    $stmt = qc_db()->prepare(
        "SELECT `code`, `duration_hours`, `created_at` FROM `{$table}` WHERE `batch_no` = ? ORDER BY `id` ASC"
    );
    $stmt->execute([$batchNo]);
    $rows = $stmt->fetchAll();

    return [
        'batch_no'       => $batchNo,
        'codes'          => array_map(static fn(array $r): string => (string) $r['code'], $rows),
        'count'          => count($rows),
        'duration_hours' => $rows ? (int) $rows[0]['duration_hours'] : 0,
        'created_at'     => $rows ? (string) $rows[0]['created_at'] : '',
    ];
}

function qc_card_list(array $post): array
{
    $page    = max(1, (int) ($post['page'] ?? 1));
    $size    = 20;
    $offset  = ($page - 1) * $size;
    $filter  = (string) ($post['filter'] ?? 'all');
    $keyword = trim((string) ($post['keyword'] ?? ''));

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

    $countStmt = qc_db()->prepare("SELECT COUNT(*) FROM `{$table}`{$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT * FROM `{$table}`{$whereSql} ORDER BY `id` DESC LIMIT {$size} OFFSET {$offset}";
    $stmt = qc_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $now = time();
    $items = array_map(static function (array $row) use ($now): array {
        $state = 'unused';
        if ((int) $row['status'] !== 1) {
            $state = 'disabled';
        } elseif ((int) $row['used'] === 1) {
            $state = (!empty($row['expires_at']) && strtotime($row['expires_at']) < $now) ? 'expired' : 'used';
        }
        return [
            'id'             => (int) $row['id'],
            'code'           => $row['code'],
            'duration_hours' => (int) $row['duration_hours'],
            'duration_text'  => qc_format_hours((int) $row['duration_hours']),
            'state'          => $state,
            'status'         => (int) $row['status'],
            'activated_at'   => $row['activated_at'],
            'expires_at'     => $row['expires_at'],
            'activated_ip'   => (string) ($row['activated_ip'] ?? ''),
            'bound'          => (($row['activated_ip'] ?? '') !== '' || ($row['bind_token'] ?? '') !== ''),
            'created_at'     => $row['created_at'],
        ];
    }, $rows);

    return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $items];
}

function qc_stats(int $days): array
{
    $days = in_array($days, [7, 30], true) ? $days : 7;
    $p = qc_config()['db']['prefix'] ?? 'qc_';

    $today = qc_db()->query(
        "SELECT `pv`, `activations`, `clicks` FROM `{$p}stats` WHERE `stat_date` = CURDATE()"
    )->fetch() ?: ['pv' => 0, 'activations' => 0, 'clicks' => 0];

    $total = qc_db()->query(
        "SELECT COALESCE(SUM(`pv`),0) pv, COALESCE(SUM(`activations`),0) activations, COALESCE(SUM(`clicks`),0) clicks FROM `{$p}stats`"
    )->fetch();

    $uvTotal = (int) qc_db()->query("SELECT COUNT(*) FROM `{$p}uv`")->fetchColumn();
    $uvToday = (int) qc_db()->query("SELECT COUNT(*) FROM `{$p}uv` WHERE `stat_date` = CURDATE()")->fetchColumn();

    $seriesStmt = qc_db()->prepare(
        "SELECT `stat_date`, `pv`, `activations`, `clicks` FROM `{$p}stats`
         WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) ORDER BY `stat_date` ASC"
    );
    $seriesStmt->execute();
    $series = $seriesStmt->fetchAll();

    $uvStmt = qc_db()->prepare(
        "SELECT `stat_date`, COUNT(*) uv FROM `{$p}uv`
         WHERE `stat_date` >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY) GROUP BY `stat_date`"
    );
    $uvStmt->execute();
    $uvMap = [];
    foreach ($uvStmt->fetchAll() as $row) {
        $uvMap[$row['stat_date']] = (int) $row['uv'];
    }

    $chart = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} day"));
        $row = ['date' => $date, 'pv' => 0, 'uv' => $uvMap[$date] ?? 0, 'activations' => 0, 'clicks' => 0];
        foreach ($series as $s) {
            if ($s['stat_date'] === $date) {
                $row['pv'] = (int) $s['pv'];
                $row['activations'] = (int) $s['activations'];
                $row['clicks'] = (int) $s['clicks'];
                break;
            }
        }
        $chart[] = $row;
    }

    $groupTop = qc_db()->query(
        "SELECT `name`, `clicks` FROM `{$p}groups` ORDER BY `clicks` DESC, `id` ASC LIMIT 8"
    )->fetchAll();

    $cardSummary = qc_db()->query(
        "SELECT
            COUNT(*) total,
            SUM(CASE WHEN `used` = 0 THEN 1 ELSE 0 END) unused,
            SUM(CASE WHEN `used` = 1 AND (`expires_at` IS NULL OR `expires_at` >= NOW()) THEN 1 ELSE 0 END) active,
            SUM(CASE WHEN `used` = 1 AND `expires_at` < NOW() THEN 1 ELSE 0 END) expired,
            SUM(CASE WHEN `status` = 0 THEN 1 ELSE 0 END) disabled
         FROM `{$p}cards`"
    )->fetch();

    return [
        'days'    => $days,
        'today'   => ['pv' => (int) $today['pv'], 'uv' => $uvToday, 'activations' => (int) $today['activations'], 'clicks' => (int) $today['clicks']],
        'total'   => ['pv' => (int) $total['pv'], 'uv' => $uvTotal, 'activations' => (int) $total['activations'], 'clicks' => (int) $total['clicks']],
        'chart'   => $chart,
        'groupTop' => array_map(static fn(array $g): array => ['name' => $g['name'], 'clicks' => (int) $g['clicks']], $groupTop),
        'cards'   => [
            'total'    => (int) ($cardSummary['total'] ?? 0),
            'unused'   => (int) ($cardSummary['unused'] ?? 0),
            'active'   => (int) ($cardSummary['active'] ?? 0),
            'expired'  => (int) ($cardSummary['expired'] ?? 0),
            'disabled' => (int) ($cardSummary['disabled'] ?? 0),
        ],
    ];
}
