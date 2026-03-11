<?php

declare(strict_types=1);

namespace Curlpit\Core\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Matches the incoming request against a route table and sets:
 *
 *   __route_handler  – handler class short name  (e.g. "DatabaseListHandler")
 *   __route_params   – path param array           (e.g. ['db'=>'shop','table'=>'users'])
 *
 * If no route matches, sets __route_handler to null and __route_status to
 * 404 or 405 so DispatchMiddleware can respond appropriately.
 *
 * Route config format (one entry per route):
 *   { "method": "GET", "path": "/api/databases/{db}/tables", "handler": "TableListHandler" }
 */
final class RoutingMiddleware implements MiddlewareInterface
{
    /** @var array<int, array{method:string, regex:string, params:string[], handler:string}> */
    private array $compiled = [];

    /** @param array<int, array{method:string, path:string, handler:string}> $routes */
    public function __construct(private readonly array $routes)
    {
        foreach ($routes as $route) {
            $this->compiled[] = $this->compile($route['method'], $route['path'], $route['handler']);
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method  = strtoupper($request->getMethod());
        $path    = '/' . ltrim($request->getUri()->getPath(), '/');

        $methodMatched = false;

        foreach ($this->compiled as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            // Path matched – check method
            if ($route['method'] !== $method && $route['method'] !== '*') {
                $methodMatched = true; // remember for 405
                continue;
            }

            // Full match – extract named params
            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = isset($matches[$name]) ? urldecode($matches[$name]) : '';
            }

            $request = $request
                ->withAttribute('__route_handler', $route['handler'])
                ->withAttribute('__route_params',  $params)
                ->withAttribute('__route_status',  200);

            return $handler->handle($request);
        }

        // No full match
        $status  = $methodMatched ? 405 : 404;
        $request = $request
            ->withAttribute('__route_handler', null)
            ->withAttribute('__route_status',  $status);

        return $handler->handle($request);
    }

    // ── private ──────────────────────────────────────────────

    private function compile(string $method, string $path, string $handlerClass): array
    {
        $params = [];
        $regex  = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $path);

        return [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handlerClass,
        ];
    }
}
