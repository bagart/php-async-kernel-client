# ASKClient Architecture Context

We are working on the `bagart/ask-client` library.

Important: before starting any work, carefully analyze all existing project code and understand the architecture first. Do not propose a new architecture without understanding the existing one.

## Library philosophy

ASKClient is a universal client for executing any operations.

It knows nothing about HTTP, Redis, SQL, Telegram, Guzzle, cURL, etc.

The library's only task is:

```
operation
    ↓
handler chain
    ↓
transport
    ↓
future
```

Only transport knows how to execute an operation.

ASKClient knows only:

- operation
- context
- handler
- future

Everything else lives in separate packages (`ask-client-redis`, `telegram-bot-lib`, etc.).

---

# Main API

The library user should write something like:

```php
$client = new ASKClient(
    transport: $transport,
    handlers: [
        new MyRetryHandler(),
        new MyLoggingHandler(),
    ],
);

$result = $client
    ->execute(new RedisGetOperation('key'))
    ->then(...)
    ->recover(...)
    ->await();
```

Or via factory (only handlers, transport by default):

```php
$client = ASKClient::create(
    new MyRetryHandler(),
    new MyLoggingHandler(),
);
```

---

# Core entities

## ASKClient

Main entry point.

Methods:

```php
execute(
    object $operation,
    ?ASKContextContract $context = null,
): ASKFutureContract

static create(ASKHandlerContract ...$handlers): self
```

Constructor accepts transport and array of handlers.

## ASKFuture

Future represents the result of an operation.

Supports full chains.

Methods:

```php
await(): mixed

then(callable): self

catch(callable): self

recover(callable): self

finally(callable): self

isCompleted(): bool

isSuccessful(): bool

getError(): ?Throwable

static resolved(mixed): self

static failed(Throwable): self

static pending(callable): self
```

Future is immutable. then/catch/recover return a new Future.

finally does not change the result.

## ASKContext

Immutable context.

Methods:

```php
with(string $key, mixed $value): self

without(string $key): self

merge(self $other): self

get(string $key): mixed

has(string $key): bool

all(): array
```

## Handler (single type)

Handler is ONE type. No separate middleware/stage layers.

Contract:

```php
__invoke(
    object $operation,
    ASKContextContract $context,
    ASKNextHandler $next,
): ASKFutureContract
```

Handler knows nothing about transport. It only calls `$next(...)`.

The client builds ONE chain of handlers → transport.

## Transport

The only object that actually knows how to execute operations.

Contract:

```php
execute(
    object $operation,
    ASKContextContract $context,
): ASKFutureContract
```

Factories: `ASKTransport::wrap(callable)`, `ASKTransport::null()`.

---

# Contracts

## ASKClientContract

```php
execute(
    object $operation,
    ?ASKContextContract $context = null,
): ASKFutureContract
```

## ASKFutureContract

```php
await(): mixed

then(callable): self

catch(callable): self

recover(callable): self

finally(callable): self

isCompleted(): bool

isSuccessful(): bool

getError(): ?Throwable
```

## ASKContextContract

```php
with(string, mixed): self

without(string): self

merge(self): self

get(string): mixed

has(string): bool

all(): array
```

## ASKTransportContract

```php
execute(
    object $operation,
    ASKContextContract $context,
): ASKFutureContract
```

## ASKHandlerContract

```php
__invoke(
    object $operation,
    ASKContextContract $context,
    ASKNextHandler $next,
): ASKFutureContract
```

---

# Project structure

```
src/
  ASKClient.php
  ASKFuture.php
  ASKContext.php
  ASKTransport.php
  ASKNextHandler.php
  Contracts/
    ASKClientContract.php
    ASKFutureContract.php
    ASKContextContract.php
    ASKHandlerContract.php
    ASKTransportContract.php
```

---

# What matters during analysis

Before any change, first determine:

- whether the file is used;
- whether it is part of the core;
- whether it duplicates another class;
- whether it can be deleted;
- whether it can be merged with a neighboring class.

Do not leave:

- stubs;
- pass-through;
- TODOs;
- "for future";
- empty DTOs;
- unnecessary factories;
- duplicate builders;
- marker interfaces without behavior.

If a class stays in the project, it must be fully implemented and actually used by the core.

The main goal is a compact, complete library where every abstraction has real value and is used at runtime.
