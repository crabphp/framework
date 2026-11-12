<?php

declare(strict_types=1);

namespace Crab\Contracts\Session;

/**
 * What a driver keeps for one session: its values, which of them were
 * flashed for the next request, and when it expires.
 */
final readonly class Record
{
    /**
     * @param array<string, mixed> $values JSON values by key.
     * @param list<string> $flashed Keys kept for the next request only.
     * @param int $expiresAt Unix time after which the session is gone.
     */
    public function __construct(
        public array $values,
        public array $flashed,
        public int $expiresAt,
    ) {}

    /**
     * The record as the text a driver keeps.
     */
    public function toJson(): string
    {
        return json_encode(
            ['values' => (object) $this->values, 'flashed' => $this->flashed, 'expires_at' => $this->expiresAt],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * The record a driver kept, or null when the text is not one or it has expired.
     */
    public static function fromJson(string $json, int $now): ?self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (
            !is_array($data)
            || !is_array($data['values'] ?? null)
            || !is_array($data['flashed'] ?? null)
            || !array_is_list($data['flashed'])
            || !is_int($data['expires_at'] ?? null)
        ) {
            return null;
        }
        if ($data['expires_at'] <= $now) {
            return null;
        }
        $flashed = [];
        foreach ($data['flashed'] as $key) {
            if (!is_string($key)) {
                continue;
            }
            $flashed[] = $key;
        }
        $values = [];
        foreach ($data['values'] as $key => $value) {
            $values[(string) $key] = $value;
        }
        return new self($values, $flashed, $data['expires_at']);
    }
}
