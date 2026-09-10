<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;

final readonly class GuzzleFactory
{
    private const PODCAST_FEED_TIMEOUT = 240.0;
    private const PODCAST_FEED_CONNECT_TIMEOUT = 15.0;

    public function __construct(
        private array $defaultConfig = []
    ) {
    }

    public function withConfig(array $defaultConfig): self
    {
        return new self($defaultConfig);
    }

    public function withAddedConfig(array $config): self
    {
        return new self(array_merge($this->defaultConfig, $config));
    }

    public function getDefaultConfig(): array
    {
        return $this->defaultConfig;
    }

    public function getHandlerStack(): HandlerStack
    {
        return $this->defaultConfig['handler'] ?? HandlerStack::create();
    }

    public function buildClient(array $config = []): Client
    {
        $clientConfig = array_merge($this->defaultConfig, $config);
        $handlerStack = $clientConfig['handler'] ?? HandlerStack::create();

        if ($handlerStack instanceof HandlerStack) {
            $handlerStack = clone $handlerStack;
            $handlerStack->unshift(
                static function (callable $handler): callable {
                    return static function (RequestInterface $request, array $options) use ($handler) {
                        $userAgent = $request->getHeaderLine('User-Agent');
                        $isPodcastRequest = str_starts_with($userAgent, 'AzuraCast/1.0 (Podcast');
                        $isFeedRequest = !array_key_exists(RequestOptions::SINK, $options);

                        if ($isPodcastRequest && $isFeedRequest) {
                            $timeout = (float)($options[RequestOptions::TIMEOUT] ?? 0.0);
                            if ($timeout > 0.0 && $timeout < self::PODCAST_FEED_TIMEOUT) {
                                $options[RequestOptions::TIMEOUT] = self::PODCAST_FEED_TIMEOUT;
                            }

                            $connectTimeout = (float)($options[RequestOptions::CONNECT_TIMEOUT] ?? 0.0);
                            if ($connectTimeout <= 0.0 || $connectTimeout > self::PODCAST_FEED_CONNECT_TIMEOUT) {
                                $options[RequestOptions::CONNECT_TIMEOUT] = self::PODCAST_FEED_CONNECT_TIMEOUT;
                            }
                        }

                        return $handler($request, $options);
                    };
                },
                'podcast_feed_timeout'
            );
            $clientConfig['handler'] = $handlerStack;
        }

        return new Client($clientConfig);
    }
}
