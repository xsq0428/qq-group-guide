<?php
/**
 * QQ 加群引导 - 安装向导
 * 步骤：环境检测 -> 填写数据库与管理员信息 -> 一键安装
 */
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

define('QC_ROOT', dirname(__DIR__));

$configFile = QC_ROOT . '/config.php';
$lockFile   = __DIR__ . '/install.lock';
$installed  = is_file($configFile) && is_file($lockFile);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$checks = [
    ['label' => 'PHP 版本 >= 8.0', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'hint' => '当前 ' . PHP_VERSION, 'critical' => true],
    ['label' => 'PDO 扩展', 'ok' => extension_loaded('pdo'), 'hint' => '数据库访问基础', 'critical' => true],
    ['label' => 'PDO MySQL 驱动', 'ok' => extension_loaded('pdo_mysql'), 'hint' => '连接 MySQL 必需', 'critical' => true],
    ['label' => 'mbstring 扩展', 'ok' => extension_loaded('mbstring'), 'hint' => '处理中文字符', 'critical' => true],
    ['label' => 'Session 支持', 'ok' => function_exists('session_start'), 'hint' => '登录状态保持', 'critical' => true],
    ['label' => 'JSON 扩展', 'ok' => extension_loaded('json'), 'hint' => '接口数据交换', 'critical' => false],
    ['label' => '项目根目录可写', 'ok' => is_writable(QC_ROOT), 'hint' => '用于生成 config.php', 'critical' => true],
    ['label' => 'install 目录可写', 'ok' => is_writable(__DIR__), 'hint' => '用于生成 install.lock', 'critical' => true],
];

$criticalOk = true;
foreach ($checks as $c) {
    if ($c['critical'] && !$c['ok']) {
        $criticalOk = false;
    }
}

$errors = [];
$done   = false;
$step   = $installed ? 'locked' : 'form';

$form = [
    'db_host'    => '127.0.0.1',
    'db_port'    => '3306',
    'db_name'    => 'qq_group',
    'db_user'    => 'root',
    'db_pass'    => '',
    'db_prefix'  => 'qc_',
    'site_name'  => 'QQ 加群引导',
    'admin_user' => 'admin',
];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? $form[$key]));
    }
    $adminPass  = (string) ($_POST['admin_pass'] ?? '');
    $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
    $form['db_prefix'] = preg_replace('/[^a-zA-Z0-9_]/', '', $form['db_prefix']) ?: 'qc_';

    if (!$criticalOk) {
        $errors[] = '存在未通过的必要环境检测项，无法继续安装。';
    }
    foreach (['db_host', 'db_name', 'db_user', 'admin_user', 'site_name'] as $key) {
        if ($form[$key] === '') {
            $errors[] = '请完整填写安装信息。';
            break;
        }
    }
    if (strlen($adminPass) < 6) {
        $errors[] = '管理员密码至少 6 位。';
    } elseif ($adminPass !== $adminPass2) {
        $errors[] = '两次输入的管理员密码不一致。';
    }
    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $form['admin_user'])) {
        $errors[] = '管理员账号需为 3-50 位字母、数字或下划线。';
    }

    if (!$errors) {
        try {
            $port = (int) $form['db_port'] ?: 3306;
            $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $form['db_host'], $port);
            $pdo = new PDO($serverDsn, $form['db_user'], $form['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec(
                'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $form['db_name']) .
                '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
            );
            $pdo->exec('USE `' . str_replace('`', '', $form['db_name']) . '`');

            $prefix = $form['db_prefix'];
            require_once QC_ROOT . '/includes/db.php';
            foreach (qc_schema_sql($prefix) as $sql) {
                $pdo->exec($sql);
            }

            $defaults = qc_default_settings();
            $defaults['site_name']    = $form['site_name'];
            $defaults['installed_at'] = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}settings` (`skey`, `svalue`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`)"
            );
            foreach ($defaults as $k => $v) {
                $stmt->execute([$k, (string) $v]);
            }

            $adminStmt = $pdo->prepare(
                "INSERT INTO `{$prefix}admins` (`username`, `password`, `created_at`) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `password` = VALUES(`password`)"
            );
            $adminStmt->execute([$form['admin_user'], password_hash($adminPass, PASSWORD_DEFAULT)]);

            $config = [
                'db' => [
                    'host'    => $form['db_host'],
                    'port'    => $port,
                    'name'    => $form['db_name'],
                    'user'    => $form['db_user'],
                    'pass'    => $form['db_pass'],
                    'charset' => 'utf8mb4',
                    'prefix'  => $prefix,
                ],
                'installed_at' => date('Y-m-d H:i:s'),
            ];
            $content = "<?php\n/**\n * 由安装向导自动生成，请勿手动修改。\n */\nreturn " .
                var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $content) === false) {
                throw new RuntimeException('无法写入 config.php，请检查根目录权限。');
            }
            @chmod($configFile, 0640);
            if (@file_put_contents($lockFile, date('Y-m-d H:i:s')) === false) {
                throw new RuntimeException('无法写入 install.lock，请检查 install 目录权限。');
            }

            $done = true;
            $step = 'done';
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 - QQ 加群引导</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#e5eefb;min-height:100vh;padding:32px 16px}
.wrap{max-width:760px;margin:0 auto}
.card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:28px;backdrop-filter:blur(8px);box-shadow:0 18px 48px rgba(0,0,0,.35)}
h1{margin:0 0 6px;font-size:24px}
.sub{color:#9fb3c8;margin:0 0 24px;font-size:14px}
.check{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.04);margin-bottom:8px;font-size:14px}
.ok{color:#4ade80}.bad{color:#f87171}
.tag{font-size:12px;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.1)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:560px){.grid{grid-template-columns:1fr}}
label{display:block;font-size:13px;color:#9fb3c8;margin-bottom:6px}
input{width:100%;padding:11px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:rgba(0,0,0,.25);color:#fff;font-size:14px;outline:none}
input:focus{border-color:#12b7f5;box-shadow:0 0 0 3px rgba(18,183,245,.18)}
.btn{display:inline-block;margin-top:22px;width:100%;padding:13px;border:0;border-radius:12px;background:linear-gradient(135deg,#12b7f5,#3b82f6);color:#fff;font-size:16px;font-weight:600;cursor:pointer}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-link{display:inline-block;margin-top:16px;padding:11px 22px;border-radius:12px;background:linear-gradient(135deg,#12b7f5,#3b82f6);color:#fff;text-decoration:none;font-weight:600}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:14px}
.alert.err{background:rgba(248,113,113,.14);border:1px solid rgba(248,113,113,.4);color:#fecaca}
.alert.ok{background:rgba(74,222,128,.14);border:1px solid rgba(74,222,128,.4);color:#bbf7d0}
.section-title{margin:22px 0 10px;font-size:14px;color:#7dd3fc;font-weight:600}
code{background:rgba(0,0,0,.35);padding:2px 6px;border-radius:6px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>QQ 加群引导 · 安装向导</h1>
    <p class="sub">三步完成部署：环境检测 → 数据库配置 → 一键安装</p>

    <?php if ($step === 'locked'): ?>
      <div class="alert ok">系统已完成安装。如需重新安装，请先删除项目根目录的 <code>config.php</code> 与 <code>install/install.lock</code>。</div>
      <a class="btn-link" href="../admin/">进入后台</a>
    <?php elseif ($step === 'done'): ?>
      <div class="alert ok">安装成功！管理员账号已创建，配置已写入。</div>
      <p class="sub">建议安装完成后删除或重命名 <code>install</code> 目录，避免被重复访问。</p>
      <a class="btn-link" href="../admin/">进入后台</a>
      <a class="btn-link" style="background:rgba(255,255,255,.12)" href="../index.php">查看前台</a>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert err">
          <?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="section-title">1. 环境检测</div>
      <?php foreach ($checks as $c): ?>
        <div class="check">
          <span><?= h($c['label']) ?> <span class="tag"><?= h($c['hint']) ?></span></span>
          <span class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? '通过' : '未通过' ?></span>
        </div>
      <?php endforeach; ?>

      <form method="post" autocomplete="off">
        <div class="section-title">2. 数据库配置</div>
        <div class="grid">
          <div><label>数据库地址</label><input name="db_host" value="<?= h($form['db_host']) ?>" required></div>
          <div><label>端口</label><input name="db_port" value="<?= h($form['db_port']) ?>" required></div>
          <div><label>数据库名（不存在会自动创建）</label><input name="db_name" value="<?= h($form['db_name']) ?>" required></div>
          <div><label>表前缀</label><input name="db_prefix" value="<?= h($form['db_prefix']) ?>"></div>
          <div><label>数据库账号</label><input name="db_user" value="<?= h($form['db_user']) ?>" required></div>
          <div><label>数据库密码</label><input name="db_pass" type="password" value=""></div>
        </div>

        <div class="section-title">3. 站点与管理员</div>
        <div class="grid">
          <div><label>站点名称</label><input name="site_name" value="<?= h($form['site_name']) ?>" required></div>
          <div><label>管理员账号</label><input name="admin_user" value="<?= h($form['admin_user']) ?>" required></div>
          <div><label>管理员密码（至少 6 位）</label><input name="admin_pass" type="password" required></div>
          <div><label>确认管理员密码</label><input name="admin_pass2" type="password" required></div>
        </div>

        <button class="btn" type="submit" <?= $criticalOk ? '' : 'disabled' ?>>一键安装</button>
        <?php if (!$criticalOk): ?><p class="sub" style="margin-top:10px">请先解决未通过的必要环境项。</p><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
