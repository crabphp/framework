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
     * Whether a domain is itself a public suffix: "co.uk" is, "example.co.uk"
     * is not. The domain is ASCII, as a cookie's must be: a Unicode name is
     * given in its punycode form, "xn--55qx5d.cn" for "公司.cn". One that is
     * not ASCII is taken for a public suffix, as it cannot be checked safely.
     */
    public static function isPublic(string $domain): bool
    {
        $domain = strtolower(rtrim($domain, '.'));
        if ($domain === '' || preg_match('/^[\x00-\x7F]*$/D', $domain) !== 1) {
            return true;
        }
        $list = self::$list ??= self::read();
        if (isset($list['exceptions'][$domain])) {
            return false;
        }
        // "*.kawasaki.jp" leaves "kawasaki.jp" itself shared, as browsers take it.
        if (isset($list['rules'][$domain]) || isset($list['wildcards'][$domain])) {
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
     * A rule in the form a cookie's domain gives it: ASCII, its Unicode labels
     * in punycode. The list is normalized already, so no IDNA mapping is
     * needed, and no extension: the encoding is done here.
     *
     * @return list<string>
     */
    private static function forms(string $rule): array
    {
        if (preg_match('/^[\x00-\x7F]*$/D', $rule) === 1) {
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
        $labels = [];
        foreach (explode('.', mb_strtolower($name, 'UTF-8')) as $label) {
            $labels[] = preg_match('/^[\x00-\x7F]*$/D', $label) === 1 ? $label : 'xn--' . self::punycode($label);
        }
        return [$marks . implode('.', $labels)];
    }

    /**
     * One label in punycode, RFC 3492's encoding, without its "xn--".
     */
    private static function punycode(string $label): string
    {
        $points = array_map(mb_ord(...), mb_str_split($label, 1, 'UTF-8'));
        $output = implode('', array_map(chr(...), array_filter($points, static fn(int $point): bool => $point < 0x80)));
        $basic = strlen($output);
        $handled = $basic;
        if ($basic > 0) {
            $output .= '-';
        }
        $n = 0x80;
        $delta = 0;
        $bias = 72;
        $total = count($points);
        while ($handled < $total) {
            $next = min(array_filter($points, static fn(int $point): bool => $point >= $n));
            $delta += ($next - $n) * ($handled + 1);
            $n = $next;
            foreach ($points as $point) {
                if ($point < $n) {
                    ++$delta;
                    continue;
                }
                if ($point !== $n) {
                    continue;
                }
                $q = $delta;
                for ($k = 36;; $k += 36) {
                    $t = $k <= $bias ? 1 : ($k >= ($bias + 26) ? 26 : $k - $bias);
                    if ($q < $t) {
                        break;
                    }
                    $output .= self::digit($t + (($q - $t) % (36 - $t)));
                    $q = intdiv($q - $t, 36 - $t);
                }
                $output .= self::digit($q);
                $bias = self::adapt($delta, $handled + 1, $handled === $basic);
                $delta = 0;
                ++$handled;
            }
            ++$delta;
            ++$n;
        }
        return $output;
    }

    private static function digit(int $value): string
    {
        return chr($value < 26 ? $value + 97 : $value + 22);
    }

    private static function adapt(int $delta, int $points, bool $first): int
    {
        $delta = $first ? intdiv($delta, 700) : intdiv($delta, 2);
        $delta += intdiv($delta, $points);
        $k = 0;
        while ($delta > 455) {
            $delta = intdiv($delta, 35);
            $k += 36;
        }
        return $k + intdiv(36 * $delta, $delta + 38);
    }
}
