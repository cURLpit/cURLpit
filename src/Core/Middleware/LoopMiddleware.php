<?php

declare(strict_types=1);

namespace Curlpit\Core\Middleware;

use Curlpit\Core\LoopContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Executes an inner handler repeatedly while a condition holds.
 *
 * A LoopContext object is created and stored in __loop_context.
 * The condition callable receives the LoopContext so it can read
 * mutable state set by inner middleware – without violating PSR-7
 * immutability (only the reference travels in the request).
 *
 * Accumulated response bodies are merged and stored in __loop_result.
 */
class LoopMiddleware implements MiddlewareInterface
{
    private $condition;
    private $handlerFactory;
    private int $maxIterations;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        callable $condition,
        callable $handlerFactory,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        int $maxIterations = 100
    ) {
        $this->condition      = $condition;
        $this->handlerFactory = $handlerFactory;
        $this->responseFactory = $responseFactory;
        $this->streamFactory   = $streamFactory;
        $this->maxIterations   = $maxIterations;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Create a fresh context for this loop – inner middleware mutates it
        $context = new LoopContext();
        $request = $request->withAttribute('__loop_context', $context);

        $body         = '';
        $lastResponse = null;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            if (!($this->condition)($context, $request)) {
                break;
            }

            /** @var RequestHandlerInterface $loop */
            $loop         = ($this->handlerFactory)();
            $innerRequest = $request->withAttribute('__pc', 0);
            $lastResponse = $loop->handle($innerRequest);
            $body        .= (string) $lastResponse->getBody();
        }

        $mergedResponse = $this->responseFactory
            ->createResponse($lastResponse ? $lastResponse->getStatusCode() : 200)
            ->withBody($this->streamFactory->createStream($body));

        return $handler->handle(
            $request->withAttribute('__loop_result', $mergedResponse)
        );
    }
}
