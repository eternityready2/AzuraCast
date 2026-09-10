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
                        if (!str_starts_with($userAgent, 'AzuraCast/1.0 (Podcast Import)')) {
                            return $handler($request, $options);
                        }

                        $options[RequestOptions::CONNECT_TIMEOUT] = 15;
                        $isMediaDownload = array_key_exists(RequestOptions::SINK, $options);

                        if (!$isMediaDownload) {
                            // Large RSS feeds can legitimately take longer than the task's old 100s ceiling.
                            $options[RequestOptions::TIMEOUT] = 240;

                            return $handler($request, $options);
                        }

                        $host = strtolower($request->getUri()->getHost());
                        $isBuzzsprout = $host === 'buzzsprout.com' || str_ends_with($host, '.buzzsprout.com');
                        if ($isBuzzsprout) {
                            // Buzzsprout's audio edge can treat bot-like HTTP clients differently. Use a
                            // browser-compatible UA while still identifying AzuraCast, and allow large
                            // episodes enough time to complete before the import's atomic replacement step.
                            $request = $request
                                ->withHeader(
                                    'User-Agent',
                                    'Mozilla/5.0 (compatible; AzuraCast Podcast Import/1.0; +https://www.azuracast.com/)'
                                )
                                ->withHeader('Accept', 'audio/mpeg,audio/*;q=0.9,*/*;q=0.8');
                            $options[RequestOptions::TIMEOUT] = 1200;
                        }

                        return $handler($request, $options);
                    };
                },
                'podcast-import-network-policy'
            );
            $mergedConfig['handler'] = $handlerStack;
        }

        return new Client($mergedConfig);
    }
}
