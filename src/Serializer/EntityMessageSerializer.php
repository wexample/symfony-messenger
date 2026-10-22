<?php

namespace Wexample\SymfonyMessenger\Serializer;

use JsonException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface as ObjectSerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface as ObjectSerializerInterface;
use Wexample\SymfonyMessenger\Message\AbstractEntityMessage;
use Wexample\SymfonyMessenger\Service\EntityMessageRegistry;

/**
 * Writes an entity message as plain JSON — `{"kind": "...", "id": "..."}` — so
 * that a consumer written in another language reads it without knowing anything
 * about Symfony. The class to rebuild is not announced in a `type` header the
 * way Symfony does it, because the far end writes no headers: the kind is the
 * discriminator, and it works in both directions of the pipe.
 *
 * One serializer serves every queue. A serializer per queue, as an application
 * ends up with when the body is a normalized entity, buys nothing once the body
 * is a pointer.
 */
class EntityMessageSerializer implements SerializerInterface
{
    private const string STAMP_HEADER_PREFIX = 'X-Message-Stamp-';

    public function __construct(
        private readonly EntityMessageRegistry $registry,
        private readonly ObjectSerializerInterface $objectSerializer
    ) {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            $body = json_decode($encodedEnvelope['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MessageDecodingFailedException(
                'Message body is not JSON: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $kind = $body['kind'] ?? '';
        $messageClass = $this->registry->classFor($kind);

        if (null === $messageClass) {
            throw new MessageDecodingFailedException(
                sprintf('No message class declared for kind "%s".', $kind)
            );
        }

        return new Envelope(
            $messageClass::fromPayload($body['id'], $body),
            $this->decodeStamps($encodedEnvelope)
        );
    }

    public function encode(Envelope $envelope): array
    {
        /** @var AbstractEntityMessage $message */
        $message = $envelope->getMessage();

        return [
            'body' => json_encode(
                [
                    'kind' => $message::getKind(),
                    'id' => $message->getId(),
                ] + $message->getPayload(),
                JSON_THROW_ON_ERROR
            ),
            'headers' => $this->encodeStamps(
                $envelope->withoutStampsOfType(NonSendableStampInterface::class)
            ),
        ];
    }

    /**
     * Stamps travel in headers, where a consumer reading only the body never
     * meets them. They have to travel: a serializer that drops them shows every
     * redelivery as a first delivery, so a message that always fails is retried
     * forever instead of landing in the failure transport.
     */
    private function encodeStamps(Envelope $envelope): array
    {
        $headers = [];

        foreach ($envelope->all() as $stampClass => $stamps) {
            // The error details carry a FlattenException, and a FlattenException
            // does not survive a JSON round trip: it comes back with its typed
            // properties uninitialized, and the first read of one crashes the
            // worker before the handler even runs — a poison pill the retry
            // then re-encodes forever. It is diagnostics, not state: what a
            // failure was is written where the failure happened. The redelivery
            // stamp, which is what makes a retry countable, is plain data and
            // travels fine.
            if (ErrorDetailsStamp::class === $stampClass) {
                continue;
            }

            $headers[self::STAMP_HEADER_PREFIX.$stampClass] = $this->objectSerializer->serialize(
                $stamps,
                'json'
            );
        }

        return $headers;
    }

    private function decodeStamps(array $encodedEnvelope): array
    {
        $stamps = [];

        foreach ($encodedEnvelope['headers'] ?? [] as $name => $value) {
            if (! str_starts_with($name, self::STAMP_HEADER_PREFIX)) {
                continue;
            }

            $stampClass = substr($name, strlen(self::STAMP_HEADER_PREFIX));

            if (! is_subclass_of($stampClass, StampInterface::class)) {
                throw new MessageDecodingFailedException(
                    sprintf('Could not decode stamp: "%s" is not a stamp.', $stampClass)
                );
            }

            // A stamp that cannot be rebuilt must not kill a message whose
            // body is fine: the body is a pointer and the record it points at
            // holds the truth. Dropped, the worst case is a retry counted as
            // a first delivery.
            try {
                $stamps[] = $this->objectSerializer->deserialize($value, $stampClass.'[]', 'json');
            } catch (ObjectSerializerExceptionInterface) {
                continue;
            }
        }

        return $stamps ? array_merge(...$stamps) : [];
    }
}
