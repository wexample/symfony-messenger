<?php

namespace Wexample\SymfonyMessenger\Message;

/**
 * A message that points at an entity instead of carrying it.
 *
 * The queue is a doorbell, not a parcel: the consumer reads the thing named
 * here in the state it is in when it gets there, so anything that must survive
 * the trip belongs in the record or the row, never in the message. What this
 * buys is that the broker stores nothing of value — losing a queue loses no
 * request — and that a message never goes stale, since it copies nothing.
 */
abstract class AbstractEntityMessage
{
    public function __construct(
        private readonly string $id
    ) {
    }

    /**
     * Names the entity pointed at, the way the record directory holding it does:
     * `process_run`, `selection`. It tells the far end of the pipe what to read,
     * and tells this end which class to rebuild.
     */
    abstract public static function getKind(): string;

    /**
     * Rebuilds a message from what encode() wrote. Override it together with
     * getPayload() when a message carries more than the pointer.
     */
    public static function fromPayload(
        string $id,
        array $payload
    ): static {
        return new static($id);
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * What this message adds to the pointer. Whatever goes here has to be
     * readable by a consumer that is not written in PHP.
     */
    public function getPayload(): array
    {
        return [];
    }
}
