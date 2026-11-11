<?php

namespace PrmCs2Plugins;

class Store
{
    public static function path(): string
    {
        $dir = storage_path('app/prmcsplugins');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . '/settings.json';
    }

    public static function defaults(): array
    {
        return [
            'helper_url' => trim((string) env('PRM_PLUGIN_HELPER_URL', 'http://prm-plugin-helper:8100')) ?: 'http://prm-plugin-helper:8100',
            'volumes_root' => '/data',
            'custom_plugins' => [],
            'logs' => [],
            'installed_map' => [],
        ];
    }

    public static function all(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            $data = self::defaults();
            self::save($data);

            return $data;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        return array_merge(self::defaults(), $raw);
    }

    public static function save(array $data): void
    {
        file_put_contents(
            self::path(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public static function put(array $patch): array
    {
        $data = array_merge(self::all(), $patch);
        self::save($data);

        return $data;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $data = self::all();
        $logs = $data['logs'] ?? [];
        array_unshift($logs, [
            'at' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
        $data['logs'] = array_slice($logs, 0, 80);
        self::save($data);
    }

    public static function markInstalled(string $uuid, string $pluginId, array $folders): void
    {
        $data = self::all();
        $map = is_array($data['installed_map'] ?? null) ? $data['installed_map'] : [];
        if (!isset($map[$uuid]) || !is_array($map[$uuid])) {
            $map[$uuid] = [];
        }
        $map[$uuid][$pluginId] = array_values(array_unique(array_filter($folders)));
        $data['installed_map'] = $map;
        self::save($data);
    }

    public static function unmarkInstalled(string $uuid, string $pluginId): void
    {
        $data = self::all();
        $map = is_array($data['installed_map'] ?? null) ? $data['installed_map'] : [];
        unset($map[$uuid][$pluginId]);
        $data['installed_map'] = $map;
        self::save($data);
    }

    public static function rememberedFolders(string $uuid, string $pluginId): array
    {
        $map = self::all()['installed_map'] ?? [];
        $folders = $map[$uuid][$pluginId] ?? [];

        return is_array($folders) ? array_values(array_filter($folders)) : [];
    }
}
