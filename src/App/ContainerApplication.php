<?php

declare(strict_types=1);

namespace Curlpit\App;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * PSR-11 container bridge for cURLpit.
 *
 * Drop-in replacement for Application that resolves middleware from a
 * PSR-11 container first, then falls back to cURLpit's built-in autowire.
 *
 * Advantages over JSON autowire:
 *   - Interface → implementation binding
 *   - Singleton / shared instance lifecycle
 *   - Works with any DI container (PHP-DI, Symfony, Laravel, …)
 *   - Type-safe scalar injection (no stringly-typed JSON args)
 *
 * Usage:
 *   $app = new ContainerApplication($responseFactory, $streamFactory, $container);
 *   $response = $app->handle($serverRequest);
 */
final class ContainerApplication extends Application
{
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface   $streamFactory,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct($responseFactory, $streamFactory);
    }

    /**
     * Resolution order:
     *   1. PSR-11 container  (interface binding, singletons, full DI power)
     *   2. parent::instantiate()  (cURLpit JSON autowire, fallback)
     *
     * The $options array contains only middleware-specific config values
     * from middleware.json (e.g. ["debug" => false]).
     * cURLpit flow-control directives (label, condition, jump_to_label, …)
     * are consumed by RequestHandler before this method is ever called.
     *
     * When the container resolves the class, $options are intentionally
     * ignored – the container is the single source of truth for that
     * middleware's configuration.
     */
    protected function instantiate(string $class, array $options): MiddlewareInterface
    {
        if ($this->container->has($class)) {
            // If middleware.json contains a declarative autowire block for this
            // class AND the container also knows it, the container wins and the
            // JSON autowire block is silently ignored. Warn the developer so
            // stale config doesn't go unnoticed – typically this happens after
            // a refactor where the container registration was added but the
            // JSON autowire block was not cleaned up.
            if (isset($options['autowire'])) {
                trigger_error(
                    sprintf(
                        "[cURLpit] '%s' is registered in the container AND has a declarative autowire block in middleware.json. The container takes precedence; the autowire block is ignored. Remove it from middleware.json to suppress this notice.",
                        $class,
                    ),
                    E_USER_NOTICE,
                );
            }

            $middleware = $this->container->get($class);

            if (!$middleware instanceof MiddlewareInterface) {
                throw new \RuntimeException(sprintf(
                    "Container resolved '%s' but the result does not implement %s. Got: %s.",
                    $class,
                    MiddlewareInterface::class,
                    get_debug_type($middleware),
                ));
            }

            return $middleware;
        }

        // Not in container → fall back to cURLpit's built-in declarative autowire.
        // This means you can mix: container for your own middleware,
        // declarative autowire for third-party ones.
        return parent::instantiate($class, $options);
    }
}
