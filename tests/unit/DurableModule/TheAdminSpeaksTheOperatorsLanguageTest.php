<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use PHPUnit\Framework\TestCase;

/**
 * The Magento dashboard ships a French dictionary (#817), as Sylius and Filament do. A dictionary
 * only covers what reaches it: a string written straight into a template, or passed to `__()`
 * through a variable, stays English whatever the locale. This test finds those strings, then holds
 * the dictionary to the phrases the module uses, in both directions. What the core composes
 * (event labels, "waiting on …", the backend health message) reaches the templates as data and
 * stays English, as on Sylius and Filament.
 */
final class TheAdminSpeaksTheOperatorsLanguageTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../src/DurableModule';

    /** PHP literals that look like words but never reach a screen, matched by their exact text. */
    private const NOT_ON_SCREEN = [
        'Y-m-d H:i:s' => 'a date format',
        'Gplanchat_DurableModule::process_history' => 'an ACL resource id',
    ];

    public function testNoVisibleStringBypassesTheTranslator(): void
    {
        $found = [];
        foreach ($this->files(['Block', 'Controller', 'Ui'], 'php') as $file) {
            $found = [...$found, ...$this->untranslatedPhp($file)];
        }
        foreach ($this->files(['view/adminhtml/templates'], 'phtml') as $file) {
            $found = [...$found, ...$this->untranslatedPhp($file), ...$this->untranslatedHtml($file)];
        }
        foreach ($this->files(['view/adminhtml/ui_component'], 'xml') as $file) {
            $xml = simplexml_load_file($file);
            self::assertInstanceOf(\SimpleXMLElement::class, $xml);
            foreach ($xml->xpath('//label | //title | //description | //item[@name="label"]') ?: [] as $node) {
                if (trim((string) $node) !== '' && (string) $node['translate'] !== 'true') {
                    $found[] = basename($file) . ': ' . trim((string) $node);
                }
            }
        }

        self::assertSame([], $found, 'Visible strings that no dictionary can reach.');
    }

    public function testEveryPhraseHasAFrenchTranslation(): void
    {
        $dictionary = $this->dictionary();
        $missing = array_values(array_diff($this->phrases(), array_keys($dictionary)));

        self::assertSame([], $missing, 'Phrases the module uses that i18n/fr_FR.csv does not translate.');
        foreach ($dictionary as $source => $translation) {
            self::assertNotSame('', trim($translation), \sprintf('"%s" has an empty translation.', $source));
            self::assertSame($this->placeholders($source), $this->placeholders($translation), \sprintf('"%s" loses or gains a placeholder.', $source));
        }
    }

    public function testTheDictionaryHoldsNoPhraseTheModuleDoesNotUse(): void
    {
        self::assertSame([], array_values(array_diff(array_keys($this->dictionary()), $this->phrases())), 'Entries of i18n/fr_FR.csv no screen asks for.');
    }

    /** @return list<string> */
    private function phrases(): array
    {
        $phrases = [];
        foreach ([...$this->files(['Block', 'Ui', 'Controller'], 'php'), ...$this->files(['view/adminhtml/templates'], 'phtml')] as $file) {
            $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)), static fn($t): bool => !\is_array($t) || !\in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
            foreach ($tokens as $i => $token) {
                if (\is_array($token) && $token[0] === \T_STRING && $token[1] === '__' && ($tokens[$i + 1] ?? null) === '(' && \is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === \T_CONSTANT_ENCAPSED_STRING) {
                    $phrases[] = $this->unquote($tokens[$i + 2][1]);
                }
            }
        }
        foreach ($this->files(['view/adminhtml/ui_component'], 'xml') as $file) {
            foreach (simplexml_load_file($file)->xpath('//*[@translate="true"]') ?: [] as $node) {
                $phrases[] = trim((string) $node);
            }
        }
        // Magento passes menu and ACL titles through `__()` when it renders them.
        foreach (['etc/adminhtml/menu.xml' => '//add/@title', 'etc/acl.xml' => '//resource[starts-with(@id, "Gplanchat_")]/@title'] as $file => $path) {
            foreach (simplexml_load_file(self::MODULE . '/' . $file)->xpath($path) ?: [] as $title) {
                $phrases[] = (string) $title;
            }
        }

        return array_values(array_unique($phrases));
    }

    /** @return array<string, string> */
    private function dictionary(): array
    {
        $path = self::MODULE . '/i18n/fr_FR.csv';
        self::assertFileExists($path);
        $handle = fopen($path, 'r');
        self::assertIsResource($handle);
        $dictionary = [];
        while (false !== ($row = fgetcsv($handle, null, ',', '"', ''))) {
            if ($row === [null]) {
                continue;
            }
            self::assertCount(2, $row, 'A line of fr_FR.csv is not "source","translation": ' . implode(',', $row));
            self::assertArrayNotHasKey((string) $row[0], $dictionary, 'Listed twice: ' . $row[0]);
            $dictionary[(string) $row[0]] = (string) $row[1];
        }
        fclose($handle);

        return $dictionary;
    }

    /**
     * Word-like PHP literals outside `__()`, and `__()` calls whose phrase is not a literal.
     *
     * @return list<string>
     */
    private function untranslatedPhp(string $file): array
    {
        $found = [];
        $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)), static fn($t): bool => !\is_array($t) || !\in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
        foreach ($tokens as $i => $token) {
            if (\is_array($token) && $token[0] === \T_STRING && $token[1] === '__' && ($tokens[$i + 1] ?? null) === '(') {
                if (!\is_array($tokens[$i + 2] ?? null) || $tokens[$i + 2][0] !== \T_CONSTANT_ENCAPSED_STRING) {
                    $found[] = basename($file) . ':' . $token[2] . ': __() with a phrase no collector can read';
                }
                continue;
            }
            if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $text = $this->unquote($token[1]);
            $wrapped = ($tokens[$i - 1] ?? null) === '(' && \is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][1] === '__';
            // A phrase starts with a capital followed by lowercase letters, or holds two words.
            if (!$wrapped && preg_match('/^\p{Lu}\p{Ll}|\p{L}{2,} \p{L}{2,}/u', $text) === 1 && !\array_key_exists($text, self::NOT_ON_SCREEN)) {
                $found[] = basename($file) . ':' . $token[2] . ': ' . $text;
            }
        }

        return $found;
    }

    /**
     * Text between tags, and title/alt/placeholder/aria-label attributes, written in the markup.
     *
     * @return list<string>
     */
    private function untranslatedHtml(string $file): array
    {
        $html = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            $html .= \is_array($token) && $token[0] === \T_INLINE_HTML ? $token[1] : ' ';
        }
        $html = (string) preg_replace('#<style\b.*?</style>#s', '', $html);
        preg_match_all('#>([^<>]*\p{L}{2}[^<>]*)<|\b(?:title|alt|placeholder|aria-label)="([^"]*\p{L}{2}[^"]*)"#u', $html, $matches);

        return array_map(static fn(string $text): string => basename($file) . ': ' . trim($text), array_filter([...$matches[1], ...$matches[2]], static fn(string $text): bool => trim($text) !== ''));
    }

    /**
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private function files(array $directories, string $extension): array
    {
        $files = [];
        foreach ($directories as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === $extension) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    private function unquote(string $literal): string
    {
        return $literal[0] === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], substr($literal, 1, -1))
            : stripcslashes(substr($literal, 1, -1));
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\d+/', $text, $matches);
        $found = $matches[0];
        sort($found);

        return array_values(array_unique($found));
    }
}
