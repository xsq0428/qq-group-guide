<?php
/**
 * 前台单页：卡密验证门 + QQ 群列表。
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (!qc_installed()) {
    header('Location: install/');
    exit;
}

qc_bump_stat('pv');
qc_record_uv();

$settings = qc_settings();

try {
    $groups = qc_db()->query(
        'SELECT `id`, `name`, `group_no`, `link`, `status` FROM `' . qc_table('groups') . '` ORDER BY `sort` ASC, `id` ASC'
    )->fetchAll();
} catch (Throwable $e) {
    $groups = [];
}

$authed = qc_card_valid();
$frontData = [
    'authed'   => $authed,
    'apiBase'  => qc_base_path() . '/api/',
    'groups'   => $authed ? array_map(static function (array $g): array {
        return [
            'id'       => (int) $g['id'],
            'name'     => $g['name'],
            'group_no' => $g['group_no'],
            'link'     => $g['link'],
            'status'   => (int) $g['status'],
        ];
    }, $groups) : [],
    'settings' => [
        'show_group_no'     => $settings['show_group_no'] === '1',
        'site_name'         => $settings['site_name'],
        'join_api_template' => $settings['join_api_template'],
    ],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($settings['site_name']) ?></title>
<meta name="description" content="<?= e($settings['site_subtitle']) ?>">
<link rel="stylesheet" href="<?= e(qc_base_path()) ?>/assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body style="--qc-theme: <?= e($settings['theme_color']) ?>">
<div class="qc-bg"></div>

<main class="qc-wrap">
  <header class="qc-header">
    <div class="qc-logo">Q</div>
    <h1 class="qc-title"><?= e($settings['site_name']) ?></h1>
    <p class="qc-subtitle"><?= e($settings['site_subtitle']) ?></p>
  </header>

  <?php if ($settings['site_notice'] !== ''): ?>
    <div class="qc-notice"><?= e($settings['site_notice']) ?></div>
  <?php endif; ?>

  <section id="qc-gate" class="qc-card qc-gate" <?= $authed ? 'hidden' : '' ?>>
    <h2 class="qc-gate-title"><?= e($settings['gate_title']) ?></h2>
    <p class="qc-gate-tip"><?= e($settings['gate_tip']) ?></p>
    <form id="qc-gate-form" class="qc-gate-form">
      <input id="qc-card-input" type="text" inputmode="text" autocomplete="off"
             placeholder="请输入卡密" maxlength="64" required>
      <button type="submit" class="qc-btn-primary" id="qc-gate-submit">立即验证</button>
    </form>
    <p id="qc-gate-msg" class="qc-gate-msg" role="alert"></p>
  </section>

  <section id="qc-list-section" class="qc-list-section" <?= $authed ? '' : 'hidden' ?>>
    <div class="qc-list-head">
      <h2>官方群列表</h2>
      <span id="qc-list-count" class="qc-list-count"></span>
    </div>
    <div id="qc-groups" class="qc-groups"></div>
    <p id="qc-empty" class="qc-empty" hidden>管理员尚未添加任何群。</p>
  </section>

  <footer class="qc-footer"><?= e($settings['footer_text']) ?></footer>
</main>

<script>window.QC_DATA = <?= json_encode($frontData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(qc_base_path()) ?>/assets/js/app.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
