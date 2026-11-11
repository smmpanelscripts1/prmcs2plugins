import React, { useCallback, useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { Button } from '@/components/elements/button/index';
import Input from '@/components/elements/Input';
import http, { httpErrorToHuman } from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';

type PluginItem = {
    id: string;
    name: string;
    summary?: string;
    stars?: number;
    html_url?: string;
    full_name?: string;
    category?: string;
    installed?: boolean;
    installed_folder?: string | null;
    installable?: boolean;
    topics?: string[];
    source?: string;
};

type CatalogResponse = {
    count: number;
    page: number;
    per_page: number;
    total_pages: number;
    query: string;
    category?: string;
    categories?: Record<string, number>;
    framework?: { metamod?: boolean; counterstrikesharp?: boolean };
    installed_count?: number;
    installed_on_disk?: string[];
    items: PluginItem[];
};

const PER_PAGE = 40;
const CATEGORY_ORDER = ['Admin', 'Stats', 'Gamemodes', 'Gameplay', 'Fun', 'Technical/Tools', 'Other'];

const categoryColor = (category?: string) => {
    switch (category) {
        case 'Admin':
            return tw`bg-red-600/30 text-red-300`;
        case 'Stats':
            return tw`bg-blue-600/30 text-blue-300`;
        case 'Gamemodes':
            return tw`bg-purple-600/30 text-purple-300`;
        case 'Gameplay':
            return tw`bg-green-600/30 text-green-300`;
        case 'Fun':
            return tw`bg-pink-600/30 text-pink-300`;
        case 'Technical/Tools':
            return tw`bg-yellow-600/30 text-yellow-300`;
        default:
            return tw`bg-neutral-600/30 text-neutral-300`;
    }
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const [query, setQuery] = useState('');
    const [debounced, setDebounced] = useState('');
    const [category, setCategory] = useState('');
    const [onlyInstalled, setOnlyInstalled] = useState(false);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [data, setData] = useState<CatalogResponse | null>(null);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(query.trim()), 250);
        return () => clearTimeout(t);
    }, [query]);

    useEffect(() => {
        setPage(1);
    }, [debounced, category, onlyInstalled]);

    const load = useCallback(() => {
        setLoading(true);
        clearFlashes('prm-plugins');
        http
            .get<CatalogResponse>(`/api/client/extensions/prmcsplugins/servers/${uuid}/catalog`, {
                params: {
                    q: debounced,
                    category: onlyInstalled ? '' : category,
                    page,
                    per_page: PER_PAGE,
                    installed: onlyInstalled ? 1 : undefined,
                },
            })
            .then(({ data: payload }) => setData(payload))
            .catch((error) => clearAndAddHttpError({ key: 'prm-plugins', error }))
            .finally(() => setLoading(false));
    }, [uuid, debounced, category, onlyInstalled, page, clearFlashes, clearAndAddHttpError]);

    useEffect(() => {
        load();
    }, [load]);

    const postInstall = (pluginId: string, installDeps = false) =>
        http.post(
            `/api/client/extensions/prmcsplugins/servers/${uuid}/install`,
            { plugin_id: pluginId, install_deps: installDeps },
            { timeout: 300000 }
        );

    const install = (plugin: PluginItem) => {
        setBusyId(plugin.id);
        clearFlashes('prm-plugins');
        postInstall(plugin.id, false)
            .then(() => {
                addFlash({
                    key: 'prm-plugins',
                    type: 'success',
                    message: `${plugin.name} kuruldu. Sunucuyu restart et.`,
                });
                load();
            })
            .catch((error) => {
                const missing = error?.response?.data?.missing;
                if (error?.response?.status === 409 && Array.isArray(missing) && missing.length) {
                    const names = missing.map((m: { name?: string; id?: string }) => m.name || m.id).join(', ');
                    if (window.confirm(`${names} kurulu değil. Şimdi kuralım mı?`)) {
                        return postInstall(plugin.id, true).then(() => {
                            addFlash({
                                key: 'prm-plugins',
                                type: 'success',
                                message: `${names} ve ${plugin.name} kuruldu. Sunucuyu restart et.`,
                            });
                            load();
                        });
                    }
                    addFlash({
                        key: 'prm-plugins',
                        type: 'warning',
                        message: `Kurulum iptal. Önce ${names} gerekli.`,
                    });
                    return;
                }
                addFlash({
                    key: 'prm-plugins',
                    type: 'error',
                    message: httpErrorToHuman(error),
                });
            })
            .finally(() => setBusyId(null));
    };

    const uninstall = (plugin: PluginItem) => {
        if (!confirm(`${plugin.name} kaldırılsın mı?`)) return;
        setBusyId(plugin.id);
        clearFlashes('prm-plugins');
        http
            .post(`/api/client/extensions/prmcsplugins/servers/${uuid}/uninstall`, { plugin_id: plugin.id })
            .then(() => {
                addFlash({
                    key: 'prm-plugins',
                    type: 'success',
                    message: `${plugin.name} kaldırıldı.`,
                });
                load();
            })
            .catch((error) => {
                addFlash({
                    key: 'prm-plugins',
                    type: 'error',
                    message: httpErrorToHuman(error),
                });
            })
            .finally(() => setBusyId(null));
    };

    const categories = data?.categories || {};
    const totalAll = Object.values(categories).reduce((sum, n) => sum + (n || 0), 0);

    return (
        <ServerContentBlock title={'Plugin Install'}>
            <FlashMessageRender byKey={'prm-plugins'} css={tw`mb-4`} />

            <div css={tw`flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4`}>
                <div>
                    <h2 css={tw`text-xl text-neutral-100 font-semibold`}>CS2 Plugin Installer</h2>
                    <p css={tw`text-sm text-neutral-400 mt-1`}>
                        {data ? `${data.count} paket` : '…'}
                        {typeof data?.installed_count === 'number' ? ` · Kurulu ${data.installed_count}` : ''}
                        {category && !onlyInstalled ? ` · ${category}` : ''}
                        {onlyInstalled ? ' · Kurulu olanlar' : ''}
                    </p>
                    {data?.framework ? (
                        <div css={tw`flex flex-wrap items-center gap-2 mt-2`}>
                            <span
                                css={[
                                    tw`text-xs px-2 py-1 rounded`,
                                    data.framework.metamod ? tw`bg-green-600/30 text-green-300` : tw`bg-red-600/30 text-red-300`,
                                ]}
                            >
                                MetaMod {data.framework.metamod ? '✓' : 'yok'}
                            </span>
                            {!data.framework.metamod ? (
                                <Button
                                    css={tw`text-xs py-1`}
                                    disabled={busyId === 'metamod'}
                                    onClick={() => install({ id: 'metamod', name: 'MetaMod:Source', installable: true })}
                                >
                                    {busyId === 'metamod' ? 'Kuruluyor…' : 'MetaMod kur'}
                                </Button>
                            ) : null}
                            <span
                                css={[
                                    tw`text-xs px-2 py-1 rounded`,
                                    data.framework.counterstrikesharp
                                        ? tw`bg-green-600/30 text-green-300`
                                        : tw`bg-red-600/30 text-red-300`,
                                ]}
                            >
                                CounterStrikeSharp {data.framework.counterstrikesharp ? '✓' : 'yok'}
                            </span>
                            {!data.framework.counterstrikesharp ? (
                                <Button
                                    css={tw`text-xs py-1`}
                                    disabled={!!busyId}
                                    onClick={() =>
                                        install({ id: 'counterstrikesharp', name: 'CounterStrikeSharp', installable: true })
                                    }
                                >
                                    {busyId === 'counterstrikesharp' ? 'Kuruluyor…' : 'CSS kur'}
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                </div>
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={'Ara: WeaponPaints, SimpleAdmin, MatchZy…'}
                    css={tw`md:w-80`}
                />
            </div>

            <div css={tw`flex flex-wrap gap-2 mb-5`}>
                <button
                    type={'button'}
                    onClick={() => {
                        setOnlyInstalled(false);
                        setCategory('');
                    }}
                    css={[
                        tw`text-xs px-3 py-1.5 rounded-md border transition-colors`,
                        category === '' && !onlyInstalled
                            ? tw`bg-neutral-100 text-neutral-900 border-neutral-100`
                            : tw`bg-neutral-700 text-neutral-300 border-neutral-600 hover:border-neutral-400`,
                    ]}
                >
                    All ({totalAll || data?.count || 0})
                </button>
                <button
                    type={'button'}
                    onClick={() => {
                        setOnlyInstalled(true);
                        setCategory('');
                    }}
                    css={[
                        tw`text-xs px-3 py-1.5 rounded-md border transition-colors`,
                        onlyInstalled
                            ? tw`bg-green-500 text-neutral-900 border-green-500`
                            : tw`bg-neutral-700 text-green-300 border-neutral-600 hover:border-green-400`,
                    ]}
                >
                    Kurulu ({data?.installed_count ?? 0})
                </button>
                {CATEGORY_ORDER.map((catLabel) => {
                    const count = categories[catLabel] ?? 0;
                    if (!count && catLabel !== 'Other') return null;
                    return (
                        <button
                            key={catLabel}
                            type={'button'}
                            onClick={() => {
                                setOnlyInstalled(false);
                                setCategory(catLabel);
                            }}
                            css={[
                                tw`text-xs px-3 py-1.5 rounded-md border transition-colors`,
                                category === catLabel && !onlyInstalled
                                    ? tw`bg-neutral-100 text-neutral-900 border-neutral-100`
                                    : tw`bg-neutral-700 text-neutral-300 border-neutral-600 hover:border-neutral-400`,
                            ]}
                        >
                            {catLabel} ({count})
                        </button>
                    );
                })}
            </div>

            {loading && !data ? (
                <Spinner centered size={'large'} />
            ) : (
                <>
                    <div
                        css={tw`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4`}
                        style={{ opacity: loading ? 0.6 : 1 }}
                    >
                        {(data?.items || []).map((plugin) => (
                            <div
                                key={plugin.id}
                                css={[
                                    tw`bg-neutral-700 rounded-lg p-4 flex flex-col border transition-colors`,
                                    plugin.installed
                                        ? tw`border-green-500/70 hover:border-green-400`
                                        : tw`border-neutral-600 hover:border-neutral-400`,
                                ]}
                            >
                                <div css={tw`flex items-start justify-between gap-2 mb-2`}>
                                    <h3 css={tw`text-sm font-semibold text-neutral-100 leading-snug break-words`}>
                                        {plugin.name}
                                    </h3>
                                    <span css={tw`text-xs text-yellow-400 whitespace-nowrap`}>
                                        ★ {plugin.stars ?? 0}
                                    </span>
                                </div>
                                <div css={tw`mb-2`}>
                                    <span
                                        css={[
                                            tw`inline-block text-[10px] uppercase tracking-wide px-2 py-0.5 rounded`,
                                            categoryColor(plugin.category),
                                        ]}
                                    >
                                        {plugin.category || 'Other'}
                                    </span>
                                    {plugin.installed ? (
                                        <span css={tw`inline-block text-[10px] uppercase tracking-wide px-2 py-0.5 rounded ml-1 bg-green-600/30 text-green-300`}>
                                            Kurulu
                                            {plugin.installed_folder ? ` · ${plugin.installed_folder}` : ''}
                                        </span>
                                    ) : null}
                                </div>
                                <p css={tw`text-xs text-neutral-400 flex-1 mb-3`} style={{ minHeight: '2.5rem' }}>
                                    {(plugin.summary || 'Açıklama yok').slice(0, 120)}
                                    {(plugin.summary || '').length > 120 ? '…' : ''}
                                </p>
                                {plugin.full_name ? (
                                    <a
                                        href={plugin.html_url || '#'}
                                        target={'_blank'}
                                        rel={'noreferrer'}
                                        css={tw`text-xs text-cyan-400 hover:text-cyan-300 mb-3 truncate`}
                                    >
                                        {plugin.full_name}
                                    </a>
                                ) : null}
                                <div css={tw`mt-auto flex items-center gap-2`}>
                                    {plugin.installed ? (
                                        <>
                                            <span
                                                css={tw`text-xs px-2 py-1 rounded bg-green-600/30 text-green-300`}
                                            >
                                                Kurulu
                                            </span>
                                            <Button.Danger
                                                css={tw`ml-auto`}
                                                disabled={busyId === plugin.id}
                                                onClick={() => uninstall(plugin)}
                                            >
                                                {busyId === plugin.id ? '…' : 'Kaldır'}
                                            </Button.Danger>
                                        </>
                                    ) : plugin.installable ? (
                                        <Button
                                            css={tw`w-full`}
                                            disabled={busyId === plugin.id}
                                            onClick={() => install(plugin)}
                                        >
                                            {busyId === plugin.id ? 'Kuruluyor…' : 'Kur'}
                                        </Button>
                                    ) : (
                                        <span css={tw`text-xs text-neutral-500`}>Kurulum yok</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>

                    {!loading && (data?.items || []).length === 0 ? (
                        <p css={tw`text-center text-neutral-400 mt-8`}>Sonuç bulunamadı.</p>
                    ) : null}

                    {data && data.total_pages > 1 ? (
                        <div css={tw`flex items-center justify-center gap-3 mt-6`}>
                            <Button.Text
                                disabled={page <= 1 || loading}
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                            >
                                Önceki
                            </Button.Text>
                            <span css={tw`text-sm text-neutral-300`}>
                                {page} / {data.total_pages}
                            </span>
                            <Button.Text
                                disabled={page >= data.total_pages || loading}
                                onClick={() => setPage((p) => p + 1)}
                            >
                                Sonraki
                            </Button.Text>
                        </div>
                    ) : null}
                </>
            )}
        </ServerContentBlock>
    );
};
