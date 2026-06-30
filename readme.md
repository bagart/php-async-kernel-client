# bagart/ask-client

Minimal deterministic execution engine: `execute(object $operation): ASKFuture`

## Usage

```php
use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\ASKContext;
use BAGArt\ASKClient\ASKFuture;

$client = new ASKClient(
    transport: ASKTransport::wrap(fn (object $op, ASKContext $ctx): ASKFuture =>
        ASKFuture::resolved($result),
    ),
);

$result = $client->execute($operation)->await();
```

## Future chain

```php
$result = $client
    ->execute($operation)
    ->then(fn ($x) => $x + 1)
    ->then(fn ($x) => $x * 2)
    ->await();

$recovered = $client
    ->execute($operation)
    ->recover(fn (Throwable $e) => [])
    ->await();
```

## Client with handlers

```php
$client = new ASKClient(
    transport: $transport,
    handlers: [new MyRetryHandler(), new MyLoggingHandler()],
);
```

## Handler contract

A handler is a single unified type (no separate middleware/stage layers):

```php
$handler = new class () implements ASKHandlerContract {
    public function __invoke(
        object $operation,
        ASKContextContract $context,
        ASKNextHandler $next,
    ): ASKFutureContract {
        return $next($operation, $context);
    }
};
```

## Architecture

```
execute(operation)
    ↓
handler chain
    ↓
transport
    ↓
future
```

- **ASKClient** — entry point, `::create(...$handlers)` factory
- **ASKFuture** — lazy future with `then` / `catch` / `recover` / `finally` chain

- **ASKContext** — immutable context with `with()` / `without()` / `merge()`
- **ASKTransport** — `wrap(callable)` / `null()` / `execute()`
- **ASKNextHandler** — typed next-handler replacing `callable`
- **Contracts\*** — interfaces for all core components
