<?php

namespace PrmCs2Plugins;

class HelperClient
{
    public static function bases(): array
    {
        $urls = [];
        $configured = trim((string) (Store::all()['helper_url'] ?? ''));
        if ($configured !== '') {
            $urls[] = rtrim($configured, '/');
        }
        $env = trim((string) env('PRM_PLUGIN_HELPER_URL', ''));
        if ($env !== '') {
            $urls[] = rtrim($env, '/');
        }
        $urls[] = 'http://prm-plugin-helper:8100';
        if (class_exists(\Pterodactyl\Models\Node::class)) {
            foreach (\Pterodactyl\Models\Node::query()->orderBy('id')->get(['fqdn']) as $node) {
                if ($node->fqdn) {
                    $urls[] = 'http://' . $node->fqdn . ':8100';
                }
            }
        }

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

    public static function install(string $uuid, string $url, string $destRel, string $mode = 'merge', ?string $stripHint = null): array
    {
        return self::request('POST', '/install', [
            'uuid' => $uuid,
            'url' => $url,
            'dest_rel' => $destRel,
            'mode' => $mode,
            'strip_hint' => $stripHint,
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
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 300,
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
                $lastError = 'Plugin helper unreachable: ' . $err;
                continue;
            }

            $json = json_decode((string) $raw, true);
            if (!is_array($json)) {
                $lastError = 'Plugin helper bad response (' . $code . ')';
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
