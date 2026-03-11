<?php

declare(strict_types=1);

namespace Curlpit\Core;

/**
 * Loads and validates a middleware.json config file.
 *
 * The JSON format is:
 *
 *   {
 *     "middleware": [
 *       { "My\\Middleware\\Class": { ...options } },
 *       { "My\\Middleware\\Class": { "label": "myLabel", ...options } }
 *     ]
 *   }
 *
 * ConfigLoader is intentionally separate from Application so that
 * config can be loaded, cached, and inspected independently.
 */
final class ConfigLoader
{
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Middleware config not found or not readable: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Failed to read middleware config: {$path}");
        }

        return self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['middleware']) || !is_array($data['middleware'])) {
            throw new \RuntimeException("Config must have a 'middleware' array");
        }

        return new self($data);
    }

    public static function fromArray(array $config): self
    {
        return new self(['middleware' => $config]);
    }

    /**
     * Returns the middleware stack as an array of [class, options] pairs.
     *
     * @return array<int, array{class: string, options: array, label: ?string}>
     */
    public function getStack(): array
    {
        $stack = [];

        foreach ($this->config['middleware'] as $entry) {
            if (!is_array($entry) || count($entry) !== 1) {
                throw new \RuntimeException(
                    "Each middleware entry must be an object with exactly one key (the class name)"
                );
            }

            $class   = array_key_first($entry);
            $options = $entry[$class] ?? [];
            $label   = $options['label'] ?? null;

            if ($label !== null) {
                unset($options['label']);
            }

            $stack[] = [
                'class'   => $class,
                'options' => $options,
                'label'   => $label,
            ];
        }

        return $stack;
    }

    public function raw(): array
    {
        return $this->config;
    }
}
