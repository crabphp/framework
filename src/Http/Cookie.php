<?php

declare(strict_types=1);

namespace Crab\Http;

/**
 * A cookie to set; as a string it is the value of a Set-Cookie header.
 */
final readonly class Cookie implements \Stringable
{
    /**
     * @param int|null $maxAge Seconds until the cookie expires; null for a cookie that lasts the browser session.
     * @param string $sameSite "Lax", "Strict" or "None"; "None" requires $secure.
     */
    public function __construct(
        public string $name,
        public string $value = '',
        public ?int $maxAge = null,
        public string $path = '/',
        public string $domain = '',
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException('A cookie name must be an HTTP token.');
        }
        if (preg_match('/[\x00-\x1F\x7F;]/', $path . $domain) === 1) {
            throw new \InvalidArgumentException('A cookie path or domain must not contain control characters or ";".');
        }
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException('SameSite is "Lax", "Strict" or "None".');
        }
        if ($sameSite === 'None' && !$secure) {
            throw new \InvalidArgumentException('A SameSite=None cookie must be Secure.');
        }
    }

    /**
     * A cookie that tells the browser to delete the one of this name, path
     * and domain.
     */
    public static function forget(string $name, string $path = '/', string $domain = ''): self
    {
        return new self($name, '', 0, $path, $domain);
    }

    public function __toString(): string
    {
        $cookie = $this->name . '=' . rawurlencode($this->value);
        if ($this->maxAge !== null) {
            $cookie .= '; Max-Age=' . max(0, $this->maxAge);
            if ($this->maxAge <= 0) {
                $cookie .= '; Expires=Thu, 01 Jan 1970 00:00:01 GMT';
            }
        }
        if ($this->path !== '') {
            $cookie .= '; Path=' . $this->path;
        }
        if ($this->domain !== '') {
            $cookie .= '; Domain=' . $this->domain;
        }
        if ($this->secure) {
            $cookie .= '; Secure';
        }
        if ($this->httpOnly) {
            $cookie .= '; HttpOnly';
        }
        return $cookie . '; SameSite=' . $this->sameSite;
    }
}
