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
}
