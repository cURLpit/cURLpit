<?php

declare(strict_types=1);

namespace Curlpit\App;

use Curlpit\Core\ConfigLoader;
use Curlpit\Core\ContainerResolver;
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

    private ?ConfigLoader      $configLoader      = null;
    private ?ContainerResolver $containerResolver = null;

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
     * Default container: container.json next to middleware.json.
     * Override in subclass to change the path or return null to disable.
     */
    protected function defaultContainerResolver(): ?ContainerResolver
    {
        return null;
    }

    /**
     * Instantiate any middleware class.
     * Override in subclass to inject dependencies from a DI container.
     */
    protected function instantiate(string $class, array $options): MiddlewareInterface
    {
        // ── EXPERIMENTAL: ContainerResolver + reflection-based auto-wiring ────
        $resolver = $this->containerResolver ?? $this->defaultContainerResolver();
        if ($resolver?->has($class)) {
            $instance = $resolver->get($class);
            if (!$instance instanceof MiddlewareInterface) {
                throw new \RuntimeException("Resolved {$class} is not a MiddlewareInterface");
            }
            return $instance;
        }
        return $this->autowire($class, $options, $resolver);
        // ── END EXPERIMENTAL ──────────────────────────────────────────────────
    }

    // ── EXPERIMENTAL: auto-wiring via registerResolver ───────
    //
    // Allows any PSR-15 compliant middleware to be used directly
    // from middleware.json without subclass instantiate() overrides.
    //
    // Usage in subclass constructor:
    //   $this->registerResolver(
    //       \Psr\Log\LoggerInterface::class,
    //       fn(array $options) => (new Logger('app'))
    //           ->pushHandler(new StreamHandler($options['path'] ?? 'logs/app.log'))
    //   );
    //
    // Usage in middleware.json:
    //   {
    //     "Middlewares\\AccessLog": {
    //       "autowire": { "logger": { "path": "logs/access.log" } }
    //     }
    //   }

    /** @var array<string, callable(array): mixed> */
    private array $resolvers = [];

    public function registerResolver(string $type, callable $factory): void
    {
        $this->resolvers[$type] = $factory;
    }

    private function autowire(string $class, array $options, ?ContainerResolver $resolver = null): MiddlewareInterface
    {
        $ref  = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if (!$ctor) {
            $instance = new $class();
            foreach ($options['calls'] ?? [] as $call) {
                $method   = $call[0];
                $callArgs = $call[1] ?? [];
                $tempResolver = $resolver ?? new ContainerResolver([], [
                    \Psr\Http\Message\ResponseFactoryInterface::class => $this->responseFactory,
                    \Psr\Http\Message\StreamFactoryInterface::class   => $this->streamFactory,
                ]);
                $resolvedArgs = array_map(fn($a) => is_array($a) ? $tempResolver->resolve($a) : $a, $callArgs);
                $returned     = $instance->$method(...$resolvedArgs);
                if (is_object($returned)) {
                    $instance = $returned;
                }
            }
            return $instance;
        }

        $autowire = $options['autowire'] ?? [];
        $args     = [];

        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType()?->getName();

            // ContainerResolver
            if ($type && $resolver?->has($type)) {
                $args[] = $resolver->get($type);
                continue;
            }

            // Registered resolver for this type
            if ($type && isset($this->resolvers[$type])) {
                $args[] = ($this->resolvers[$type])($autowire[$name] ?? []);
                continue;
            }

            // Built-in PSR-17 factories
            if ($type === \Psr\Http\Message\ResponseFactoryInterface::class) {
                $args[] = $this->responseFactory;
                continue;
            }
            if ($type === \Psr\Http\Message\StreamFactoryInterface::class) {
                $args[] = $this->streamFactory;
                continue;
            }

            // Scalar/object from autowire block
            if (array_key_exists($name, $autowire)) {
                $value = $autowire[$name];
                if (is_array($value)) {
                    $tempResolver = $resolver ?? new ContainerResolver([], [
                        \Psr\Http\Message\ResponseFactoryInterface::class => $this->responseFactory,
                        \Psr\Http\Message\StreamFactoryInterface::class   => $this->streamFactory,
                    ]);
                    $args[] = $tempResolver->resolve($value);
                } else {
                    $args[] = $value;
                }
                continue;
            }

            // Scalar from options
            if (array_key_exists($name, $options)) {
                $args[] = $options[$name];
                continue;
            }

            // Optional parameter – use default
            if ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(
                "Cannot resolve parameter \${$name} (type: {$type}) for {$class}. " .
                "Add a definition to container.json, register a resolver via registerResolver(), " .
                "or add it to 'autowire' in middleware.json."
            );
        }

        $instance = $ref->newInstanceArgs($args);

        // Apply method calls if defined in options
        foreach ($options['calls'] ?? [] as $call) {
            $method     = $call[0];
            $callArgs   = $call[1] ?? [];
            $tempResolver = $resolver ?? new ContainerResolver([], [
                \Psr\Http\Message\ResponseFactoryInterface::class => $this->responseFactory,
                \Psr\Http\Message\StreamFactoryInterface::class   => $this->streamFactory,
            ]);
            $resolvedArgs = array_map(fn($a) => is_array($a) ? $tempResolver->resolve($a) : $a, $callArgs);
            $returned     = $instance->$method(...$resolvedArgs);
            if (is_object($returned)) {
                $instance = $returned;
            }
        }

        return $instance;
    }
    // ── END EXPERIMENTAL ──────────────────────────────────────

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
                        $options['else_label'] ?? null,
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

            if ($class === \Curlpit\Core\Middleware\TryMiddleware::class) {
                $bodyMiddleware = $options['body']['middleware'] ?? [];
                $bodyStack      = ConfigLoader::fromArray($bodyMiddleware)->getStack();
                $catchLabel     = $options['catch_label'];
                $catchTypes     = $options['catch_types'] ?? [];

                $handler->add(
                    new \Curlpit\Core\Middleware\TryMiddleware(
                        fn() => $this->buildHandler($bodyStack),
                        $catchLabel,
                        $catchTypes,
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
