<?php

declare(strict_types=1);

namespace Crab\Contracts\Config;

/**
 * Configuration, addressed with dotted keys such as "log.level". Its values
 * are fixed once the application is defined; providers may only add
 * defaults beneath them.
 */
interface Config
{
    /**
     * The value at a key, or the default when the key does not exist. A key
     * that exists with a null value yields null.
     */
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    /**
     * Declares a section's settings, their default values and what each
     * takes, in one place. A value the application has set wins; a setting
     * it has not set reads as its default from then on, through get() and
     * all().
     *
     * What a setting takes is JSON Schema: ['type' => 'integer', 'minimum' => 1].
     * A string of digits counts as an integer and a numeric string as a
     * number, since that is what env() gives. A section with rules takes
     * no other keys, unless '*' gives the rule for them. An "enum" given
     * again for a key adds its values to the earlier ones, so that a
     * provider can offer another choice, as Redis does for "cache.store";
     * any other rule given again replaces the earlier one.
     *
     * @param string $section A top-level key such as "cache".
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $rules What each setting takes, by its key in the section.
     */
    public function defaults(string $section, array $values, array $rules = []): void;

    /**
     * What is wrong with the settings that have rules, one sentence each,
     * naming the setting and what it takes: 'server.workers is "four"; it
     * takes a whole number, at least 1.' Empty when all of them fit. The
     * application checks before it serves or runs workers.
     *
     * @param string ...$sections Only these sections; every section with rules when none is given.
     * @return list<string>
     */
    public function problems(string ...$sections): array;

    /**
     * Throws when problems() finds any, with every one of them.
     *
     * @param string ...$sections Only these sections; every section with rules when none is given.
     * @throws InvalidSettings
     */
    public function check(string ...$sections): void;

    /**
     * What each setting takes, as declared: section, then key, then its JSON Schema.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function rules(): array;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;
}
