<?php

declare(strict_types=1);

namespace Curlpit\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Executes an inner middleware body and catches exceptions.
 *
 * On success: continues the outer pipeline normally, with __try_result
 *             containing the inner handler's response.
 *
 * On exception: jumps to $catchLabel in the outer pipeline, with
 *               __exception containing the caught Throwable.
 *
 * Optionally filtered by exception class via $catchTypes – uncaught
 * types are re-thrown and propagate up to ErrorHandlerMiddleware.
 *
 * @example middleware.json
 *   {
 *     "Curlpit\\Core\\Middleware\\TryMiddleware": {
 *       "body": { "middleware": [...] },
 *       "catch_label": "handle_error",
 *       "catch_types": ["App\\Exception\\ValidationException"]
 *     }
 *   },
 *   { "Curlpit\\Core\\Middleware\\JumpMiddleware": {
 *       "condition": { "type": "attribute", "attribute": "__exception", "negate": true },
 *       "jump_to_label": "after_catch"
 *   }},
 *   ... catch body ...,
 *   { "label": "after_catch", ... }
 */
class TryMiddleware implements MiddlewareInterface
{
    private $handlerFactory;

    /** @param array<class-string<Throwable>> $catchTypes  empty = catch all */
    public function __construct(
        callable $handlerFactory,
        private readonly string $catchLabel,
        private readonly array  $catchTypes = [],
    ) {
        $this->handlerFactory = $handlerFactory;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            /** @var RequestHandlerInterface $inner */
            $inner    = ($this->handlerFactory)();
            $response = $inner->handle($request->withAttribute('__pc', 0));

            return $handler->handle(
                $request->withAttribute('__try_result', $response)
            );
        } catch (Throwable $e) {
            if (!$this->catches($e)) {
                throw $e;
            }

            return $handler->handle(
                $request
                    ->withAttribute('__exception', $e)
                    ->withAttribute('__exception_class', get_class($e))
                    ->withAttribute('__jump_to', $this->catchLabel)
            );
        }
    }

    // ── private ──────────────────────────────────────────────

    private function catches(Throwable $e): bool
    {
        if (empty($this->catchTypes)) {
            return true;
        }

        foreach ($this->catchTypes as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }
}
