<?php

namespace Wexample\SymfonyMessenger\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('wexample_symfony_messenger');

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('dsn')
            ->defaultValue('%env(MESSENGER_TRANSPORT_DSN)%')
            ->info('Broker address down to the vhost, a queue name being appended to it.')
            ->end()
            ->end();

        $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('queues')
            ->info('A queue per entry, named by its key. The message class it carries is what gets routed there; left empty, the queue is only consumed — which is what a return queue is.')
            ->useAttributeAsKey('name')
            ->scalarPrototype()
            ->defaultNull()
            ->end()
            ->end()
            ->end();

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('failure_queue')
            ->defaultValue('failed')
            ->info('Where a message goes once it has exhausted its retries. Without it, it is simply lost.')
            ->end()
            ->end();

        $treeBuilder->getRootNode()
            ->children()
            ->variableNode('retry_strategy')
            ->defaultValue([
                'max_retries' => 3,
                'delay' => 1000,
                'multiplier' => 2,
            ])
            ->info('Passed through to every declared transport, in Symfony\'s own schema.')
            ->end()
            ->end();

        return $treeBuilder;
    }
}
