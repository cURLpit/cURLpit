<?php

declare(strict_types=1);

namespace Curlpit\Core;

/**
 * Mutable state container for LoopMiddleware iterations.
 *
 * PSR-7 requests are immutable, so loop state cannot be passed back
 * through request attributes the normal way. LoopContext solves this:
 * the object reference is stored as a request attribute once, and
 * middleware mutates it directly – the condition callable reads the
 * same object on every iteration.
 */
final class LoopContext
{
    private array $data;

    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }
}
