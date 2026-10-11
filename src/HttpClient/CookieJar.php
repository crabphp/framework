<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\CookieJar as CookieJarContract;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UriInterface;

/**
 * Cookies kept in memory for as long as the jar lives: what a server set
 * is sent back to that server, and to nobody else.
 *
 *     $jar = new CookieJar();
 *     $session = $http->cookies($jar);
 *     $session->post('https://shop.example/login', form: [...]);
 *     $session->get('https://shop.example/orders');     // sent with the cookie the login set
 *
 * Make one for each caller whose cookies are to be kept apart. A cookie
 * goes back only where a browser would send it: to the host that set it,
 * or to the hosts under the domain it named, on the paths it named, over
 * an encrypted connection when it said so, and until it expires.
 */
final class CookieJar implements CookieJarContract
{
    /**
     * By domain, path and name.
     *
     * @var array<string, array{name: string, value: string, domain: string, hostOnly: bool, path: string, secure: bool, expires: ?int}>
     */
    private array $cookies = [];

    /**
     * @param ClockInterface|null $clock Tells whether a cookie has expired; the real time when left out.
     */
    public function __construct(private readonly ?ClockInterface $clock = null)
    {
    }

    public function store(UriInterface $from, array $setCookie): void
    {
        $host = strtolower($from->getHost());
        foreach ($setCookie as $line) {
            $parts = explode(';', $line);
            $pair = explode('=', trim(array_shift($parts)), 2);
            if (count($pair) !== 2 || trim($pair[0]) === '') {
                continue;
            }
            $cookie = [
                'name' => trim($pair[0]),
                'value' => trim($pair[1], " \t\""),
                'domain' => $host,
                'hostOnly' => true,
                'path' => self::folder($from->getPath()),
                'secure' => false,
                'expires' => null,
            ];
            $maxAge = null;
            foreach ($parts as $part) {
                [$name, $value] = array_map(trim(...), [...explode('=', $part, 2), '']);
                switch (strtolower($name)) {
                    case 'domain':
                        $domain = strtolower(ltrim($value, '.'));
                        if ($domain !== '') {
                            $cookie['domain'] = $domain;
                            $cookie['hostOnly'] = false;
                        }
                        break;
                    case 'path':
                        if (str_starts_with($value, '/')) {
                            $cookie['path'] = $value;
                        }
                        break;
                    case 'secure':
                        $cookie['secure'] = true;
                        break;
                    case 'max-age':
                        if (preg_match('/^-?\d+$/D', $value) === 1) {
                            $maxAge = (int) $value;
                        }
                        break;
                    case 'expires':
                        $at = strtotime($value);
                        if ($at !== false) {
                            $cookie['expires'] = $at;
                        }
                        break;
                }
            }
            if ($maxAge !== null) {
                // Stated both ways, the number of seconds is the one that counts.
                $cookie['expires'] = $this->now() + $maxAge;
            }
            // A server speaks for itself and for the hosts under it, never for another domain or for every site of an ending such as "com".
            if (!$cookie['hostOnly'] && ($cookie['domain'] !== $host && (!self::under($host, $cookie['domain']) || !str_contains($cookie['domain'], '.')))) {
                continue;
            }
            $key = "{$cookie['domain']}|{$cookie['path']}|{$cookie['name']}";
            if ($cookie['expires'] !== null && $cookie['expires'] <= $this->now()) {
                // Setting a cookie that has already expired is how one is taken back.
                unset($this->cookies[$key]);
                continue;
            }
            $this->cookies[$key] = $cookie;
        }
    }

    public function header(UriInterface $to): string
    {
        $pairs = [];
        foreach ($this->matching($to) as $cookie) {
            $pairs[] = "{$cookie['name']}={$cookie['value']}";
        }
        return implode('; ', $pairs);
    }

    /**
     * Puts a cookie in the jar by hand, for the host of a URL and every path on it.
     */
    public function set(string $url, string $name, string $value): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            $shown = substr($url, 0, strcspn($url, '?#'));
            throw new \LogicException("A cookie is set for a whole URL, \"https://shop.example\", not \"{$shown}\".");
        }
        $this->cookies["{$host}|/|{$name}"] = [
            'name' => $name, 'value' => $value, 'domain' => $host, 'hostOnly' => true, 'path' => '/', 'secure' => false, 'expires' => null,
        ];
    }

    /**
     * The cookies a request to a URL would be sent with, by name.
     *
     * @return array<string, string>
     */
    public function for(string $url): array
    {
        $cookies = [];
        foreach ($this->matching(new \Crab\Http\Uri($url)) as $cookie) {
            $cookies[$cookie['name']] ??= $cookie['value'];
        }
        return $cookies;
    }

    public function clear(): void
    {
        $this->cookies = [];
    }

    /**
     * The cookies that belong to a request there, the more specific path first.
     *
     * @return list<array{name: string, value: string, domain: string, hostOnly: bool, path: string, secure: bool, expires: ?int}>
     */
    private function matching(UriInterface $to): array
    {
        $host = strtolower($to->getHost());
        $path = $to->getPath() === '' ? '/' : $to->getPath();
        $encrypted = strtolower($to->getScheme()) === 'https';
        $now = $this->now();
        $matching = [];
        foreach ($this->cookies as $key => $cookie) {
            if ($cookie['expires'] !== null && $cookie['expires'] <= $now) {
                unset($this->cookies[$key]);
                continue;
            }
            if ($cookie['domain'] !== $host && ($cookie['hostOnly'] || !self::under($host, $cookie['domain']))) {
                continue;
            }
            if ($cookie['secure'] && !$encrypted) {
                continue;
            }
            $on = $cookie['path'];
            if ($path !== $on && !(str_starts_with($path, $on) && (str_ends_with($on, '/') || $path[strlen($on)] === '/'))) {
                continue;
            }
            $matching[] = $cookie;
        }
        usort($matching, static fn (array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));
        return $matching;
    }

    /**
     * Whether a host is under a domain: "api.shop.example" is under "shop.example". A numeric address is under nothing.
     */
    private static function under(string $host, string $domain): bool
    {
        return str_ends_with($host, '.' . $domain) && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
    }

    /**
     * The folder of a path, which a cookie that names no path belongs to.
     */
    private static function folder(string $path): string
    {
        $last = strrpos($path, '/');
        return $last === false || $last === 0 ? '/' : substr($path, 0, $last);
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }
}
