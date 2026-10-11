<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

use Crab\Engine\Async\Cancellation;

/**
 * Negotiates a v10 handshake and the two supported password plugins.
 * Password material and RSA keys never appear in a diagnostic message.
 *
 * @internal
 */
final class Authentication
{
    private const LONG_PASSWORD = 0x000001;
    private const LONG_FLAG = 0x000004;
    private const CONNECT_WITH_DB = 0x000008;
    private const PROTOCOL_41 = 0x000200;
    private const SSL = 0x000800;
    private const TRANSACTIONS = 0x002000;
    private const SECURE_CONNECTION = 0x008000;
    private const MULTI_RESULTS = 0x020000;
    private const PS_MULTI_RESULTS = 0x040000;
    private const PLUGIN_AUTH = 0x080000;
    private const LENENC_AUTH = 0x200000;

    private int $sequence = 0;

    private function __construct(
        private readonly Stream $stream,
        private readonly Settings $settings,
        private readonly float $deadline,
        private readonly ?Cancellation $cancellation,
    ) {
    }

    /**
     * Retains the numeric server version for executable-comment parsing.
     *
     * @return array{capabilities: int, salt: string, plugin: string, version: int}
     */
    public static function greeting(string $packet): array
    {
        if (($packet[0] ?? '') === "\xff") {
            throw Codec::error($packet);
        }
        $offset = 0;
        if (Codec::integer($packet, $offset, 1) !== 10) {
            throw new \RuntimeException('The async MySQL driver requires a v10 handshake from MySQL 8 or newer.');
        }
        $serverVersion = Codec::terminated($packet, $offset);
        $version = 0;
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $serverVersion, $parts) === 1) {
            $version = (int) $parts[1] * 10000 + (int) $parts[2] * 100 + (int) $parts[3];
        }
        Codec::bytes($packet, $offset, 4);
        $salt = Codec::bytes($packet, $offset, 8);
        Codec::bytes($packet, $offset, 1);
        $flags = Codec::integer($packet, $offset, 2);
        Codec::bytes($packet, $offset, 3);
        $flags |= Codec::integer($packet, $offset, 2) << 16;
        $length = Codec::integer($packet, $offset, 1);
        Codec::bytes($packet, $offset, 10);
        $tail = Codec::bytes($packet, $offset, max(13, $length - 8));
        $salt .= substr($tail, 0, 12);
        $plugin = ($flags & self::PLUGIN_AUTH) ? Codec::terminated($packet, $offset) : 'mysql_native_password';
        return ['capabilities' => $flags, 'salt' => $salt, 'plugin' => $plugin, 'version' => $version];
    }

    public static function response(string $plugin, string $password, string $salt): string
    {
        if (!in_array($plugin, ['mysql_native_password', 'caching_sha2_password'], true)) {
            throw new \RuntimeException("MySQL authentication plugin {$plugin} is unsupported; use caching_sha2_password or mysql_native_password.");
        }
        if ($password === '') {
            return '';
        }
        if ($plugin === 'mysql_native_password') {
            $first = sha1($password, true);
            return $first ^ sha1($salt . sha1($first, true), true);
        }
        $first = hash('sha256', $password, true);
        return $first ^ hash('sha256', hash('sha256', $first, true) . $salt, true);
    }

    public static function rsa(string $password, string $salt, string $key): string
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('Full MySQL authentication needs TLS or PHP openssl; enable one or use mysql_native_password.');
        }
        $plain = $password . "\0";
        $scrambled = '';
        for ($i = 0; $i < strlen($plain); ++$i) {
            $scrambled .= $plain[$i] ^ $salt[$i % strlen($salt)];
        }
        if (!@openssl_public_encrypt($scrambled, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new \RuntimeException('MySQL RSA authentication failed; use TLS or check the server authentication configuration.');
        }
        return $encrypted;
    }

    /**
     * SSLRequest upgrades the same stream before authentication credentials.
     * Every step uses one setup deadline, including progressing/buffered traffic.
     *
     * @return array{status: int, version: int}
     */
    public static function login(Stream $stream, Settings $settings, string $username, string $password, float $deadline, ?Cancellation $cancellation): array
    {
        $exchange = new self($stream, $settings, $deadline, $cancellation);
        $greeting = self::greeting($exchange->receive());
        $flags = $exchange->capabilities($greeting['capabilities']);
        $exchange->handshake($flags, $greeting['plugin'], $greeting['salt'], $username, $password);
        $status = $exchange->authenticate($greeting['plugin'], $greeting['salt'], $password);
        return ['status' => $status, 'version' => $greeting['version']];
    }

    /**
     * Negotiates only features the decoder implements and the server offers.
     */
    private function capabilities(int $offered): int
    {
        // FOUND_ROWS is deliberately absent: PDO reports changed rows by default.
        // Ordinary EOF packets keep result termination unambiguous for both protocols.
        $flags = self::LONG_PASSWORD | self::LONG_FLAG | self::PROTOCOL_41 | self::TRANSACTIONS
            | self::SECURE_CONNECTION | self::MULTI_RESULTS | self::PS_MULTI_RESULTS | self::PLUGIN_AUTH | self::LENENC_AUTH;
        if ($this->settings->database !== '') {
            $flags |= self::CONNECT_WITH_DB;
        }
        if ($this->settings->encrypted) {
            if (!($offered & self::SSL)) {
                throw new \RuntimeException('MySQL does not offer TLS; enable TLS on the server before setting options["ssl"] on the database connection.');
            }
            $flags |= self::SSL;
        }
        $flags &= $offered;
        $required = self::PROTOCOL_41 | self::SECURE_CONNECTION | self::PLUGIN_AUTH;
        if (($flags & $required) !== $required) {
            throw new \RuntimeException('MySQL lacks the required protocol and authentication capabilities; use MySQL 8 or newer.');
        }
        return $flags;
    }

    /**
     * The first 32 bytes are SSLRequest when TLS is requested.
     */
    private function handshake(int $flags, string $plugin, string $salt, string $username, string $password): void
    {
        $header = pack('VV', $flags, 0x40000000) . chr($this->settings->charset) . str_repeat("\0", 23);
        if ($this->settings->encrypted) {
            $this->send($header);
            $this->stream->encrypt($this->deadline, $this->cancellation);
        }
        $response = self::response($plugin, $password, $salt);
        $auth = ($flags & self::LENENC_AUTH) ? Codec::string($response) : chr(strlen($response)) . $response;
        $database = ($flags & self::CONNECT_WITH_DB) ? $this->settings->database . "\0" : '';
        $this->send($header . $username . "\0" . $auth . $database . $plugin . "\0");
    }

    /**
     * A switch may replace the plugin once. Fast/full success must then be
     * followed by OK or ERR; repeated AuthMoreData is not another auth round.
     */
    private function authenticate(string $plugin, string $salt, string $password): int
    {
        $switched = false;
        $awaitingOk = false;
        while (true) {
            $packet = $this->receive();
            $kind = $packet[0] ?? '';
            if ($kind === "\0") {
                return Codec::ok($packet)['status'];
            }
            if ($kind === "\xff") {
                throw Codec::error($packet);
            }
            if ($awaitingOk) {
                throw new \RuntimeException('MySQL repeated a completed authentication step; check the server authentication configuration.');
            }
            if ($kind === "\xfe") {
                if ($switched) {
                    throw new \RuntimeException('MySQL repeated its authentication switch; check the server authentication configuration.');
                }
                $switched = true;
                $offset = 1;
                $plugin = Codec::terminated($packet, $offset);
                $salt = substr(substr($packet, $offset), 0, 20);
                $this->send(self::response($plugin, $password, $salt));
                continue;
            }
            if ($plugin === 'caching_sha2_password' && $packet === "\x01\x03") {
                $awaitingOk = true;
                continue;
            }
            if ($plugin === 'caching_sha2_password' && $packet === "\x01\x04") {
                $this->fullAuthentication($password, $salt);
                $awaitingOk = true;
                continue;
            }
            throw new \RuntimeException('MySQL sent an unsupported authentication exchange; use caching_sha2_password or mysql_native_password.');
        }
    }

    /**
     * Clear credentials use an encrypted/local stream; TCP uses RSA OAEP.
     * A server-supplied key needs a trusted network, as documented in the guide.
     */
    private function fullAuthentication(string $password, string $salt): void
    {
        if ($this->settings->encrypted || $this->settings->socket !== null) {
            $this->send($password . "\0");
            return;
        }
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('Full MySQL authentication needs TLS or PHP openssl; enable one or use mysql_native_password.');
        }
        $this->send("\x02");
        $key = $this->receive();
        if (($key[0] ?? '') === "\xff") {
            throw Codec::error($key);
        }
        if (($key[0] ?? '') !== "\x01") {
            throw new \RuntimeException('MySQL did not provide an RSA key; use TLS or mysql_native_password.');
        }
        $this->send(self::rsa($password, $salt, rtrim(substr($key, 1), "\0")));
    }

    private function receive(): string
    {
        $read = fn (int $length): string => $this->stream->read($length, $this->deadline, $this->cancellation);
        return Packets::read($read, $this->sequence);
    }

    private function send(string $payload): void
    {
        $sent = false;
        foreach (Packets::encode($payload, $this->sequence) as $packet) {
            $this->stream->write($packet, $sent, $this->deadline, $this->cancellation);
        }
    }
}
