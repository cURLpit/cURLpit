<?php

declare(strict_types=1);

namespace Curlpit\App;

use Curlpit\Core\ConfigLoader;
use Curlpit\Core\LoopContext;
use Curlpit\Core\RequestHandler;
use Curlpit\Core\Middleware\JumpMiddleware;
use Curlpit\Core\Middleware\LoopMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Builds and runs the middleware flow defined in a ConfigLoader.
 *
 * By default looks for middleware.json next to this file (App/Config/).
 * Override configLoader() or call withConfig() to supply your own.
 *
 * PSR-17 factories are injected so any compliant PSR-7 library works.
 */
class Application
{
    protected ResponseFactoryInterface $responseFactory;
    protected StreamFactoryInterface   $streamFactory;

    private ?ConfigLoader $configLoader = null;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface   $streamFactory,
    ) {
        $this->responseFactory = $responseFactory;
        $this->streamFactory   = $streamFactory;
    }

    /** Replace the config source before calling handle(). */
    public function withConfig(ConfigLoader $loader): static
    {
        $clone               = clone $this;
        $clone->configLoader = $loader;
        return $clone;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $loader  = $this->configLoader ?? $this->defaultConfigLoader();
        $handler = $this->buildHandler($loader->getStack());

        return $handler->handle($request->withAttribute('__pc', 0));
    }

    // ── Overrideable ─────────────────────────────────────────

    /**
     * Default config: middleware.json in App/Config/ next to this file.
     * Override in subclass to change the path.
     */
    protected function defaultConfigLoader(): ConfigLoader
    {
        return ConfigLoader::fromFile(__DIR__ . '/Config/middleware.json');
    }

    /**
     * Instantiate any middleware class.
     * Override in subclass to inject dependencies from a DI container.
     */
    protected function instantiate(string $class, array $options): MiddlewareInterface
    {
        return new $class();
    }

    // ── Builder ──────────────────────────────────────────────

    private function buildHandler(array $stack): RequestHandler
    {
        $handler = new RequestHandler($this->responseFactory);

        foreach ($stack as ['class' => $class, 'options' => $options, 'label' => $label]) {

            if ($class === \Curlpit\Core\Middleware\JumpMiddleware::class) {
                $handler->add(
                    new JumpMiddleware(
                        $this->buildCondition($options['condition'] ?? []),
                        $options['jump_to_label'],
                    ),
                    $label,
                );
                continue;
            }

            if ($class === \Curlpit\Core\Middleware\LoopMiddleware::class) {
                $bodyMiddleware = $options['body']['middleware'] ?? [];
                $bodyStack      = ConfigLoader::fromArray($bodyMiddleware)->getStack();
                $maxIterations  = (int) ($options['max_iterations'] ?? 100);
                $rf = $this->responseFactory;
                $sf = $this->streamFactory;

                $handler->add(
                    new LoopMiddleware(
                        $this->buildCondition($options['condition'] ?? []),
                        fn() => $this->buildHandler($bodyStack),
                        $rf,
                        $sf,
                        $maxIterations,
                    ),
                    $label,
                );
                continue;
            }

            if (!class_exists($class)) {
                throw new \RuntimeException("Middleware class not found: {$class}");
            }

            $handler->add($this->instantiate($class, $options), $label);
        }

        return $handler;
    }

    // ── Condition DSL ────────────────────────────────────────

    private function buildCondition(array $cfg): callable
    {
        $type = $cfg['type'] ?? 'always';

        return match ($type) {
            'always' => static fn() => true,
            'never'  => static fn() => false,

            'attr' => function (
                LoopContext $ctx,
                ServerRequestInterface $req,
            ) use ($cfg): bool {
                return $this->compare($req->getAttribute($cfg['name'] ?? ''), $cfg);
            },

            'context' => function (
                LoopContext $ctx,
            ) use ($cfg): bool {
                return $this->compare($ctx->get($cfg['name'] ?? ''), $cfg);
            },

            default => throw new \RuntimeException("Unknown condition type: {$type}"),
        };
    }

    private function compare(mixed $value, array $cfg): bool
    {
        if (isset($cfg['eq']))  return $value == $cfg['eq'];
        if (isset($cfg['neq'])) return $value != $cfg['neq'];
        if (isset($cfg['gt']))  return $value >  $cfg['gt'];
        if (isset($cfg['gte'])) return $value >= $cfg['gte'];
        if (isset($cfg['lt']))  return $value <  $cfg['lt'];
        if (isset($cfg['lte'])) return $value <= $cfg['lte'];
        return (bool) $value;
    }
}
