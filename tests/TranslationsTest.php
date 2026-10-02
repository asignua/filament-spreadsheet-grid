<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TranslationsTest extends TestCase
{
    private const array LOCALES = ['de', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr', 'uk'];

    /**
     * @return array<string, mixed>
     */
    private static function load(string $locale): array
    {
        /** @var array<string, mixed> */
        return require __DIR__.'/../resources/lang/'.$locale.'/messages.php';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return array_combine(self::LOCALES, array_map(fn (string $locale): array => [$locale], self::LOCALES));
    }

    #[DataProvider('locales')]
    public function test_every_locale_has_the_same_keys_and_placeholders_as_english(string $locale): void
    {
        $en = self::load('en');
        $other = self::load($locale);

        $this->assertSame(array_keys($en), array_keys($other), $locale.' keys differ');

        foreach ($en as $key => $english) {
            $translated = $other[$key];

            $this->assertIsString($english);
            $this->assertIsString($translated);
            $this->assertNotSame('', $translated, $locale.'.'.$key.' is empty');

            preg_match_all('/:[a-z_]+/', $english, $expected);
            preg_match_all('/:[a-z_]+/', $translated, $actual);

            $this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), $locale.'.'.$key.' placeholders differ');
        }
    }
}
