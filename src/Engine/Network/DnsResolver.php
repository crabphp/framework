<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

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
     * @var array<string, array{list<string>, float}>
     */
    private array $kept = [];
    /**
     * The next expiry sweep, on the same monotonic clock as cached answers.
     */
    private float $pruneAt = 0.0;
    /**
     * Callers waiting for a name another caller is already asking for; each takes (?list<string>, ?Throwable).
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
        $cancellation?->throwIfCancelled();
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
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
        if (isset($this->hosts[$name])) {
            return $this->hosts[$name];
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
            $found = $this->find($loop, $name, $cancellation);
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
     * @return non-empty-list<string>
     */
    private function find(EventLoop $loop, string $name, ?Cancellation $cancellation): array
    {
        $this->servers ??= self::readServers($this->configuration);
        $answer = null;
        if ($this->servers !== []) {
            $answer = $this->ask($loop, $name, self::TYPE_A, $cancellation);
            if ($answer !== null && $answer[0] === []) {
                // A name with no IPv4 address may have an IPv6 one.
                $answer = $this->ask($loop, $name, self::TYPE_AAAA, $cancellation) ?? $answer;
            }
        }
        if ($answer !== null && $answer[0] !== []) {
            $keep = max(self::KEEP_AT_LEAST, min(self::KEEP_AT_MOST, $answer[1]));
            $this->kept[$name] = [$answer[0], (hrtime(true) / 1_000_000_000) + $keep];
            return $answer[0];
        }
        if ($this->fallback) {
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
     * @return array{list<string>, int}|null The addresses, none when the name has none of this kind, and for how many seconds they hold; null when no name server gave an answer.
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
        $suspension = $loop->getSuspension();
        $settled = false;
        $finish = static function (?string $reply, ?Throwable $error = null) use ($suspension, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume($reply);
            }
        };
        $watcher = $loop->onReadable($socket, static function () use ($socket, $id, $finish): void {
            $reply = @fread($socket, 4096);
            if ($reply === false || $reply === '') {
                // The server's address refuses the question: there is nothing to wait for.
                $finish(null);
                return;
            }
            // Only the reply to this question: anything else on the socket is not ours to believe.
            if (strlen($reply) >= 12 && unpack('n', $reply)[1] === $id && (ord($reply[2]) & 0x80) !== 0) {
                $finish($reply);
            }
        });
        $timer = $loop->delay($this->patience, static fn() => $finish(null));
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $finish(null, $error));
        try {
            return $suspension->suspend();
        } finally {
            $settled = true;
            $loop->cancel($watcher);
            $loop->cancel($timer);
            $unsubscribe?->__invoke();
            fclose($socket);
        }
    }

    /**
     * Waits for the answer another caller is already asking for.
     *
     * @return non-empty-list<string>
     */
    private function join(EventLoop $loop, string $name, ?Cancellation $cancellation): array
    {
        $suspension = $loop->getSuspension();
        $settled = false;
        $finish = static function (?array $found, ?Throwable $error = null) use ($suspension, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume($found);
            }
        };
        $id = $this->next++;
        $this->asking[$name][$id] = $finish;
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $finish(null, $error));
        try {
            return $suspension->suspend();
        } finally {
            $settled = true;
            // Keep the leader's empty map so later callers still join it.
            unset($this->asking[$name][$id]);
            $unsubscribe?->__invoke();
        }
    }

    /**
     * Reads a reply's addresses of one kind.
     *
     * @return array{list<string>, int}|null Null when the reply is no answer to go by: cut short, refused, or not to be made sense of.
     */
    private static function read(string $reply, int $type): ?array
    {
        $head = unpack('nid/nflags/nquestions/nanswers', $reply);
        $code = $head['flags'] & 0x000F;
        if (($head['flags'] & 0x0200) !== 0 || $code !== 0 && $code !== 3) {
            // Cut short to fit, or a server that failed or refused: another may do better.
            return null;
        }
        if ($code === 3) {
            // The name does not exist.
            return [[], 0];
        }
        $offset = 12;
        $length = strlen($reply);
        for ($i = 0; $i < $head['questions']; ++$i) {
            $offset = self::skipName($reply, $offset);
            if ($offset === null) {
                return null;
            }
            $offset += 4;
        }
        $addresses = [];
        $holds = PHP_INT_MAX;
        for ($i = 0; $i < $head['answers']; ++$i) {
            $offset = self::skipName($reply, $offset);
            if ($offset === null || ($offset + 10) > $length) {
                return null;
            }
            $record = unpack('ntype/nclass/Nttl/nlength', $reply, $offset);
            $offset += 10;
            if (($offset + $record['length']) > $length) {
                return null;
            }
            $size = $type === self::TYPE_A ? 4 : 16;
            if ($record['type'] === $type && $record['class'] === 1 && $record['length'] === $size) {
                $addresses[] = (string) inet_ntop(substr($reply, $offset, $size));
                $holds = min($holds, $record['ttl']);
            }
            // Other records, such as the other names a name stands for, are passed over.
            $offset += $record['length'];
        }
        return [$addresses, $addresses === [] ? 0 : $holds];
    }

    /**
     * Where the bytes after a name begin; null when the name runs off the reply.
     */
    private static function skipName(string $reply, int $offset): ?int
    {
        $length = strlen($reply);
        while ($offset < $length) {
            $size = ord($reply[$offset]);
            if ($size === 0) {
                return $offset + 1;
            }
            if (($size & 0xC0) === 0xC0) {
                // The rest of the name is elsewhere in the reply; here it takes two bytes.
                return $offset + 2;
            }
            $offset += 1 + $size;
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
