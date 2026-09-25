<?php
/**
 * 公共函数：设置读写、统计、会话、CSRF、输出等。
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => qc_is_https(),
    ]);
    session_start();
}

/**
 * 判断当前请求是否为 HTTPS（兼容反向代理终止 TLS）。
 */
function qc_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * 是否为内网/保留地址（用于判断可信代理）。
 */
function qc_is_private_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * HTML 转义输出。
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 输出 JSON 并结束。
 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 读取全部设置（含默认值）。
 */
function qc_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $settings = qc_default_settings();
    try {
        $rows = qc_db()->query('SELECT `skey`, `svalue` FROM `' . qc_table('settings') . '`')->fetchAll();
        foreach ($rows as $row) {
            $settings[$row['skey']] = $row['svalue'];
        }
    } catch (Throwable $e) {
        // 未安装或表缺失时回退到默认值。
    }
    return $settings;
}

function qc_setting(string $key, string $default = ''): string
{
    $settings = qc_settings();
    return isset($settings[$key]) ? (string) $settings[$key] : $default;
}

/**
 * 写入 / 更新单个设置。
 */
function qc_set_setting(string $key, string $value): void
{
    $table = qc_table('settings');
    $stmt = qc_db()->prepare(
        "INSERT INTO `{$table}` (`skey`, `svalue`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`)"
    );
    $stmt->execute([$key, $value]);
}

/**
 * 项目根目录对应的 URL 路径（用于生成绝对接口地址）。
 * 例如部署在子目录 /qq 时返回 "/qq"，部署在站点根目录时返回 ""。
 */
function qc_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $root = str_replace('\\', '/', QC_ROOT);
    $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/')
        : '';
    if ($docRoot !== '' && strpos($root, $docRoot) === 0) {
        return $base = rtrim(substr($root, strlen($docRoot)), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/');
    foreach (['/admin', '/api', '/install'] as $suffix) {
        if (substr($dir, -strlen($suffix)) === $suffix) {
            $dir = substr($dir, 0, -strlen($suffix));
            break;
        }
    }
    return $base = ($dir === '/' ? '' : $dir);
}

/**
 * 客户端 IP。
 */
function qc_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '0.0.0.0';
    }
    // 仅在直连来源为内网/本机（可信代理）时才采信转发头，避免客户端伪造。
    if (qc_is_private_ip($remote)) {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    return $remote;
}

/**
 * 当日统计自增。
 */
function qc_bump_stat(string $column, int $delta = 1): void
{
    if (!in_array($column, ['pv', 'activations', 'clicks'], true)) {
        return;
    }
    try {
        $table = qc_table('stats');
        $stmt = qc_db()->prepare(
            "INSERT INTO `{$table}` (`stat_date`, `{$column}`) VALUES (CURDATE(), ?)
             ON DUPLICATE KEY UPDATE `{$column}` = `{$column}` + VALUES(`{$column}`)"
        );
        $stmt->execute([$delta]);
    } catch (Throwable $e) {
        // 统计失败不影响主流程。
    }
}

/**
 * 记录独立访客（按天 + IP 哈希去重）。
 */
function qc_record_uv(): void
{
    try {
        $table = qc_table('uv');
        $hash = hash('sha256', qc_ip() . '|' . date('Y-m-d'));
        $stmt = qc_db()->prepare(
            "INSERT IGNORE INTO `{$table}` (`stat_date`, `ip_hash`) VALUES (CURDATE(), ?)"
        );
        $stmt->execute([$hash]);
    } catch (Throwable $e) {
        // 忽略。
    }
}

/**
 * 写操作日志。
 */
function qc_log(string $type, string $detail = ''): void
{
    try {
        $table = qc_table('logs');
        $stmt = qc_db()->prepare(
            "INSERT INTO `{$table}` (`type`, `ip`, `detail`, `created_at`) VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$type, qc_ip(), mb_substr($detail, 0, 250)]);
    } catch (Throwable $e) {
        // 忽略。
    }
}

/* ------------------------------------------------------------------ */
/* 卡密会话                                                            */
/* ------------------------------------------------------------------ */

/**
 * 当前通过卡密验证的卡密 ID。
 */
function qc_card_id(): int
{
    return (int) ($_SESSION['qc_card_id'] ?? 0);
}

/**
 * 校验当前会话是否仍然有效（卡密未被禁用且未过期）。
 */
function qc_card_valid(): bool
{
    $id = qc_card_id();
    if ($id <= 0) {
        return false;
    }
    try {
        $table = qc_table('cards');
        $stmt = qc_db()->prepare("SELECT `status`, `expires_at` FROM `{$table}` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$id]);
        $card = $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
    if (!$card || (int) $card['status'] !== 1) {
        return false;
    }
    if (!empty($card['expires_at']) && strtotime($card['expires_at']) < time()) {
        return false;
    }
    return true;
}

function qc_card_logout(): void
{
    unset($_SESSION['qc_card_id'], $_SESSION['qc_card_expires']);
}

/* ------------------------------------------------------------------ */
/* 后台会话与 CSRF                                                     */
/* ------------------------------------------------------------------ */

function qc_admin_id(): int
{
    return (int) ($_SESSION['qc_admin_id'] ?? 0);
}

function qc_admin_required(): void
{
    $id = qc_admin_id();
    if ($id <= 0) {
        json_response(['code' => 401, 'msg' => '登录已失效，请重新登录。'], 401);
    }
    $valid = false;
    try {
        $stmt = qc_db()->prepare('SELECT `token_version` FROM `' . qc_table('admins') . '` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);
        $version = $stmt->fetchColumn();
        $valid = $version !== false && (int) $version === (int) ($_SESSION['qc_admin_ver'] ?? -1);
    } catch (Throwable $e) {
        $valid = false;
    }
    if (!$valid) {
        $_SESSION = [];
        json_response(['code' => 401, 'msg' => '登录已失效，请重新登录。'], 401);
    }
}

function qc_csrf_token(): string
{
    if (empty($_SESSION['qc_csrf'])) {
        $_SESSION['qc_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['qc_csrf'];
}

function qc_csrf_check(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['qc_csrf'] ?? '', $token)) {
        json_response(['code' => 419, 'msg' => '请求校验失败，请刷新页面重试。'], 419);
    }
}

/* ------------------------------------------------------------------ */
/* 卡密生成                                                            */
/* ------------------------------------------------------------------ */

/**
 * 生成随机卡密。
 */
function qc_generate_code(string $prefix, int $len): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $suffix = '';
    for ($i = 0; $i < $len; $i++) {
        $suffix .= $alphabet[random_int(0, $max)];
    }
    return $prefix . $suffix;
}

/**
 * 时长单位换算为小时。
 */
function qc_duration_to_hours(int $value, string $unit): int
{
    $value = max(1, $value);
    return $unit === 'hour' ? $value : $value * 24;
}

/**
 * 将小时格式化为友好文本。
 */
function qc_format_hours(int $hours): string
{
    if ($hours >= 24 && $hours % 24 === 0) {
        return ($hours / 24) . ' 天';
    }
    return $hours . ' 小时';
}

/**
 * 将十六进制颜色按比例加深，用于生成主题色的深色变体。
 */
function qc_darken_hex(string $hex, float $amount = 0.22): string
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return '#2563eb';
    }
    $amount = max(0.0, min(0.9, $amount));
    $out = '#';
    foreach ([0, 2, 4] as $offset) {
        $channel = (int) round(hexdec(substr($hex, $offset, 2)) * (1 - $amount));
        $out .= sprintf('%02x', max(0, min(255, $channel)));
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* 加群链接探测                                                        */
/* ------------------------------------------------------------------ */

/**
 * 确保链接探测结果缓存表存在（兼容已安装的旧库）。
 */
function qc_ensure_link_status_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        qc_db()->exec(
            'CREATE TABLE IF NOT EXISTS `' . qc_table('link_status') . "` (
                `group_id` INT UNSIGNED NOT NULL,
                `ok` TINYINT NOT NULL DEFAULT 0,
                `http_code` INT NOT NULL DEFAULT 0,
                `latency_ms` INT NOT NULL DEFAULT 0,
                `checked_at` DATETIME NOT NULL,
                PRIMARY KEY (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // 忽略建表失败，探测功能降级。
    }
    $done = true;
}

/**
 * 确保数据库结构包含最新字段（兼容已安装的旧库）。
 */
function qc_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    qc_ensure_link_status_table();
    try {
        $columns = qc_db()->query('SHOW COLUMNS FROM `' . qc_table('cards') . '`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('bind_token', $columns, true)) {
            qc_db()->exec(
                "ALTER TABLE `" . qc_table('cards') . "` ADD COLUMN `bind_token` VARCHAR(64) NOT NULL DEFAULT '' AFTER `activated_ip`"
            );
        }
        if (!in_array('batch_no', $columns, true)) {
            qc_db()->exec(
                "ALTER TABLE `" . qc_table('cards') . "` ADD COLUMN `batch_no` VARCHAR(32) NOT NULL DEFAULT '' AFTER `bind_token`"
            );
        }
        $adminColumns = qc_db()->query('SHOW COLUMNS FROM `' . qc_table('admins') . '`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('token_version', $adminColumns, true)) {
            qc_db()->exec(
                "ALTER TABLE `" . qc_table('admins') . "` ADD COLUMN `token_version` INT NOT NULL DEFAULT 1 AFTER `password`"
            );
        }
    } catch (Throwable $e) {
        // 忽略迁移失败。
    }
    qc_ensure_rate_table();
    $done = true;
}

/**
 * 确保限速表存在。
 */
function qc_ensure_rate_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        qc_db()->exec(
            'CREATE TABLE IF NOT EXISTS `' . qc_table('rate_limits') . "` (
                `rkey` VARCHAR(64) NOT NULL,
                `hits` INT NOT NULL DEFAULT 0,
                `first_at` DATETIME NOT NULL,
                `locked_until` DATETIME NULL,
                PRIMARY KEY (`rkey`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // 忽略。
    }
    $done = true;
}

/**
 * 记录一次命中，达到阈值则锁定，返回剩余锁定秒数（未锁定为 0）。
 */
function qc_rate_hit(string $key, int $max, int $window, int $lock): int
{
    qc_ensure_rate_table();
    $table = qc_table('rate_limits');
    $pdo = qc_db();
    $now = time();

    $stmt = $pdo->prepare("SELECT `hits`, `first_at`, `locked_until` FROM `{$table}` WHERE `rkey` = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if ($row && !empty($row['locked_until']) && strtotime($row['locked_until']) > $now) {
        return strtotime($row['locked_until']) - $now;
    }

    $inWindow = $row && strtotime($row['first_at']) > ($now - $window);
    $firstAt = $inWindow ? $row['first_at'] : date('Y-m-d H:i:s', $now);
    $hits = $inWindow ? (int) $row['hits'] + 1 : 1;
    $lockedUntil = null;
    if ($hits >= $max) {
        $lockedUntil = date('Y-m-d H:i:s', $now + $lock);
        $hits = 0;
    }

    if ($row) {
        $pdo->prepare("UPDATE `{$table}` SET `hits` = ?, `first_at` = ?, `locked_until` = ? WHERE `rkey` = ?")
            ->execute([$hits, $firstAt, $lockedUntil, $key]);
    } else {
        $pdo->prepare("INSERT INTO `{$table}` (`rkey`, `hits`, `first_at`, `locked_until`) VALUES (?, ?, ?, ?)")
            ->execute([$key, $hits, $firstAt, $lockedUntil]);
    }

    return $lockedUntil ? $lock : 0;
}

/**
 * 查询剩余锁定秒数（未锁定为 0）。
 */
function qc_rate_blocked(string $key): int
{
    qc_ensure_rate_table();
    $stmt = qc_db()->prepare('SELECT `locked_until` FROM `' . qc_table('rate_limits') . '` WHERE `rkey` = ? LIMIT 1');
    $stmt->execute([$key]);
    $until = $stmt->fetchColumn();
    if ($until) {
        $ts = strtotime((string) $until);
        if ($ts > time()) {
            return $ts - time();
        }
    }
    return 0;
}

/**
 * 清除限速记录（验证成功后调用）。
 */
function qc_rate_clear(string $key): void
{
    qc_ensure_rate_table();
    $stmt = qc_db()->prepare('DELETE FROM `' . qc_table('rate_limits') . '` WHERE `rkey` = ?');
    $stmt->execute([$key]);
}

/**
 * 读取访客设备标识 Cookie。
 */
function qc_device_token(): string
{
    return (string) ($_COOKIE['qc_device'] ?? '');
}

/**
 * 写入访客设备标识 Cookie。
 */
function qc_set_device_cookie(string $token): void
{
    setcookie('qc_device', $token, [
        'expires'  => time() + 86400 * 365,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => qc_is_https(),
    ]);
    $_COOKIE['qc_device'] = $token;
}

/**
 * 解析群的实际加群链接：专属链接优先，否则按接口模板用群号生成。
 */
function qc_group_link(array $group, ?array $settings = null): string
{
    $link = trim((string) ($group['link'] ?? ''));
    if ($link !== '') {
        return $link;
    }
    $settings = $settings ?: qc_settings();
    $template = (string) ($settings['join_api_template'] ?? '');
    $groupNo = trim((string) ($group['group_no'] ?? ''));
    if ($groupNo !== '' && $template !== '') {
        return str_replace('{group_no}', rawurlencode($groupNo), $template);
    }
    return '';
}

/**
 * 单条 URL 探测（stream 回退方案）。
 */
function qc_http_probe_stream(string $url, string $method, int $timeout): array
{
    $start = microtime(true);
    $context = stream_context_create([
        'http' => [
            'method'          => $method,
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => 0,
        ],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    @file_get_contents($url, false, $context);
    $ms = (int) round((microtime(true) - $start) * 1000);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return ['ok' => $code >= 200 && $code < 400, 'ms' => $ms, 'code' => $code, 'errno' => 0];
}

/**
 * 并发探测一组 URL，返回下标 => 探测结果。
 */
function qc_http_probe_multi(array $urls, string $method, int $timeout): array
{
    $results = [];
    if (!$urls) {
        return $results;
    }
    if (!function_exists('curl_multi_init')) {
        foreach ($urls as $i => $url) {
            $results[$i] = qc_http_probe_stream($url, $method, $timeout);
        }
        return $results;
    }

    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; QcLinkCheck/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($ch, $options);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($handles as $i => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $ms = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $results[$i] = [
            'ok'    => $errno === 0 && $code >= 200 && $code < 400,
            'ms'    => $ms,
            'code'  => $code,
            'errno' => $errno,
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $results;
}

/**
 * 探测 URL：优先 HEAD，遇到不支持 HEAD 的状态码再用 GET 重试。
 */
function qc_test_urls(array $urls, int $timeout = 6): array
{
    $results = qc_http_probe_multi($urls, 'HEAD', $timeout);
    $retry = [];
    foreach ($results as $i => $row) {
        if (in_array($row['code'], [403, 405, 501], true)) {
            $retry[] = $i;
        }
    }
    if ($retry) {
        $subset = [];
        $indexMap = [];
        foreach ($retry as $i) {
            $subset[] = $urls[$i];
            $indexMap[] = $i;
        }
        $again = qc_http_probe_multi($subset, 'GET', $timeout);
        foreach ($again as $k => $row) {
            if ($row['code'] !== 0) {
                $results[$indexMap[$k]] = $row;
            }
        }
    }
    return $results;
}

/**
 * 读取缓存的探测结果。
 */
function qc_cached_group_status(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    qc_ensure_link_status_table();
    $in = implode(',', $ids);
    $rows = qc_db()->query(
        'SELECT * FROM `' . qc_table('link_status') . "` WHERE `group_id` IN ({$in})"
    )->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['group_id']] = [
            'ok'         => (int) $row['ok'] === 1,
            'ms'         => (int) $row['latency_ms'],
            'code'       => (int) $row['http_code'],
            'checked_at' => $row['checked_at'],
        ];
    }
    return $out;
}

/**
 * 探测群链接可用性，命中未过期缓存则直接复用。
 */
function qc_check_groups_status(array $groups, bool $force = false): array
{
    qc_ensure_link_status_table();
    $ttl = max(30, (int) qc_setting('status_cache_ttl', '300'));
    $now = time();

    $ids = [];
    foreach ($groups as $group) {
        $ids[] = (int) $group['id'];
    }
    $cache = $force ? [] : qc_cached_group_status($ids);

    $result = [];
    $pending = [];
    foreach ($groups as $group) {
        $id = (int) $group['id'];
        if (isset($cache[$id]) && ($now - strtotime($cache[$id]['checked_at'])) < $ttl) {
            $result[$id] = $cache[$id] + ['cached' => true];
            continue;
        }
        $pending[$id] = qc_group_link($group);
    }

    if ($pending) {
        $urls = [];
        $map = [];
        foreach ($pending as $id => $url) {
            if ($url === '') {
                $result[$id] = ['ok' => false, 'ms' => 0, 'code' => 0, 'checked_at' => date('Y-m-d H:i:s'), 'cached' => false];
                continue;
            }
            $urls[] = $url;
            $map[] = $id;
        }
        if ($urls) {
            $raw = qc_test_urls($urls, 6);
            $upsert = qc_db()->prepare(
                'INSERT INTO `' . qc_table('link_status') . "` (`group_id`, `ok`, `http_code`, `latency_ms`, `checked_at`)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `ok` = VALUES(`ok`), `http_code` = VALUES(`http_code`),
                 `latency_ms` = VALUES(`latency_ms`), `checked_at` = NOW()"
            );
            foreach ($raw as $i => $row) {
                $id = $map[$i];
                $result[$id] = [
                    'ok'         => $row['ok'],
                    'ms'         => $row['ms'],
                    'code'       => $row['code'],
                    'checked_at' => date('Y-m-d H:i:s'),
                    'cached'     => false,
                ];
                $upsert->execute([$id, $row['ok'] ? 1 : 0, $row['code'], $row['ms']]);
            }
        }
    }

    return $result;
}
