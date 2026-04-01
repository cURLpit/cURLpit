<?php

declare(strict_types=1);

namespace Curlpit\Core\Middleware;

use Curlpit\Core\LoopContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redirects the program counter to a named label based on a condition.
 *
 * Unconditional jump (condition always true):
 *   new JumpMiddleware(fn() => true, 'myLabel')
 *
 * Conditional jump (branch):
 *   new JumpMiddleware($condition, 'trueLabel', 'falseLabel')
 *   – if condition is true  → jump to $jumpToLabel
 *   – if condition is false → jump to $elseLabel (if set), else continue
 *
 * The condition callable receives (LoopContext $ctx, ServerRequestInterface $req).
 * Outside a loop, $ctx is a fresh empty LoopContext.
 */
class JumpMiddleware implements MiddlewareInterface
{
    private $condition;
    private string $jumpToLabel;
    private ?string $elseLabel;

    public function __construct(callable $condition, string $jumpToLabel, ?string $elseLabel = null)
    {
        $this->condition   = $condition;
        $this->jumpToLabel = $jumpToLabel;
        $this->elseLabel   = $elseLabel;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ctx = $request->getAttribute('__loop_context') ?? new LoopContext();

        if (($this->condition)($ctx, $request)) {
            return $handler->handle(
                $request->withAttribute('__jump_to', $this->jumpToLabel)
            );
        }

        if ($this->elseLabel !== null) {
            return $handler->handle(
                $request->withAttribute('__jump_to', $this->elseLabel)
            );
        }

        return $handler->handle($request);
    }
}
