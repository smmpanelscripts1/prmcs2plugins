# PRM CS2 Plugin Installer

Blueprint eklentisi: CS2 sunucularına MetaMod, CounterStrikeSharp ve CSS pluginlerini panelden kurar / kaldırır.

## Ne yapar?

- Client sunucu menüsü: **Plugin Install** (`/server/{id}/plugin-install`) — 4’lü kart grid
- Admin katalog + kurulum
- Framework / temel paketleri kur (MetaMod, CSS, SimpleAdmin…)
- Discord `#plugin-releases` + GitHub CSS araması + requi indeks
- GitHub release zip veya düz zip URL ile özel kurulum

Discord verisi: `data/discord-plugin-releases.json`

## Gereksinim

Panel PHP `nginx` kullanıcısıyla çalışır; Wings volume’ları `988:988` olduğu için **root helper** şart:

```yaml
prm-plugin-helper:
  image: python:3.12-alpine
  restart: unless-stopped
  volumes:
    - /var/lib/pterodactyl/volumes:/data
    - ./extensions/prmcs2plugins/helper.py:/helper.py:ro
  command: ["python", "/helper.py"]
```

Panel ile aynı Docker network’te olmalı. Varsayılan URL: `http://prm-plugin-helper:8100`

Helper volume kökünü otomatik dener (`/data`, `/var/lib/pterodactyl/volumes`, `/peto-volumes`, …). Farklı mount için `PRM_VOLUMES` ile virgülle kök listesi ver.

GKE / ayrı Wings node için helper’ı volume’un durduğu makinede çalıştır. Panel `prm-plugin-helper` hostname’ini çözemezse `host.docker.internal:8100`, `127.0.0.1:8100` ve node FQDN `:8100` adreslerine düşer. İstersen admin ayarından veya `PRM_PLUGIN_HELPER_URL` ile sabitle.

Windows’ta volume yolu örnek: `D:/pterodactyl/volumes:/data`

## Kurulum

```bash
blueprint -install prmcsplugins
```

Docker Blueprint’te kaynak klasör `/blueprint_extensions/prmcs2plugins`, identifier `prmcsplugins`.

## Notlar

- Kurulum sonrası sunucuyu **restart** et.
- MetaMod/CSS framework kaldırma kapalı (yanlışlıkla bozmamak için).
- Özel paketleri admin sayfasından kataloğa ekleyebilirsin (`github:owner/repo` veya zip URL).
