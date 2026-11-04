<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * The domains under which unrelated parties register their own, such as
 * "com", "co.uk" and "github.io", from the Public Suffix List. A cookie may
 * not be set for one, or a site could set it for every other site there.
 *
 * The list is data/public_suffix_list.dat, kept as published under the
 * Mozilla Public License 2.0. To bring it up to date, replace it with
 * https://publicsuffix.org/list/public_suffix_list.dat.
 */
final class PublicSuffixes
{
    private const LIST = __DIR__ . '/data/public_suffix_list.dat';

    /**
     * @var array{rules: array<string, true>, wildcards: array<string, true>, exceptions: array<string, true>}|null
     */
    private static ?array $list = null;

    /**
     * Whether a domain is itself a public suffix: "co.uk" is, "example.co.uk" is not.
     */
    public static function isPublic(string $domain): bool
    {
        $domain = strtolower(rtrim($domain, '.'));
        if ($domain === '') {
            return true;
        }
        $list = self::$list ??= self::read();
        if (isset($list['exceptions'][$domain])) {
            return false;
        }
        if (isset($list['rules'][$domain])) {
            return true;
        }
        $dot = strpos($domain, '.');
        if ($dot === false) {
            // Every top-level domain is one, listed or not: the list's own "*" rule.
            return true;
        }
        // "*.ck" makes every name directly under "ck" one.
        return isset($list['wildcards'][substr($domain, $dot + 1)]);
    }

    /**
     * @return array{rules: array<string, true>, wildcards: array<string, true>, exceptions: array<string, true>}
     */
    private static function read(): array
    {
        $list = ['rules' => [], 'wildcards' => [], 'exceptions' => []];
        $lines = @file(self::LIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('The public suffix list, ' . self::LIST . ', cannot be read.');
        }
        foreach ($lines as $line) {
            $rule = strtolower(trim(explode(' ', $line, 2)[0]));
            if ($rule === '' || str_starts_with($rule, '//')) {
                continue;
            }
            foreach (self::forms($rule) as $form) {
                if (str_starts_with($form, '!')) {
                    $list['exceptions'][substr($form, 1)] = true;
                } elseif (str_starts_with($form, '*.')) {
                    $list['wildcards'][substr($form, 2)] = true;
                } else {
                    $list['rules'][$form] = true;
                }
            }
        }
        return $list;
    }

    /**
     * A rule as written, and as a cookie's domain names it when it is not
     * plain ASCII: in its punycode form.
     *
     * @return list<string>
     */
    private static function forms(string $rule): array
    {
        if (preg_match('/^[\x00-\x7F]*$/D', $rule) === 1 || !function_exists('idn_to_ascii')) {
            return [$rule];
        }
        $marks = '';
        $name = $rule;
        foreach (['!', '*.'] as $mark) {
            if (!str_starts_with($name, $mark)) {
                continue;
            }
            $marks = $mark;
            $name = substr($name, strlen($mark));
        }
        $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return is_string($ascii) && $ascii !== $name ? [$rule, $marks . $ascii] : [$rule];
    }
}
