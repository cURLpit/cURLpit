# cURLpit (https://github.com/curlpit/curlpit)

Curlpit is a PSR-15 middleware orchestrator for PHP – with branching, looping, and declarative flow control built in.

Most middleware stacks are pipelines: request goes in, response comes out, linearly. Curlpit treats the middleware stack as an **instruction sequence** – with a program counter, named labels, conditional jumps, and loops. Business logic that would otherwise be hardcoded in handlers can be expressed as configuration.

## Installation

```bash
composer require curlpit/curlpit
```

Then install a PSR-7/17 implementation of your choice:

```bash
# nyholm/psr7 (recommended – lightweight, zero dependencies)
composer require nyholm/psr7 nyholm/psr7-server

# or guzzlehttp/psr7
composer require guzzlehttp/psr7
```

## How it differs

| | Standard PSR-15 | Curlpit |
|---|---|---|
| Execution | Linear chain | Instruction sequence (program counter) |
| Branching | None | `JumpMiddleware` (conditional goto) |
| Looping | None | `LoopMiddleware` + `LoopContext` |
| State | Informal | Explicit Set/Get/Inc/Dec middleware |
| Error handling | Manual | Built-in `ErrorHandlerMiddleware` |
| Config | Code only | JSON-declarable |

## Quick start

Extend `Application`, override `instantiate()` to wire up your dependencies, point it at a `middleware.json`:

```php
use Curlpit\App\Application;
use Curlpit\Core\Emitter;

class MyApp extends Application
{
    protected function instantiate(string $class, array $options): MiddlewareInterface
    {
        return match ($class) {
            MyMiddleware::class => new MyMiddleware($this->responseFactory),
            default             => parent::instantiate($class, $options),
        };
    }
}

$app      = new MyApp($responseFactory, $streamFactory);
$response = $app->handle($serverRequest);
(new Emitter())->emit($response);
```

## Flow config (middleware.json)

```json
{
  "middleware": [
    { "Curlpit\\Core\\Middleware\\ErrorHandlerMiddleware": { "debug": false } },
    { "My\\AuthMiddleware": {} },
    {
      "Curlpit\\Core\\Middleware\\JumpMiddleware": {
        "condition": { "type": "attr", "name": "user_role", "eq": "admin" },
        "jump_to_label": "admin"
      }
    },
    { "My\\PublicDispatch": {} },
    { "My\\AdminDispatch": { "label": "admin" } }
  ]
}
```

## Condition DSL

```json
{ "type": "always" }
{ "type": "never" }
{ "type": "attr",    "name": "status",   "eq":  "active" }
{ "type": "attr",    "name": "retries",  "lte": 3        }
{ "type": "context", "name": "has_more"                  }
{ "type": "context", "name": "count",    "gt":  0        }
```

Operators: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`. Without an operator, truthy check.

## Built-in middleware

- **`RequestHandler`** – PSR-15 handler with program counter and label-based jumps
- **`JumpMiddleware`** – conditional branch to a named label (`else_label` for two-way branch)
- **`LoopMiddleware`** – repeat a sub-handler while a condition holds
- **`TryMiddleware`** – execute a sub-handler, jump to catch label on exception
- **`LoopContext`** – mutable state container for loop iterations
- **`RoutingMiddleware`** – path pattern matching with `{param}` placeholders, 404/405 aware
- **`DispatchMiddleware`** – resolves and calls the matched handler via an injected resolver callable
- **`ErrorHandlerMiddleware`** – catches all exceptions, returns JSON or plaintext based on Accept header
- **`SetVariableMiddleware`** / **`GetVariableMiddleware`** – read/write request attributes
- **`IncrementMiddleware`** / **`DecrementMiddleware`** – numeric counters in request attributes
- **`ConfigLoader`** – loads and validates `middleware.json`, standalone and cacheable
- **`Emitter`** – sends PSR-7 responses to the SAPI with chunked streaming

## Using third-party PSR-15 middleware

Any PSR-15 compliant middleware works with Curlpit without modification or wrappers – including inside loops and try bodies. Curlpit's flow control state (`__pc`, `__jump_to`) travels in request attributes and is invisible to third-party middleware.

### Static wiring (explicit)

For middleware with complex constructor dependencies, wire them up in `instantiate()`:

```php
protected function instantiate(string $class, array $options): MiddlewareInterface
{
    return match ($class) {
        \Middlewares\AccessLog::class => new AccessLog($this->buildLogger()),
        default                       => parent::instantiate($class, $options),
    };
}
```

### Auto-wiring (declarative)

For simpler cases, register type resolvers in your `Application` constructor and let Curlpit wire dependencies automatically via reflection:

```php
public function __construct(
    ResponseFactoryInterface $responseFactory,
    StreamFactoryInterface   $streamFactory,
) {
    parent::__construct($responseFactory, $streamFactory);

    $this->registerResolver(
        \Psr\Log\LoggerInterface::class,
        fn(array $options) => (new Logger('app'))
            ->pushHandler(new StreamHandler($options['path'] ?? 'logs/app.log'))
    );
}
```

Then in `middleware.json`, pass constructor arguments via `autowire`:

```json
{
  "Middlewares\\AccessLog": {
    "autowire": {
      "logger": { "path": "logs/access.log" }
    }
  }
}
```

The resolver receives the `autowire` sub-object for that parameter. `ResponseFactoryInterface` and `StreamFactoryInterface` are always resolved automatically without registration.

> **Note:** Auto-wiring is experimental. The API may change in future versions.

## Example project

[DBCommander](https://github.com/curlpit/dbcommander) – a Norton Commander-style MySQL manager built on Curlpit.

## License

MIT
