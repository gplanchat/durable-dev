<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class DurablePluginExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../config'));
        $loader->load('services.php');
    }

    /**
     * The dashboard's place in the Sylius admin hooks (#383), declared only when Sylius's
     * TwigHooks is there: the plugin does not require Sylius (owner decision, 2026-09-24).
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('sylius_twig_hooks')) {
            return;
        }

        $container->prependExtensionConfig('sylius_twig_hooks', ['hooks' => [
            'sylius_admin.durable_dashboard.index.content' => [
                // The list is the dashboard's own, keyset-paged; a Sylius grid waits on #383's slice B.
                'grid' => ['enabled' => false],
                // The dashboard carries its own heading, translated in the `durable` domain.
                'header' => ['enabled' => false],
                'dashboard' => [
                    'template' => '@DurablePlugin/admin/dashboard/index/content/dashboard.html.twig',
                    'priority' => 100,
                ],
            ],
        ]]);
    }
}
