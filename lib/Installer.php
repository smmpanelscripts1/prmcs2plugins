<?php

namespace PrmCs2Plugins;

use Pterodactyl\Models\Server;

class Installer
{
    public static function isCs2Server(Server $server): bool
    {
        $uuid = (string) $server->uuid;
        if (HelperClient::exists($uuid, 'game/cs2.sh') || HelperClient::exists($uuid, 'game/csgo')) {
            return true;
        }

        $egg = strtolower((string) optional($server->egg)->name);
        $nest = strtolower((string) optional($server->nest)->name);

        return str_contains($egg, 'cs2')
            || str_contains($egg, 'counter-strike')
            || str_contains($egg, 'counterstrike')
            || str_contains($nest, 'cs2')
            || str_contains($egg, 'kitsune');
    }

    public static function installedPlugins(string $uuid): array
    {
        $entries = HelperClient::list($uuid, 'game/csgo/addons/counterstrikesharp/plugins');
        $names = [];
        foreach ($entries as $entry) {
            if (!empty($entry['dir']) && ($entry['name'] ?? '') !== '' && ($entry['name'] ?? '') !== 'README.txt') {
                $names[] = (string) $entry['name'];
            }
        }

        return $names;
    }

    public static function statusFor(Server $server): array
    {
        $uuid = (string) $server->uuid;
        $pluginsDir = self::installedPlugins($uuid);
        $pluginsDirLower = array_map('strtolower', $pluginsDir);

        $local = [];
        foreach (Catalog::all() as $plugin) {
            $local[] = array_merge($plugin, [
                'installed' => self::isInstalled($uuid, $plugin, $pluginsDirLower),
                'installable' => self::isInstallable($plugin),
                'source' => $plugin['source'] ?? 'builtin',
            ]);
        }

        $remoteMeta = ['count' => 0, 'generated_at' => null, 'fetched_at' => null, 'error' => null];
        $community = [];
        try {
            $remote = RemoteCatalog::asCatalogItems();
            $remoteMeta['count'] = (int) ($remote['count'] ?? 0);
            $remoteMeta['discord_count'] = (int) ($remote['discord_count'] ?? 0);
            $remoteMeta['source'] = $remote['source'] ?? null;
            $remoteMeta['generated_at'] = $remote['generated_at'] ?? null;
            $remoteMeta['fetched_at'] = $remote['fetched_at'] ?? null;
            $remoteMeta['categories'] = $remote['categories'] ?? [];
            foreach ($remote['items'] as $plugin) {
                $community[] = array_merge($plugin, [
                    'installed' => self::isInstalled($uuid, $plugin, $pluginsDirLower),
                    'installable' => self::isInstallable($plugin),
                    'category' => $plugin['category'] ?? Category::resolve($plugin),
                ]);
            }
        } catch (\Throwable $e) {
            $remoteMeta['error'] = $e->getMessage();
        }

        foreach ($local as &$localPlugin) {
            $localPlugin['category'] = Category::resolve($localPlugin);
        }
        unset($localPlugin);

        return [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'uuid' => $uuid,
            ],
            'framework' => [
                'metamod' => HelperClient::exists($uuid, 'game/csgo/addons/metamod'),
                'counterstrikesharp' => HelperClient::exists($uuid, 'game/csgo/addons/counterstrikesharp'),
            ],
            'plugins_dir' => $pluginsDir,
            'catalog' => $local,
            'community' => $community,
            'remote' => $remoteMeta,
            'categories' => $remoteMeta['categories'] ?? Category::counts($community),
        ];
    }

    public static function isInstalled(string $uuid, array $plugin, ?array $pluginsDirLower = null): bool
    {
        if ($pluginsDirLower !== null) {
            $names = [];
            if (!empty($plugin['name'])) {
                $names[] = strtolower((string) $plugin['name']);
            }
            if (!empty($plugin['detect'])) {
                $names[] = strtolower(basename(str_replace('\\', '/', (string) $plugin['detect'])));
            }
            foreach ($plugin['optional_detect'] ?? [] as $p) {
                $names[] = strtolower(basename(str_replace('\\', '/', (string) $p)));
            }
            foreach (array_unique(array_filter($names)) as $name) {
                if (in_array($name, $pluginsDirLower, true)) {
                    return true;
                }
            }
        }

        $paths = [];
        if (!empty($plugin['detect'])) {
            $paths[] = (string) $plugin['detect'];
        }
        foreach ($plugin['optional_detect'] ?? [] as $p) {
            $paths[] = (string) $p;
        }
        foreach ($paths as $path) {
            if ($path !== '' && HelperClient::exists($uuid, $path)) {
                return true;
            }
        }

        return false;
    }

    public static function isInstallable(array $plugin): bool
    {
        if (isset($plugin['enabled']) && $plugin['enabled'] === false) {
            return false;
        }
        if (array_key_exists('installable', $plugin) && $plugin['installable'] === false) {
            return false;
        }
        $download = (string) ($plugin['download'] ?? '');
        if ($download === '') {
            return false;
        }

        return true;
    }

    public static function resolveDownloadUrl(array $plugin): string
    {
        $download = trim((string) ($plugin['download'] ?? ''));
        if ($download === '') {
            throw new \RuntimeException('Download URL missing');
        }
        if (str_starts_with($download, 'github:')) {
            $repo = substr($download, strlen('github:'));
            $regex = (string) ($plugin['asset_regex'] ?? '\\.zip$');

            return GitHub::latestZipUrl($repo, $regex);
        }
        if (str_starts_with($download, 'http://') || str_starts_with($download, 'https://')) {
            return $download;
        }

        throw new \RuntimeException('Unsupported download source');
    }

    public static function install(Server $server, string $pluginId): array
    {
        $plugin = Catalog::find($pluginId);
        if (!$plugin) {
            throw new \RuntimeException('Plugin not found: ' . $pluginId);
        }
        if (!self::isInstallable($plugin)) {
            throw new \RuntimeException('This package cannot be auto-installed yet: ' . $pluginId);
        }

        $uuid = (string) $server->uuid;
        foreach ($plugin['requires'] ?? [] as $req) {
            $reqPlugin = Catalog::find((string) $req);
            if ($reqPlugin && !self::isInstalled($uuid, $reqPlugin)) {
                throw new \RuntimeException("Önce şunu kur: {$req}");
            }
        }

        $url = self::resolveDownloadUrl($plugin);
        $dest = (string) ($plugin['dest_rel'] ?? 'game/csgo/addons/counterstrikesharp');
        $mode = (string) ($plugin['mode'] ?? 'merge');
        $result = HelperClient::install($uuid, $url, $dest, $mode, $plugin['strip_hint'] ?? null);
        Store::log('info', 'Installed ' . $pluginId, [
            'server' => $server->id,
            'url' => $url,
            'files' => $result['files'] ?? null,
        ]);

        return $result;
    }

    public static function uninstall(Server $server, string $pluginId): array
    {
        $plugin = Catalog::find($pluginId);
        if (!$plugin) {
            throw new \RuntimeException('Plugin not found: ' . $pluginId);
        }
        if (($plugin['category'] ?? '') === 'framework') {
            throw new \RuntimeException('Framework kaldırma desteklenmiyor (MetaMod/CSS).');
        }
        $detect = (string) ($plugin['detect'] ?? '');
        if ($detect === '') {
            throw new \RuntimeException('Bu paket için kaldırma yolu tanımlı değil.');
        }

        $result = HelperClient::remove((string) $server->uuid, $detect);
        Store::log('info', 'Uninstalled ' . $pluginId, ['server' => $server->id]);

        return $result;
    }

    public static function installCustomUrl(Server $server, string $url, string $destRel = 'game/csgo/addons/counterstrikesharp'): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('URL http(s) olmalı');
        }
        if (!str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?: ''), '.zip') && !str_contains($url, 'github.com')) {
            // still allow github release asset URLs without .zip in path edge cases
        }

        $result = HelperClient::install((string) $server->uuid, $url, $destRel, 'merge', null);
        Store::log('info', 'Custom zip installed', ['server' => $server->id, 'url' => $url]);

        return $result;
    }
}
