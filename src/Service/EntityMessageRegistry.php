<?php

namespace Wexample\SymfonyMessenger\Service;

use Wexample\SymfonyMessenger\Message\AbstractEntityMessage;

/**
 * Maps the kind written in a message body back to the class that carries it.
 * Messages are not services, so nothing tags them: the map is built from the
 * classes the application declares under `wexample_symfony_messenger.queues`.
 */
class EntityMessageRegistry
{
    /**
     * @var array<string, class-string<AbstractEntityMessage>>
     */
    private readonly array $classes;

    /**
     * @param array<class-string<AbstractEntityMessage>> $messageClasses
     */
    public function __construct(array $messageClasses)
    {
        $classes = [];

        foreach ($messageClasses as $messageClass) {
            $classes[$messageClass::getKind()] = $messageClass;
        }

        $this->classes = $classes;
    }

    /**
     * @return class-string<AbstractEntityMessage>|null
     */
    public function classFor(string $kind): ?string
    {
        return $this->classes[$kind] ?? null;
    }
}
