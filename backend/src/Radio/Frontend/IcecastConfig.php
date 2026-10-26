<?php

declare(strict_types=1);

namespace App\Radio\Frontend;

use NowPlaying\Result\Client;

final class IcecastConfig
{
    private const string PUBLIC_SOCKET_ID = 'public';
    private const string PROXY_SOCKET_ID = 'azuracast-proxy';
    private const string EXTERNAL_PROXY_SOCKET_ID = 'external-proxy';
    private const string DEFAULT_PROXY_CLIENT_ADDRESS = '127.0.0.1';

    /**
     * Icecast 2.5 reserves one source slot for the fallback file associated
     * with each configured station mount.
     */
    public static function getSourceLimit(int $mountCount): int
    {
        return $mountCount * 2;
    }

    public static function getFallbackOverride(): string
    {
        return 'all';
    }

    /**
     * @return array<int, array<string, int|string|array<int, string>>>
     */
    public static function getListenSockets(int $port, ?string $proxyClientAddress = null): array
    {
        $proxyClientAddress = trim($proxyClientAddress ?? '');

        $trustedProxies = ['#' . self::PROXY_SOCKET_ID];
        $proxySockets = [
            [
                '@id' => self::PROXY_SOCKET_ID,
                '@type' => 'virtual',
                'client-address' => self::DEFAULT_PROXY_CLIENT_ADDRESS,
            ],
        ];

        if ('' !== $proxyClientAddress && self::DEFAULT_PROXY_CLIENT_ADDRESS !== $proxyClientAddress) {
            $trustedProxies[] = '#' . self::EXTERNAL_PROXY_SOCKET_ID;
            $proxySockets[] = [
                '@id' => self::EXTERNAL_PROXY_SOCKET_ID,
                '@type' => 'virtual',
                'client-address' => $proxyClientAddress,
            ];
        }

        return [
            [
                '@id' => self::PUBLIC_SOCKET_ID,
                'port' => $port,
                'trusted-proxy' => $trustedProxies,
            ],
            ...$proxySockets,
        ];
    }

    /**
     * Parses Icecast's /admin/listclients XML into unique listeners.
     *
     * Icecast 2.5 names the fields <id>, <ip>, <useragent>, <connected>;
     * Icecast-KH used <ID>, <IP>, <UserAgent>, <Connected>. The NowPlaying
     * library only reads the KH names, which under 2.5 left every listener with
     * a blank IP and user agent (so all listeners collapsed into one).
     *
     * @return Client[]|null Null when the response isn't valid XML.
     */
    public static function parseListClients(string $xml): ?array
    {
        $xml = preg_replace('/&(?!#?[a-z0-9]+;)/i', '&amp;', $xml) ?? '';

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (false === $doc) {
            return null;
        }

        $clients = [];
        foreach ($doc->xpath('/icestats/source/listener') ?: [] as $listener) {
            $fields = [];
            foreach ($listener->children() as $child) {
                $fields[strtolower($child->getName())] = trim((string)$child);
            }

            $client = new Client(
                $fields['id'] ?? trim((string)$listener['id']),
                $fields['ip'] ?? '',
                $fields['useragent'] ?? '',
                (int)($fields['connected'] ?? 0)
            );

            $clients[md5($client->ip . $client->userAgent)] ??= $client;
        }

        return array_values($clients);
    }
}
