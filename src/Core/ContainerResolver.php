<?php

declare(strict_types=1);

namespace Curlpit\Core;

/**
 * Minimal dependency resolver driven by a JSON definition file.
 *
 * Supports four keywords in a definition:
 *
 *   "autowire": true
 *     – Instantiate the class, resolving constructor parameters from
 *       known services and recursively from other definitions.
 *
 *   "class": "Foo\\Bar", "args": [...]
 *     – Instantiate the given class with the provided arguments.
 *       Each arg can be a scalar, a nested definition object, or a
 *       { "ref": "TypeName" } reference to another definition.
 *
 *   "ref": "TypeName"
 *     – Alias: resolve another definition by type name.
 *
 * Definitions are resolved once and cached (singleton behaviour).
 *
 * @example config/container.json
 * {
 *   "\\Psr\\Log\\LoggerInterface": {
 *     "class": "Monolog\\Logger",
 *     "args": [
 *       "app",
 *       { "class": "Monolog\\Handler\\StreamHandler", "args": ["logs/app.log", 200] }
 *     ]
 *   },
 *   "\\Middlewares\\AccessLog": { "autowire": true }
 * }
 */
final class ContainerResolver
{
    /** @var array<string, mixed> resolved singleton cache */
    private array $resolved = [];

    /**
     * @param array<string, mixed>  $definitions  parsed container.json
     * @param array<string, mixed>  $services     pre-built instances (e.g. PSR-17 factories)
     */
    public function __construct(
        private readonly array $definitions,
        private readonly array $services = [],
    ) {}

    public static function fromFile(string $path, array $services = []): self
    {
        if (!file_exists($path)) {
            return new self([], $services);
        }

        $raw = json_decode(file_get_contents($path), true);

        if (!is_array($raw)) {
            throw new \RuntimeException("Invalid container.json: {$path}");
        }

        // Normalize keys: strip leading backslash so both \Foo\Bar and Foo\Bar work
        $definitions = [];
        foreach ($raw as $key => $value) {
            $definitions[ltrim($key, '\\')] = $value;
        }

        // Normalize service keys too
        $normalizedServices = [];
        foreach ($services as $key => $value) {
            $normalizedServices[ltrim($key, '\\')] = $value;
        }

        return new self($definitions, $normalizedServices);
    }

    public function has(string $type): bool
    {
        $type = ltrim($type, '\\');
        return isset($this->definitions[$type]) || isset($this->services[$type]);
    }

    public function get(string $type): mixed
    {
        $type = ltrim($type, '\\');

        if (isset($this->services[$type])) {
            return $this->services[$type];
        }

        if (array_key_exists($type, $this->resolved)) {
            return $this->resolved[$type];
        }

        if (!isset($this->definitions[$type])) {
            throw new \RuntimeException("No definition for type: {$type}");
        }

        return $this->resolved[$type] = $this->build($type, $this->definitions[$type]);
    }

    /**
     * Resolve a single inline definition – useful for resolving autowire values
     * from middleware.json without a full container definition file.
     *
     * Example: resolve(['class' => 'Monolog\Logger', 'args' => ['app', [...]]])
     */
    public function resolve(mixed $def): mixed
    {
        return $this->resolveArg($def);
    }

    // ── private ──────────────────────────────────────────────

    private function build(string $type, mixed $def): mixed
    {
        // ref: alias to another type
        if (is_array($def) && isset($def['ref'])) {
            return $this->get($def['ref']);
        }

        // autowire: reflection-based, constructor params resolved from known services/definitions
        if (is_array($def) && ($def['autowire'] ?? false) === true) {
            $class    = $def['class'] ?? $type;
            $instance = $this->autowire($class);
            return $this->applyCalls($instance, $def['calls'] ?? []);
        }

        // class + args: explicit instantiation
        if (is_array($def) && isset($def['class'])) {
            $args     = array_map(fn($arg) => $this->resolveArg($arg), $def['args'] ?? []);
            $instance = new $def['class'](...$args);
            return $this->applyCalls($instance, $def['calls'] ?? []);
        }

        // Scalar or pre-built value
        return $def;
    }

    private function autowire(string $class): object
    {
        $ref  = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if (!$ctor) return new $class();

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $typeName = $param->getType()?->getName();

            if ($typeName && $this->has($typeName)) {
                $args[] = $this->get($typeName);
                continue;
            }

            if ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(
                "Cannot autowire parameter \${$param->getName()} " .
                "(type: {$typeName}) for {$class}. " .
                "Add a definition to container.json or register a known service."
            );
        }

        return $ref->newInstanceArgs($args);
    }

    /**
     * Apply method calls to an instance after construction.
     *
     * @param array<int, array{0: string, 1?: array}> $calls
     *   Each call: ["methodName", [arg1, arg2, ...]]
     *   Args are resolved the same way as constructor args.
     */
    private function applyCalls(object $instance, array $calls): object
    {
        foreach ($calls as $call) {
            $method   = $call[0];
            $args     = array_map(fn($arg) => $this->resolveArg($arg), $call[1] ?? []);
            $returned = $instance->$method(...$args);
            // Support both mutable (returns void/$this) and immutable (returns new clone)
            if (is_object($returned)) {
                $instance = $returned;
            }
        }
        return $instance;
    }

    private function resolveArg(mixed $arg): mixed
    {
        if (!is_array($arg)) {
            return $arg;
        }

        // Inline ref
        if (isset($arg['ref'])) {
            return $this->get($arg['ref']);
        }

        // Inline class instantiation
        if (isset($arg['class'])) {
            return $this->build($arg['class'], $arg);
        }

        // Plain array – resolve each element recursively
        return array_map(fn($item) => $this->resolveArg($item), $arg);
    }
}
