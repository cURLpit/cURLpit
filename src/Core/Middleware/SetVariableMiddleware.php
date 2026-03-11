<?php
namespace Curlpit\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SetVariableMiddleware implements MiddlewareInterface
{
    private string $name;
    private $value;

    public function __construct(string $name, $value)
    {
        $this->name  = $name;
        $this->value = $value;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // “memory” is just request attributes
        return $handler->handle(
            $request->withAttribute($this->name, $this->value)
        );
    }
} 