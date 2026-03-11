<?php

declare(strict_types=1);

namespace Curlpit\Core;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 compliant request handler with flow control.
 *
 * State is carried in request attributes, not in instance fields:
 *   __pc        – program counter (current middleware index)
 *   __jump_to   – label name to jump to on next dispatch (consumed immediately)
 *
 * This makes the handler stateless and safely reusable / cloneable.
 */
class RequestHandler implements RequestHandlerInterface
{
    /** @var MiddlewareInterface[] */
    private array $middleware = [];

    /** @var array<string, int> label => index */
    private array $labels = [];

    private ResponseFactoryInterface $responseFactory;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        array $middlewareStack = []
    ) {
        $this->responseFactory = $responseFactory;

        foreach ($middlewareStack as [$middleware, $label]) {
            $this->add($middleware, $label);
        }
    }

    public function add(MiddlewareInterface $middleware, ?string $label = null): void
    {
        $this->middleware[] = $middleware;
        if ($label !== null && $label !== '') {
            $this->labels[$label] = count($this->middleware) - 1;
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Resolve program counter
        $index = $request->getAttribute('__pc', 0);

        // Handle jump: consume __jump_to and repoint __pc
        $jumpTo = $request->getAttribute('__jump_to');
        if ($jumpTo !== null) {
            if (!isset($this->labels[$jumpTo])) {
                throw new \RuntimeException("Unknown jump label: {$jumpTo}");
            }
            $index   = $this->labels[$jumpTo];
            $request = $request->withoutAttribute('__jump_to');
        }

        // Advance counter for next dispatch
        $request = $request->withAttribute('__pc', $index + 1);

        if (!isset($this->middleware[$index])) {
            return $this->responseFactory->createResponse(404);
        }

        return $this->middleware[$index]->process($request, $this);
    }

    public function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }
}
