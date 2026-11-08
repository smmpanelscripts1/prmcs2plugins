<?php

namespace PrmCs2Plugins;

/**
 * Topluluk kataloğu:
 * 1) Discord #plugin-releases scrape (bundled JSON) — birincil
 * 2) css-plugins.requi.dev — yedek / birleştirme
 */
class RemoteCatalog
{
    public const REQUI_URL = 'https://css-plugins.requi.dev/api/plugins';

    public static function discordPath(): string
    {
        $candidates = [
            '/blueprint_extensions/prmcs2plugins/data/discord-plugin-releases.json',
            dirname(__DIR__) . '/data/discord-plugin-releases.json',
            storage_path('app/prmcsplugins/discord-plugin-releases.json'),
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }

    public static function cachePath(): string
    {
        $dir = storage_path('app/prmcsplugins');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . '/remote-catalog.json';
    }

    public static function asCatalogItems(bool $force = false): array
    {
        $discord = self::fromDiscord();
        $requi = [];
        $github = [];
        try {
            $requi = self::fromRequi($force);
        } catch (\Throwable $e) {
            // opsiyonel
        }
        try {
            $github = self::fromGitHubSeed();
        } catch (\Throwable $e) {
            // opsiyonel
        }

        $map = [];
        foreach ([$requi, $github, $discord['items']] as $batch) {
            foreach ($batch as $item) {
                $key = strtolower((string) ($item['full_name'] ?? $item['id']));
                if ($key === '') {
                    continue;
                }
                if (isset($map[$key])) {
                    $prev = $map[$key];
                    $item['stars'] = max((int) ($item['stars'] ?? 0), (int) ($prev['stars'] ?? 0));
                    $item['last_release'] = $item['last_release'] ?? ($prev['last_release'] ?? null);
                    if (empty($item['summary']) && !empty($prev['summary'])) {
                        $item['summary'] = $prev['summary'];
                    }
                    if (($item['source'] ?? '') === 'discord' && !empty($prev['name']) && str_contains((string) $item['name'], ' — ')) {
                        // keep discord display, but preserve stars from github/requi
                    }
                    $item['installable'] = !empty($item['installable']) || !empty($prev['installable']);
                    $topics = array_values(array_unique(array_merge($prev['topics'] ?? [], $item['topics'] ?? [])));
                    $item['topics'] = $topics;
                }
                $map[$key] = $item;
            }
        }

        $items = array_values($map);
        foreach ($items as &$item) {
            $item['category'] = Category::resolve($item);
        }
        unset($item);

        usort($items, static function ($a, $b) {
            $sa = (int) ($a['stars'] ?? 0);
            $sb = (int) ($b['stars'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return strtolower((string) ($a['name'] ?? '')) <=> strtolower((string) ($b['name'] ?? ''));
        });

        return [
            'generated_at' => $discord['scraped_at'] ?? null,
            'fetched_at' => date('c'),
            'count' => count($items),
            'source' => 'discord+requi+github',
            'discord_count' => count($discord['items']),
            'categories' => Category::counts($items),
            'items' => $items,
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::asCatalogItems()['items'] as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }

        return null;
    }

    private static function fromDiscord(): array
    {
        $path = self::discordPath();
        if (!is_file($path)) {
            return ['items' => [], 'scraped_at' => null];
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return ['items' => [], 'scraped_at' => null];
        }

        $tagMap = [];
        foreach ($raw['tags'] ?? [] as $tag) {
            if (!empty($tag['id'])) {
                $tagMap[(string) $tag['id']] = (string) ($tag['name'] ?? '');
            }
        }

        $items = [];
        $seenRepos = [];
        foreach ($raw['plugins'] ?? [] as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }
            $name = trim((string) ($plugin['name'] ?? ''));
            if ($name === '' || strcasecmp($name, 'del') === 0) {
                continue;
            }
            $githubs = is_array($plugin['github'] ?? null) ? $plugin['github'] : [];
            if ($githubs === []) {
                continue;
            }

            $tags = [];
            foreach ($plugin['applied_tags'] ?? [] as $tid) {
                if (isset($tagMap[(string) $tid])) {
                    $tags[] = $tagMap[(string) $tid];
                }
            }

            // Thread içindeki TÜM GitHub repo linklerini ayrı paket olarak al
            foreach ($githubs as $repoUrl) {
                $repoUrl = (string) $repoUrl;
                if (!preg_match('~^https?://github\.com/([^/]+)/([^/\?#]+)~i', $repoUrl, $m)) {
                    continue;
                }
                $owner = $m[1];
                $repo = $m[2];
                // org/meta gürültüsü
                if (preg_match('~^(awesome-|dotnet|DSharpPlus)~i', $repo)) {
                    continue;
                }
                $fullName = $owner . '/' . $repo;
                $fullKey = strtolower($fullName);
                if (isset($seenRepos[$fullKey])) {
                    continue;
                }
                $seenRepos[$fullKey] = true;

                $displayName = $name;
                // Birden fazla repo varsa repo adını öne çıkar
                if (count($githubs) > 1) {
                    $displayName = $repo . ' — ' . $name;
                }

                $id = 'gh:' . $fullKey;
                $items[] = [
                    'id' => $id,
                    'name' => $displayName,
                    'category' => $tags[0] ?? 'community',
                    'summary' => trim((string) ($plugin['content_preview'] ?? '')),
                    'download' => 'github:' . $fullName,
                    'asset_regex' => '\\.zip$',
                    'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                    'mode' => 'merge',
                    'detect' => 'game/csgo/addons/counterstrikesharp/plugins/' . $repo,
                    'optional_detect' => [
                        'game/csgo/addons/counterstrikesharp/plugins/' . $name,
                        'game/csgo/addons/counterstrikesharp/plugins/' . preg_replace('/^\[.*?\]\s*/', '', $name),
                    ],
                    'requires' => ['counterstrikesharp'],
                    'stars' => 0,
                    'html_url' => 'https://github.com/' . $fullName,
                    'last_release' => null,
                    'topics' => $tags,
                    'owner' => $owner,
                    'full_name' => $fullName,
                    'source' => 'discord',
                    'discord_id' => (string) ($plugin['id'] ?? ''),
                    'archived' => !empty($plugin['archived']),
                    'installable' => true,
                ];
            }
        }

        return [
            'items' => $items,
            'scraped_at' => $raw['scraped_at'] ?? null,
        ];
    }

    private static function bundledRequiPath(): string
    {
        $candidates = [
            '/blueprint_extensions/prmcs2plugins/data/requi-plugins.json',
            dirname(__DIR__) . '/data/requi-plugins.json',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }

    private static function fromGitHubSeed(): array
    {
        $candidates = [
            '/blueprint_extensions/prmcs2plugins/data/github-css-plugins.json',
            dirname(__DIR__) . '/data/github-css-plugins.json',
        ];
        $path = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
            return [];
        }
        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload) || !isset($payload['plugins'])) {
            return [];
        }

        $items = [];
        foreach ($payload['plugins'] as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }
            $fullName = trim((string) ($plugin['fullName'] ?? ''));
            $name = trim((string) ($plugin['name'] ?? ''));
            $owner = trim((string) ($plugin['owner'] ?? ''));
            if ($fullName === '' || $name === '' || $owner === '') {
                continue;
            }
            if (str_starts_with(strtolower($name), 'awesome-')) {
                continue;
            }
            // Framework repo ayrı builtin'de
            if (strtolower($fullName) === 'roflmuffin/counterstrikesharp') {
                continue;
            }
            $items[] = [
                'id' => 'gh:' . strtolower($fullName),
                'name' => $name,
                'category' => 'community',
                'summary' => (string) ($plugin['description'] ?? ''),
                'download' => 'github:' . $fullName,
                'asset_regex' => '\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp/plugins/' . $name,
                'optional_detect' => [],
                'requires' => ['counterstrikesharp'],
                'stars' => (int) ($plugin['stars'] ?? 0),
                'html_url' => (string) ($plugin['htmlUrl'] ?? ('https://github.com/' . $fullName)),
                'last_release' => null,
                'topics' => is_array($plugin['topics'] ?? null) ? $plugin['topics'] : [],
                'owner' => $owner,
                'full_name' => $fullName,
                'source' => 'github',
                'archived' => !empty($plugin['archived']),
                'installable' => true,
            ];
        }

        return $items;
    }

    private static function fromRequi(bool $force = false): array
    {
        $path = self::cachePath();
        $ttl = 6 * 3600;
        $payload = null;

        if (!$force && is_file($path) && (time() - filemtime($path)) < $ttl) {
            $payload = json_decode((string) file_get_contents($path), true);
        }

        if ((!is_array($payload) || !isset($payload['plugins'])) && !$force) {
            $bundled = self::bundledRequiPath();
            if (is_file($bundled)) {
                $payload = json_decode((string) file_get_contents($bundled), true);
            }
        }

        if (!is_array($payload) || !isset($payload['plugins']) || $force) {
            $ch = curl_init(self::REQUI_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: PRM-CS2-Plugin-Installer/1.2',
                ],
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw !== false && $code < 400) {
                $live = json_decode((string) $raw, true);
                if (is_array($live) && isset($live['plugins'])) {
                    $payload = $live;
                    $payload['fetchedAt'] = date('c');
                    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        if (!is_array($payload) || !isset($payload['plugins'])) {
            throw new \RuntimeException('Requi catalog unavailable');
        }

        $items = [];
        foreach ($payload['plugins'] as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }
            $fullName = trim((string) ($plugin['fullName'] ?? ''));
            $name = trim((string) ($plugin['name'] ?? ''));
            $owner = trim((string) ($plugin['owner'] ?? ''));
            if ($fullName === '' || $name === '' || $owner === '') {
                continue;
            }
            if (str_starts_with(strtolower($name), 'awesome-')) {
                continue;
            }
            $items[] = [
                'id' => 'gh:' . strtolower($fullName),
                'name' => $name,
                'category' => 'community',
                'summary' => (string) ($plugin['description'] ?? ''),
                'download' => 'github:' . $fullName,
                'asset_regex' => '\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp/plugins/' . $name,
                'optional_detect' => [],
                'requires' => ['counterstrikesharp'],
                'stars' => (int) ($plugin['stars'] ?? 0),
                'html_url' => (string) ($plugin['htmlUrl'] ?? ('https://github.com/' . $fullName)),
                'last_release' => $plugin['lastRelease'] ?? null,
                'topics' => is_array($plugin['topics'] ?? null) ? $plugin['topics'] : [],
                'owner' => $owner,
                'full_name' => $fullName,
                'source' => 'requi',
                'installable' => !empty($plugin['lastRelease']),
            ];
        }

        return $items;
    }
}
