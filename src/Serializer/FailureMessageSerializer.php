<?php

namespace Wexample\SymfonyMessenger\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Wexample\SymfonyMessenger\Message\AbstractEntityMessage;

/**
 * The serializer of the failure transport, which is the whole application's:
 * every message that exhausts its retries lands there, whichever transport it
 * came from — a mail routed to an `async` transport of the application as much
 * as an entity message.
 *
 * An entity message is written as on its own queue. Any other is handed to the
 * application's default serializer and marked, so that reading it back does
 * not depend on guessing a body. Only PHP reads this queue, so the far end of
 * the entity queues never meets such a body.
 */
class FailureMessageSerializer implements SerializerInterface
{
    private const string FORMAT_HEADER = 'X-Message-Format';

    private const string FORMAT_DEFAULT = 'default';

    public function __construct(
        private readonly EntityMessageSerializer $entityMessageSerializer,
        private readonly SerializerInterface $defaultSerializer
    ) {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        if (self::FORMAT_DEFAULT === ($encodedEnvelope['headers'][self::FORMAT_HEADER] ?? null)) {
            return $this->defaultSerializer->decode($encodedEnvelope);
        }

        return $this->entityMessageSerializer->decode($encodedEnvelope);
    }

    public function encode(Envelope $envelope): array
    {
        if ($envelope->getMessage() instanceof AbstractEntityMessage) {
            return $this->entityMessageSerializer->encode($envelope);
        }

        $encodedEnvelope = $this->defaultSerializer->encode($envelope);
        $encodedEnvelope['headers'][self::FORMAT_HEADER] = self::FORMAT_DEFAULT;

        return $encodedEnvelope;
    }
}
