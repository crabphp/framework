<?php

declare(strict_types=1);

namespace Crab\Session;

use Crab\Contracts\Session\Driver;
use Crab\Contracts\Session\Record;

/**
 * Keeps the whole session in its cookie, signed with HMAC-SHA256 so that the
 * browser cannot change it. It is not encrypted: the browser can read what it
 * holds, so a secret belongs in a driver that keeps sessions on the server.
 * There is nothing on the server to remove, and so nothing to revoke: a copy
 * of the cookie works until it expires.
 */
final readonly class CookieDriver implements Driver
{
    /**
     * The most a cookie's value may hold; browsers keep 4,096 bytes per cookie,
     * its name and attributes included.
     */
    public const int MAX_BYTES = 3_800;

    /**
     * @param string $key The secret that signs the cookie: at least 32 bytes.
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $key,
    ) {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException(
                'The cookie session driver needs a key of at least 32 bytes: set app.key, which php cua key:generate writes.',
            );
        }
    }

    public function read(string $cookie, int $now): ?Record
    {
        $parts = explode('.', $cookie);
        if (count($parts) !== 2 || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        return $json === false ? null : Record::fromJson($json, $now);
    }

    public function write(?string $cookie, Record $record): string
    {
        $payload = self::base64($record->toJson());
        $value = $payload . '.' . $this->sign($payload);
        if (strlen($value) > self::MAX_BYTES) {
            throw new \LengthException(
                'The session holds more than a cookie can carry ('
                . strlen($value)
                . ' bytes, at most '
                . self::MAX_BYTES
                . '): keep less in it, or set session.driver to file or redis.',
            );
        }
        return $value;
    }

    public function destroy(string $cookie): void {}

    public function sweep(int $now): int
    {
        return 0;
    }

    private function sign(string $payload): string
    {
        return self::base64(hash_hmac('sha256', 'crab-session:' . $payload, $this->key, true));
    }

    private static function base64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
