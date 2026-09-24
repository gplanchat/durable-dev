<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `{% trans_default_domain %}` does not compile with symfony/twig-bridge 6.4.0 and a current Twig
 * ("EmptyNode cannot have children"): the dashboard answered 500 on the lowest 6.4 lane. Every
 * `|trans` names the `durable` domain itself instead, and nothing brings the tag back.
 */
final class TheTemplateNamesItsTranslationDomainTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../templates/admin/dashboard/_dashboard.html.twig';

    public function testTheTemplateDoesNotSetADefaultDomain(): void
    {
        self::assertStringNotContainsString('trans_default_domain', (string) file_get_contents(self::TEMPLATE));
    }

    public function testEveryTransCallNamesTheDurableDomain(): void
    {
        $source = (string) file_get_contents(self::TEMPLATE);
        $offenders = [];
        $offset = 0;
        while (false !== ($at = strpos($source, '|trans', $offset))) {
            $offset = $at + 6;
            $arguments = '(' === ($source[$offset] ?? '') ? self::balanced($source, $offset) : '';
            if (!str_ends_with(rtrim($arguments), "'durable'")) {
                $offenders[] = substr_count(substr($source, 0, $at), "\n") + 1;
            }
        }

        self::assertSame([], $offenders, 'lines with a |trans that does not name the durable domain');
    }

    /** The text between the parenthesis at $open and the one that closes it. */
    private static function balanced(string $source, int $open): string
    {
        for ($depth = 0, $i = $open; $i < \strlen($source); ++$i) {
            $depth += match ($source[$i]) {
                '(' => 1, ')' => -1, default => 0,
            };
            if (0 === $depth) {
                return substr($source, $open + 1, $i - $open - 1);
            }
        }

        return '';
    }
}
