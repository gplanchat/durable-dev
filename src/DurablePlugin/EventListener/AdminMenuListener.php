<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\EventListener;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminMenuListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

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
                'uri' => $this->urlGenerator->generate('gplanchat_durable_plugin_admin_dashboard'),
            ])
            ->setLabelAttribute('icon', 'tabler:clock')
        ;
    }
}
