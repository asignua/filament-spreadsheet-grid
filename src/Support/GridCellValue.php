<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Support;

use Asignua\FilamentSpreadsheetGrid\Enums\GridCellType;
use Carbon\Carbon;
use Throwable;

/**
 * Turns a raw string from the browser into a typed PHP value. The client already
 * normalises (see `coerceValue()` in the JS), but the server never trusts it.
 */
final class GridCellValue
{
    /**
     * @param array<string, string> $options select options, value => label
     *
     * @return array{0: mixed, 1: string|null} [value, message key under `spreadsheet-grid::messages` when malformed]
     */
    public static function normalize(GridCellType $type, mixed $raw, array $options = []): array
    {
        if ($raw !== null && !is_scalar($raw)) {
            return [null, 'invalid_value'];
        }

        if (is_bool($raw)) {
            $raw = $raw ? '1' : '0';
        }

        $text = $raw === null ? null : (string) $raw;

        if ($text === null || ($type !== GridCellType::Text && trim($text) === '') || ($type === GridCellType::Text && $text === '')) {
            // An empty boolean is "off", not "unknown".
            return $type === GridCellType::Boolean ? [false, null] : [null, null];
        }

        return match ($type) {
            GridCellType::Text => [$text, null],
            GridCellType::Number => self::number($text, false),
            GridCellType::Integer => self::number($text, true),
            GridCellType::Boolean => self::boolean($text),
            GridCellType::Date => self::date($text),
            GridCellType::Select => self::select($text, $options),
        };
    }

    /**
     * "1 250,5" -> "1250.5" (same rules as the client).
     */
    public static function normalizeNumber(string $value): string
    {
        $text = (string) preg_replace('/[\s\x{00a0}\x{202f}\']/u', '', $value);

        if (str_contains($text, ',') && str_contains($text, '.')) {
            return strrpos($text, ',') > strrpos($text, '.')
                ? str_replace(',', '.', str_replace('.', '', $text))
                : str_replace(',', '', $text);
        }

        return str_replace(',', '.', $text);
    }

    /**
     * "1,250" / "1.000": one separator before exactly three digits reads as thousands in one
     * locale and as decimals in another, and guessing wrong is a silent factor of 1000. Spaces
     * and apostrophes are thousands marks only, so they do not make a value ambiguous.
     *
     * @param bool $dotIsDecimal the browser sends numbers in the machine form, where a dot is
     *                           always the decimal point ("1.250" from a decimal(8,3) column)
     */
    public static function isAmbiguousNumber(string $value, bool $dotIsDecimal = false): bool
    {
        $text = (string) preg_replace('/[\s\x{00a0}\x{202f}\']/u', '', $value);

        return (bool) preg_match($dotIsDecimal ? '/^-?[1-9]\d{0,2},\d{3}$/' : '/^-?[1-9]\d{0,2}[.,]\d{3}$/', $text);
    }

    /**
     * ISO date formatted with PHP-style d/m/Y/y tokens; anything else is returned as is.
     */
    public static function formatDate(string $iso, string $format): string
    {
        if ($format === 'Y-m-d' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return $iso;
        }

        return (string) preg_replace_callback(
            '/[dmYy]/',
            static fn (array $token): string => match ($token[0]) {
                'd' => $m[3],
                'm' => $m[2],
                'Y' => $m[1],
                default => substr($m[1], 2),
            },
            $format,
        );
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    private static function number(string $text, bool $integer): array
    {
        // A decimal column keeps "1.250" (the machine form the browser sends); an integer column
        // has no machine form with three decimals, so there "1.000" can only be a thousands mark.
        if (self::isAmbiguousNumber($text, dotIsDecimal: !$integer)) {
            return [null, 'invalid_number'];
        }

        $normalized = self::normalizeNumber(trim($text));

        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            return [null, 'invalid_number'];
        }

        if ($integer) {
            // "5" and "5.0" are integers, "5.5" is left for the `integer` rule to reject, and so
            // is a number beyond PHP's int range ((int) would saturate it silently).
            $whole = (string) preg_replace('/\.0+$/', '', $normalized);

            if (!preg_match('/^-?\d+$/', $whole)) {
                return [$normalized, null];
            }

            $canonical = (string) preg_replace('/^(-?)0+(?=\d)/', '$1', $whole);
            $int = (int) $whole;

            return [(string) $int === $canonical || $canonical === '-0' ? $int : $normalized, null];
        }

        // A numeric STRING: no float rounding for decimal columns.
        return [$normalized, null];
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    private static function boolean(string $text): array
    {
        return match (mb_strtolower(trim($text))) {
            '1', 'true', 'yes', 'y', 'on', 'x', '✓', '✔', 'так', 'да', 'ja', 'oui', 'si', 'sí', 'tak', 'evet', 'sim' => [true, null],
            '0', 'false', 'no', 'n', 'off', '✗', '✘', 'ні', 'нет', 'nein', 'non', 'nee', 'nie', 'hayır', 'não' => [false, null],
            default => [null, 'invalid_boolean'],
        };
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    private static function date(string $text): array
    {
        $text = trim($text);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
            return [null, 'invalid_date'];
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $text);
        } catch (Throwable) {
            return [null, 'invalid_date'];
        }

        // createFromFormat rolls 2026-02-31 over to March: reject that.
        if (!$date instanceof Carbon || $date->format('Y-m-d') !== $text) {
            return [null, 'invalid_date'];
        }

        return [$text, null];
    }

    /**
     * @param array<string, string> $options
     *
     * @return array{0: mixed, 1: string|null}
     */
    private static function select(string $text, array $options): array
    {
        // PHP turns numeric-string array keys into ints, so a key comes back in its own type.
        foreach (array_keys($options) as $key) {
            if ((string) $key === $text) {
                return [$key, null];
            }
        }

        return [null, 'invalid_option'];
    }
}
