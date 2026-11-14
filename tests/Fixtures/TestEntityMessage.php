<?php

declare(strict_types=1);

namespace Wexample\SymfonyMessenger\Tests\Fixtures;

use Wexample\SymfonyMessenger\Message\AbstractEntityMessage;

final class TestEntityMessage extends AbstractEntityMessage
{
    public static function getKind(): string
    {
        return 'test_entity';
    }
}
