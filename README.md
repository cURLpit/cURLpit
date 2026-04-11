# [cURLpit](https://github.com/curlpit/curlpit)

Curlpit is a PSR-15 middleware orchestrator for PHP – with branching, looping, and declarative flow control built in.

Most middleware stacks are pipelines: request goes in, response comes out, linearly. Curlpit treats the middleware stack as an **instruction sequence** – with a program counter, named labels, conditional jumps, and loops. Business logic that would otherwise be hardcoded in handlers can be expressed as configuration.

## Installation

```
composer require curlpit/curlpit
```

Then install a PSR-7/17 implementation of your choice:

```
# nyholm/psr7 (recommended – lightweight, zero dependencies)
composer require nyholm/psr7 nyholm/psr7-server

# or guzzlehttp/psr7
composer require guzzlehttp/psr7
```

## How it differs

|  | Standard PSR-15 | Curlpit |
| --- | --- | --- |
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

```
{ "type": "always" }
{ "type": "never" }
{ "type": "attr",    "name": "status",   "eq":  "active" }
{ "type": "attr",    "name": "retries",  "lte": 3        }
{ "type": "context", "name": "has_more"                  }
{ "type": "context", "name": "count",    "gt":  0        }
```

Operators: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`. Without an operator, truthy check.

## Built-in middleware

* **`RequestHandler`** – PSR-15 handler with program counter and label-based jumps
* **`JumpMiddleware`** – conditional branch to a named label (`else_label` for two-way branch)
* **`LoopMiddleware`** – repeat a sub-handler while a condition holds
* **`TryMiddleware`** – execute a sub-handler, jump to catch label on exception
* **`LoopContext`** – mutable state container for loop iterations
* **`RoutingMiddleware`** – path pattern matching with `{param}` placeholders, 404/405 aware
* **`DispatchMiddleware`** – resolves and calls the matched handler via an injected resolver callable
* **`ErrorHandlerMiddleware`** – catches all exceptions, returns JSON or plaintext based on Accept header
* **`SetVariableMiddleware`** / **`GetVariableMiddleware`** – read/write request attributes
* **`IncrementMiddleware`** / **`DecrementMiddleware`** – numeric counters in request attributes
* **`ConfigLoader`** – loads and validates `middleware.json`, standalone and cacheable
* **`Emitter`** – sends PSR-7 responses to the SAPI with chunked streaming

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

## Declarative autowire

Curlpit supports **fully declarative middleware configuration** via `middleware.json`.

For simpler cases, there is **no need to register or wire anything in your application code**.  
All dependencies, configuration values, and even method calls can be defined in a single place.

Unlike reflection-based autowire (as found in PHP-DI or Symfony), declarative autowire is **explicit**: you describe the full object graph in JSON. Nothing is inferred automatically – which means no surprises, no hidden reflection overhead, and no extra dependencies.

---

### Example

Install any PSR-15 middleware:

```
composer require middlewares/http-authentication
composer require middlewares/access-log monolog/monolog
```

Generate a password hash:

```
php -r "echo password_hash('your password', PASSWORD_DEFAULT);"
```

Define everything in `middleware.json`:

```json
{
  "middleware": [
    {
      "Middlewares\\AccessLog": {
        "autowire": {
          "logger": {
            "class": "Monolog\\Logger",
            "args": [
              "access",
              [
                {
                  "class": "Monolog\\Handler\\StreamHandler",
                  "args": ["../logs/access.log", 200]
                }
              ]
            ]
          }
        }
      }
    },
    {
      "Middlewares\\BasicAuthentication": {
        "autowire": {
          "users": { "admin": "$2y$12$abc123..." }
        },
        "calls": [["verifyHash", []]]
      }
    }
  ]
}
```

And that's it.

> **Note:** Declarative autowire is experimental. The API may change in future versions.

## PSR-11 container integration

> **This is an optional integration.** Most projects will not need it. If declarative autowire covers your use case, there is nothing to install or configure here.

For projects where middleware share services (loggers, database connections, etc.) and a PSR-11 container is already in use, Curlpit provides `ContainerApplication` – a drop-in replacement for `Application` that resolves middleware from any PSR-11 compatible container.

Install `psr/container` and a compatible container implementation of your choice:

```
composer require psr/container
composer require php-di/php-di   # or symfony/dependency-injection, etc.
```

```php
use Curlpit\App\ContainerApplication;
use Curlpit\Core\Emitter;

$app      = new ContainerApplication($responseFactory, $streamFactory, $container);
$response = $app->handle($serverRequest);
(new Emitter())->emit($response);
```

The `middleware.json` flow config stays unchanged. `ContainerApplication` only affects how middleware instances are created.

### Resolution order

1. **PSR-11 container** – if the container knows the class, it resolves it (interface binding, singletons, full DI power)
2. **Declarative autowire** – if the container does not know the class, Curlpit's built-in JSON-based wiring handles it as usual

This means you can mix both approaches per middleware: use the container for your own middleware, declarative autowire for simpler third-party ones.

### What the container adds over declarative autowire

| | Declarative autowire | PSR-11 container |
| --- | --- | --- |
| Extra dependencies | None | `psr/container` + a container impl |
| Interface → implementation binding | No | Yes |
| Shared instances (singletons) | No | Yes |
| Wiring lives in | `middleware.json` | PHP (container definitions) |
| Best for | Third-party middleware, simple cases | Your own middleware, shared services |

### Example with PHP-DI

```php
use DI\ContainerBuilder;

$builder = new ContainerBuilder();
$builder->addDefinitions([
    // one shared PDO across all middleware
    PDO::class => \DI\factory(fn() => new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass')),

    // interface → implementation binding
    Psr\Log\LoggerInterface::class => \DI\factory(function () {
        $logger = new Monolog\Logger('app');
        $logger->pushHandler(new Monolog\Handler\StreamHandler('../logs/app.log'));
        return $logger;
    }),

    // constructor dependencies resolved automatically from the container
    App\Middleware\AuthMiddleware::class  => \DI\autowire(),
    App\Middleware\AuditMiddleware::class => \DI\autowire(),
]);

$app = new ContainerApplication($responseFactory, $streamFactory, $builder->build());
```

In `middleware.json`, these middleware need no `autowire` block – an empty options object is enough:

```json
{
  "middleware": [
    { "Curlpit\\Core\\Middleware\\ErrorHandlerMiddleware": { "debug": false } },
    { "App\\Middleware\\AuthMiddleware": {} },
    { "App\\Middleware\\AuditMiddleware": {} }
  ]
}
```

### Conflict detection

If a class is registered in the container **and** has a declarative `autowire` block in `middleware.json`, the container takes precedence and the `autowire` block is ignored. Curlpit will emit an `E_USER_NOTICE` to make the stale configuration visible:

```
[cURLpit] 'App\Middleware\AuthMiddleware' is registered in the container AND has a
declarative autowire block in middleware.json. The container takes precedence; the
autowire block is ignored. Remove it from middleware.json to suppress this notice.
```

This typically surfaces after a refactor where a container registration was added but the JSON `autowire` block was not cleaned up. Remove the `autowire` block from `middleware.json` to resolve it.

## Example project

[DBCommander](https://github.com/curlpit/dbcommander) – a Norton Commander-style MySQL manager built on Curlpit.

## License

MIT
