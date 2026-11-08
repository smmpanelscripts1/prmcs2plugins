<?php

namespace PrmCs2Plugins;

/**
 * Plugin kategorileri — Discord tag + isim/açıklama tahmini.
 * Eşleşmeyenler Other.
 */
class Category
{
    public const ALL = [
        'Admin',
        'Stats',
        'Gamemodes',
        'Gameplay',
        'Fun',
        'Technical/Tools',
        'Other',
    ];

    /** Discord / serbest metin → canonical */
    private const ALIASES = [
        'admin' => 'Admin',
        'stats' => 'Stats',
        'stat' => 'Stats',
        'rank' => 'Stats',
        'ranks' => 'Stats',
        'gamemodes' => 'Gamemodes',
        'gamemode' => 'Gamemodes',
        'game modes' => 'Gamemodes',
        'gameplay' => 'Gameplay',
        'fun' => 'Fun',
        'technical/tools' => 'Technical/Tools',
        'technical' => 'Technical/Tools',
        'tools' => 'Technical/Tools',
        'tool' => 'Technical/Tools',
        'community' => 'Other',
        'other' => 'Other',
        'dependency' => 'Technical/Tools',
        'framework' => 'Technical/Tools',
        'custom' => 'Other',
    ];

    /**
     * Anahtar kelime kuralları (öncelik sırası önemli).
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'Admin' => [
            'admin', 'simpleadmin', 'ban', 'mute', 'kick', 'warn', 'rcon', 'whitelist',
            'adminchat', 'admin-chat', 'adminlist', 'admin-list', 'sourcebans', 'css-bans',
            'report', 'calladmin', 'moderation', 'permission', 'staff',
        ],
        'Stats' => [
            'rank plugin', 'ranks plugin', 'stats plugin', 'statistic', 'k4-system', 'k4system',
            'k4-zenith', 'zenith', 'leaderboard', 'scoreboard', 'elo rank', ' /rank', '!rank',
            'kill stats', 'player stats', 'cs2-ranks', 'cs2-stats',
        ],
        'Gamemodes' => [
            'retake', 'retakes', 'deathmatch', 'jailbreak', 'zombie', 'zombies', 'matchzy',
            'execute', 'executes', 'aim map', 'aimtrain', 'wingman', '1v1', '1vs1', 'pug',
            'scrim', 'practice', 'surf', 'bhop', 'minigame', 'multimod', 'gamemode',
            'game mode', 'arena', 'gungame', 'arms race', 'hidenseek', 'hide and seek',
            'automix', 'openprefire', 'prefire', 'match plugin', 'competitive match',
        ],
        'Fun' => [
            'emote', 'dance', 'fortnite', 'trail', 'glow', 'fun', 'paintball', 'costume',
            'pet ', 'balloon', 'firework', 'meme', 'joke', 'particle', 'effect', 'skins fun',
            'kill effect', 'killfeed', 'bullet effect',
        ],
        'Gameplay' => [
            'weapon', 'paint', 'skin', 'gloves', 'knife', 'damage', 'grenade', 'defuse',
            'plant', 'spawn', 'recoil', 'spread', 'armor', 'health', 'hp ', 'ammo',
            'buy', 'inventory', 'agent', 'model changer', 'playermodel', 'vipcore feature',
            'alwaysweaponskins', 'weaponpaints', 'reserved', 'slot',
        ],
        'Technical/Tools' => [
            'fix', 'api', 'menu', 'library', 'shared', 'logger', 'discord', 'database',
            'mysql', 'sqlite', 'mapvote', 'rockthevote', 'rtv', 'votemap', 'tool',
            'manager', 'utility', 'util', 'websocket', 'http', 'webhook', 'config',
            'cleaner', 'anti', 'crash', 'metamod', 'trace', 'schema', 'gamedata',
            ' Precacher', 'precacher', 'resource', 'workshop', 'translator', 'i18n',
            'anybaselib', 'playersettings', 'menumanager', 'wasdmenu',
        ],
    ];

    public static function normalize(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $key = strtolower($raw);
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }
        foreach (self::ALL as $canonical) {
            if (strcasecmp($canonical, $raw) === 0) {
                return $canonical;
            }
        }

        return null;
    }

    public static function resolve(array $plugin): string
    {
        $candidates = [];

        foreach ($plugin['topics'] ?? [] as $topic) {
            $n = self::normalize(is_string($topic) ? $topic : null);
            if ($n !== null) {
                $candidates[$n] = true;
            }
        }

        $n = self::normalize($plugin['category'] ?? null);
        if ($n !== null) {
            $candidates[$n] = true;
        }

        $hay = mb_strtolower(implode(' ', [
            $plugin['name'] ?? '',
            $plugin['full_name'] ?? '',
            $plugin['summary'] ?? '',
            implode(' ', $plugin['topics'] ?? []),
        ]));

        foreach (self::KEYWORDS as $category => $words) {
            foreach ($words as $word) {
                if ($word !== '' && str_contains($hay, $word)) {
                    $candidates[$category] = true;
                    break;
                }
            }
        }

        unset($candidates['Other']);
        if ($candidates === []) {
            return 'Other';
        }

        // Discord birden fazla tag verebilir; en spesifik olanı seç
        foreach (self::ALL as $canonical) {
            if ($canonical === 'Other') {
                continue;
            }
            if (isset($candidates[$canonical])) {
                return $canonical;
            }
        }

        return 'Other';
    }

    /**
     * @param list<array> $items
     * @return array<string,int>
     */
    public static function counts(array $items): array
    {
        $counts = array_fill_keys(self::ALL, 0);
        foreach ($items as $item) {
            $cat = self::resolve(is_array($item) ? $item : []);
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }

        return $counts;
    }
}
