<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\EventListener;

/**
 * Adds the dashboard to the Sylius admin menu.
 *
 * The event stays untyped on purpose: typing it against Sylius's `MenuBuilderEvent` would make the
 * plugin require Sylius, and Sylius 2 does not run on Symfony 8, which the monorepo's root suite
 * tests (owner decision on #381, 2026-09-24). An unexpected payload is a no-op.
 */
final class AdminMenuListener
{
    public function addDashboardItem(object $event): void
    {
        if (!\method_exists($event, 'getMenu')) {
            return;
        }

        $menu = $event->getMenu();
        if (!\is_object($menu) || !\method_exists($menu, 'addChild') || !\method_exists($menu, 'getChild')) {
            return;
        }

        $configurationMenu = $menu->getChild('configuration');
        if (!\is_object($configurationMenu) || !\method_exists($configurationMenu, 'addChild') || !\method_exists($configurationMenu, 'getChild')) {
            $configurationMenu = $menu;
        }

        if (null !== $configurationMenu->getChild('durable_dashboard')) {
            return;
        }

        $configurationMenu
            ->addChild('durable_dashboard', [
                'label' => 'Durable Dashboard',
                // A route, not a URI: KnpMenu's route voter marks the entry active on its page.
                'route' => 'gplanchat_durable_plugin_admin_dashboard',
            ])
            ->setLabelAttribute('icon', 'tabler:clock')
        ;
    }
}
