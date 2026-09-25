(function () {
  'use strict';

  var THEMES = [
    {
      key: 'oled',
      name: '深色 OLED',
      desc: '深色低功耗，性能优秀，可访问性达 WCAG AAA。适合夜间浏览与移动端，最贴近当前前台。',
      swatch: [
        ['背景', '#0F172A'], ['主色', '#22C55E'], ['次色', '#16A34A'], ['成功', '#22C55E'], ['错误', '#EF4444']
      ],
      vars: [
        ['--qc-bg', '#0F172A'], ['--qc-card', 'rgba(255,255,255,.055)'],
        ['--qc-border', 'rgba(255,255,255,.12)'], ['--qc-text', '#F8FAFC'],
        ['--qc-muted', '#9DB0C7'], ['--qc-theme', '#22C55E']
      ]
    },
    {
      key: 'vibrant',
      name: '社区紫 · 活力块面',
      desc: '高饱和紫色与块面布局，年轻社群氛围，CTA 用绿色提升点击。',
      swatch: [
        ['背景', '#FAF5FF'], ['主色', '#7C3AED'], ['次色', '#16A34A'], ['成功', '#16A34A'], ['错误', '#DC2626']
      ],
      vars: [
        ['--qc-bg', '#FAF5FF'], ['--qc-card', '#FFFFFF'],
        ['--qc-border', '#DDD6FE'], ['--qc-text', '#4C1D95'],
        ['--qc-muted', '#7C6BAE'], ['--qc-theme', '#7C3AED']
      ]
    },
    {
      key: 'cyber',
      name: '霓虹赛博',
      desc: '终端 HUD 与霓虹发光，Orbitron 标题配等宽正文，游戏/二次元取向。',
      swatch: [
        ['背景', '#0D0D0D'], ['霓虹绿', '#00FF00'], ['品红', '#FF00FF'], ['成功', '#00FF00'], ['错误', '#FF00FF']
      ],
      vars: [
        ['--qc-bg', '#0D0D0D'], ['--qc-card', 'rgba(0,255,255,.04)'],
        ['--qc-border', 'rgba(0,255,255,.25)'], ['--qc-text', '#E2E8F0'],
        ['--qc-muted', '#7F9A9A'], ['--qc-theme', '#00FF00']
      ]
    },
    {
      key: 'glass',
      name: '玻璃拟态',
      desc: '磨砂玻璃与背景模糊，层次通透，适合卡片与弹层点缀，需自行校验对比度。',
      swatch: [
        ['背景', '#0B1220'], ['主色', '#007AFF'], ['次色', '#4DA3FF'], ['成功', '#34D399'], ['错误', '#FF3B30']
      ],
      vars: [
        ['--qc-bg', '#0B1220'], ['--qc-card', 'rgba(255,255,255,.12)'],
        ['--qc-border', 'rgba(255,255,255,.25)'], ['--qc-text', '#FFFFFF'],
        ['--qc-muted', '#D6E2F0'], ['--qc-theme', '#007AFF']
      ]
    },
    {
      key: 'neu',
      name: '新拟态 · 柔和 UI',
      desc: '同色系双阴影，柔和凸起质感，适合浅色界面；对比度偏低，文字需谨慎。',
      swatch: [
        ['背景', '#E0E5EC'], ['主色', '#6C63FF'], ['次色', '#5A52E0'], ['成功', '#22A06B'], ['错误', '#E05252']
      ],
      vars: [
        ['--qc-bg', '#E0E5EC'], ['--qc-card', '#E0E5EC'],
        ['--qc-border', 'transparent'], ['--qc-text', '#3D4852'],
        ['--qc-muted', '#6B7280'], ['--qc-theme', '#6C63FF']
      ]
    },
    {
      key: 'minimal',
      name: '极简浅色',
      desc: '大留白单列，专业克制，信息密度与可读性兼顾，适合后台与正式场景。',
      swatch: [
        ['背景', '#FFFFFF'], ['主色', '#2563EB'], ['次色', '#EC4899'], ['成功', '#16A34A'], ['错误', '#DC2626']
      ],
      vars: [
        ['--qc-bg', '#FFFFFF'], ['--qc-card', '#FFFFFF'],
        ['--qc-border', '#E4ECFC'], ['--qc-text', '#0F172A'],
        ['--qc-muted', '#64748B'], ['--qc-theme', '#2563EB']
      ]
    }
  ];

  var template = document.getElementById('mock-tpl');
  var tabsBox = document.getElementById('theme-tabs');
  var singleFrame = document.getElementById('single-frame');
  var gridBox = document.getElementById('grid');
  var tokenName = document.getElementById('token-name');
  var tokenDesc = document.getElementById('token-desc');
  var swatchBox = document.getElementById('swatches');
  var tokenList = document.getElementById('token-list');

  function buildMock() {
    return template.content.cloneNode(true);
  }

  function hex(value) {
    return /^#/.test(value) ? value : '#8894a8';
  }

  function renderTokens(theme) {
    tokenName.textContent = theme.name;
    tokenDesc.textContent = theme.desc;
    swatchBox.innerHTML = theme.swatch.map(function (item) {
      return '<div class="swatch"><i style="background:' + item[1] + '"></i><span>' + item[0] + '<br>' + item[1] + '</span></div>';
    }).join('');
    tokenList.innerHTML = theme.vars.map(function (item) {
      return '<div><span>' + item[0] + '</span><b>' + item[1] + '</b></div>';
    }).join('');
  }

  function selectTheme(key) {
    var theme = THEMES.filter(function (t) { return t.key === key; })[0] || THEMES[0];
    singleFrame.setAttribute('data-theme', theme.key);
    singleFrame.innerHTML = '';
    singleFrame.appendChild(buildMock());
    renderTokens(theme);
    Array.prototype.forEach.call(tabsBox.children, function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-theme-key') === theme.key);
      btn.setAttribute('aria-selected', btn.getAttribute('data-theme-key') === theme.key ? 'true' : 'false');
    });
  }

  function buildTabs() {
    tabsBox.innerHTML = THEMES.map(function (theme) {
      var color = hex(theme.swatch[1][1]);
      return '<button type="button" class="theme-tab" data-theme-key="' + theme.key + '" role="tab">' +
        '<span class="dot" style="background:' + color + '"></span>' + theme.name + '</button>';
    }).join('');
    Array.prototype.forEach.call(tabsBox.children, function (btn) {
      btn.addEventListener('click', function () {
        selectTheme(btn.getAttribute('data-theme-key'));
      });
    });
  }

  function renderGrid() {
    if (gridBox.getAttribute('data-built') === '1') { return; }
    gridBox.innerHTML = THEMES.map(function (theme) {
      var color = hex(theme.swatch[1][1]);
      return '<div class="grid-item">' +
        '<div class="grid-label"><span class="dot" style="background:' + color + '"></span>' + theme.name + '</div>' +
        '<div class="frame" data-theme="' + theme.key + '"></div>' +
        '</div>';
    }).join('');
    var frames = gridBox.querySelectorAll('.frame');
    Array.prototype.forEach.call(frames, function (frame) {
      frame.appendChild(buildMock());
    });
    gridBox.setAttribute('data-built', '1');
  }

  function initViewToggle() {
    var buttons = document.querySelectorAll('.view-btn');
    var singleView = document.getElementById('single-view');
    var gridView = document.getElementById('grid-view');
    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        var view = btn.getAttribute('data-view');
        Array.prototype.forEach.call(buttons, function (b) {
          b.classList.toggle('active', b === btn);
          b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
        });
        singleView.hidden = view !== 'single';
        gridView.hidden = view !== 'grid';
        if (view === 'grid') { renderGrid(); }
      });
    });
  }

  buildTabs();
  selectTheme('oled');
  initViewToggle();
})();
