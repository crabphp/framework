<?php

declare(strict_types=1);

namespace Crab\Contracts\Validation;

/**
 * A check on one object as a whole, for what one field alone cannot say:
 * an end that comes after its start, a value whose kind depends on another
 * field. Written as an attribute on the input class, or on a Data parameter
 * described with #[Fields]:
 *
 *     #[EndsAfterStarts]
 *     final readonly class Booking
 *
 * It runs once every field of the object is valid, so it sees the object
 * built and typed. It is a pure check: anything that needs the database or
 * the logged-in user belongs in the action.
 */
interface ObjectRule
{
    /**
     * @param object $input The object built from the data: an instance of the class, or a Validation\Data.
     * @return array<string, string> What is wrong, by the name of the field it is told against, each worded to follow that name ("must be after the start"); empty when nothing is.
     */
    public function check(object $input): array;
}
