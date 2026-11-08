@php
  $lib = '/blueprint_extensions/prmcs2plugins/lib';
  if (is_file($lib . '/Store.php')) {
      require_once $lib . '/Store.php';
      require_once $lib . '/GitHub.php';
      require_once $lib . '/HelperClient.php';
      require_once $lib . '/Category.php';
      require_once $lib . '/Catalog.php';
      require_once $lib . '/RemoteCatalog.php';
      require_once $lib . '/Installer.php';
  }

  $settings = class_exists(\PrmCs2Plugins\Store::class) ? \PrmCs2Plugins\Store::all() : [
      'helper_url' => 'http://prm-plugin-helper:8100',
      'custom_plugins' => [],
      'logs' => [],
  ];

  $helperOk = false;
  $helperErr = '';
  try {
      if (class_exists(\PrmCs2Plugins\HelperClient::class)) {
          $h = \PrmCs2Plugins\HelperClient::health();
          $helperOk = !empty($h['ok']);
      }
  } catch (\Throwable $e) {
      $helperErr = $e->getMessage();
  }

  $servers = \Pterodactyl\Models\Server::query()->with(['egg', 'nest'])->orderBy('name')->get();
  $cs2Servers = [];
  foreach ($servers as $s) {
      $looksCs2 = false;
      try {
          $looksCs2 = class_exists(\PrmCs2Plugins\Installer::class) && $helperOk
              ? \PrmCs2Plugins\Installer::isCs2Server($s)
              : (stripos((string) optional($s->egg)->name, 'cs2') !== false
                  || stripos((string) optional($s->egg)->name, 'kitsune') !== false
                  || stripos((string) optional($s->egg)->name, 'counter') !== false);
      } catch (\Throwable $e) {
          $looksCs2 = stripos((string) optional($s->egg)->name, 'cs2') !== false;
      }
      if ($looksCs2) {
          $cs2Servers[] = $s;
      }
  }

  $selectedId = (int) request()->query('server_id', $cs2Servers[0]->id ?? 0);
  $selected = null;
  foreach ($cs2Servers as $s) {
      if ((int) $s->id === $selectedId) {
          $selected = $s;
          break;
      }
  }
  if (!$selected && $cs2Servers) {
      $selected = $cs2Servers[0];
      $selectedId = (int) $selected->id;
  }

  $status = null;
  $statusErr = '';
  if ($selected && $helperOk && class_exists(\PrmCs2Plugins\Installer::class)) {
      try {
          $status = \PrmCs2Plugins\Installer::statusFor($selected);
      } catch (\Throwable $e) {
          $statusErr = $e->getMessage();
      }
  }

  $customs = $settings['custom_plugins'] ?? [];
  $logs = $settings['logs'] ?? [];
@endphp

<div class="row">
  <div class="col-xs-12">
    @if(session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
  </div>
</div>

<div class="row">
  <div class="col-md-4">
    <div class="box box-primary">
      <div class="box-header with-border">
        <h3 class="box-title">Helper</h3>
      </div>
      <div class="box-body">
        @if($helperOk)
          <p><span class="label label-success">Online</span> Root plugin helper hazır.</p>
        @else
          <p><span class="label label-danger">Offline</span> {{ $helperErr ?: 'prm-plugin-helper çalışmıyor.' }}</p>
          <p class="text-muted" style="margin-top:8px">
            docker-compose içinde <code>prm-plugin-helper</code> servisini başlat.
          </p>
        @endif
        <form method="post" action="{{ url('/extensions/prmcsplugins/settings') }}">
          @csrf
          <label>Helper URL</label>
          <input class="form-control" name="helper_url" value="{{ $settings['helper_url'] ?? '' }}">
          <button class="btn btn-default btn-sm" style="margin-top:8px" type="submit">Kaydet</button>
        </form>
      </div>
    </div>

    <div class="box box-default">
      <div class="box-header with-border">
        <h3 class="box-title">CS2 sunucu</h3>
      </div>
      <div class="box-body">
        @if(!$cs2Servers)
          <p class="text-muted">CS2 sunucu bulunamadı.</p>
        @else
          <form method="get" action="{{ url('/admin/extensions/prmcsplugins') }}">
            <select class="form-control" name="server_id" onchange="this.form.submit()">
              @foreach($cs2Servers as $s)
                <option value="{{ $s->id }}" @if((int)$s->id === $selectedId) selected @endif>
                  #{{ $s->id }} — {{ $s->name }}
                </option>
              @endforeach
            </select>
          </form>
          @if($status)
            <p style="margin-top:12px">
              MetaMod:
              @if($status['framework']['metamod'])
                <span class="label label-success">var</span>
              @else
                <span class="label label-warning">yok</span>
              @endif
              &nbsp; CSS:
              @if($status['framework']['counterstrikesharp'])
                <span class="label label-success">var</span>
              @else
                <span class="label label-warning">yok</span>
              @endif
            </p>
            <p class="text-muted">plugins/: {{ count($status['plugins_dir']) }} klasör</p>
          @endif
          @if($statusErr)
            <div class="alert alert-warning" style="margin-top:10px">{{ $statusErr }}</div>
          @endif
        @endif
      </div>
    </div>

    <div class="box box-warning">
      <div class="box-header with-border">
        <h3 class="box-title">Özel zip URL</h3>
      </div>
      <div class="box-body">
        @if($selected)
          <form method="post" action="{{ url('/extensions/prmcsplugins/install-url') }}">
            @csrf
            <input type="hidden" name="server_id" value="{{ $selectedId }}">
            <label>Zip URL</label>
            <input class="form-control" name="url" placeholder="https://.../plugin.zip" required>
            <label style="margin-top:8px">Hedef</label>
            <input class="form-control" name="dest_rel" value="game/csgo/addons/counterstrikesharp">
            <button class="btn btn-warning" style="margin-top:10px" type="submit" {{ !$helperOk ? 'disabled' : '' }}>
              Kur
            </button>
          </form>
        @else
          <p class="text-muted">Önce sunucu seç.</p>
        @endif
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <div class="box box-success">
      <div class="box-header with-border">
        <h3 class="box-title">Framework / temel</h3>
      </div>
      <div class="box-body table-responsive no-padding">
        @if(!$selected)
          <p class="text-muted" style="padding:15px">Sunucu seçilmedi.</p>
        @elseif(!$status)
          <p class="text-muted" style="padding:15px">Durum okunamadı (helper offline olabilir).</p>
        @else
          <table class="table table-hover prm-cs2-table">
            <thead>
              <tr>
                <th>Paket</th>
                <th>Tür</th>
                <th>Durum</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              @foreach($status['catalog'] as $plugin)
                <tr>
                  <td>
                    <strong>{{ $plugin['name'] }}</strong>
                    <div class="text-muted">{{ $plugin['summary'] ?? '' }}</div>
                  </td>
                  <td><span class="label label-default">{{ $plugin['category'] }}</span></td>
                  <td>
                    @if(!empty($plugin['installed']))
                      <span class="label label-success">kurulu</span>
                    @else
                      <span class="label label-default">yok</span>
                    @endif
                  </td>
                  <td class="text-right" style="white-space:nowrap">
                    @if(empty($plugin['installed']) && !empty($plugin['installable']))
                      <form method="post" action="{{ url('/extensions/prmcsplugins/install') }}" style="display:inline">
                        @csrf
                        <input type="hidden" name="server_id" value="{{ $selectedId }}">
                        <input type="hidden" name="plugin_id" value="{{ $plugin['id'] }}">
                        <button class="btn btn-success btn-xs" type="submit">Kur</button>
                      </form>
                    @endif
                    @if(!empty($plugin['installed']) && ($plugin['category'] ?? '') !== 'framework')
                      <form method="post" action="{{ url('/extensions/prmcsplugins/uninstall') }}" style="display:inline"
                            onsubmit="return confirm('Kaldırılsın mı?')">
                        @csrf
                        <input type="hidden" name="server_id" value="{{ $selectedId }}">
                        <input type="hidden" name="plugin_id" value="{{ $plugin['id'] }}">
                        <button class="btn btn-danger btn-xs" type="submit">Kaldır</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>

    <div class="box box-primary">
      <div class="box-header with-border">
        <h3 class="box-title">
          Topluluk plugin listesi
          @if(!empty($status['remote']['count']))
            <small>({{ $status['remote']['count'] }})</small>
          @endif
        </h3>
        <div class="box-tools">
          @if($selected)
            <form method="post" action="{{ url('/extensions/prmcsplugins/catalog/refresh') }}" style="display:inline">
              @csrf
              <input type="hidden" name="server_id" value="{{ $selectedId }}">
              <button class="btn btn-box-tool" type="submit" title="Listeyi yenile">
                <i class="fa fa-refresh"></i> Yenile
              </button>
            </form>
          @endif
        </div>
      </div>
      <div class="box-body">
        <p class="text-muted" style="margin-bottom:10px">
          Kaynaklar: Discord <code>#plugin-releases</code>
          + GitHub CSS araması + requi indeks.
          Toplam {{ $status['remote']['count'] ?? '?' }} paket.
          Yıldıza göre sıralı — ara / kur.
        </p>
        @if(!empty($status['remote']['error']))
          <div class="alert alert-warning">{{ $status['remote']['error'] }}</div>
        @endif
        @if(!$selected || !$status)
          <p class="text-muted">Sunucu / helper hazır değil.</p>
        @else
          <div class="form-inline" style="margin-bottom:10px">
            <input type="search" id="prm-plugin-search" class="form-control" style="width:100%;max-width:320px;display:inline-block"
                   placeholder="Ara: isim, repo, açıklama…">
            <select id="prm-plugin-category" class="form-control" style="width:auto;display:inline-block;margin-left:8px">
              <option value="">Tüm kategoriler</option>
              @foreach(($status['categories'] ?? []) as $catName => $catCount)
                <option value="{{ $catName }}">{{ $catName }} ({{ $catCount }})</option>
              @endforeach
            </select>
            <label style="margin-left:12px;font-weight:normal">
              <input type="checkbox" id="prm-plugin-release-only"> Sadece GitHub release’i bilinenler
            </label>
          </div>
          <div class="table-responsive" style="max-height:560px;overflow:auto">
            <table class="table table-hover prm-cs2-table" id="prm-community-table">
              <thead>
                <tr>
                  <th>Plugin</th>
                  <th>Kategori</th>
                  <th>★</th>
                  <th>Durum</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @foreach(($status['community'] ?? []) as $plugin)
                  @php $pluginCat = $plugin['category'] ?? 'Other'; @endphp
                  <tr class="prm-community-row"
                      data-search="{{ strtolower(($plugin['name'] ?? '').' '.($plugin['full_name'] ?? '').' '.($plugin['summary'] ?? '').' '.$pluginCat.' '.implode(' ', $plugin['topics'] ?? [])) }}"
                      data-category="{{ $pluginCat }}"
                      data-has-release="{{ (!empty($plugin['last_release']) || !empty($plugin['installable'])) ? '1' : '0' }}">
                    <td>
                      <strong>{{ $plugin['name'] }}</strong>
                      <div class="text-muted">{{ \Illuminate\Support\Str::limit($plugin['summary'] ?? '', 140) }}</div>
                      <small>
                        <a href="{{ $plugin['html_url'] ?? '#' }}" target="_blank" rel="noopener">
                          {{ $plugin['full_name'] ?? '' }}
                        </a>
                        @if(!empty($plugin['last_release']))
                          · release {{ substr((string)$plugin['last_release'], 0, 10) }}
                        @endif
                      </small>
                    </td>
                    <td><span class="label label-default">{{ $pluginCat }}</span></td>
                    <td>{{ (int)($plugin['stars'] ?? 0) }}</td>
                    <td>
                      @if(!empty($plugin['installed']))
                        <span class="label label-success">kurulu</span>
                      @elseif(!empty($plugin['installable']))
                        <span class="label label-info">kurulabilir</span>
                      @else
                        <span class="label label-default">release yok</span>
                      @endif
                    </td>
                    <td class="text-right" style="white-space:nowrap">
                      @if(empty($plugin['installed']) && !empty($plugin['installable']))
                        <form method="post" action="{{ url('/extensions/prmcsplugins/install') }}" style="display:inline">
                          @csrf
                          <input type="hidden" name="server_id" value="{{ $selectedId }}">
                          <input type="hidden" name="plugin_id" value="{{ $plugin['id'] }}">
                          <button class="btn btn-success btn-xs" type="submit">Kur</button>
                        </form>
                      @endif
                      @if(!empty($plugin['installed']))
                        <form method="post" action="{{ url('/extensions/prmcsplugins/uninstall') }}" style="display:inline"
                              onsubmit="return confirm('Kaldırılsın mı?')">
                          @csrf
                          <input type="hidden" name="server_id" value="{{ $selectedId }}">
                          <input type="hidden" name="plugin_id" value="{{ $plugin['id'] }}">
                          <button class="btn btn-danger btn-xs" type="submit">Kaldır</button>
                        </form>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <script>
          (function () {
            var input = document.getElementById('prm-plugin-search');
            var cat = document.getElementById('prm-plugin-category');
            var onlyRelease = document.getElementById('prm-plugin-release-only');
            var rows = document.querySelectorAll('#prm-community-table .prm-community-row');
            function apply() {
              var q = (input && input.value || '').toLowerCase().trim();
              var c = (cat && cat.value || '');
              var needRelease = !!(onlyRelease && onlyRelease.checked);
              rows.forEach(function (row) {
                var hay = row.getAttribute('data-search') || '';
                var okSearch = !q || hay.indexOf(q) !== -1;
                var okCat = !c || row.getAttribute('data-category') === c;
                var okRelease = !needRelease || row.getAttribute('data-has-release') === '1';
                row.style.display = (okSearch && okCat && okRelease) ? '' : 'none';
              });
            }
            if (input) input.addEventListener('input', apply);
            if (cat) cat.addEventListener('change', apply);
            if (onlyRelease) onlyRelease.addEventListener('change', apply);
            apply();
          })();
          </script>
        @endif
      </div>
    </div>

    <div class="box box-info">
      <div class="box-header with-border">
        <h3 class="box-title">Özel katalog kaydı</h3>
      </div>
      <div class="box-body">
        <form method="post" action="{{ url('/extensions/prmcsplugins/custom') }}" class="row">
          @csrf
          <div class="col-sm-3">
            <label>id</label>
            <input class="form-control" name="id" placeholder="my-plugin" required>
          </div>
          <div class="col-sm-3">
            <label>name</label>
            <input class="form-control" name="name" placeholder="My Plugin" required>
          </div>
          <div class="col-sm-6">
            <label>download</label>
            <input class="form-control" name="download" placeholder="github:owner/repo veya https://...zip" required>
          </div>
          <div class="col-sm-6" style="margin-top:8px">
            <label>detect path</label>
            <input class="form-control" name="detect" placeholder="game/csgo/addons/counterstrikesharp/plugins/MyPlugin">
          </div>
          <div class="col-sm-6" style="margin-top:8px">
            <label>summary</label>
            <input class="form-control" name="summary" placeholder="Kısa açıklama">
          </div>
          <div class="col-sm-12" style="margin-top:10px">
            <button class="btn btn-info" type="submit">Kataloğa ekle</button>
          </div>
        </form>

        @if($customs)
          <hr>
          <ul class="list-unstyled">
            @foreach($customs as $c)
              <li style="margin-bottom:6px">
                <code>{{ $c['id'] ?? '' }}</code> — {{ $c['name'] ?? '' }}
                <form method="post" action="{{ url('/extensions/prmcsplugins/custom/delete') }}" style="display:inline">
                  @csrf
                  <input type="hidden" name="id" value="{{ $c['id'] ?? '' }}">
                  <button class="btn btn-xs btn-default" type="submit">Sil</button>
                </form>
              </li>
            @endforeach
          </ul>
        @endif
      </div>
    </div>

    <div class="box">
      <div class="box-header with-border">
        <h3 class="box-title">Log</h3>
      </div>
      <div class="box-body" style="max-height:220px;overflow:auto">
        @forelse($logs as $log)
          <div>
            <small class="text-muted">{{ $log['at'] ?? '' }}</small>
            <strong>[{{ $log['level'] ?? '' }}]</strong>
            {{ $log['message'] ?? '' }}
          </div>
        @empty
          <p class="text-muted">Henüz log yok.</p>
        @endforelse
      </div>
    </div>
  </div>
</div>
