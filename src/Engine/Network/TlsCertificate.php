<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use RuntimeException;

/** Server certificate validation, performed in the worker without retaining PEM. */
final readonly class TlsCertificate
{
    /**
     * @param array<string,mixed> $options Explicit SSL options for this listener or upgrade.
     */
    public function __construct(
        private array $options,
    ) {}

    public function check(): void
    {
        if (
            isset($this->options['ciphers'])
            && (!is_string($this->options['ciphers']) || trim($this->options['ciphers']) === '')
        ) {
            throw new RuntimeException(
                'TLS ciphers must be a nonempty OpenSSL cipher list; omit the option to use DEFAULT.',
            );
        }
        // PHP has no SSL_CTX cipher-list validator. OpenSSL validates a nonempty
        // expression during negotiation; Connection reports local setup errors.

        while (openssl_error_string() !== false) {
            // Discard errors belonging to an earlier independent operation.
        }
        $certificate = $this->read('local_cert', 'certificate');
        $public = @openssl_x509_read($certificate);
        if ($public === false) {
            throw $this->failure('Cannot parse TLS certificate');
        }
        $private = isset($this->options['local_pk']) ? $this->read('local_pk', 'private key') : $certificate;
        $key = @openssl_pkey_get_private($private, $this->options['passphrase'] ?? '');
        if ($key === false) {
            throw $this->failure('Cannot load TLS private key');
        }
        if (!openssl_x509_check_private_key($public, $key)) {
            throw $this->failure('TLS certificate and private key do not match');
        }
    }

    private function read(string $option, string $kind): string
    {
        $path = $this->options[$option] ?? '';
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException(
                "Cannot read TLS {$kind} file {$path}; check its configured path and permissions.",
            );
        }
        $bytes = @file_get_contents($path, length: 1_048_577);
        if ($bytes === false || strlen($bytes) > 1_048_576) {
            throw new RuntimeException("Cannot read TLS {$kind} file {$path}; it must be readable and at most 1 MiB.");
        }
        return $bytes;
    }

    private function failure(string $what): RuntimeException
    {
        $reasons = [];
        while (($reason = openssl_error_string()) !== false) {
            $reasons[] = $reason;
        }
        return new RuntimeException(
            $what . ': ' . ($reasons === [] ? 'OpenSSL rejected the configuration.' : implode('; ', $reasons)),
        );
    }
}
