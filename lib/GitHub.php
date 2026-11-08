<?php

namespace PrmCs2Plugins;

class GitHub
{
    public static function latestZipUrl(string $repo, string $assetRegex = '\\.zip$'): string
    {
        $repo = trim($repo, '/');
        $api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $json = self::getJson($api);
        $assets = $json['assets'] ?? [];
        if (!is_array($assets) || $assets === []) {
            throw new \RuntimeException("GitHub release has no assets: {$repo}");
        }

        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url !== '' && preg_match('/' . $assetRegex . '/i', $name)) {
                return $url;
            }
        }

        throw new \RuntimeException("No matching zip asset for {$repo} (regex: {$assetRegex})");
    }

    public static function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: PRM-CS2-Plugin-Installer/1.0',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            throw new \RuntimeException('GitHub API failed (' . $code . '): ' . ($err ?: substr((string) $raw, 0, 200)));
        }

        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid GitHub JSON');
        }

        return $json;
    }
}
