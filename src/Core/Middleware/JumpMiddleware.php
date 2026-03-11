<?php

declare(strict_types=1);

namespace Curlpit\Core\Middleware;

use Curlpit\Core\LoopContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Conditionally redirects the program counter to a named label.
 *
 * The condition callable receives (LoopContext $ctx, ServerRequestInterface $req).
 * Outside a loop, $ctx is a fresh empty LoopContext.
 */
class JumpMiddleware implements MiddlewareInterface
{
    private $condition;
    private string $jumpToLabel;

    public function __construct(callable $condition, string $jumpToLabel)
    {
        $this->condition   = $condition;
        $this->jumpToLabel = $jumpToLabel;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ctx = $request->getAttribute('__loop_context') ?? new LoopContext();

        if (($this->condition)($ctx, $request)) {
            return $handler->handle(
                $request->withAttribute('__jump_to', $this->jumpToLabel)
            );
        }

        return $handler->handle($request);
    }
}
