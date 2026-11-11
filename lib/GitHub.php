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

        $candidates = [];
        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '' || !preg_match('/' . $assetRegex . '/i', $name)) {
                continue;
            }
            $size = (int) ($asset['size'] ?? 0);
            $candidates[] = [
                'name' => $name,
                'url' => $url,
                'size' => $size,
                'score' => self::scoreAsset($name, $size, $repo),
            ];
        }
        if ($candidates === []) {
            throw new \RuntimeException("No matching zip asset for {$repo} (regex: {$assetRegex})");
        }

        usort($candidates, static function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['size'] <=> $b['size'];
        });

        return $candidates[0]['url'];
    }

    public static function scoreAsset(string $name, int $size, string $repo = ''): int
    {
        $n = strtolower($name);
        $score = 0;
        if (preg_match('/website|webpanel|web-panel|frontend|dashboard/', $n)) {
            $score -= 200;
        }
        if (preg_match('/windows|win64|win32|-win\\.zip/', $n)) {
            $score -= 100;
        }
        if (preg_match('/with-cssharp|with-runtime|with-metamod|full-pack|fullpack/', $n)) {
            $score -= 80;
        }
        if (preg_match('/sourcecode|[-.]src\\.zip|docs\\.zip/', $n)) {
            $score -= 40;
        }
        if (str_contains($n, 'linux')) {
            $score += 12;
        }
        $repoName = strtolower(basename(str_replace('\\', '/', $repo)));
        $repoName = preg_replace('/[^a-z0-9]+/', '', $repoName) ?? '';
        $flat = preg_replace('/[^a-z0-9]+/', '', $n) ?? '';
        if ($repoName !== '' && strlen($repoName) >= 4 && str_contains($flat, $repoName)) {
            $score += 20;
        }
        if ($size > 40 * 1024 * 1024) {
            $score -= 50;
        }

        return $score;
    }

    public static function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
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
