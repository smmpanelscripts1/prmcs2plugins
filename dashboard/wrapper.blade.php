<style>
  #prm-cs2-installed { margin: 0 0 18px; }
  #prm-cs2-installed h2 { color: #d3d6db; font-size: 1.25rem; font-weight: 600; margin: 0 0 10px; }
  #prm-cs2-installed-card { background: #334155; border-radius: 8px; padding: 14px 16px; border: 1px solid rgba(16,185,129,.45); }
  #prm-cs2-installed-card p { margin: 0 0 10px; color: #9aa5b1; font-size: 13px; }
  .prm-cs2-installed-item { display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; padding: 8px 0; border-top: 1px solid rgba(255,255,255,.08); }
  .prm-cs2-installed-item:first-of-type { border-top: 0; padding-top: 0; }
  .prm-cs2-installed-item strong { color: #ecf0f5; font-size: 14px; }
  .prm-cs2-installed-item span { color: #9aa5b1; font-size: 12px; margin-left: 8px; }
  .prm-cs2-installed-item button, #prm-cs2-fw button { background: #dd4b39; color: #fff; border: 0; border-radius: 4px; padding: 5px 10px; font-size: 12px; font-weight: 600; cursor: pointer; }
  .prm-cs2-installed-item button[disabled], #prm-cs2-fw button[disabled] { opacity: .5; cursor: default; }
  #prm-cs2-fw { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 12px; }
  #prm-cs2-fw .ok { background: rgba(16,185,129,.25); color: #86efac; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
  #prm-cs2-fw .bad { background: rgba(221,75,57,.25); color: #fca5a5; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
  #prm-cs2-fw button { background: #2563eb; }
</style>
<script>
(function () {
  var fw = { metamod: false, counterstrikesharp: false };
  function isPluginPage() {
    return location.pathname.indexOf('/plugin-install') !== -1;
  }
  function serverId() {
    var m = location.pathname.match(/\/server\/([^/]+)/);
    return m ? m[1] : '';
  }
  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"], meta[name="_token"]');
    if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
    var match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }
  function headers() {
    return {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrf(),
      'X-XSRF-TOKEN': csrf()
    };
  }
  function findHost() {
    var h = document.querySelector('h2');
    if (h && /CS2 Plugin Installer/.test(h.textContent || '')) {
      return h.parentNode && h.parentNode.parentNode ? h.parentNode.parentNode : h.parentNode;
    }
    return null;
  }
  function renderFw() {
    var el = document.getElementById('prm-cs2-fw');
    if (!el) return;
    el.innerHTML =
      '<span class="' + (fw.metamod ? 'ok' : 'bad') + '">MetaMod ' + (fw.metamod ? '✓' : 'yok') + '</span>' +
      (fw.metamod ? '' : '<button type="button" data-fw="metamod">MetaMod kur</button>') +
      '<span class="' + (fw.counterstrikesharp ? 'ok' : 'bad') + '">CounterStrikeSharp ' + (fw.counterstrikesharp ? '✓' : 'yok') + '</span>' +
      (fw.counterstrikesharp ? '' : '<button type="button" data-fw="counterstrikesharp">CSS kur</button>');
  }
  function render(items) {
    var list = document.getElementById('prm-cs2-installed-list');
    if (!list) return;
    if (!items.length) {
      list.innerHTML = '<div class="prm-cs2-installed-item"><strong>Henüz kurulu plugin yok.</strong></div>';
      return;
    }
    list.innerHTML = items.map(function (p) {
      var folder = p.installed_folder ? '<span>klasör: ' + p.installed_folder + '</span>' : '';
      return '<div class="prm-cs2-installed-item" data-id="' + p.id + '">' +
        '<div><strong>' + (p.name || p.id) + '</strong>' + folder + '</div>' +
        '<button type="button" data-remove="' + p.id + '">Kaldır</button></div>';
    }).join('');
  }
  function loadBox() {
    var uuid = serverId();
    if (!uuid) return;
    fetch('/api/client/extensions/prmcsplugins/servers/' + encodeURIComponent(uuid) + '/catalog?installed=1&per_page=80', {
      credentials: 'same-origin',
      headers: headers()
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.framework) fw = data.framework;
      renderFw();
      render(data.items || []);
      var title = document.querySelector('#prm-cs2-installed h2');
      if (title) title.textContent = 'Kurulu pluginler (' + (data.installed_count || (data.items || []).length) + ')';
    }).catch(function () {
      render([]);
    });
  }
  function installId(id, deps) {
    var uuid = serverId();
    return fetch('/api/client/extensions/prmcsplugins/servers/' + encodeURIComponent(uuid) + '/install', {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers(),
      body: JSON.stringify({ plugin_id: id, install_deps: !!deps })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); });
  }
  function removePlugin(id) {
    var uuid = serverId();
    if (!uuid || !confirm('Bu plugin kaldırılsın mı?')) return;
    fetch('/api/client/extensions/prmcsplugins/servers/' + encodeURIComponent(uuid) + '/uninstall', {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers(),
      body: JSON.stringify({ plugin_id: id })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok) {
          alert((res.body && (res.body.error || res.body.message)) || 'Kaldırılamadı.');
          return;
        }
        loadBox();
      }).catch(function () { alert('Kaldırılamadı.'); });
  }
  function askFramework(id) {
    var label = id === 'metamod' ? 'MetaMod' : 'CounterStrikeSharp';
    if (!confirm(label + ' kurulu değil. Kuralım mı?')) return;
    installId(id, true).then(function (res) {
      if (!res.ok) {
        alert((res.body && res.body.error) || (label + ' kurulamadı.'));
        return;
      }
      alert(label + ' kuruldu. Sunucuyu restart et.');
      loadBox();
    }).catch(function () { alert(label + ' kurulamadı.'); });
  }
  function mount() {
    if (!isPluginPage()) {
      var stale = document.getElementById('prm-cs2-installed');
      if (stale) stale.remove();
      return;
    }
    if (document.getElementById('prm-cs2-installed')) return;
    var host = findHost();
    if (!host || !host.parentNode) return;
    var box = document.createElement('div');
    box.id = 'prm-cs2-installed';
    box.innerHTML =
      '<h2>Kurulu pluginler</h2>' +
      '<div id="prm-cs2-installed-card">' +
        '<div id="prm-cs2-fw"></div>' +
        '<p>Sunucudaki CSS plugins/shared klasöründen okunur. Kur dediğin paket burada görünüyorsa kurulmuştur.</p>' +
        '<div id="prm-cs2-installed-list"></div>' +
      '</div>';
    host.parentNode.insertBefore(box, host);
    box.addEventListener('click', function (e) {
      var rm = e.target.closest && e.target.closest('[data-remove]');
      if (rm) removePlugin(rm.getAttribute('data-remove'));
      var fwBtn = e.target.closest && e.target.closest('[data-fw]');
      if (fwBtn) askFramework(fwBtn.getAttribute('data-fw'));
    });
    loadBox();
  }

  var xhrOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__prmCs2Url = String(url || '');
    return xhrOpen.apply(this, arguments);
  };
  var xhrSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function (body) {
    var url = this.__prmCs2Url || '';
    if (!/\/extensions\/prmcsplugins\/servers\/[^/]+\/install/.test(url) || typeof body !== 'string') {
      return xhrSend.call(this, body);
    }
    try {
      var data = JSON.parse(body);
      if (data && data.install_deps) {
        return xhrSend.call(this, body);
      }
      var id = data && data.plugin_id;
      var need = [];
      if (id !== 'metamod' && !fw.metamod) need.push('MetaMod');
      if (id !== 'metamod' && id !== 'counterstrikesharp' && !fw.counterstrikesharp) need.push('CounterStrikeSharp');
      if (id === 'counterstrikesharp' && !fw.metamod) need = ['MetaMod'];
      if (need.length) {
        if (!confirm(need.join(' ve ') + ' kurulu değil. Şimdi kuralım mı?')) {
          return xhrSend.call(this, body);
        }
        data.install_deps = true;
        body = JSON.stringify(data);
      }
    } catch (e) {}
    return xhrSend.call(this, body);
  };

  setInterval(mount, 500);
  mount();
})();
</script>
