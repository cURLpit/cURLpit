<?php
namespace Curlpit\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GetVariableMiddleware implements MiddlewareInterface
{
    private string $name;
    private string $targetAttribute;

    public function __construct(string $name, string $targetAttribute = '__result')
    {
        $this->name            = $name;
        $this->targetAttribute = $targetAttribute;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $value = $request->getAttribute($this->name);
        return $handler->handle(
            $request->withAttribute($this->targetAttribute, $value)
        );
    }
} 