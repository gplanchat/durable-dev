<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Plugin\EventListener\AdminMenuListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminMenuListenerTest extends TestCase
{
    public function testItAddsDurableDashboardItemToAdminMenu(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('gplanchat_durable_plugin_admin_dashboard')
            ->willReturn('/admin/durable/dashboard')
        ;

        $listener = new AdminMenuListener($urlGenerator);
        $configurationMenu = new class {
            /** @var array<string, object> */
            private array $children = [];

            public function addChild(string $name, array $options = []): object
            {
                $item = new class ($options['uri'] ?? null) {
                    public function __construct(private ?string $uri = null) {}

                    public function setLabelAttribute(string $_name, string $_value): self
                    {
                        return $this;
                    }

                    public function getUri(): ?string
                    {
                        return $this->uri;
                    }
                };
                $this->children[$name] = $item;

                return $item;
            }

            public function getChild(string $name): ?object
            {
                return $this->children[$name] ?? null;
            }
        };
        $menu = new class ($configurationMenu) {
            public function __construct(private readonly object $configurationMenu) {}

            public function addChild(string $_name, array $_options = []): object
            {
                throw new \AssertionError('Root menu should not receive durable child directly.');
            }

            public function getChild(string $name): ?object
            {
                if ('configuration' === $name) {
                    return $this->configurationMenu;
                }

                return null;
            }
        };

        $event = new class ($menu) {
            public function __construct(private readonly mixed $menu) {}

            public function getMenu(): mixed
            {
                return $this->menu;
            }
        };

        $listener->addDashboardItem($event);

        $item = $configurationMenu->getChild('durable_dashboard');
        self::assertNotNull($item);
        self::assertSame('/admin/durable/dashboard', $item->getUri());
    }
}
