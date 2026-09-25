(function () {
  'use strict';

  var CFG = window.QC_ADMIN || { csrf: '' };
  var state = {
    settings: {},
    groups: [],
    linkStatus: {},
    selectedCards: {},
    cardPage: 1,
    cardFilter: 'all',
    cardKeyword: '',
    statsDays: 7
  };

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  var toastTimer = null;
  function toast(message) {
    var el = $('#toast');
    if (!el) { return; }
    el.textContent = message;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 2600);
  }

  var modalState = { lastFocus: null, open: null };

  function openModal(id) {
    var modal = document.getElementById(id);
    if (!modal) { return; }
    modalState.lastFocus = document.activeElement;
    modalState.open = modal;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    var focusable = modal.querySelector('input:not([type="hidden"]), select, textarea, button');
    if (focusable) { setTimeout(function () { focusable.focus(); }, 30); }
  }

  function closeModal(id) {
    var modal = id ? document.getElementById(id) : modalState.open;
    if (!modal) { return; }
    modal.hidden = true;
    if (modalState.open === modal) { modalState.open = null; }
    document.body.style.overflow = '';
    if (modalState.lastFocus && modalState.lastFocus.focus) {
      modalState.lastFocus.focus();
      modalState.lastFocus = null;
    }
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modalState.open) { closeModal(); }
  });

  function api(action, data) {
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('csrf', CFG.csrf);
    Object.keys(data || {}).forEach(function (key) {
      body.append(key, data[key]);
    });
    return fetch(CFG.api || 'api.php', { method: 'POST', body: body }).then(function (res) {
      return res.json().then(function (json) {
        if (json.code === 401) {
          location.reload();
          throw new Error('unauthorized');
        }
        if (json.code === 419) {
          toast(json.msg || '请求校验失败');
          throw new Error('csrf');
        }
        return json;
      });
    });
  }

  /* ---------------- 侧边栏与视图 ---------------- */

  function closeSidebar() {
    var sidebar = $('#sidebar');
    var overlay = $('#overlay');
    if (sidebar) { sidebar.classList.remove('open'); }
    if (overlay) { overlay.classList.remove('show'); }
  }

  function activateView(view) {
    var item = document.querySelector('.nav-item[data-view="' + view + '"]');
    if (!item) { return; }
    $all('.nav-item').forEach(function (n) { n.classList.remove('active'); });
    item.classList.add('active');
    $all('.view').forEach(function (v) { v.classList.remove('active'); });
    var target = $('#view-' + view);
    if (target) { target.classList.add('active'); }
    var title = item.getAttribute('data-title') || item.textContent.trim();
    var topbarTitle = $('#topbar-title');
    if (topbarTitle) { topbarTitle.textContent = title; }
    document.title = title + ' - 加群后台';
    closeSidebar();
    if (view === 'stats') { loadStats(state.statsDays); }
    if (view === 'cards') { loadCards(1); }
  }

  function initSidebar() {
    var sidebar = $('#sidebar');
    var overlay = $('#overlay');
    var hamburger = $('#hamburger');

    hamburger.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      overlay.classList.toggle('show', sidebar.classList.contains('open'));
    });
    overlay.addEventListener('click', closeSidebar);

    $all('.nav-item').forEach(function (item) {
      item.addEventListener('click', function (event) {
        event.preventDefault();
        var view = item.getAttribute('data-view');
        if (location.hash !== '#' + view) {
          location.hash = view;
        }
        activateView(view);
      });
    });
  }

  /* ---------------- 基础配置 ---------------- */

  function fillSettings(settings) {
    state.settings = settings;
    var form = $('#settings-form');
    ['site_name', 'site_subtitle', 'theme_color', 'footer_text', 'site_notice', 'gate_title', 'gate_tip'].forEach(function (key) {
      var input = form.elements[key];
      if (input) { input.value = settings[key] || ''; }
    });
    var showNo = form.elements['show_group_no'];
    if (showNo) { showNo.checked = settings.show_group_no === '1'; }

    var joinForm = $('#join-template-form');
    if (joinForm) {
      joinForm.elements['join_api_template'].value = settings.join_api_template || '';
      joinForm.elements['status_cache_ttl'].value = settings.status_cache_ttl || 300;
    }

    var bindForm = $('#card-bind-form');
    if (bindForm) {
      bindForm.elements['card_bind'].value = settings.card_bind || 'none';
    }

    var gen = $('#card-generate-form');
    if (gen) {
      gen.elements['prefix'].value = settings.card_prefix || '';
      gen.elements['suffix_len'].value = settings.card_suffix_len || 8;
      gen.elements['duration'].value = settings.default_duration || 7;
      gen.elements['unit'].value = settings.duration_unit || 'day';
    }
  }

  function initSettingsForm() {
    var form = $('#settings-form');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var msg = $('[data-msg]', form);
      var payload = {
        site_name: form.elements['site_name'].value,
        site_subtitle: form.elements['site_subtitle'].value,
        theme_color: form.elements['theme_color'].value,
        footer_text: form.elements['footer_text'].value,
        site_notice: form.elements['site_notice'].value,
        gate_title: form.elements['gate_title'].value,
        gate_tip: form.elements['gate_tip'].value,
        show_group_no: form.elements['show_group_no'].checked ? '1' : '0'
      };
      msg.textContent = '保存中...';
      api('save_settings', payload).then(function (res) {
        if (res.code === 0) {
          msg.className = 'form-msg ok';
          msg.textContent = '已保存';
          toast('配置已保存');
        } else {
          msg.className = 'form-msg';
          msg.textContent = res.msg || '保存失败';
        }
      }).catch(function () {});
    });
  }

  function initPasswordForm() {
    var form = $('#password-form');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var msg = $('[data-msg]', form);
      var oldPass = form.elements['old_password'].value;
      var newPass = form.elements['new_password'].value;
      var confirmPass = form.elements['confirm_password'].value;
      if (newPass.length < 6) { msg.className = 'form-msg'; msg.textContent = '新密码至少 6 位'; return; }
      if (newPass !== confirmPass) { msg.className = 'form-msg'; msg.textContent = '两次输入的新密码不一致'; return; }
      msg.textContent = '提交中...';
      api('change_password', { old_password: oldPass, new_password: newPass }).then(function (res) {
        if (res.code === 0) {
          toast('密码已更新，请重新登录');
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          msg.className = 'form-msg';
          msg.textContent = res.msg || '更新失败';
        }
      }).catch(function () {});
    });
  }

  /* ---------------- 加群配置 ---------------- */

  function renderGroups() {
    var box = $('#group-list');
    if (!state.groups.length) {
      box.innerHTML = '<p class="empty-tip">还没有群，点击右上角「新增群」开始添加。</p>';
      return;
    }
    box.innerHTML = state.groups.map(function (group) {
      var on = Number(group.status) === 1;
      var detail = [];
      if (group.group_no) { detail.push('群号 ' + escapeHtml(group.group_no)); }
      detail.push('点击 ' + group.clicks + ' 次');
      if (group.link) { detail.push('专属跳转链接'); }
      else if (group.group_no) { detail.push('接口自动生成链接'); }

      var test = state.linkStatus[group.id];
      var testText = '未测试';
      var testClass = 'idle';
      if (!on) {
        testText = '已停用';
      } else if (test) {
        testText = test.ok ? ('✅ ' + test.ms + 'ms') : '❌ 不可访问';
        testClass = test.ok ? 'on' : 'off';
      }
      var testTitle = (test && test.checked_at) ? '检测于 ' + test.checked_at : '尚未检测';

      return '' +
        '<div class="group-row" data-id="' + group.id + '">' +
          '<div class="group-avatar">' + escapeHtml((group.name || 'Q').trim().charAt(0) || 'Q') + '</div>' +
          '<div class="group-info"><strong>' + escapeHtml(group.name) + '</strong><small>' + detail.join(' · ') + '</small></div>' +
          '<span class="test-badge ' + testClass + '" title="' + escapeHtml(testTitle) + '">' + testText + '</span>' +
          '<div class="group-actions">' +
            (on ? '<button type="button" class="btn btn-ghost btn-sm" data-act="test">测试</button>' : '') +
            '<button type="button" class="btn btn-ghost btn-sm" data-act="edit">编辑</button>' +
            '<button type="button" class="btn btn-ghost btn-sm" data-act="toggle">' + (on ? '停用' : '启用') + '</button>' +
            '<button type="button" class="btn btn-danger btn-sm" data-act="delete">删除</button>' +
          '</div>' +
        '</div>';
    }).join('');

    $all('.group-row', box).forEach(function (row) {
      var id = Number(row.getAttribute('data-id'));
      row.addEventListener('click', function (event) {
        var act = event.target.getAttribute && event.target.getAttribute('data-act');
        if (!act) { return; }
        var group = state.groups.filter(function (g) { return g.id === id; })[0];
        if (!group) { return; }
        if (act === 'edit') { openGroupModal(group); }
        if (act === 'test') { testGroup(id); }
        if (act === 'toggle') { saveGroup(Object.assign({}, group, { status: Number(group.status) === 1 ? 0 : 1 })); }
        if (act === 'delete') {
          if (window.confirm('确定删除群「' + group.name + '」？')) {
            api('group_delete', { id: id }).then(function (res) {
              if (res.code === 0) { toast('已删除'); refreshGroups(); }
            }).catch(function () {});
          }
        }
      });
    });
  }

  function openGroupModal(group) {
    var modal = $('#group-modal');
    var form = $('#group-form');
    form.reset();
    if (group) {
      $('#group-modal-title').textContent = '编辑群';
      form.elements['id'].value = group.id;
      form.elements['name'].value = group.name;
      form.elements['group_no'].value = group.group_no;
      form.elements['link'].value = group.link;
      form.elements['sort'].value = group.sort;
      form.elements['status'].checked = Number(group.status) === 1;
    } else {
      $('#group-modal-title').textContent = '新增群';
      form.elements['id'].value = 0;
      form.elements['status'].checked = true;
      form.elements['sort'].value = 0;
    }
    updateJoinPreview();
    openModal('group-modal');
  }

  function closeGroupModal() { closeModal('group-modal'); }

  function updateJoinPreview() {
    var form = $('#group-form');
    var preview = $('#join-preview');
    if (!form || !preview) { return; }
    var link = form.elements['link'].value.trim();
    var no = form.elements['group_no'].value.trim();
    var template = (state.settings && state.settings.join_api_template) || '';
    if (link) {
      preview.textContent = '前台将使用该专属链接跳转。';
    } else if (no && template) {
      preview.textContent = '自动生成加群链接：' + template.replace('{group_no}', no);
    } else if (no) {
      preview.textContent = '未配置接口模板，前台将复制群号提示用户手动加入。';
    } else {
      preview.textContent = '请填写群号，或填写自定义跳转链接。';
    }
  }

  function saveGroup(payload) {
    return api('group_save', {
      id: payload.id || 0,
      name: payload.name,
      group_no: payload.group_no || '',
      link: payload.link || '',
      status: Number(payload.status) === 1 ? 1 : 0,
      sort: payload.sort || 0
    }).then(function (res) {
      if (res.code === 0) { toast('已保存'); refreshGroups(); }
      else { toast(res.msg || '保存失败'); }
      return res;
    });
  }

  function refreshGroups() {
    return api('bootstrap').then(function (res) {
      if (res.code === 0) {
        state.groups = res.data.groups;
        state.linkStatus = res.data.linkStatus || {};
        renderGroups();
      }
    }).catch(function () {});
  }

  function applyLinkStatus(list) {
    (list || []).forEach(function (row) {
      state.linkStatus[row.id] = { ok: row.ok, ms: row.ms, code: row.code, checked_at: row.checked_at };
    });
  }

  function testGroup(id) {
    toast('测试中...');
    api('test_links', { id: id }).then(function (res) {
      if (res.code !== 0) { return; }
      applyLinkStatus(res.data);
      renderGroups();
      var row = (res.data || [])[0];
      if (!row) { toast('该群已停用，未执行测试'); return; }
      toast(row.ok ? ('可访问 ' + row.ms + 'ms') : '不可访问');
    }).catch(function () {});
  }

  function testAll() {
    toast('正在测试全部群...');
    api('test_links', {}).then(function (res) {
      if (res.code !== 0) { return; }
      applyLinkStatus(res.data);
      renderGroups();
      toast('测试完成，共 ' + (res.data || []).length + ' 个群');
    }).catch(function () {});
  }

  function initGroups() {
    $('#group-new').addEventListener('click', function () { openGroupModal(null); });
    var testAllBtn = $('#group-test-all');
    if (testAllBtn) { testAllBtn.addEventListener('click', testAll); }
    $all('[data-close]').forEach(function (el) {
      el.addEventListener('click', closeGroupModal);
    });
    var form = $('#group-form');
    ['group_no', 'link'].forEach(function (name) {
      form.elements[name].addEventListener('input', updateJoinPreview);
    });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var msg = $('[data-msg]', form);
      msg.textContent = '';
      if (form.elements['group_no'].value.trim() === '' && form.elements['link'].value.trim() === '') {
        msg.textContent = '请填写群号，或填写自定义跳转链接。';
        return;
      }
      saveGroup({
        id: form.elements['id'].value,
        name: form.elements['name'].value,
        group_no: form.elements['group_no'].value,
        link: form.elements['link'].value,
        status: form.elements['status'].checked ? 1 : 0,
        sort: form.elements['sort'].value
      }).then(function (res) {
        if (res.code === 0) { closeGroupModal(); }
        else { msg.textContent = res.msg || '保存失败'; }
      });
    });
  }

  function initJoinTemplate() {
    var form = $('#join-template-form');
    if (!form) { return; }
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var msg = $('[data-msg]', form);
      msg.className = 'form-msg';
      msg.textContent = '保存中...';
      api('save_settings', {
        join_api_template: form.elements['join_api_template'].value,
        status_cache_ttl: form.elements['status_cache_ttl'].value
      }).then(function (res) {
        if (res.code === 0) {
          state.settings.join_api_template = form.elements['join_api_template'].value;
          state.settings.status_cache_ttl = form.elements['status_cache_ttl'].value;
          msg.className = 'form-msg ok';
          msg.textContent = '已保存';
          toast('加群设置已保存');
        } else {
          msg.textContent = res.msg || '保存失败';
        }
      }).catch(function () {});
    });

    $('#test-template').addEventListener('click', function () {
      var template = form.elements['join_api_template'].value;
      var sample = state.groups.filter(function (g) { return g.group_no; })[0];
      if (!sample) { toast('请先添加一个带群号的群用于测试'); return; }
      var url = template.replace('{group_no}', encodeURIComponent(sample.group_no));
      window.open(url, '_blank', 'noopener');
    });
  }

  /* ---------------- 卡密配置 ---------------- */

  function initCardGenerate() {
    var form = $('#card-generate-form');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var msg = $('[data-msg]', form);
      msg.textContent = '生成中...';
      api('card_generate', {
        count: form.elements['count'].value,
        prefix: form.elements['prefix'].value,
        suffix_len: form.elements['suffix_len'].value,
        duration: form.elements['duration'].value,
        unit: form.elements['unit'].value
      }).then(function (res) {
        if (res.code === 0) {
          msg.className = 'form-msg ok';
          msg.textContent = '已生成 ' + res.data.codes.length + ' 张';
          $('#generate-result').hidden = false;
          $('#generate-codes').value = res.data.codes.join('\n');
          toast('卡密生成完成');
          loadCards(1);
        } else {
          msg.className = 'form-msg';
          msg.textContent = res.msg || '生成失败';
        }
      }).catch(function () {});
    });

    $('#copy-codes').addEventListener('click', function () {
      var area = $('#generate-codes');
      area.select();
      try {
        document.execCommand('copy');
        toast('已复制本次结果');
      } catch (err) {
        toast('复制失败，请手动选择');
      }
    });

    var copyLatest = $('#copy-latest-cards');
    if (copyLatest) {
      copyLatest.addEventListener('click', function () {
        api('card_latest_batch', {}).then(function (res) {
          if (res.code !== 0) { toast('获取失败'); return; }
          var d = res.data;
          if (!d || !d.count) { toast('还没有生成过卡密'); return; }
          var text = d.codes.join('\n');
          copyText(text).then(function () {
            toast('已复制最新 ' + d.count + ' 张卡密');
          }).catch(function () {
            toast('复制失败，已展示在下方');
          });
          $('#generate-result').hidden = false;
          $('#generate-codes').value = text;
        }).catch(function () { toast('网络异常'); });
      });
    }
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var area = document.createElement('textarea');
      area.value = text;
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      try {
        document.execCommand('copy');
        resolve();
      } catch (err) {
        reject(err);
      } finally {
        document.body.removeChild(area);
      }
    });
  }

  function stateText(state) {
    return {
      unused: '未使用', used: '使用中', expired: '已过期', disabled: '已禁用'
    }[state] || state;
  }

  function loadCards(page) {
    state.cardPage = page;
    api('card_list', {
      page: page,
      filter: state.cardFilter,
      keyword: state.cardKeyword
    }).then(function (res) {
      if (res.code !== 0) { return; }
      var data = res.data;
      var box = $('#card-list');
      if (!data.items.length) {
        box.innerHTML = '<p class="empty-tip">暂无卡密数据。</p>';
        $('#card-pager').innerHTML = '';
        return;
      }
      var pageIds = data.items.map(function (item) { return item.id; });
      box.innerHTML = '' +
        '<table class="data"><thead><tr>' +
          '<th><input type="checkbox" id="card-check-all"></th>' +
          '<th>卡密</th><th>时长</th><th>状态</th><th>激活时间</th><th>到期时间</th><th>绑定</th><th>操作</th>' +
        '</tr></thead><tbody>' +
        data.items.map(function (item) {
          var checked = state.selectedCards[item.id] ? ' checked' : '';
          return '<tr>' +
            '<td data-label="选择"><input type="checkbox" class="card-check" data-id="' + item.id + '" aria-label="选择卡密 ' + escapeHtml(item.code) + '"' + checked + '></td>' +
            '<td data-label="卡密"><code>' + escapeHtml(item.code) + '</code></td>' +
            '<td data-label="时长">' + escapeHtml(item.duration_text) + '</td>' +
            '<td data-label="状态"><span class="state ' + item.state + '">' + stateText(item.state) + '</span></td>' +
            '<td data-label="激活时间">' + escapeHtml(item.activated_at || '-') + '</td>' +
            '<td data-label="到期时间">' + escapeHtml(item.expires_at || '-') + '</td>' +
            '<td data-label="绑定">' + (item.bound ? '<span class="test-badge on">已绑定</span>' : '<span class="test-badge idle">未绑定</span>') + '</td>' +
            '<td data-label="操作">' +
              '<button class="btn btn-ghost btn-sm" data-act="toggle" data-id="' + item.id + '">' + (item.status === 1 ? '禁用' : '启用') + '</button> ' +
              (item.bound ? '<button class="btn btn-ghost btn-sm" data-act="unbind" data-id="' + item.id + '">解绑</button> ' : '') +
              '<button class="btn btn-danger btn-sm" data-act="delete" data-id="' + item.id + '">删除</button>' +
            '</td>' +
          '</tr>';
        }).join('') +
        '</tbody></table>';

      $all('.card-check', box).forEach(function (cb) {
        cb.addEventListener('change', function () {
          var id = Number(cb.getAttribute('data-id'));
          if (cb.checked) { state.selectedCards[id] = true; } else { delete state.selectedCards[id]; }
          updateCheckAll(pageIds);
          renderBatchBar();
        });
      });

      var checkAll = $('#card-check-all');
      if (checkAll) {
        checkAll.checked = pageIds.length > 0 && pageIds.every(function (id) { return state.selectedCards[id]; });
        checkAll.addEventListener('change', function () {
          pageIds.forEach(function (id) {
            if (checkAll.checked) { state.selectedCards[id] = true; } else { delete state.selectedCards[id]; }
          });
          $all('.card-check', box).forEach(function (cb) { cb.checked = checkAll.checked; });
          renderBatchBar();
        });
      }

      $all('#card-list [data-act]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = Number(btn.getAttribute('data-id'));
          var act = btn.getAttribute('data-act');
          if (act === 'toggle') {
            api('card_toggle', { id: id }).then(function (r) { if (r.code === 0) { loadCards(state.cardPage); } });
          } else if (act === 'unbind') {
            api('card_unbind', { id: id }).then(function (r) { if (r.code === 0) { toast('已解绑'); loadCards(state.cardPage); } });
          } else if (act === 'delete') {
            if (window.confirm('确定删除这张卡密？')) {
              api('card_delete', { id: id }).then(function (r) {
                if (r.code === 0) { delete state.selectedCards[id]; toast('已删除'); loadCards(state.cardPage); }
              });
            }
          }
        });
      });

      renderBatchBar();
      renderPager(data.total, data.page, data.size);
    }).catch(function () {});
  }

  function updateCheckAll(pageIds) {
    var checkAll = $('#card-check-all');
    if (!checkAll) { return; }
    checkAll.checked = pageIds.length > 0 && pageIds.every(function (id) { return state.selectedCards[id]; });
  }

  function selectedCardIds() {
    return Object.keys(state.selectedCards).filter(function (id) { return state.selectedCards[id]; });
  }

  function renderBatchBar() {
    var bar = $('#card-batch');
    if (!bar) { return; }
    var ids = selectedCardIds();
    bar.hidden = ids.length === 0;
    $('#card-batch-count').textContent = '已选 ' + ids.length + ' 张';
  }

  function renderPager(total, page, size) {
    var pages = Math.max(1, Math.ceil(total / size));
    var pager = $('#card-pager');
    var html = '<button data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>上一页</button>';
    var start = Math.max(1, page - 2);
    var end = Math.min(pages, start + 4);
    start = Math.max(1, end - 4);
    for (var i = start; i <= end; i++) {
      html += '<button data-page="' + i + '" class="' + (i === page ? 'active' : '') + '">' + i + '</button>';
    }
    html += '<button data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>下一页</button>';
    html += '<span style="color:#7b8aa0;font-size:12px">共 ' + total + ' 条</span>';
    pager.innerHTML = html;
    $all('button', pager).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = Number(btn.getAttribute('data-page'));
        if (target >= 1 && target <= pages) { loadCards(target); }
      });
    });
  }

  function initCardFilters() {
    $('#card-filter').addEventListener('change', function () {
      state.cardFilter = this.value;
      loadCards(1);
    });
    $('#card-search').addEventListener('click', function () {
      state.cardKeyword = $('#card-keyword').value.trim();
      loadCards(1);
    });
    $('#card-keyword').addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        state.cardKeyword = this.value.trim();
        loadCards(1);
      }
    });
  }

  function initCardExtras() {
    var exportBtn = $('#card-export');
    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        var base = (CFG.api || 'api.php').replace(/api\.php$/, '');
        var url = base + 'export.php?filter=' + encodeURIComponent(state.cardFilter) +
          '&keyword=' + encodeURIComponent(state.cardKeyword) +
          '&token=' + encodeURIComponent(CFG.exportToken || '');
        window.open(url, '_blank', 'noopener');
      });
    }

    var importBtn = $('#card-import-btn');
    if (importBtn) {
      importBtn.addEventListener('click', function () { openModal('card-import-modal'); });
    }
    $all('[data-close-import]').forEach(function (el) {
      el.addEventListener('click', function () { closeModal('card-import-modal'); });
    });
    var importForm = $('#card-import-form');
    if (importForm) {
      importForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var msg = $('[data-msg]', importForm);
        msg.className = 'form-msg';
        msg.textContent = '导入中...';
        api('card_import', {
          codes: importForm.elements['codes'].value,
          duration: importForm.elements['duration'].value,
          unit: importForm.elements['unit'].value
        }).then(function (res) {
          if (res.code === 0) {
            var d = res.data;
            msg.className = 'form-msg ok';
            msg.textContent = '成功 ' + d.added + '，重复 ' + d.skipped + '，无效 ' + d.invalid;
            toast('导入完成：成功 ' + d.added + ' 张');
            importForm.elements['codes'].value = '';
            loadCards(1);
          } else {
            msg.textContent = res.msg || '导入失败';
          }
        }).catch(function () {});
      });
    }

    $all('[data-batch]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var op = btn.getAttribute('data-batch');
        var ids = selectedCardIds();
        if (!ids.length) { toast('请先选择卡密'); return; }
        if (op === 'delete' && !window.confirm('确定删除选中的 ' + ids.length + ' 张卡密？')) { return; }
        var payload = { op: op, ids: ids.join(',') };
        if (op === 'renew') {
          payload.renew_value = $('#renew-value').value;
          payload.renew_unit = $('#renew-unit').value;
        }
        api('card_batch', payload).then(function (res) {
          if (res.code === 0) {
            toast(res.data && res.data.msg ? res.data.msg : '操作完成');
            if (op === 'delete') { state.selectedCards = {}; }
            loadCards(state.cardPage);
          } else {
            toast(res.msg || '操作失败');
          }
        }).catch(function () {});
      });
    });

    var clearBtn = $('#card-select-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        state.selectedCards = {};
        loadCards(state.cardPage);
      });
    }

    var bindForm = $('#card-bind-form');
    if (bindForm) {
      bindForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var msg = $('[data-msg]', bindForm);
        msg.className = 'form-msg';
        msg.textContent = '保存中...';
        api('save_settings', { card_bind: bindForm.elements['card_bind'].value }).then(function (res) {
          if (res.code === 0) {
            state.settings.card_bind = bindForm.elements['card_bind'].value;
            msg.className = 'form-msg ok';
            msg.textContent = '已保存';
            toast('卡密验证设置已保存');
          } else {
            msg.textContent = res.msg || '保存失败';
          }
        }).catch(function () {});
      });
    }
  }

  /* ---------------- 统计功能 ---------------- */

  function loadStats(days) {
    state.statsDays = days;
    api('stats', { days: days }).then(function (res) {
      if (res.code !== 0) { return; }
      renderStats(res.data);
    }).catch(function () {});
  }

  function niceCeil(value) {
    if (value <= 5) { return Math.max(1, Math.ceil(value)); }
    var pow = Math.pow(10, Math.floor(Math.log10(value)));
    return Math.ceil(value / pow) * pow;
  }

  function renderStats(data) {
    var today = data.today;
    var total = data.total;
    $('#stat-cards').innerHTML = [
      ['今日访问量 PV', today.pv, '累计 ' + total.pv],
      ['今日独立访客 UV', today.uv, '累计 ' + total.uv],
      ['今日卡密激活', today.activations, '累计 ' + total.activations],
      ['今日群点击', today.clicks, '累计 ' + total.clicks]
    ].map(function (item) {
      return '<div class="stat-card"><div class="label">' + item[0] + '</div><div class="value">' + item[1] + '</div><div class="sub">' + item[2] + '</div></div>';
    }).join('');

    var max = 1;
    data.chart.forEach(function (row) {
      max = Math.max(max, row.pv, row.uv, row.activations, row.clicks);
    });
    var scaleMax = niceCeil(max);
    var chart = $('#stat-chart');
    chart.setAttribute('role', 'img');
    chart.setAttribute('aria-label', '近 ' + data.days + ' 天趋势，纵轴上限 ' + scaleMax);
    chart.innerHTML = data.chart.map(function (row) {
      function bar(value, color, name) {
        var h = Math.round((value / scaleMax) * 100);
        return '<i style="height:' + Math.max(value > 0 ? 3 : 0, h) + '%;background:' + color + '" title="' + name + ' ' + value + '"></i>';
      }
      var label = row.date.slice(5);
      return '<div class="chart-col" aria-label="' + row.date + '：访问 ' + row.pv + '，访客 ' + row.uv + '，激活 ' + row.activations + '，点击 ' + row.clicks + '">' +
        '<div class="chart-bars">' +
          bar(row.pv, '#12b7f5', '访问量') + bar(row.uv, '#34d399', '独立访客') + bar(row.activations, '#f59e0b', '卡密激活') + bar(row.clicks, '#a78bfa', '群点击') +
        '</div><span class="x">' + label + '</span></div>';
    }).join('');

    var maxClick = 1;
    data.groupTop.forEach(function (g) { maxClick = Math.max(maxClick, g.clicks); });
    $('#stat-groups').innerHTML = data.groupTop.length
      ? data.groupTop.map(function (g) {
          return '<div class="rank-row"><span class="rank-name">' + escapeHtml(g.name) + '</span>' +
            '<span class="rank-bar"><i style="width:' + Math.round((g.clicks / maxClick) * 100) + '%"></i></span>' +
            '<span>' + g.clicks + '</span></div>';
        }).join('')
      : '<p class="empty-tip">暂无数据。</p>';

    var c = data.cards;
    $('#stat-card-summary').innerHTML = [
      ['卡密总数', c.total], ['未使用', c.unused], ['使用中', c.active], ['已过期', c.expired], ['已禁用', c.disabled]
    ].map(function (item) {
      return '<div class="summary-item"><div class="k">' + item[0] + '</div><div class="v">' + item[1] + '</div></div>';
    }).join('');
  }

  function initStats() {
    $all('.seg-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        $all('.seg-btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        loadStats(Number(btn.getAttribute('data-days')));
      });
    });
  }

  /* ---------------- 启动 ---------------- */

  function initLogout() {
    var btn = $('#logout-btn');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
      var body = new URLSearchParams();
      body.append('action', 'logout');
      fetch(CFG.api || 'api.php', { method: 'POST', body: body }).then(function () { location.reload(); });
    });
  }

  function boot() {
    initSidebar();
    initSettingsForm();
    initPasswordForm();
    initGroups();
    initJoinTemplate();
    initCardGenerate();
    initCardFilters();
    initCardExtras();
    initStats();
    initLogout();

    api('bootstrap').then(function (res) {
      if (res.code !== 0) { return; }
      fillSettings(res.data.settings);
      state.groups = res.data.groups;
      state.linkStatus = res.data.linkStatus || {};
      renderGroups();
      $('#admin-name').textContent = res.data.admin.name;
      var hash = (location.hash || '').replace('#', '');
      if (hash) { activateView(hash); }
    }).catch(function () {});
  }

  window.addEventListener('hashchange', function () {
    activateView((location.hash || '').replace('#', '') || 'basic');
  });

  document.addEventListener('DOMContentLoaded', function () {
    if ($('#sidebar')) { boot(); }
  });
})();
