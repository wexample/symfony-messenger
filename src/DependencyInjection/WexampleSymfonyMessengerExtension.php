<?php

namespace Wexample\SymfonyMessenger\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Wexample\SymfonyHelpers\DependencyInjection\AbstractWexampleSymfonyExtension;
use Wexample\SymfonyMessenger\HealthCheck\QueueHealthCheck;
use Wexample\SymfonyMessenger\Serializer\EntityMessageSerializer;
use Wexample\SymfonyMessenger\Serializer\FailureMessageSerializer;

class WexampleSymfonyMessengerExtension extends AbstractWexampleSymfonyExtension
{
    /**
     * HealthCheckInterface::TAG of symfony-api, written out so that this
     * package does not require it.
     */
    private const string HEALTH_CHECK_TAG = 'wexample_symfony_api.health_check';

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

            // A queue naming no class is one this application only listens on.
            // Routing it would mean sending back what it is meant to receive.
            if (null !== $messageClass) {
                $routing[$messageClass] = $queue;
            }
        }

        // The failure transport is the application's, not only these queues':
        // it keeps whatever exhausts its retries, entity message or not.
        $transports[$config['failure_queue']] = [
            'dsn' => $dsn.'/'.$config['failure_queue'],
            'serializer' => FailureMessageSerializer::class,
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
            array_values(array_filter($config['queues']))
        );

        // Probes a declared queue, not the failure transport, which an
        // application may move off the broker. Tagged for symfony-api's health
        // endpoint; without it, the tag is read by nobody and the probe is
        // removed as unused.
        $probedQueue = array_key_first($config['queues']);

        if (null !== $probedQueue) {
            $container
                ->register(QueueHealthCheck::class, QueueHealthCheck::class)
                ->setArgument('$transport', new Reference('messenger.transport.'.$probedQueue))
                ->addTag(self::HEALTH_CHECK_TAG);
        }
    }
}
