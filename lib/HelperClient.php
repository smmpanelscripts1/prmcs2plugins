<?php

namespace PrmCs2Plugins;

class HelperClient
{
    public static function bases(): array
    {
        $urls = [];
        $configured = trim((string) (Store::all()['helper_url'] ?? ''));
        if ($configured !== '' && !str_contains($configured, '127.0.0.1') && !str_contains($configured, 'localhost')) {
            $urls[] = rtrim($configured, '/');
        }
        $env = trim((string) env('PRM_PLUGIN_HELPER_URL', ''));
        if ($env !== '' && !str_contains($env, '127.0.0.1') && !str_contains($env, 'localhost')) {
            $urls[] = rtrim($env, '/');
        }
        $urls[] = 'http://prm-plugin-helper:8100';
        $urls[] = 'http://host.docker.internal:8100';

        return array_values(array_unique($urls));
    }

    public static function base(): string
    {
        return self::bases()[0];
    }

    public static function health(): array
    {
        return self::request('GET', '/health');
    }

    public static function exists(string $uuid, string $rel): bool
    {
        $res = self::request('GET', '/exists?uuid=' . rawurlencode($uuid) . '&rel=' . rawurlencode($rel));

        return !empty($res['exists']);
    }

    public static function list(string $uuid, string $rel): array
    {
        $res = self::request('GET', '/list?uuid=' . rawurlencode($uuid) . '&rel=' . rawurlencode($rel));

        return is_array($res['entries'] ?? null) ? $res['entries'] : [];
    }

    public static function install(string $uuid, string $url, string $destRel, string $mode = 'merge', ?string $stripHint = null, array $onlyNames = []): array
    {
        return self::request('POST', '/install', [
            'uuid' => $uuid,
            'url' => $url,
            'dest_rel' => $destRel,
            'mode' => $mode,
            'strip_hint' => $stripHint,
            'only_names' => array_values($onlyNames),
        ]);
    }

    public static function remove(string $uuid, string $rel): array
    {
        return self::request('POST', '/remove', [
            'uuid' => $uuid,
            'rel' => $rel,
        ]);
    }

    private static function request(string $method, string $path, ?array $body = null): array
    {
        $lastError = 'Plugin helper unreachable';
        foreach (self::bases() as $base) {
            $ch = curl_init($base . $path);
            $headers = ['Accept: application/json'];
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => $body ? 300 : 20,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CUSTOMREQUEST => $method,
            ];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                $lastError = 'Plugin helper unreachable (' . $base . '): ' . $err;
                continue;
            }

            $json = json_decode((string) $raw, true);
            if ($code === 404) {
                $lastError = "Plugin helper {$base} has no {$path}";
                continue;
            }
            if (!is_array($json)) {
                $lastError = 'Plugin helper bad response (' . $code . ') from ' . $base;
                continue;
            }
            if ($code >= 400 || (isset($json['ok']) && $json['ok'] === false)) {
                throw new \RuntimeException($json['error'] ?? ('Plugin helper error ' . $code));
            }

            return $json;
        }

        throw new \RuntimeException($lastError);
    }
}
