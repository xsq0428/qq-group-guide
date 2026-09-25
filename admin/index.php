<?php
/**
 * 后台单页入口：登录 + 侧边栏汉堡菜单 + 顶栏标题（加群后台）。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

if (!qc_installed()) {
    header('Location: ../install/');
    exit;
}

$loggedIn = qc_admin_id() > 0;
$csrf = $loggedIn ? qc_csrf_token() : '';
$adminName = $_SESSION['qc_admin_name'] ?? '';
$apiUrl = qc_base_path() . '/admin/api.php';
$frontUrl = qc_base_path() . '/index.php';
$themeColor = qc_setting('theme_color', '#12b7f5');
$themeDark = qc_darken_hex($themeColor, 0.24);
$exportToken = '';
if ($loggedIn) {
    $exportToken = bin2hex(random_bytes(16));
    $_SESSION['qc_export_token'] = $exportToken;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>加群后台</title>
<link rel="stylesheet" href="<?= e(qc_base_path()) ?>/assets/css/admin.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/admin.css') ?>">
<style>:root{--accent:<?= e($themeColor) ?>;--accent-2:<?= e($themeDark) ?>;}</style>
</head>
<body>
<script>window.QC_ADMIN = {csrf: <?= json_encode($csrf) ?>, api: <?= json_encode($apiUrl) ?>, exportToken: <?= json_encode($exportToken) ?>};</script>

<?php if (!$loggedIn): ?>
  <div class="login-page">
    <form id="login-form" class="login-card">
      <div class="login-logo">Q</div>
      <h1>加群后台</h1>
      <p class="login-sub">请使用管理员账号登录</p>
      <label>账号<input name="username" autocomplete="username" required></label>
      <label>密码<input name="password" type="password" autocomplete="current-password" required></label>
      <button type="submit" class="btn btn-primary">登录</button>
      <p id="login-msg" class="form-msg"></p>
    </form>
  </div>
<?php else: ?>
  <div class="app">
    <aside id="sidebar" class="sidebar">
      <div class="sidebar-brand">
        <span class="brand-dot">Q</span>
        <span>加群后台</span>
      </div>
      <nav class="sidebar-nav">
        <a href="#" class="nav-item active" data-view="basic" data-title="基础配置">
          <span class="nav-ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg></span>基础配置
        </a>
        <a href="#" class="nav-item" data-view="groups" data-title="加群配置">
          <span class="nav-ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>加群配置
        </a>
        <a href="#" class="nav-item" data-view="cards" data-title="卡密配置">
          <span class="nav-ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><line x1="13" y1="5" x2="13" y2="19"/></svg></span>卡密配置
        </a>
        <a href="#" class="nav-item" data-view="stats" data-title="统计功能">
          <span class="nav-ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>统计功能
        </a>
      </nav>
      <div class="sidebar-foot">
        <a href="<?= e($frontUrl) ?>" target="_blank" class="sidebar-link">查看前台</a>
        <button type="button" id="logout-btn" class="sidebar-link">退出登录</button>
      </div>
    </aside>
    <div id="overlay" class="overlay"></div>

    <div class="main">
      <header class="topbar">
        <button id="hamburger" class="hamburger" aria-label="菜单">
          <span></span><span></span><span></span>
        </button>
        <h1 class="topbar-title" id="topbar-title">基础配置</h1>
        <div class="topbar-user">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span id="admin-name"><?= e($adminName) ?></span>
        </div>
      </header>

      <div class="content">
        <!-- 基础配置 -->
        <section id="view-basic" class="view active">
          <div class="panel">
            <div class="panel-head"><h2>站点信息</h2></div>
            <form id="settings-form" class="form-grid">
              <label>站点名称<input name="site_name"></label>
              <label>副标题<input name="site_subtitle"></label>
              <label>主题色<input name="theme_color" type="color"></label>
              <label>页脚文案<input name="footer_text"></label>
              <label class="span-2">公告<textarea name="site_notice" rows="2"></textarea></label>
              <label>卡密验证标题<input name="gate_title"></label>
              <label class="check-inline"><input type="checkbox" name="show_group_no" value="1"> 前台显示群号</label>
              <label class="span-2">卡密验证说明<textarea name="gate_tip" rows="2"></textarea></label>
              <div class="span-2 form-actions">
                <button type="submit" class="btn btn-primary">保存配置</button>
                <span class="form-msg" data-msg></span>
              </div>
            </form>
          </div>

          <div class="panel">
            <div class="panel-head"><h2>修改管理员密码</h2></div>
            <form id="password-form" class="form-grid">
              <label>原密码<input name="old_password" type="password" required></label>
              <label>新密码<input name="new_password" type="password" required></label>
              <label>确认新密码<input name="confirm_password" type="password" required></label>
              <div class="span-2 form-actions">
                <button type="submit" class="btn btn-primary">更新密码</button>
                <span class="form-msg" data-msg></span>
              </div>
            </form>
          </div>
        </section>

        <!-- 加群配置 -->
        <section id="view-groups" class="view">
          <div class="panel">
            <div class="panel-head"><h2>加群链接生成</h2></div>
            <form id="join-template-form" class="form-grid">
              <label class="span-2">跳转接口模板（{group_no} 会被替换为群号）
                <input name="join_api_template" placeholder="https://api.tangdouz.com/qqtzq.php?qh={group_no}">
              </label>
              <label>状态缓存时长（秒，30-3600）
                <input name="status_cache_ttl" type="number" min="30" max="3600">
              </label>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary">保存设置</button>
                <button type="button" id="test-template" class="btn btn-ghost">测试跳转</button>
                <span class="form-msg" data-msg></span>
              </div>
            </form>
            <p class="hint">前台群状态由服务端实时探测链接得到：可访问显示 ✅ 并给出延迟，不可访问显示 ❌。探测结果按缓存时长复用，避免频繁请求被接口限流。专属链接优先于模板生成的链接。</p>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h2>QQ 群列表</h2>
              <div class="head-actions">
                <button type="button" id="group-test-all" class="btn btn-ghost">全部测试</button>
                <button type="button" id="group-new" class="btn btn-primary">新增群</button>
              </div>
            </div>
            <div id="group-list" class="group-list"></div>
          </div>
        </section>

        <!-- 卡密配置 -->
        <section id="view-cards" class="view">
          <div class="panel">
            <div class="panel-head"><h2>卡密验证设置</h2></div>
            <form id="card-bind-form" class="form-grid">
              <label>防分享绑定
                <select name="card_bind">
                  <option value="none">不绑定（同一卡密可多设备使用）</option>
                  <option value="ip">绑定首次激活 IP</option>
                  <option value="device">绑定首次激活设备</option>
                </select>
              </label>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary">保存设置</button>
                <span class="form-msg" data-msg></span>
              </div>
            </form>
            <p class="hint">绑定后，同一卡密在其他 IP/设备登录会被拒绝；如用户更换设备，可在卡密列表点「解绑」后重新绑定。绑定 IP 对移动网络用户可能因 IP 变化误拦，请按需选择。</p>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h2>生成卡密</h2>
              <button type="button" id="copy-latest-cards" class="btn btn-ghost">复制最新卡密</button>
            </div>
            <form id="card-generate-form" class="form-grid">
              <label>生成数量<input name="count" type="number" min="1" max="100" value="10"></label>
              <label>卡密前缀<input name="prefix"></label>
              <label>随机后缀长度<input name="suffix_len" type="number" min="4" max="16"></label>
              <label>时长数值<input name="duration" type="number" min="1"></label>
              <label>时长单位
                <select name="unit">
                  <option value="day">天</option>
                  <option value="hour">小时</option>
                </select>
              </label>
              <div class="span-2 form-actions">
                <button type="submit" class="btn btn-primary">批量生成</button>
                <span class="form-msg" data-msg></span>
              </div>
            </form>
            <div id="generate-result" class="generate-result" hidden>
              <div class="generate-result-head">
                <span>本次生成结果</span>
                <button type="button" id="copy-codes" class="btn btn-ghost">复制本次结果</button>
              </div>
              <textarea id="generate-codes" rows="5" readonly></textarea>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h2>卡密列表</h2>
              <div class="card-filters">
                <select id="card-filter">
                  <option value="all">全部</option>
                  <option value="unused">未使用</option>
                  <option value="used">使用中</option>
                  <option value="expired">已过期</option>
                  <option value="disabled">已禁用</option>
                </select>
                <input id="card-keyword" type="text" placeholder="搜索卡密">
                <button type="button" id="card-search" class="btn btn-ghost">搜索</button>
                <button type="button" id="card-export" class="btn btn-ghost">导出</button>
                <button type="button" id="card-import-btn" class="btn btn-ghost">导入</button>
              </div>
            </div>

            <div id="card-batch" class="batch-bar" hidden>
              <span class="batch-count" id="card-batch-count">已选 0 张</span>
              <div class="batch-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-batch="enable">启用</button>
                <button type="button" class="btn btn-ghost btn-sm" data-batch="disable">禁用</button>
                <span class="batch-renew">
                  续期
                  <input id="renew-value" type="number" min="1" max="3650" value="30">
                  <select id="renew-unit">
                    <option value="day">天</option>
                    <option value="hour">小时</option>
                  </select>
                  <button type="button" class="btn btn-ghost btn-sm" data-batch="renew">应用</button>
                </span>
                <button type="button" class="btn btn-danger btn-sm" data-batch="delete">删除</button>
                <button type="button" id="card-select-clear" class="btn btn-ghost btn-sm">取消选择</button>
              </div>
            </div>

            <div id="card-list" class="card-list"></div>
            <div id="card-pager" class="pager"></div>
          </div>
        </section>

        <!-- 统计功能 -->
        <section id="view-stats" class="view">
          <div class="stat-cards" id="stat-cards"></div>
          <div class="panel">
            <div class="panel-head">
              <h2>趋势</h2>
              <div class="seg">
                <button type="button" class="seg-btn active" data-days="7">近 7 天</button>
                <button type="button" class="seg-btn" data-days="30">近 30 天</button>
              </div>
            </div>
            <div id="stat-chart" class="chart"></div>
            <div class="chart-legend">
              <span><i style="background:#12b7f5"></i>访问量 PV</span>
              <span><i style="background:#34d399"></i>独立访客 UV</span>
              <span><i style="background:#f59e0b"></i>卡密激活</span>
              <span><i style="background:#a78bfa"></i>群点击</span>
            </div>
          </div>
          <div class="stat-two">
            <div class="panel">
              <div class="panel-head"><h2>群点击排行</h2></div>
              <div id="stat-groups" class="rank-list"></div>
            </div>
            <div class="panel">
              <div class="panel-head"><h2>卡密概况</h2></div>
              <div id="stat-card-summary" class="summary-grid"></div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>

  <!-- 群编辑弹层 -->
  <div id="group-modal" class="modal" hidden>
    <div class="modal-mask" data-close></div>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="group-modal-title">
      <div class="modal-head"><h3 id="group-modal-title">新增群</h3><button type="button" class="modal-x" data-close aria-label="关闭">×</button></div>
      <form id="group-form" class="form-grid">
        <input type="hidden" name="id" value="0">
        <label class="span-2">群名称<input name="name" required></label>
        <label class="span-2">QQ 群号（用于自动生成加群链接）<input name="group_no" inputmode="numeric" placeholder="5-12 位数字，如 123456789"></label>
        <label>排序（越小越靠前）<input name="sort" type="number" value="0"></label>
        <label class="check-inline"><input type="checkbox" name="status" value="1" checked> 状态正常（前台显示 ✅）</label>
        <label class="span-2">自定义跳转链接（选填，留空则用群号自动生成）<input name="link" placeholder="http(s):// 开头，填写后优先使用"></label>
        <p id="join-preview" class="hint span-2"></p>
        <div class="span-2 form-actions">
          <button type="submit" class="btn btn-primary">保存</button>
          <button type="button" class="btn btn-ghost" data-close>取消</button>
          <span class="form-msg" data-msg></span>
        </div>
      </form>
    </div>
  </div>

  <!-- 卡密导入弹层 -->
  <div id="card-import-modal" class="modal" hidden>
    <div class="modal-mask" data-close-import></div>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="card-import-title">
      <div class="modal-head"><h3 id="card-import-title">导入卡密</h3><button type="button" class="modal-x" data-close-import aria-label="关闭">×</button></div>
      <form id="card-import-form" class="form-grid">
        <label class="span-2">卡密列表（每行一个，4-64 位字母、数字、横线或下划线）
          <textarea name="codes" rows="8" placeholder="VIP-AAAA1111&#10;VIP-BBBB2222"></textarea>
        </label>
        <label>时长数值<input name="duration" type="number" min="1" max="3650" value="7"></label>
        <label>时长单位
          <select name="unit">
            <option value="day">天</option>
            <option value="hour">小时</option>
          </select>
        </label>
        <div class="span-2 form-actions">
          <button type="submit" class="btn btn-primary">开始导入</button>
          <button type="button" class="btn btn-ghost" data-close-import>取消</button>
          <span class="form-msg" data-msg></span>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>

  <script src="<?= e(qc_base_path()) ?>/assets/js/admin.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/admin.js') ?>"></script>
<?php endif; ?>

<?php if (!$loggedIn): ?>
  <script>
  (function () {
    var cfg = window.QC_ADMIN || {};
    var form = document.getElementById('login-form');
    var msg = document.getElementById('login-msg');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      msg.className = 'form-msg';
      msg.textContent = '登录中...';
      var body = new URLSearchParams();
      body.append('action', 'login');
      body.append('username', form.elements['username'].value);
      body.append('password', form.elements['password'].value);
      fetch(cfg.api || 'api.php', { method: 'POST', body: body })
        .then(function (r) {
          return r.text().then(function (text) {
            var data = null;
            try { data = JSON.parse(text); } catch (err) { data = null; }
            return { status: r.status, data: data };
          });
        })
        .then(function (res) {
          if (res.data && res.data.code === 0) { location.reload(); return; }
          msg.textContent = (res.data && res.data.msg) || ('登录失败（HTTP ' + res.status + '）');
        })
        .catch(function () { msg.textContent = '网络异常，请重试。'; });
    });
  })();
  </script>
<?php endif; ?>
</body>
</html>
