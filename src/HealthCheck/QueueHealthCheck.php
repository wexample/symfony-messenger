<?php

namespace Wexample\SymfonyMessenger\HealthCheck;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * The broker answers: counting the messages of a declared queue opens a
 * connection to it. A probe of `symfony-api`'s health endpoint, collected by
 * its tag rather than by its interface, so this package does not require
 * `symfony-api` to offer it.
 */
class QueueHealthCheck
{
    public function __construct(
        private readonly MessageCountAwareInterface $transport,
    ) {
    }

    public function getName(): string
    {
        return 'queue';
    }

    public function check(): void
    {
        $this->transport->getMessageCount();
    }
}
