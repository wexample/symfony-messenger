`wexample/symfony-messenger` is a Symfony bundle for the case where a message names a thing instead of carrying it: the body written on the queue is `{"kind": "process_run", "id": "0198…"}`, and the consumer reads the current state of what is named. It exists because the consumer is not always written in PHP — a body of plain JSON discriminated by a `kind` is read by a Python worker that knows nothing about Symfony, and is written back by it on the return queue without a single header. A `wexample_symfony_messenger` configuration block turns each declared queue into a transport, a routing rule and a retry strategy, so that adding one is a line naming a message class rather than a block of yaml repeating a dsn.

## Declaring a queue

```yaml
# config/packages/wexample_symfony_messenger.yaml
wexample_symfony_messenger:
    dsn: '%env(RABBITMQ_DSN)%'
    queues:
        process_run: App\Message\ProcessRunMessage
        process_run_event: App\Message\ProcessRunEventMessage
```

The `dsn` reaches down to the vhost; each queue name is appended to it. A
`failed` transport is declared alongside and set as the failure transport, so a
message that exhausts its retries is kept rather than dropped.

## Writing a message

A message declares the kind it points at. When it carries nothing beyond the
pointer, that is all there is to write:

```php
class SelectionIndexMessage extends AbstractEntityMessage
{
    public static function getKind(): string
    {
        return 'selection';
    }
}
```

When it carries more, `getPayload()` says what goes on the wire and
`fromPayload()` reads it back — both sides of the same sentence:

```php
class ProcessRunMessage extends AbstractEntityMessage
{
    public function __construct(
        string $id,
        private readonly string $app
    ) {
        parent::__construct($id);
    }

    public static function getKind(): string
    {
        return 'process_run';
    }

    public static function fromPayload(string $id, array $payload): static
    {
        return new static($id, $payload['app']);
    }

    public function getPayload(): array
    {
        return ['app' => $this->app];
    }
}
```

Whatever `getPayload()` returns has to be readable by a consumer that is not
PHP, which in practice means scalars.

## What belongs in a message, and what does not

The queue is a doorbell, not a parcel. Three consequences are worth stating
before they are discovered:

- The consumer sees the entity in the state it is in when the message is
  handled, not the state it was in when the message was sent.
- Anything that must survive the trip belongs in the row or the record. A
  message that carries a value is a copy that can go stale.
- The entity has to be written before the message is sent. Dispatching inside
  the same `flush()` is the classic way to have a message consumed before the
  commit, and a consumer finding nothing.
