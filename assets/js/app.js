(function () {
  'use strict';

  var data = window.QC_DATA || { authed: false, groups: [], settings: {} };
  var apiBase = data.apiBase || 'api/';
  var gate = document.getElementById('qc-gate');
  var gateForm = document.getElementById('qc-gate-form');
  var gateInput = document.getElementById('qc-card-input');
  var gateSubmit = document.getElementById('qc-gate-submit');
  var gateMsg = document.getElementById('qc-gate-msg');
  var listSection = document.getElementById('qc-list-section');
  var groupsBox = document.getElementById('qc-groups');
  var emptyBox = document.getElementById('qc-empty');
  var countBox = document.getElementById('qc-list-count');

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function showTip(text) {
    var tip = document.getElementById('qc-tip');
    if (!tip) {
      tip = document.createElement('div');
      tip.id = 'qc-tip';
      tip.className = 'qc-tip';
      document.body.appendChild(tip);
    }
    tip.textContent = text;
    tip.classList.add('show');
    clearTimeout(showTip._timer);
    showTip._timer = setTimeout(function () { tip.classList.remove('show'); }, 2200);
  }

  var groupEls = {};

  function renderGroups() {
    var groups = data.groups || [];
    groupsBox.innerHTML = '';
    groupEls = {};
    countBox.textContent = groups.length ? groups.length + ' 个群' : '';

    if (!groups.length) {
      emptyBox.hidden = false;
      return;
    }
    emptyBox.hidden = true;

    groups.forEach(function (group) {
      var enabled = Number(group.status) === 1;
      var item = document.createElement('div');
      item.className = 'qc-group ' + (enabled ? 'is-checking' : 'is-off');
      item.setAttribute('data-id', group.id);

      var avatar = document.createElement('div');
      avatar.className = 'qc-group-avatar';
      avatar.textContent = (group.name || 'Q').trim().charAt(0) || 'Q';

      var main = document.createElement('div');
      main.className = 'qc-group-main';
      var name = document.createElement('div');
      name.className = 'qc-group-name';
      name.textContent = group.name || '未命名群';
      main.appendChild(name);

      if (data.settings.show_group_no && group.group_no) {
        var no = document.createElement('div');
        no.className = 'qc-group-no';
        no.textContent = '群号：' + group.group_no;
        main.appendChild(no);
      }

      var status = document.createElement('span');
      status.className = 'qc-group-status ' + (enabled ? 'checking' : 'off');
      status.textContent = enabled ? '检测中…' : '❌ 不可用';

      item.appendChild(avatar);
      item.appendChild(main);
      item.appendChild(status);
      groupsBox.appendChild(item);

      groupEls[group.id] = { el: item, statusEl: status, group: group, enabled: enabled, clickable: false };
    });
  }

  function applyStatus(id, result) {
    var ref = groupEls[id];
    if (!ref) { return; }
    var badge = ref.statusEl;
    if (result && result.ok) {
      badge.className = 'qc-group-status on';
      badge.textContent = '✅ 可加入 · ' + result.ms + 'ms';
      ref.el.classList.remove('is-off', 'is-checking');
      ref.el.classList.add('is-on');
      if (!ref.clickable) {
        ref.clickable = true;
        ref.el.setAttribute('role', 'button');
        ref.el.setAttribute('tabindex', '0');
        ref.el.setAttribute('aria-label', '加入 ' + (ref.group.name || 'QQ 群'));
        ref.el.addEventListener('click', function () { onGroupClick(ref.group); });
        ref.el.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onGroupClick(ref.group);
          }
        });
      }
    } else {
      badge.className = 'qc-group-status off';
      badge.textContent = result ? '❌ 不可用' : '❌ 检测失败';
      ref.el.classList.remove('is-on', 'is-checking');
      ref.el.classList.add('is-off');
    }
  }

  function loadStatuses() {
    var ids = [];
    Object.keys(groupEls).forEach(function (id) {
      if (groupEls[id].enabled) { ids.push(id); }
    });
    if (!ids.length) { return; }

    var body = new URLSearchParams();
    body.append('ids', ids.join(','));

    fetch(apiBase + 'status.php', { method: 'POST', body: body })
      .then(function (res) {
        return res.text().then(function (text) {
          try { return JSON.parse(text); } catch (err) { return null; }
        });
      })
      .then(function (res) {
        var list = (res && res.data) || [];
        var returned = {};
        list.forEach(function (row) {
          returned[row.id] = true;
          applyStatus(row.id, row);
        });
        Object.keys(groupEls).forEach(function (id) {
          if (groupEls[id].enabled && !returned[id]) {
            applyStatus(Number(id), null);
          }
        });
      })
      .catch(function () {
        Object.keys(groupEls).forEach(function (id) {
          if (groupEls[id].enabled) { applyStatus(Number(id), null); }
        });
      });
  }

  function resolveJoinLink(group) {
    if (group.link) {
      return group.link;
    }
    var template = (data.settings && data.settings.join_api_template) || '';
    if (group.group_no && template) {
      return template.replace('{group_no}', encodeURIComponent(group.group_no));
    }
    return '';
  }

  function onGroupClick(group) {
    trackClick(group.id);
    var link = resolveJoinLink(group);
    if (link) {
      window.open(link, '_blank', 'noopener');
      return;
    }
    if (group.group_no) {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(group.group_no).then(function () {
          showTip('群号已复制，请在 QQ 中搜索加入');
        }).catch(function () {
          showTip('群号：' + group.group_no);
        });
      } else {
        showTip('群号：' + group.group_no);
      }
      return;
    }
    showTip('管理员尚未配置该群的跳转链接');
  }

  function trackClick(id) {
    try {
      var body = new URLSearchParams();
      body.append('id', id);
      fetch(apiBase + 'click.php', { method: 'POST', body: body, keepalive: true }).catch(function () {});
    } catch (err) {
      /* 忽略统计失败 */
    }
  }

  function showList() {
    gate.hidden = true;
    listSection.hidden = false;
  }

  function verifyCard(code) {
    gateSubmit.disabled = true;
    gateSubmit.setAttribute('aria-busy', 'true');
    gateMsg.className = 'qc-gate-msg';
    gateMsg.textContent = '正在验证...';

    var body = new URLSearchParams();
    body.append('code', code);

    fetch(apiBase + 'verify.php', { method: 'POST', body: body })
      .then(function (res) {
        return res.text().then(function (text) {
          try { return JSON.parse(text); } catch (err) { return null; }
        });
      })
      .then(function (res) {
        if (res && res.code === 0) {
          gateMsg.className = 'qc-gate-msg ok';
          gateMsg.textContent = '验证成功，正在进入...';
          setTimeout(function () { location.reload(); }, 320);
        } else {
          gateMsg.className = 'qc-gate-msg';
          gateMsg.textContent = (res && res.msg) || '卡密验证失败。';
          gateSubmit.disabled = false;
          gateSubmit.removeAttribute('aria-busy');
        }
      })
      .catch(function () {
        gateMsg.className = 'qc-gate-msg';
        gateMsg.textContent = '网络异常，请稍后重试。';
        gateSubmit.disabled = false;
        gateSubmit.removeAttribute('aria-busy');
      });
  }

  if (gateForm) {
    gateForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var code = (gateInput.value || '').trim();
      if (!code) {
        gateMsg.className = 'qc-gate-msg';
        gateMsg.textContent = '请输入卡密。';
        return;
      }
      verifyCard(code);
    });
  }

  if (data.authed) {
    showList();
  }
  renderGroups();
  if (data.authed) {
    loadStatuses();
  }
})();
