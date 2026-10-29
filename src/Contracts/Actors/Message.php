<?php

declare(strict_types=1);

namespace Crab\Contracts\Actors;

/**
 * One message to an actor: what was sent, as JSON reads it, and the way
 * back to whoever asked.
 */
final class Message
{
    private bool $replied = false;

    /**
     * @param (\Closure(bool, mixed): void)|null $answer Given whether the actor replied, and the reply or why not.
     */
    public function __construct(
        public readonly mixed $data,
        private readonly ?\Closure $answer = null,
    ) {}

    /**
     * Whether it was sent with ask(), so that someone waits for a reply.
     */
    public function asked(): bool
    {
        return $this->answer !== null;
    }

    /**
     * Answers ask(). The reply travels as JSON; replying to a message sent
     * with tell() does nothing, and a message is replied to once.
     */
    public function reply(mixed $value = null): void
    {
        if ($this->replied) {
            throw new \LogicException('This message has already been replied to.');
        }
        $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true);
        $this->replied = true;
        if ($this->answer !== null) {
            ($this->answer)(true, $value);
        }
    }

    /**
     * Tells whoever asked that no reply is coming, and why. For the framework.
     *
     * @internal
     */
    public function refuse(string $why): void
    {
        if ($this->replied) {
            return;
        }
        $this->replied = true;
        if ($this->answer !== null) {
            ($this->answer)(false, $why);
        }
    }
}
