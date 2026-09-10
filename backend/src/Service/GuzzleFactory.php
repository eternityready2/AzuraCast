<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;

final readonly class GuzzleFactory
{
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
        $mergedConfig = array_merge($this->defaultConfig, $config);
        $handlerStack = $mergedConfig['handler'] ?? HandlerStack::create();

        if ($handlerStack instanceof HandlerStack) {
            $handlerStack = clone $handlerStack;
            $handlerStack->push(
                static function (callable $handler): callable {
                    return static function (RequestInterface $request, array $options) use ($handler) {
                        $userAgent = $request->getHeaderLine('User-Agent');
                        $isPodcastMediaDownload = str_starts_with(
                            $userAgent,
                            'AzuraCast/1.0 (Podcast Import)'
                        ) && array_key_exists(RequestOptions::SINK, $options);

                        if ($isPodcastMediaDownload) {
                            $host = strtolower($request->getUri()->getHost());
                            $isBuzzsprout = $host === 'buzzsprout.com'
                                || str_ends_with($host, '.buzzsprout.com');

                            if ($isBuzzsprout) {
                                $request = $request->withHeader(
                                    'User-Agent',
                                    'Mozilla/5.0 (compatible; AzuraCast Podcast Import/1.0; +https://www.azuracast.com/)'
                                );
                            }
                        }

                        return $handler($request, $options);
                    };
                },
                'podcast-buzzsprout-user-agent'
            );
            $mergedConfig['handler'] = $handlerStack;
        }

        return new Client($mergedConfig);
    }
}
