<?php

namespace PrmCs2Plugins;

use Pterodactyl\Models\Server;

class MissingRequirement extends \RuntimeException
{
    /** @var list<array{id:string,name:string}> */
    public array $missing = [];

    public function __construct(array $missing)
    {
        $this->missing = $missing;
        $names = [];
        foreach ($missing as $item) {
            $names[] = (string) ($item['name'] ?? $item['id'] ?? '');
        }
        parent::__construct(trim(implode(', ', $names)) . ' kurulu değil.');
    }
}

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
        $names = [];
        foreach ([
            'game/csgo/addons/counterstrikesharp/plugins',
            'game/csgo/addons/counterstrikesharp/shared',
        ] as $rel) {
            foreach (HelperClient::list($uuid, $rel) as $entry) {
                if (empty($entry['dir']) || ($entry['name'] ?? '') === '' || ($entry['name'] ?? '') === 'README.txt') {
                    continue;
                }
                $names[] = (string) $entry['name'];
            }
        }

        return array_values(array_unique($names));
    }

    public static function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/^gh:/', '', $value) ?? $value;
        $value = preg_replace('#^.*/#', '', $value) ?? $value;
        $value = preg_replace('/\s*[—–-]\s+.+$/u', '', $value) ?? $value;
        $value = preg_replace('/\[[^\]]*\]/', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
        $value = preg_replace('/^(cs2|css)/', '', $value) ?? $value;

        return $value;
    }

    public static function namesMatch(string $a, string $b): bool
    {
        $na = self::normalizeName($a);
        $nb = self::normalizeName($b);
        if ($na === '' || $nb === '' || strlen($na) < 4 || strlen($nb) < 4) {
            return false;
        }
        if ($na === $nb) {
            return true;
        }
        $short = strlen($na) <= strlen($nb) ? $na : $nb;
        $long = $short === $na ? $nb : $na;
        if (strlen($short) >= 8 && str_contains($long, $short)) {
            return true;
        }

        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }
        $overlap = array_values(array_intersect($ta, $tb));

        return count($overlap) >= 2;
    }

    public static function pairScore(string $folder, string $name): int
    {
        $na = self::normalizeName($folder);
        $nb = self::normalizeName($name);
        if ($na === '' || $nb === '' || strlen($na) < 4 || strlen($nb) < 4) {
            return 0;
        }
        if ($na === $nb) {
            return 100;
        }
        $short = strlen($na) <= strlen($nb) ? $na : $nb;
        $long = $short === $na ? $nb : $na;
        if (strlen($short) >= 8 && str_contains($long, $short)) {
            return 80;
        }
        $overlap = array_values(array_intersect(self::tokens($folder), self::tokens($name)));
        if (count($overlap) >= 2) {
            return 40 + count($overlap) * 10;
        }

        return 0;
    }

    public static function pluginFolderScore(array $plugin, string $folder): int
    {
        $best = 0;
        foreach (self::identityNames($plugin) as $name) {
            $best = max($best, self::pairScore($folder, $name));
        }

        return $best;
    }

    /**
     * Each on-disk folder belongs to at most one catalog plugin.
     *
     * @param list<array> $plugins
     * @param list<string> $folders
     * @return list<array>
     */
    public static function assignInstalled(array $plugins, array $folders): array
    {
        $pairs = [];
        foreach ($plugins as $i => $plugin) {
            foreach ($folders as $folder) {
                $score = self::pluginFolderScore($plugin, (string) $folder);
                if ($score > 0) {
                    $pairs[] = [$score, $i, (string) $folder];
                }
            }
        }
        usort($pairs, static fn ($a, $b) => $b[0] <=> $a[0]);
        $usedFolder = [];
        $usedPlugin = [];
        $chosen = [];
        foreach ($pairs as [$score, $i, $folder]) {
            $id = (string) ($plugins[$i]['id'] ?? $i);
            $fk = strtolower($folder);
            if (isset($usedFolder[$fk]) || isset($usedPlugin[$id])) {
                continue;
            }
            $usedFolder[$fk] = true;
            $usedPlugin[$id] = true;
            $chosen[$i] = $folder;
        }
        foreach ($plugins as $i => &$plugin) {
            $folder = $chosen[$i] ?? null;
            $plugin['installed_folder'] = $folder;
            $plugin['installed'] = $folder !== null;
        }
        unset($plugin);

        return $plugins;
    }

    public static function tokens(string $value): array
    {
        $value = preg_replace('/\[[^\]]*\]/', '', $value) ?? $value;
        $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value) ?? $value;
        $value = strtolower((string) preg_replace('/[\/_\-]+/', ' ', $value));
        $value = preg_replace('/^(cs2|css)\s+/', '', $value) ?? $value;
        $drop = ['cs2' => true, 'css' => true, 'plugin' => true, 'plugins' => true, 'mod' => true, 'for' => true, 'the' => true, 'and' => true, 'with' => true, 'api' => true];
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', $value) ?: [] as $part) {
            if (strlen($part) >= 3 && !isset($drop[$part])) {
                $out[$part] = true;
            }
        }

        return array_keys($out);
    }

    public static function identityNames(array $plugin): array
    {
        $names = [
            (string) ($plugin['name'] ?? ''),
            (string) ($plugin['full_name'] ?? ''),
            basename(str_replace('\\', '/', (string) ($plugin['full_name'] ?? ''))),
            (string) ($plugin['id'] ?? ''),
            basename(str_replace('\\', '/', (string) ($plugin['detect'] ?? ''))),
        ];
        if (str_starts_with((string) ($plugin['id'] ?? ''), 'gh:')) {
            $names[] = substr((string) $plugin['id'], 3);
            $names[] = basename(str_replace(':', '/', substr((string) $plugin['id'], 3)));
        }
        foreach ($plugin['optional_detect'] ?? [] as $path) {
            $names[] = basename(str_replace('\\', '/', (string) $path));
        }

        return array_values(array_unique(array_filter($names)));
    }

    public static function matchedFolders(array $plugin, array $pluginsDir): array
    {
        $hits = [];
        foreach ($pluginsDir as $folder) {
            foreach (self::identityNames($plugin) as $name) {
                if (self::namesMatch((string) $folder, (string) $name)) {
                    $hits[] = (string) $folder;
                    break;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    public static function statusFor(Server $server): array
    {
        $uuid = (string) $server->uuid;
        $pluginsDir = self::installedPlugins($uuid);
        $pluginsDirLower = array_map('strtolower', $pluginsDir);

        $local = [];
        foreach (Catalog::all() as $plugin) {
            $plugin['_uuid'] = $uuid;
            $local[] = array_merge($plugin, [
                'installable' => self::isInstallable($plugin),
                'source' => $plugin['source'] ?? 'builtin',
            ]);
        }
        $local = self::assignInstalled($local, $pluginsDir);

        $framework = [
            'metamod' => HelperClient::exists($uuid, 'game/csgo/addons/metamod'),
            'counterstrikesharp' => HelperClient::exists($uuid, 'game/csgo/addons/counterstrikesharp'),
        ];
        foreach ($local as &$plugin) {
            $id = (string) ($plugin['id'] ?? '');
            if ($id === 'metamod') {
                $plugin['installed'] = $framework['metamod'];
                $plugin['installed_folder'] = $framework['metamod'] ? 'metamod' : null;
            }
            if ($id === 'counterstrikesharp') {
                $plugin['installed'] = $framework['counterstrikesharp'];
                $plugin['installed_folder'] = $framework['counterstrikesharp'] ? 'counterstrikesharp' : null;
            }
        }
        unset($plugin);

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
                $plugin['_uuid'] = $uuid;
                $community[] = array_merge($plugin, [
                    'installable' => self::isInstallable($plugin),
                    'category' => $plugin['category'] ?? Category::resolve($plugin),
                ]);
            }
            $community = self::assignInstalled($community, $pluginsDir);
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
            'framework' => $framework,
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
                foreach ($pluginsDirLower as $folder) {
                    if ($folder === $name || self::namesMatch($folder, $name)) {
                        return true;
                    }
                }
            }
            foreach (Store::rememberedFolders($uuid, (string) ($plugin['id'] ?? '')) as $folder) {
                if (in_array(strtolower($folder), $pluginsDirLower, true)) {
                    return true;
                }
            }

            return false;
        }

        return self::matchedFolders($plugin + ['_uuid' => $uuid], self::installedPlugins($uuid)) !== [];
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

    public static function requirementMet(string $uuid, string $reqId): bool
    {
        $plugin = Catalog::find($reqId);
        if (!$plugin) {
            return true;
        }
        $detect = (string) ($plugin['detect'] ?? '');
        if ($reqId === 'metamod' || $reqId === 'counterstrikesharp' || ($plugin['category'] ?? '') === 'framework') {
            return $detect !== '' && HelperClient::exists($uuid, $detect);
        }

        return self::isInstalled($uuid, $plugin);
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    public static function missingRequirements(string $uuid, array $plugin, array $seen = []): array
    {
        $out = [];
        $seenIds = [];
        foreach ($plugin['requires'] ?? [] as $req) {
            $req = (string) $req;
            if ($req === '' || in_array($req, $seen, true)) {
                continue;
            }
            $reqPlugin = Catalog::find($req);
            if (!$reqPlugin) {
                continue;
            }
            if (self::requirementMet($uuid, $req)) {
                continue;
            }
            foreach (self::missingRequirements($uuid, $reqPlugin, array_merge($seen, [$req])) as $nested) {
                $id = (string) ($nested['id'] ?? '');
                if ($id === '' || isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;
                $out[] = $nested;
            }
            if (!isset($seenIds[$req])) {
                $seenIds[$req] = true;
                $out[] = [
                    'id' => $req,
                    'name' => (string) ($reqPlugin['name'] ?? $req),
                ];
            }
        }

        return $out;
    }

    public static function install(Server $server, string $pluginId, bool $installDeps = false): array
    {
        $plugin = Catalog::find($pluginId);
        if (!$plugin) {
            throw new \RuntimeException('Plugin not found: ' . $pluginId);
        }
        if (!self::isInstallable($plugin)) {
            throw new \RuntimeException('This package cannot be auto-installed yet: ' . $pluginId);
        }

        $uuid = (string) $server->uuid;
        $missing = self::missingRequirements($uuid, $plugin);
        if ($missing !== []) {
            if (!$installDeps) {
                throw new MissingRequirement($missing);
            }
            foreach ($missing as $req) {
                self::install($server, (string) $req['id'], true);
            }
        }

        $before = self::installedPlugins($uuid);
        $url = self::resolveDownloadUrl($plugin);
        $dest = (string) ($plugin['dest_rel'] ?? 'game/csgo/addons/counterstrikesharp');
        $mode = (string) ($plugin['mode'] ?? 'merge');
        $result = HelperClient::install(
            $uuid,
            $url,
            $dest,
            $mode,
            $plugin['strip_hint'] ?? null,
            self::identityNames($plugin)
        );
        $after = self::installedPlugins($uuid);
        $added = array_values(array_diff($after, $before));
        $matched = self::matchedFolders($plugin, $after);
        if ($added !== [] && $matched !== []) {
            $added = array_values(array_intersect($added, $matched));
        }
        if ($added === []) {
            $added = $matched;
        }
        if ($added === [] && !empty($result['plugin_dirs']) && is_array($result['plugin_dirs'])) {
            $added = array_values(array_intersect($after, $result['plugin_dirs']));
        }
        Store::markInstalled($uuid, $pluginId, $added);
        $result['added'] = $added;
        $result['plugins'] = $after;
        $result['installed'] = $added !== [] || self::isInstalled($uuid, $plugin, array_map('strtolower', $after));
        Store::log('info', 'Installed ' . $pluginId, [
            'server' => $server->id,
            'url' => $url,
            'files' => $result['files'] ?? null,
            'added' => $added,
        ]);

        return $result;
    }

    public static function uninstall(Server $server, string $pluginId): array
    {
        $uuid = (string) $server->uuid;
        if (str_starts_with($pluginId, 'disk:')) {
            $folder = substr($pluginId, 5);
            $result = HelperClient::remove($uuid, 'game/csgo/addons/counterstrikesharp/plugins/' . $folder);
            HelperClient::remove($uuid, 'game/csgo/addons/counterstrikesharp/shared/' . $folder);
            Store::unmarkInstalled($uuid, $pluginId);
            Store::log('info', 'Uninstalled disk plugin ' . $folder, ['server' => $server->id]);

            return $result;
        }

        $plugin = Catalog::find($pluginId);
        if (!$plugin) {
            throw new \RuntimeException('Plugin not found: ' . $pluginId);
        }
        if (($plugin['category'] ?? '') === 'framework') {
            throw new \RuntimeException('Framework kaldırma desteklenmiyor (MetaMod/CSS).');
        }

        $all = self::installedPlugins($uuid);
        $matched = self::matchedFolders($plugin, $all);
        $remembered = Store::rememberedFolders($uuid, $pluginId);
        $folders = array_values(array_intersect($remembered, $matched));
        if ($folders === []) {
            $folders = $matched;
        }
        $detect = (string) ($plugin['detect'] ?? '');
        $paths = [];
        foreach ($folders as $folder) {
            $paths[] = 'game/csgo/addons/counterstrikesharp/plugins/' . $folder;
            $paths[] = 'game/csgo/addons/counterstrikesharp/shared/' . $folder;
        }
        if ($detect !== '') {
            $paths[] = $detect;
        }
        if ($paths === []) {
            throw new \RuntimeException('Bu paket için kaldırma yolu tanımlı değil.');
        }
        $result = ['ok' => true, 'removed' => false];
        foreach (array_unique($paths) as $path) {
            $result = HelperClient::remove($uuid, $path);
        }
        Store::unmarkInstalled($uuid, $pluginId);
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
