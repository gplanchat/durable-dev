<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Plugin\EventListener\AdminMenuListener;
use PHPUnit\Framework\TestCase;

final class AdminMenuListenerTest extends TestCase
{
    public function testTheEntryNamesItsRouteSoTheMenuCanMarkItActive(): void
    {
        // A `uri` entry is never active: KnpMenu's route voter matches `route`, not a URL (M30).
        $configurationMenu = new class {
            /** @var array<string, array<string, mixed>> */
            public array $options = [];
            /** @var array<string, object> */
            private array $children = [];

            public function addChild(string $name, array $options = []): object
            {
                $this->options[$name] = $options;

                return $this->children[$name] = new class {
                    public function setLabelAttribute(string $_name, string $_value): self
                    {
                        return $this;
                    }
                };
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
                return 'configuration' === $name ? $this->configurationMenu : null;
            }
        };
        $event = new class ($menu) {
            public function __construct(private readonly mixed $menu) {}

            public function getMenu(): mixed
            {
                return $this->menu;
            }
        };

        (new AdminMenuListener())->addDashboardItem($event);

        self::assertSame('gplanchat_durable_plugin_admin_dashboard', $configurationMenu->options['durable_dashboard']['route'] ?? null);
        self::assertArrayNotHasKey('uri', $configurationMenu->options['durable_dashboard']);
    }
}
