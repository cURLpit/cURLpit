<?php

declare(strict_types=1);

namespace Curlpit\Core\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Catches all exceptions from downstream and converts them to responses.
 *
 * Response format is determined by the Accept header:
 *   - application/json (or API requests) → JSON error body
 *   - everything else                    → plain text
 *
 * In debug mode, exception details (class, message, trace) are included.
 * In production, only a generic message is returned for 500 errors.
 *
 * Exception → status code mapping can be extended via $statusMap.
 *
 * @example middleware.json
 *   { "Curlpit\\Core\\Middleware\\ErrorHandlerMiddleware": { "debug": false } }
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    /** @param array<class-string, int> $statusMap  exception class → HTTP status */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        private readonly bool                     $debug     = false,
        private readonly array                    $statusMap = [],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            return $this->handleException($request, $e);
        }
    }

    // ── private ──────────────────────────────────────────────

    private function handleException(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        $status  = $this->resolveStatus($e);
        $message = $status < 500 || $this->debug
            ? $e->getMessage()
            : 'Internal server error';

        if ($this->wantsJson($request)) {
            return $this->jsonResponse($status, $message, $e);
        }

        return $this->textResponse($status, $message, $e);
    }

    private function resolveStatus(Throwable $e): int
    {
        foreach ($this->statusMap as $class => $status) {
            if ($e instanceof $class) {
                return $status;
            }
        }

        // HttpExceptionInterface convention
        if (method_exists($e, 'getStatusCode')) {
            return (int) $e->getStatusCode();
        }

        return 500;
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        return str_contains($accept, 'application/json')
            || str_contains($accept, '*/*') === false && $accept === ''
            || str_starts_with($request->getUri()->getPath(), '/api');
    }

    private function jsonResponse(int $status, string $message, Throwable $e): ResponseInterface
    {
        $body = ['error' => $message];

        if ($this->debug) {
            $body['debug'] = [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => explode("\n", $e->getTraceAsString()),
            ];
        }

        $stream = $this->streamFactory->createStream(
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    private function textResponse(int $status, string $message, Throwable $e): ResponseInterface
    {
        $body = $this->debug
            ? "{$status} {$message}\n\n" . get_class($e) . "\n" . $e->getTraceAsString()
            : "{$status} {$message}";

        $stream = $this->streamFactory->createStream($body);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($stream);
    }
}
