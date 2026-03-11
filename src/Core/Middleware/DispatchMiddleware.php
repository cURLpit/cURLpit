<?php

declare(strict_types=1);

namespace Curlpit\Core\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Terminal middleware: resolves the matched handler from a DI container
 * and delegates the request to it.
 *
 * Expects __route_handler and __route_params to be set by RoutingMiddleware.
 * Path params are merged into request attributes for handler convenience.
 *
 * The $resolver callable receives the handler class name and must return
 * a RequestHandlerInterface instance.
 */
final class DispatchMiddleware implements MiddlewareInterface
{
    private $resolver;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface   $streamFactory;

    public function __construct(
        callable                 $resolver,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface   $streamFactory,
    ) {
        $this->resolver        = $resolver;
        $this->responseFactory = $responseFactory;
        $this->streamFactory   = $streamFactory;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $handlerClass = $request->getAttribute('__route_handler');
        $status       = $request->getAttribute('__route_status', 404);

        if ($handlerClass === null) {
            return $this->errorJson($status, $status === 405 ? 'Method not allowed' : 'Not found');
        }

        // Merge path params into request attributes
        $params = $request->getAttribute('__route_params', []);
        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $resolved = ($this->resolver)($handlerClass);

        if (!$resolved instanceof \Psr\Http\Server\RequestHandlerInterface) {
            return $this->errorJson(500, "Handler '{$handlerClass}' could not be resolved");
        }

        return $resolved->handle($request);
    }

    // ── private ──────────────────────────────────────────────

    private function errorJson(int $status, string $message): ResponseInterface
    {
        $body = $this->streamFactory->createStream(
            json_encode(['error' => $message], JSON_UNESCAPED_UNICODE)
        );

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }
}
