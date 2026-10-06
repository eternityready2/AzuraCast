<?php

declare(strict_types=1);

namespace PureUnit;

use App\Radio\Frontend\IcecastConfig;
use App\Xml\Writer;
use PHPUnit\Framework\TestCase;

final class IcecastConfigTest extends TestCase
{
    public function testIcecast25ReservesSourceSlotsForMountsAndFallbackFiles(): void
    {
        self::assertSame(2, IcecastConfig::getSourceLimit(1));
        self::assertSame(6, IcecastConfig::getSourceLimit(3));
    }

    public function testIcecast25UsesNamedFallbackOverrideMode(): void
    {
        self::assertSame('all', IcecastConfig::getFallbackOverride());
    }

    public function testTrustedProxyVirtualSocketDefaultsToBuiltInProxy(): void
    {
        $xml = Writer::toString(
            ['listen-socket' => IcecastConfig::getListenSockets(8000)],
            'icecast',
            false
        );

        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="public">
                    <port>8000</port>
                    <trusted-proxy>#azuracast-proxy</trusted-proxy>
                </listen-socket>
            XML,
            $xml
        );
        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="azuracast-proxy" type="virtual">
                    <client-address>127.0.0.1</client-address>
                </listen-socket>
            XML,
            $xml
        );
    }

    public function testConfiguredExternalProxyIsAddedWithoutRemovingBuiltInProxy(): void
    {
        $xml = Writer::toString(
            ['listen-socket' => IcecastConfig::getListenSockets(8000, '192.168.1.50')],
            'icecast',
            false
        );

        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="public">
                    <port>8000</port>
                    <trusted-proxy>#azuracast-proxy</trusted-proxy>
                    <trusted-proxy>#external-proxy</trusted-proxy>
                </listen-socket>
            XML,
            $xml
        );
        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="azuracast-proxy" type="virtual">
                    <client-address>127.0.0.1</client-address>
                </listen-socket>
            XML,
            $xml
        );
        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="external-proxy" type="virtual">
                    <client-address>192.168.1.50</client-address>
                </listen-socket>
            XML,
            $xml
        );
    }

    public function testLoopbackAddressDoesNotCreateDuplicateTrustedProxy(): void
    {
        $xml = Writer::toString(
            ['listen-socket' => IcecastConfig::getListenSockets(8000, '127.0.0.1')],
            'icecast',
            false
        );

        self::assertSame(1, substr_count($xml, '<trusted-proxy>'));
        self::assertSame(1, substr_count($xml, '<client-address>127.0.0.1</client-address>'));
    }

    public function testParsesIcecast25ListClients(): void
    {
        $clients = IcecastConfig::parseListClients(
            <<<'XML'
            <?xml version="1.0"?>
            <icestats><source mount="/radio.mp3"><listeners>3</listeners>
            <listener id="10855"><id>10855</id><ip>172.127.140.205</ip>
            <useragent>Chrome & Co</useragent><connected>1863</connected></listener>
            <listener id="10856"><id>10856</id><ip>99.66.12.67</ip>
            <useragent>VLC/3.0</useragent><connected>12</connected></listener>
            <listener id="10857"><id>10857</id><ip>99.66.12.67</ip>
            <useragent>VLC/3.0</useragent><connected>5</connected></listener>
            </source></icestats>
            XML
        );

        self::assertNotNull($clients);
        self::assertCount(2, $clients);
        self::assertSame('10855', $clients[0]->uid);
        self::assertSame('172.127.140.205', $clients[0]->ip);
        self::assertSame('Chrome & Co', $clients[0]->userAgent);
        self::assertSame(1863, $clients[0]->connectedSeconds);
        self::assertSame('99.66.12.67', $clients[1]->ip);
    }

    public function testParsesIcecastKhListClients(): void
    {
        $clients = IcecastConfig::parseListClients(
            '<icestats><source mount="/radio.mp3"><listener><IP>1.2.3.4</IP>'
            . '<UserAgent>Winamp</UserAgent><Connected>60</Connected><ID>7</ID></listener></source></icestats>'
        );

        self::assertNotNull($clients);
        self::assertCount(1, $clients);
        self::assertSame('7', $clients[0]->uid);
        self::assertSame('1.2.3.4', $clients[0]->ip);
        self::assertSame('Winamp', $clients[0]->userAgent);
        self::assertSame(60, $clients[0]->connectedSeconds);
    }

    public function testInvalidListClientsXmlReturnsNull(): void
    {
        self::assertNull(IcecastConfig::parseListClients('not xml'));
    }
}
