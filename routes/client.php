<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PrmCs2Plugins\Installer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
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

Route::middleware([AuthenticateServerAccess::class])->group(function () {
    Route::get('/servers/{server}/catalog', function (Request $request, Server $server) {
        @set_time_limit(120);

        try {
            $status = Installer::statusFor($server);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $category = trim((string) $request->query('category', ''));
        $onlyInstalled = $request->boolean('installed') || $category === 'installed';
        if ($category === 'installed') {
            $category = '';
        }
        if ($category !== '' && !in_array($category, \PrmCs2Plugins\Category::ALL, true)) {
            $category = '';
        }
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(80, max(8, (int) $request->query('per_page', 40)));

        $all = $status['community'] ?? [];
        $categories = $status['categories'] ?? \PrmCs2Plugins\Category::counts($all);
        $onDisk = $status['plugins_dir'] ?? [];
        $matchedFolders = [];
        foreach ($all as $plugin) {
            if (!empty($plugin['installed_folder'])) {
                $matchedFolders[strtolower((string) $plugin['installed_folder'])] = true;
            }
        }
        $diskOnly = [];
        foreach ($onDisk as $folder) {
            if (isset($matchedFolders[strtolower((string) $folder)])) {
                continue;
            }
            $diskOnly[] = [
                'id' => 'disk:' . $folder,
                'name' => $folder,
                'summary' => 'Sunucu klasöründe bulundu (katalogda yok).',
                'stars' => 0,
                'html_url' => '',
                'full_name' => '',
                'category' => 'Other',
                'installed' => true,
                'installed_folder' => $folder,
                'installable' => false,
                'topics' => [],
                'source' => 'disk',
            ];
        }

        $items = array_values(array_filter($all, static function (array $plugin) use ($q, $category, $onlyInstalled) {
            if ($onlyInstalled && empty($plugin['installed'])) {
                return false;
            }
            $pluginCategory = $plugin['category'] ?? \PrmCs2Plugins\Category::resolve($plugin);
            if ($category !== '' && $pluginCategory !== $category) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            $hay = mb_strtolower(implode(' ', [
                $plugin['name'] ?? '',
                $plugin['full_name'] ?? '',
                $plugin['summary'] ?? '',
                $pluginCategory,
                implode(' ', $plugin['topics'] ?? []),
            ]));

            return str_contains($hay, $q);
        }));
        if ($onlyInstalled) {
            $items = array_merge($items, $diskOnly);
        }
        usort($items, static function ($a, $b) {
            $ia = !empty($a['installed']);
            $ib = !empty($b['installed']);
            if ($ia !== $ib) {
                return $ib <=> $ia;
            }

            return ((int) ($b['stars'] ?? 0)) <=> ((int) ($a['stars'] ?? 0));
        });
        $installedCount = 0;
        foreach ($all as $plugin) {
            if (!empty($plugin['installed'])) {
                $installedCount++;
            }
        }
        $installedCount += count($diskOnly);

        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        $mapped = array_map(static function (array $plugin) {
            return [
                'id' => $plugin['id'] ?? '',
                'name' => $plugin['name'] ?? '',
                'summary' => $plugin['summary'] ?? '',
                'stars' => (int) ($plugin['stars'] ?? 0),
                'html_url' => $plugin['html_url'] ?? '',
                'full_name' => $plugin['full_name'] ?? '',
                'category' => $plugin['category'] ?? 'Other',
                'installed' => !empty($plugin['installed']),
                'installed_folder' => $plugin['installed_folder'] ?? null,
                'installable' => !empty($plugin['installable']),
                'topics' => $plugin['topics'] ?? [],
                'source' => $plugin['source'] ?? '',
            ];
        }, $slice);

        return response()->json([
            'count' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'query' => $q,
            'category' => $category,
            'categories' => $categories,
            'framework' => $status['framework'] ?? [],
            'installed_count' => $installedCount,
            'installed_on_disk' => $onDisk,
            'items' => $mapped,
        ]);
    });

    Route::post('/servers/{server}/install', function (Request $request, Server $server) {
        @set_time_limit(0);
        $pluginId = (string) $request->input('plugin_id', '');
        if ($pluginId === '') {
            return response()->json(['error' => 'plugin_id required'], 422);
        }

        try {
            $result = Installer::install($server, $pluginId, $request->boolean('install_deps'));

            return response()->json([
                'ok' => true,
                'plugin_id' => $pluginId,
                'files' => $result['files'] ?? null,
                'added' => $result['added'] ?? null,
            ]);
        } catch (\PrmCs2Plugins\MissingRequirement $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'needs_framework',
                'missing' => $e->missing,
            ], 409);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    Route::post('/servers/{server}/uninstall', function (Request $request, Server $server) {
        $pluginId = (string) $request->input('plugin_id', '');
        if ($pluginId === '') {
            return response()->json(['error' => 'plugin_id required'], 422);
        }

        try {
            Installer::uninstall($server, $pluginId);

            return response()->json(['ok' => true, 'plugin_id' => $pluginId]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });
});
