# symfony-messenger

Version: 3.0.1

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

## Table of Contents

- [Declaring a queue](#declaring-a-queue)
- [Writing a message](#writing-a-message)
- [What belongs in a message, and what does not](#what-belongs-in-a-message-and-what-does-not)
- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

`wexample/symfony-messenger` owns one decision and its consequences: an entity message carries a pointer, and the body on the wire is plain JSON so that both ends of the queue can be written in different languages. Everything in the package follows from that.

### The message

src/Message/AbstractEntityMessage.php holds an id and nothing else. A subclass declares `getKind()` — the name of the entity, spelled as the record directory holding it is spelled, `process_run` or `selection` — and, when it carries more than the pointer, the pair `getPayload()` / `fromPayload()`.

The kind is not decoration: it is the discriminator. Symfony's own transport serializer announces the class to rebuild in a `type` header, which works as long as PHP is what writes the message. Here the far end writes no headers at all, so the class has to be recoverable from the body, and the kind is the one field the far end must write anyway.

### The registry

Messages are not services, so no tag can collect them. src/Service/EntityMessageRegistry.php is built instead from the classes the application declares under `wexample_symfony_messenger.queues`, and maps each `getKind()` back to its class. It answers `null` for a kind it does not know; deciding what that means belongs to the caller.

### The serializer

src/Serializer/EntityMessageSerializer.php implements Messenger's `SerializerInterface`. One instance serves every queue — a serializer per queue, which an application ends up writing when the body is a normalized entity, buys nothing once the body is a pointer.

`encode()` writes `kind` and `id`, then whatever `getPayload()` adds. `decode()` reads the kind, asks the registry for the class, and rebuilds through `fromPayload()`. An unknown kind and a body that is not JSON both raise `MessageDecodingFailedException`, which is what tells Messenger to reject the message rather than redeliver it forever.

Stamps travel in headers, under the `X-Message-Stamp-` prefix Symfony itself uses, serialized by the `serializer` service. They have to travel: a serializer that drops them shows every redelivery as a first delivery, so the retry count never grows, and a message that always fails is retried for ever instead of landing in the failure transport. A consumer that reads only the body never meets them.

### Configuration and wiring

src/DependencyInjection/Configuration.php defines the tree under `wexample_symfony_messenger`: a `dsn` reaching down to the vhost, a `queues` map of queue name to message class, the name of the `failure_queue`, and a `retry_strategy` passed through in Symfony's own schema.

src/DependencyInjection/WexampleSymfonyMessengerExtension.php does the work in `prepend()`, where each declared queue becomes a transport — dsn with the queue name appended, this package's serializer, the shared retry strategy — and a routing rule sending its message class there. The failure transport is declared the same way and named in `framework.messenger.failure_transport`. Prepending rather than setting means an application that wants another shape for one of these transports says so in its own configuration and wins.

`load()` writes the declared message classes into `wexample_symfony_messenger.message_classes`, which src/Resources/config/services.yaml injects into the registry.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- php: >=8.5
- symfony/amqp-messenger: ^7.0
- symfony/messenger: ^7.0
- symfony/serializer: ^7.0
- wexample/symfony-helpers: >=9.0.0

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
