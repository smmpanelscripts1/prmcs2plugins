<?php

namespace PrmCs2Plugins;

class HelperClient
{
    public static function base(): string
    {
        $url = trim((string) (Store::all()['helper_url'] ?? ''));

        return $url !== '' ? rtrim($url, '/') : 'http://prm-plugin-helper:8100';
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
        $ch = curl_init(self::base() . $path);
        $headers = ['Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
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
            throw new \RuntimeException('Plugin helper unreachable: ' . $err);
        }

        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Plugin helper bad response (' . $code . ')');
        }
        if ($code >= 400 || (isset($json['ok']) && $json['ok'] === false)) {
            throw new \RuntimeException($json['error'] ?? ('Plugin helper error ' . $code));
        }

        return $json;
    }
}
