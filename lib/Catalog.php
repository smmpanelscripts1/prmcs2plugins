<?php

namespace PrmCs2Plugins;

class Catalog
{
    /**
     * Built-in CS2 framework + popular CSS plugins.
     * download: github:<owner>/<repo> or direct https zip URL
     * asset_regex: pick GitHub release asset
     * dest_rel: extract target under server volume
     * mode: merge | replace_dir
     * detect: relative path that means "installed"
     * requires: other catalog ids that should exist first
     */
    public static function builtin(): array
    {
        return [
            [
                'id' => 'metamod',
                'name' => 'MetaMod:Source',
                'category' => 'framework',
                'summary' => 'CS2 plugin framework tabanı. CounterStrikeSharp için gerekli.',
                'download' => 'https://mms.alliedmods.net/mmsdrop/2.0/mmsource-latest-linux.tar.gz',
                'format' => 'tar.gz',
                'dest_rel' => 'game/csgo',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/metamod',
                'requires' => [],
            ],
            [
                'id' => 'counterstrikesharp',
                'name' => 'CounterStrikeSharp',
                'category' => 'framework',
                'summary' => 'C# plugin runtime (with-runtime linux). MetaMod üstüne kurulur.',
                'download' => 'github:roflmuffin/CounterStrikeSharp',
                'asset_regex' => 'with-runtime-linux.*\\.zip$',
                'dest_rel' => 'game/csgo',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp',
                'requires' => [],
            ],
            [
                'id' => 'anybaselib',
                'name' => 'AnyBaseLibCS2',
                'category' => 'dependency',
                'summary' => 'DB API — PlayerSettings ve birçok eklentinin bağımlılığı.',
                'download' => 'github:NickFox007/AnyBaseLibCS2',
                'asset_regex' => '\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp/shared/AnyBaseLib',
                'requires' => ['counterstrikesharp'],
                'optional_detect' => [
                    'game/csgo/addons/counterstrikesharp/plugins/AnyBaseLib',
                    'game/csgo/addons/counterstrikesharp/shared/AnyBaseLibCS2',
                ],
            ],
            [
                'id' => 'playersettings',
                'name' => 'PlayerSettingsCS2',
                'category' => 'dependency',
                'summary' => 'Oyuncu ayarları API — menü / admin eklentileri için.',
                'download' => 'github:NickFox007/PlayerSettingsCS2',
                'asset_regex' => '\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp/plugins/PlayerSettings',
                'requires' => ['counterstrikesharp', 'anybaselib'],
                'optional_detect' => [
                    'game/csgo/addons/counterstrikesharp/plugins/PlayerSettingsCS2',
                ],
            ],
            [
                'id' => 'menumanager',
                'name' => 'CS2MenuManager',
                'category' => 'dependency',
                'summary' => 'Modern menü altyapısı (WASD / chat / HTML menü).',
                'download' => 'github:schwarper/CS2MenuManager',
                'asset_regex' => '\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp/shared/CS2MenuManager',
                'requires' => ['counterstrikesharp'],
                'optional_detect' => [
                    'game/csgo/addons/counterstrikesharp/plugins/CS2MenuManager',
                    'game/csgo/addons/counterstrikesharp/plugins/MenuManagerCS2',
                ],
            ],
            [
                'id' => 'wasdmenu',
                'name' => 'WASDMenuAPI',
                'category' => 'dependency',
                'summary' => 'Klasik WASD menü API.',
                'download' => 'github:Interesting-exe/WASDMenuAPI',
                'asset_regex' => '\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp/plugins/WASDMenuAPI',
                'requires' => ['counterstrikesharp'],
                'optional_detect' => [
                    'game/csgo/addons/counterstrikesharp/shared/WASDMenuAPI',
                ],
            ],
            [
                'id' => 'simpleadmin',
                'name' => 'CS2-SimpleAdmin',
                'category' => 'plugin',
                'summary' => 'Ban, mute, kick ve admin menüsü. En yaygın CS2 admin eklentisi.',
                'download' => 'github:daffyyyy/CS2-SimpleAdmin',
                'asset_regex' => 'CS2-SimpleAdmin.*\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => 'game/csgo/addons/counterstrikesharp/plugins/CS2-SimpleAdmin',
                'requires' => ['counterstrikesharp'],
            ],
        ];
    }

    public static function all(): array
    {
        $custom = Store::all()['custom_plugins'] ?? [];
        if (!is_array($custom)) {
            $custom = [];
        }

        $map = [];
        foreach (self::builtin() as $item) {
            $map[$item['id']] = $item;
        }
        foreach ($custom as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $id = (string) $item['id'];
            $map[$id] = array_merge([
                'category' => 'custom',
                'summary' => '',
                'asset_regex' => '\\.zip$',
                'dest_rel' => 'game/csgo/addons/counterstrikesharp',
                'mode' => 'merge',
                'detect' => '',
                'requires' => ['counterstrikesharp'],
            ], $item);
        }

        return array_values($map);
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }

        if (str_starts_with($id, 'gh:')) {
            return RemoteCatalog::find($id);
        }

        return null;
    }
}

