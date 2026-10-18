<?php

declare(strict_types=1);

namespace Crab\Validation;

/**
 * The data did not fit its class. Every problem is listed, not only the
 * first, under the field it belongs to; a field inside another is named
 * with dots, "address.city" or "tags.0".
 */
final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors Messages by field, each worded to follow the field's name.
     */
    public function __construct(
        public readonly array $errors,
    ) {
        $field = (string) array_key_first($errors);
        $more = count($errors) - 1;
        parent::__construct(
            "{$field} {$errors[$field][0]}" . ($more > 0 ? ", and {$more} more invalid field(s)." : '.'),
        );
    }
}
