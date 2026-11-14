<?php

declare(strict_types=1);

namespace Wexample\SymfonyMessenger\Tests\Unit\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Wexample\SymfonyMessenger\Serializer\EntityMessageSerializer;
use Wexample\SymfonyMessenger\Service\EntityMessageRegistry;
use Wexample\SymfonyMessenger\Tests\Fixtures\TestEntityMessage;

final class EntityMessageSerializerTest extends TestCase
{
    private EntityMessageSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new EntityMessageSerializer(
            new EntityMessageRegistry([TestEntityMessage::class]),
            new Serializer(
                [new DateTimeNormalizer(), new ArrayDenormalizer(), new ObjectNormalizer()],
                [new JsonEncoder()]
            )
        );
    }

    public function testRoundTripKeepsKindAndId(): void
    {
        $encoded = $this->serializer->encode(new Envelope(new TestEntityMessage('abc-123')));

        $this->assertSame(
            ['kind' => 'test_entity', 'id' => 'abc-123'],
            json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR)
        );

        $message = $this->serializer->decode($encoded)->getMessage();

        $this->assertInstanceOf(TestEntityMessage::class, $message);
        $this->assertSame('abc-123', $message->getId());
    }

    public function testStampsSurviveTheRoundTrip(): void
    {
        $envelope = new Envelope(
            new TestEntityMessage('abc-123'),
            [new DelayStamp(5000), new RedeliveryStamp(2)]
        );

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));

        $this->assertSame(5000, $decoded->last(DelayStamp::class)?->getDelay());
        $this->assertSame(2, RedeliveryStamp::getRetryCountFromEnvelope($decoded));
    }

    public function testNumericIdIsReadAsString(): void
    {
        $message = $this->serializer->decode(['body' => '{"kind":"test_entity","id":42}'])->getMessage();

        $this->assertSame('42', $message->getId());
    }

    public static function malformedBodies(): array
    {
        return [
            'invalid json' => ['{"kind": "test_entity", "id": '],
            'not an object' => ['"test_entity"'],
            'missing kind' => ['{"id":"abc-123"}'],
            'unknown kind' => ['{"kind":"other","id":"abc-123"}'],
            'kind not a string' => ['{"kind":["test_entity"],"id":"abc-123"}'],
            'missing id' => ['{"kind":"test_entity"}'],
            'null id' => ['{"kind":"test_entity","id":null}'],
            'empty id' => ['{"kind":"test_entity","id":""}'],
            'id not scalar' => ['{"kind":"test_entity","id":{"value":"abc-123"}}'],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedBodyIsADecodingFailure(string $body): void
    {
        // The worker rejects a message on this exception only; any other one
        // leaves the message queued for the next worker to crash on.
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => $body]);
    }
}
