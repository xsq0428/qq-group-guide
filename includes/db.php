<?php
/**
 * 数据库连接与表结构定义。
 * 兼容 PHP 8.0+ / MySQL 5.6+（不使用窗口函数、JSON 函数、CTE）。
 */

if (!defined('QC_ROOT')) {
    define('QC_ROOT', dirname(__DIR__));
}

/**
 * 读取项目配置（由安装向导生成）。
 */
function qc_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $file = QC_ROOT . '/config.php';
    if (!is_file($file)) {
        return $config = [];
    }
    $loaded = require $file;
    return $config = is_array($loaded) ? $loaded : [];
}

/**
 * 是否已完成安装。
 */
function qc_installed(): bool
{
    $config = qc_config();
    return !empty($config['db']['name']) && is_file(QC_ROOT . '/install/install.lock');
}

/**
 * 获取 PDO 单例。
 */
function qc_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = qc_config();
    $db = $config['db'] ?? [];
    if (empty($db['name'])) {
        throw new RuntimeException('数据库未配置，请先运行安装向导。');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? '127.0.0.1',
        (int) ($db['port'] ?? 3306),
        $db['name'],
        $db['charset'] ?? 'utf8mb4'
    );
    try {
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('数据库连接失败：' . $e->getMessage());
    }
    return $pdo;
}

/**
 * 带前缀的表名。
 */
function qc_table(string $name): string
{
    $config = qc_config();
    $prefix = $config['db']['prefix'] ?? 'qc_';
    return $prefix . $name;
}

/**
 * 完整建表 SQL 列表。索引长度均控制在 MySQL 5.6 InnoDB 767 字节限制内。
 */
function qc_schema_sql(string $prefix): array
{
    $p = $prefix;
    return [
        "CREATE TABLE IF NOT EXISTS `{$p}settings` (
            `skey` VARCHAR(64) NOT NULL,
            `svalue` TEXT NULL,
            PRIMARY KEY (`skey`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}admins` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(50) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `token_version` INT NOT NULL DEFAULT 1,
            `last_login_at` DATETIME NULL,
            `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}groups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `group_no` VARCHAR(20) NOT NULL DEFAULT '',
            `link` VARCHAR(500) NOT NULL DEFAULT '',
            `status` TINYINT NOT NULL DEFAULT 1,
            `sort` INT NOT NULL DEFAULT 0,
            `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_sort` (`sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}cards` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(64) NOT NULL,
            `duration_hours` INT UNSIGNED NOT NULL DEFAULT 24,
            `used` TINYINT NOT NULL DEFAULT 0,
            `status` TINYINT NOT NULL DEFAULT 1,
            `activated_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `activated_ip` VARCHAR(45) NOT NULL DEFAULT '',
            `bind_token` VARCHAR(64) NOT NULL DEFAULT '',
            `batch_no` VARCHAR(32) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_used` (`used`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}stats` (
            `stat_date` DATE NOT NULL,
            `pv` INT UNSIGNED NOT NULL DEFAULT 0,
            `activations` INT UNSIGNED NOT NULL DEFAULT 0,
            `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`stat_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}uv` (
            `stat_date` DATE NOT NULL,
            `ip_hash` CHAR(64) NOT NULL,
            PRIMARY KEY (`stat_date`, `ip_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}link_status` (
            `group_id` INT UNSIGNED NOT NULL,
            `ok` TINYINT NOT NULL DEFAULT 0,
            `http_code` INT NOT NULL DEFAULT 0,
            `latency_ms` INT NOT NULL DEFAULT 0,
            `checked_at` DATETIME NOT NULL,
            PRIMARY KEY (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}rate_limits` (
            `rkey` VARCHAR(64) NOT NULL,
            `hits` INT NOT NULL DEFAULT 0,
            `first_at` DATETIME NOT NULL,
            `locked_until` DATETIME NULL,
            PRIMARY KEY (`rkey`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(20) NOT NULL DEFAULT '',
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `detail` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/**
 * 默认站点配置。
 */
function qc_default_settings(): array
{
    return [
        'site_name'        => 'QQ 加群引导',
        'site_subtitle'    => '输入卡密即可获取全部官方群入口',
        'site_notice'      => '请选择状态正常的群加入，群满或维护中的群暂不可用。',
        'theme_color'      => '#12b7f5',
        'footer_text'      => '© ' . date('Y') . ' QQ 加群引导',
        'show_group_no'    => '1',
        'card_prefix'      => 'VIP-',
        'card_suffix_len'  => '8',
        'duration_unit'    => 'day',
        'default_duration' => '7',
        'gate_title'       => '卡密验证',
        'gate_tip'         => '请输入管理员发放的卡密，验证成功后即可查看加群列表。',
        'join_api_template' => 'https://api.tangdouz.com/qqtzq.php?qh={group_no}',
        'status_cache_ttl'  => '300',
        'card_bind'         => 'none',
        'admin_path_note'  => 'admin/',
        'installed_at'     => date('Y-m-d H:i:s'),
    ];
}
