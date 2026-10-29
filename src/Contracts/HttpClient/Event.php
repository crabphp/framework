<?php

declare(strict_types=1);

namespace Crab\Contracts\HttpClient;

/**
 * One server-sent event of an answer read with events(): its name, which
 * is "message" when the server gave none, its data, and its id.
 */
final readonly class Event
{
    public function __construct(
        public string $name,
        public string $data,
        public ?string $id = null,
    ) {}

    /**
     * The data read as JSON, an object as an array.
     *
     * @throws \JsonException When the data is not JSON, as a closing "[DONE]" is not.
     */
    public function json(): mixed
    {
        return json_decode($this->data, true, flags: JSON_THROW_ON_ERROR);
    }
}
