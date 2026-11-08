<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PrmCs2Plugins\Catalog;
use PrmCs2Plugins\HelperClient;
use PrmCs2Plugins\Installer;
use PrmCs2Plugins\Store;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Models\Server;

$lib = '/blueprint_extensions/prmcs2plugins/lib';
if (!is_dir($lib)) {
    $lib = dirname(__DIR__) . '/lib';
}
require_once $lib . '/Store.php';
require_once $lib . '/GitHub.php';
require_once $lib . '/HelperClient.php';
require_once $lib . '/Category.php';
require_once $lib . '/Catalog.php';
require_once $lib . '/RemoteCatalog.php';
require_once $lib . '/Installer.php';

$admin = ['web', 'auth', AdminAuthenticate::class];

Route::middleware($admin)->group(function () {
    Route::post('/catalog/refresh', function (Request $request) {
        $serverId = (int) $request->input('server_id', 0);
        try {
            $remote = RemoteCatalog::asCatalogItems(true);

            return redirect('/admin/extensions/prmcsplugins' . ($serverId ? ('?server_id=' . $serverId) : ''))
                ->with('success', 'Topluluk kataloğu yenilendi: ' . ($remote['count'] ?? 0) . ' plugin.');
        } catch (\Throwable $e) {
            return redirect('/admin/extensions/prmcsplugins' . ($serverId ? ('?server_id=' . $serverId) : ''))
                ->with('error', $e->getMessage());
        }
    })->name('prmcsplugins.catalog.refresh');

    Route::post('/settings', function (Request $request) {
        Store::put([
            'helper_url' => trim((string) $request->input('helper_url', 'http://prm-plugin-helper:8100'))
                ?: 'http://prm-plugin-helper:8100',
        ]);

        return redirect('/admin/extensions/prmcsplugins')->with('success', 'Ayarlar kaydedildi.');
    })->name('prmcs2plugins.settings');

    Route::post('/custom', function (Request $request) {
        $id = preg_replace('/[^a-z0-9\\-_]/i', '', (string) $request->input('id', '')) ?: '';
        $name = trim((string) $request->input('name', ''));
        $download = trim((string) $request->input('download', ''));
        $detect = trim((string) $request->input('detect', ''));
        $dest = trim((string) $request->input('dest_rel', 'game/csgo/addons/counterstrikesharp'))
            ?: 'game/csgo/addons/counterstrikesharp';

        if ($id === '' || $name === '' || $download === '') {
            return redirect('/admin/extensions/prmcsplugins')->with('error', 'id, name ve download zorunlu.');
        }

        $data = Store::all();
        $customs = is_array($data['custom_plugins'] ?? null) ? $data['custom_plugins'] : [];
        $customs = array_values(array_filter($customs, fn ($p) => ($p['id'] ?? '') !== $id));
        $customs[] = [
            'id' => $id,
            'name' => $name,
            'summary' => trim((string) $request->input('summary', '')),
            'download' => $download,
            'asset_regex' => trim((string) $request->input('asset_regex', '\\.zip$')) ?: '\\.zip$',
            'dest_rel' => $dest,
            'mode' => 'merge',
            'detect' => $detect,
            'requires' => ['counterstrikesharp'],
            'category' => 'custom',
        ];
        Store::put(['custom_plugins' => $customs]);

        return redirect('/admin/extensions/prmcsplugins')->with('success', 'Özel paket eklendi: ' . $id);
    })->name('prmcs2plugins.custom');

    Route::post('/custom/delete', function (Request $request) {
        $id = (string) $request->input('id', '');
        $data = Store::all();
        $customs = is_array($data['custom_plugins'] ?? null) ? $data['custom_plugins'] : [];
        $customs = array_values(array_filter($customs, fn ($p) => ($p['id'] ?? '') !== $id));
        Store::put(['custom_plugins' => $customs]);

        return redirect('/admin/extensions/prmcsplugins')->with('success', 'Özel paket silindi.');
    })->name('prmcs2plugins.custom.delete');

    Route::post('/install', function (Request $request) {
        @set_time_limit(0);
        $serverId = (int) $request->input('server_id');
        $pluginId = (string) $request->input('plugin_id');
        $server = Server::query()->with(['egg', 'nest'])->findOrFail($serverId);

        try {
            $result = Installer::install($server, $pluginId);

            return redirect('/admin/extensions/prmcsplugins?server_id=' . $serverId)
                ->with('success', $pluginId . ' kuruldu (' . ($result['files'] ?? '?') . ' dosya). Sunucuyu restart et.');
        } catch (\Throwable $e) {
            Store::log('error', 'Install failed', ['plugin' => $pluginId, 'error' => $e->getMessage()]);

            return redirect('/admin/extensions/prmcsplugins?server_id=' . $serverId)
                ->with('error', $e->getMessage());
        }
    })->name('prmcs2plugins.install');

    Route::post('/uninstall', function (Request $request) {
        $serverId = (int) $request->input('server_id');
        $pluginId = (string) $request->input('plugin_id');
        $server = Server::query()->findOrFail($serverId);

        try {
            Installer::uninstall($server, $pluginId);

            return redirect('/admin/extensions/prmcsplugins?server_id=' . $serverId)
                ->with('success', $pluginId . ' kaldırıldı. Sunucuyu restart et.');
        } catch (\Throwable $e) {
            return redirect('/admin/extensions/prmcsplugins?server_id=' . $serverId)
                ->with('error', $e->getMessage());
        }
    })->name('prmcs2plugins.uninstall');

    Route::post('/install-url', function (Request $request) {
        @set_time_limit(0);
        $serverId = (int) $request->input('server_id');
        $url = (string) $request->input('url');
        $dest = trim((string) $request->input('dest_rel', 'game/csgo/addons/counterstrikesharp'))
            ?: 'game/csgo/addons/counterstrikesharp';
        $server = Server::query()->findOrFail($serverId);

        try {
            $result = Installer::installCustomUrl($server, $url, $dest);

            return redirect('/admin/extensions/prmcsplugins?server_id=' . $serverId)
                ->with('success', 'Zip kuruldu (' . ($result['files'] ?? '?') . ' dosya).');
        } catch (\Throwable $e) {
            return redirect('/admin/extensions/prmcsplugins?server_id=' . $serverId)
                ->with('error', $e->getMessage());
        }
    })->name('prmcs2plugins.install_url');
});
