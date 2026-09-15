<?php

namespace Wexample\SymfonyMessenger\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Wexample\SymfonyHelpers\DependencyInjection\AbstractWexampleSymfonyExtension;
use Wexample\SymfonyMessenger\Serializer\EntityMessageSerializer;

class WexampleSymfonyMessengerExtension extends AbstractWexampleSymfonyExtension
{
    /**
     * Turns each declared queue into a transport and a routing rule, so that
     * adding one is a line naming a message class rather than a block of yaml
     * repeating a dsn, a serializer and a retry strategy.
     */
    public function prepend(ContainerBuilder $container): void
    {
        parent::prepend($container);

        $config = $this->processConfiguration(
            new Configuration(),
            $container->getExtensionConfig($this->getAlias())
        );

        $dsn = rtrim($config['dsn'], '/');
        $transports = [];
        $routing = [];

        foreach ($config['queues'] as $queue => $messageClass) {
            $transports[$queue] = [
                'dsn' => $dsn.'/'.$queue,
                'serializer' => EntityMessageSerializer::class,
                'retry_strategy' => $config['retry_strategy'],
            ];

            $routing[$messageClass] = $queue;
        }

        $transports[$config['failure_queue']] = [
            'dsn' => $dsn.'/'.$config['failure_queue'],
            'serializer' => EntityMessageSerializer::class,
        ];

        // Prepended, so an application that wants another shape for one of these
        // transports says so in its own configuration and wins.
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'failure_transport' => $config['failure_queue'],
                'transports' => $transports,
                'routing' => $routing,
            ],
        ]);
    }

    public function load(
        array $configs,
        ContainerBuilder $container
    ): void {
        $this->loadConfig(
            __DIR__,
            $container
        );

        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter(
            'wexample_symfony_messenger.message_classes',
            array_values($config['queues'])
        );
    }
}
