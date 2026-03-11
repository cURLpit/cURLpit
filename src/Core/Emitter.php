<?php

declare(strict_types=1);

namespace Curlpit\Core;

use Psr\Http\Message\ResponseInterface;

/**
 * Emits a PSR-7 response to the SAPI (browser / CLI).
 *
 * Call emit() after Application::handle() to send the response.
 * Handles chunked streaming for large bodies automatically.
 */
final class Emitter
{
    private int $chunkSize;

    public function __construct(int $chunkSize = 8192)
    {
        $this->chunkSize = $chunkSize;
    }

    public function emit(ResponseInterface $response): void
    {
        $this->assertNoOutputSent();
        $this->sendStatus($response);
        $this->sendHeaders($response);
        $this->sendBody($response);
    }

    // ── private ──────────────────────────────────────────────

    private function assertNoOutputSent(): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException(
                "Headers already sent in {$file} on line {$line} – cannot emit response."
            );
        }
    }

    private function sendStatus(ResponseInterface $response): void
    {
        $code   = $response->getStatusCode();
        $reason = $response->getReasonPhrase();
        $proto  = $response->getProtocolVersion();

        header("HTTP/{$proto} {$code} {$reason}", true, $code);
    }

    private function sendHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header("{$name}: {$value}", $first);
                $first = false;
            }
        }
    }

    private function sendBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read($this->chunkSize);
            if (connection_aborted()) {
                break;
            }
        }
    }
}
