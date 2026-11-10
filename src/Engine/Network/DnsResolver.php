<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use RuntimeException;
use Throwable;

/**
 * Finds the numeric addresses of a host name without holding the worker:
 * the fiber that asks is suspended while the name servers answer.
 *
 * A name is looked for in the hosts file, then among the answers kept
 * from before, then asked of the name servers over UDP. Addresses are
 * kept for as long as their answer said they hold. Where the name
 * servers give nothing, the system's own resolver is asked, which does
 * hold the worker but knows what they may not: names on the local
 * network, and search domains.
 */
final class DnsResolver
{
    private const TYPE_A = 1;
    private const TYPE_AAAA = 28;
    private const TYPE_MX = 15;
    private const TYPE_TXT = 16;
    private const TYPE_PTR = 12;
    /**
     * The shortest and the longest an answer is kept, in seconds, whatever it says.
     */
    private const KEEP_AT_LEAST = 5;
    private const KEEP_AT_MOST = 300;

    /**
     * Name servers as "address" or "address:port".
     *
     * @var list<string>|null
     */
    private ?array $servers;
    /**
     * Names in the hosts file to their addresses; read on first use.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $hosts = null;
    /**
     * Addresses found, and until when each answer is kept, on the clock hrtime() reads, in seconds.
     *
     * @var array<string, array{list<string>|list<MxRecord>, float}>
     */
    private array $kept = [];
    /**
     * The next expiry sweep, on the same monotonic clock as cached answers.
     */
    private float $pruneAt = 0.0;
    /**
     * Callers waiting for a name another caller is already asking for; each takes (?array, ?Throwable).
     *
     * @var array<string, array<int, \Closure>>
     */
    private array $asking = [];
    private int $next = 0;

    /**
     * @param list<string>|null $servers The name servers to ask; those of the system's resolver configuration when left out.
     * @param float $patience Seconds one name server is given to answer before the next is asked.
     * @param int $rounds How many times the list of name servers is gone through.
     * @param bool $fallback Whether the system's resolver, which holds the worker, is asked when the name servers give nothing.
     */
    public function __construct(
        ?array $servers = null,
        private readonly float $patience = 1.5,
        private readonly int $rounds = 2,
        private readonly bool $fallback = true,
        private readonly string $hostsFile = '/etc/hosts',
        private readonly string $configuration = '/etc/resolv.conf',
    ) {
        $this->servers = $servers;
    }

    /**
     * The addresses of a host, IPv4 before IPv6. A numeric address is
     * returned as it is.
     *
     * @return non-empty-list<string>
     * @throws RuntimeException When no address is found for the name.
     */
    public function resolve(EventLoop $loop, string $host, ?Cancellation $cancellation = null): array
    {
        return $this->lookup($loop, $host, self::TYPE_A, $cancellation, addressFallback: true);
    }

    /**
     * @return list<string> IPv4 addresses only; empty for a valid answer without A records.
     */
    public function resolveA(EventLoop $loop, string $host, ?Cancellation $cancellation = null): array
    {
        return $this->lookup($loop, $host, self::TYPE_A, $cancellation);
    }

    /**
     * @return list<string> IPv6 addresses only; empty for a valid answer without AAAA records.
     */
    public function resolveAaaa(EventLoop $loop, string $host, ?Cancellation $cancellation = null): array
    {
        return $this->lookup($loop, $host, self::TYPE_AAAA, $cancellation);
    }

    /**
     * Reverse an IPv4 or IPv6 address and return validated PTR host names.
     *
     * @return list<string>
     */
    public function resolvePtr(EventLoop $loop, string $address, ?Cancellation $cancellation = null): array
    {
        $packed = @inet_pton(trim($address, '[]'));
        if ($packed === false) {
            throw new RuntimeException('PTR lookup requires a numeric IPv4 or IPv6 address.');
        }
        $name = strlen($packed) === 4
            ? implode('.', array_reverse(explode('.', inet_ntop($packed)))) . '.in-addr.arpa'
            : implode('.', array_reverse(str_split(bin2hex($packed)))) . '.ip6.arpa';
        return $this->lookup($loop, $name, self::TYPE_PTR, $cancellation);
    }

    /**
     * @return list<MxRecord> Lowest preference first; an exchange of "." is a null MX.
     */
    public function resolveMx(EventLoop $loop, string $host, ?Cancellation $cancellation = null): array
    {
        return $this->lookup($loop, $host, self::TYPE_MX, $cancellation);
    }

    /**
     * @return list<string> Each record's character strings joined in wire order.
     */
    public function resolveTxt(EventLoop $loop, string $host, ?Cancellation $cancellation = null): array
    {
        return $this->lookup($loop, $host, self::TYPE_TXT, $cancellation);
    }

    /**
     * @return list<string>|list<MxRecord>
     */
    private function lookup(
        EventLoop $loop,
        string $host,
        int $type,
        ?Cancellation $cancellation,
        bool $addressFallback = false,
    ): array {
        $cancellation?->throwIfCancelled();
        $literal = trim($host, '[]');
        if (
            in_array($type, [self::TYPE_A, self::TYPE_AAAA], true)
            && filter_var($literal, FILTER_VALIDATE_IP) !== false
        ) {
            $ipv6 = str_contains($literal, ':');
            return $addressFallback || $ipv6 === ($type === self::TYPE_AAAA) ? [$literal] : [];
        }
        $name = strtolower(rtrim($host, '.'));
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $name) === 1) {
            $name = (string) idn_to_ascii($name);
        }
        if (
            $name === ''
            || strlen($name) > 253
            || preg_match(
                '/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/D',
                $name,
            ) !== 1
        ) {
            throw new RuntimeException("\"{$host}\" is not a host name");
        }
        $this->hosts ??= self::readHosts($this->hostsFile);
        if (in_array($type, [self::TYPE_A, self::TYPE_AAAA], true) && isset($this->hosts[$name])) {
            return $addressFallback
                ? $this->hosts[$name]
                : array_values(array_filter(
                    $this->hosts[$name],
                    static fn(string $address): bool => str_contains($address, ':') === ($type === self::TYPE_AAAA),
                ));
        }
        $question = $name;
        if (!$addressFallback) {
            $name .= ':' . $type;
        }
        $now = hrtime(true) / 1_000_000_000;
        if ($now >= $this->pruneAt) {
            foreach ($this->kept as $key => $entry) {
                if ($entry[1] > $now) {
                    continue;
                }
                unset($this->kept[$key]);
            }
            $this->pruneAt = $now + self::KEEP_AT_LEAST;
        }
        if (isset($this->kept[$name]) && $this->kept[$name][1] > $now) {
            return $this->kept[$name][0];
        }
        if (isset($this->asking[$name])) {
            return $this->join($loop, $name, $cancellation);
        }
        $this->asking[$name] = [];
        $found = null;
        $failure = null;
        try {
            $found = $this->find($loop, $question, $cancellation, $type, $name, $addressFallback);
        } catch (Throwable $error) {
            $failure = $error;
        }
        $waiting = $this->asking[$name];
        unset($this->asking[$name]);
        foreach ($waiting as $settle) {
            // A caller that asked while this one was asking is given the same answer.
            $failure instanceof CancelledException
                ? $settle(null, new RuntimeException("no address was found for the name {$host}"))
                : $settle($found, $failure);
        }
        return $found ?? throw $failure;
    }

    /**
     * Forgets every answer that was kept.
     */
    public function forget(): void
    {
        $this->kept = [];
        $this->pruneAt = 0.0;
        $this->hosts = null;
    }

    /**
     * @return list<string>|list<MxRecord>
     */
    private function find(
        EventLoop $loop,
        string $name,
        ?Cancellation $cancellation,
        int $type,
        string $key,
        bool $addressFallback,
    ): array {
        $this->servers ??= self::readServers($this->configuration);
        $answer = null;
        if ($this->servers !== []) {
            $answer = $this->ask($loop, $name, $type, $cancellation);
            if ($addressFallback && $answer !== null && $answer[0] === []) {
                // A name with no IPv4 address may have an IPv6 one.
                $answer = $this->ask($loop, $name, self::TYPE_AAAA, $cancellation) ?? $answer;
            }
        }
        if ($answer !== null && ($answer[0] !== [] || !$addressFallback)) {
            $keep = max(self::KEEP_AT_LEAST, min(self::KEEP_AT_MOST, $answer[1]));
            $this->kept[$key] = [$answer[0], (hrtime(true) / 1_000_000_000) + $keep];
            return $answer[0];
        }
        if ($addressFallback && $this->fallback) {
            $cancellation?->throwIfCancelled();
            $found = @gethostbynamel($name);
            if ($found !== false && $found !== []) {
                $this->kept[$name] = [$found, (hrtime(true) / 1_000_000_000) + (self::KEEP_AT_LEAST * 6)];
                return $found;
            }
        }
        throw new RuntimeException("no address was found for the name {$name}");
    }

    /**
     * Asks the name servers one question, each in turn until one answers.
     *
     * @return array{list<string>|list<MxRecord>, int}|null The addresses, none when the name has none of this kind, and for how many seconds they hold; null when no name server gave an answer.
     */
    private function ask(EventLoop $loop, string $name, int $type, ?Cancellation $cancellation): ?array
    {
        $question = '';
        foreach (explode('.', $name) as $label) {
            $question .= chr(strlen($label)) . $label;
        }
        $question .= "\0" . pack('nn', $type, 1);
        for ($round = 0; $round < $this->rounds; ++$round) {
            foreach ($this->servers as $server) {
                $id = random_int(0, 0xFFFF);
                // Recursion asked for, one question.
                $query = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $question;
                $reply = $this->exchange($loop, $server, $query, $id, $cancellation);
                $answer = $reply === null ? null : self::read($reply, $type);
                if ($answer !== null) {
                    return $answer;
                }
            }
        }
        return null;
    }

    /**
     * Sends one query to one name server and waits for its reply.
     *
     * @return string|null The reply; null when none came in time or the server could not be asked.
     */
    private function exchange(
        EventLoop $loop,
        string $server,
        string $query,
        int $id,
        ?Cancellation $cancellation,
    ): ?string {
        $cancellation?->throwIfCancelled();
        $address = preg_match('/^(.+):(\d+)$/D', $server, $parts) === 1 && !str_contains($parts[1], ':')
            ? [$parts[1], $parts[2]]
            : [trim($server, '[]'), '53'];
        $target = str_contains($address[0], ':')
            ? "udp://[{$address[0]}]:{$address[1]}"
            : "udp://{$address[0]}:{$address[1]}";
        $socket = @stream_socket_client($target, $code, $reason, 0, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            return null;
        }
        stream_set_blocking($socket, false);
        if (@fwrite($socket, $query) !== strlen($query)) {
            fclose($socket);
            return null;
        }
        return Async::await(
            $loop,
            function (\Closure $resume) use ($loop, $socket, $id): \Closure {
                $watcher = $loop->onReadable($socket, static function () use ($socket, $id, $resume): void {
                    $reply = @fread($socket, 4096);
                    if ($reply === false || $reply === '') {
                        // The server's address refuses the question: there is nothing to wait for.
                        $resume(null);
                        return;
                    }
                    // Only the reply to this question: anything else on the socket is not ours to believe.
                    if (strlen($reply) >= 12 && unpack('n', $reply)[1] === $id && (ord($reply[2]) & 0x80) !== 0) {
                        $resume($reply);
                    }
                });
                $timer = $loop->delay($this->patience, static fn() => $resume(null));
                return static function () use ($loop, $watcher, $timer, $socket): void {
                    $loop->cancel($watcher);
                    $loop->cancel($timer);
                    fclose($socket);
                };
            },
            $cancellation,
        );
    }

    /**
     * Waits for the answer another caller is already asking for.
     *
     * @return list<string>|list<MxRecord>
     */
    private function join(EventLoop $loop, string $name, ?Cancellation $cancellation): array
    {
        $id = $this->next++;
        return Async::await(
            $loop,
            function (\Closure $resume, \Closure $fail) use ($name, $id): \Closure {
                $this->asking[$name][$id] = static fn(?array $found, ?Throwable $error = null) => $error === null
                    ? $resume($found)
                    : $fail($error);
                // Keep the leader's empty map so later callers still join it.
                return function () use ($name, $id): void {
                    unset($this->asking[$name][$id]);
                };
            },
            $cancellation,
        );
    }

    /**
     * Reads a reply's addresses of one kind.
     *
     * @return array{list<string>|list<MxRecord>, int}|null Null when the reply is no answer to go by: cut short, refused, or not to be made sense of.
     */
    private static function read(string $reply, int $type): ?array
    {
        $length = strlen($reply);
        if ($length < 12) {
            return null;
        }
        $head = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', $reply);
        $code = $head['flags'] & 0x000F;
        if (($head['flags'] & 0x0200) !== 0 || $code !== 0 && $code !== 3 || $head['questions'] !== 1) {
            return null;
        }
        $offset = self::skipName($reply, 12);
        if ($offset === null || ($offset + 4) > $length) {
            return null;
        }
        $question = unpack('ntype/nclass', $reply, $offset);
        if ($question['type'] !== $type || $question['class'] !== 1) {
            return null;
        }
        $offset += 4;
        $addresses = [];
        $holds = PHP_INT_MAX;
        $count = $head['answers'] + $head['authority'] + $head['additional'];
        for ($i = 0; $i < $count; ++$i) {
            $offset = self::skipName($reply, $offset);
            if ($offset === null || ($offset + 10) > $length) {
                return null;
            }
            $record = unpack('ntype/nclass/Nttl/nlength', $reply, $offset);
            $offset += 10;
            $end = $offset + $record['length'];
            if ($end > $length) {
                return null;
            }
            $value = self::recordData($reply, $record['type'], $offset, $end);
            if ($value === false) {
                return null;
            }
            if ($code === 0 && $i < $head['answers'] && $record['type'] === $type && $record['class'] === 1) {
                $addresses[] = $value;
                $holds = min($holds, $record['ttl']);
            }
            $offset = $end;
        }
        if ($offset !== $length) {
            return null;
        }
        if ($type === self::TYPE_MX) {
            usort($addresses, static fn(MxRecord $a, MxRecord $b): int => $a->preference <=> $b->preference);
        }
        return [$addresses, $addresses === [] ? 0 : $holds];
    }

    /**
     * Validate known RDATA shapes even outside the answer section. Unknown
     * record types remain opaque, bounded by their declared RDLENGTH.
     */
    private static function recordData(string $reply, int $type, int $offset, int $end): string|MxRecord|false|null
    {
        if ($type === self::TYPE_A || $type === self::TYPE_AAAA) {
            $size = $type === self::TYPE_A ? 4 : 16;
            return ($end - $offset) === $size ? inet_ntop(substr($reply, $offset, $size)) : false;
        }
        if ($type === self::TYPE_TXT) {
            if ($offset === $end) {
                return false;
            }
            $text = '';
            while ($offset < $end) {
                $size = ord($reply[$offset++]);
                if (($offset + $size) > $end) {
                    return false;
                }
                $text .= substr($reply, $offset, $size);
                $offset += $size;
            }
            return $text;
        }
        if ($type === self::TYPE_MX) {
            if (($end - $offset) < 3) {
                return false;
            }
            $preference = unpack('n', $reply, $offset)[1];
            $offset += 2;
            $name = self::readName($reply, $offset);
            return $name !== null && $offset === $end ? new MxRecord($preference, $name === '' ? '.' : $name) : false;
        }
        if ($type === self::TYPE_PTR) {
            $name = self::readName($reply, $offset);
            if (
                $name === null
                || $offset !== $end
                || $name === ''
                || preg_match(
                    '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD',
                    $name,
                ) !== 1
            ) {
                return false;
            }
            return strtolower($name);
        }
        if (in_array($type, [2, 5], true)) {
            $name = self::readName($reply, $offset);
            return $name !== null && $offset === $end ? null : false;
        }
        if ($type === 6) {
            // SOA: primary server, mailbox, and five 32-bit values.
            if (
                self::readName($reply, $offset) === null
                || $offset > $end
                || self::readName($reply, $offset) === null
            ) {
                return false;
            }
            return ($offset + 20) === $end ? null : false;
        }
        return null;
    }

    /**
     * Where the bytes after a name begin; null when the name runs off the reply.
     */
    private static function skipName(string $reply, int $offset): ?int
    {
        return self::readName($reply, $offset) === null ? null : $offset;
    }

    /**
     * Decode a bounded DNS name, including compression; reject cycles and truncation.
     */
    private static function readName(string $reply, int &$offset): ?string
    {
        $cursor = $offset;
        $end = null;
        $labels = [];
        $seen = [];
        $total = 0;
        while ($cursor < strlen($reply) && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $size = ord($reply[$cursor++]);
            if ($size === 0) {
                $offset = $end ?? $cursor;
                return implode('.', $labels);
            }
            if (($size & 0xC0) === 0xC0) {
                if ($cursor >= strlen($reply)) {
                    return null;
                }
                $end ??= $cursor + 1;
                $cursor = (($size & 0x3F) << 8) | ord($reply[$cursor]);
                continue;
            }
            if ($size > 63 || ($cursor + $size) > strlen($reply) || ($total += $size + 1) > 254) {
                return null;
            }
            $labels[] = substr($reply, $cursor, $size);
            $cursor += $size;
        }
        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function readHosts(string $file): array
    {
        $hosts = [];
        $lines = is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        foreach ($lines === false ? [] : $lines as $line) {
            $fields = preg_split('/\s+/', trim((string) strtok($line, '#')), flags: PREG_SPLIT_NO_EMPTY);
            if ($fields === false || count($fields) < 2 || filter_var($fields[0], FILTER_VALIDATE_IP) === false) {
                continue;
            }
            foreach (array_slice($fields, 1) as $name) {
                $hosts[strtolower($name)][] = $fields[0];
            }
        }
        foreach ($hosts as $name => $addresses) {
            // IPv4 first, as for a name that was asked for.
            usort($addresses, static fn(string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));
            $hosts[$name] = array_values(array_unique($addresses));
        }
        return $hosts;
    }

    /**
     * @return list<string>
     */
    private static function readServers(string $file): array
    {
        $servers = [];
        $lines = is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        foreach ($lines === false ? [] : $lines as $line) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $match) !== 1) {
                continue;
            }

            // An address may name the interface it is reached through, which a socket address cannot.
            $address = (string) strtok($match[1], '%');
            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $servers[] = $address;
            }
        }
        return $servers;
    }
}
