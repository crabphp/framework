<?php

declare(strict_types=1);

namespace Crab\Validation;

/**
 * Describes the fields of a request where they are used, in place of a
 * class written for them. On an action's parameter typed Data, it is
 * the whole of the request's data:
 *
 *     public function handle(
 *         #[Fields([
 *             'email' => ['string', new Email()],
 *             'age' => '?int',
 *             'address' => ['street' => 'string', 'country' => ['string', new Length(min: 2, max: 2)]],
 *             'lines' => [['productId' => 'int', 'quantity' => ['int', new Range(min: 1)]], new Length(min: 1)],
 *         ])]
 *         Data $order,
 *     )
 *
 * Each field is described by one of these:
 *
 *     'int'                          a type: int, float, string, bool, array, mixed, an enum or DateTimeImmutable
 *     '?int'                         the same, and it may be left out or null
 *     ['int', new Range(min: 1)]     a type, then rules for it
 *     ['street' => 'string']         an object with these fields
 *     [['street' => 'string']]       a list of those; or [['int']], a list of ints
 *     [[...], new Length(min: 1)]    a list, then rules for the list
 *
 * A name ending in "?", 'address?' => [...], is a field that may be left
 * out, whatever it is. What is described arrives as Data objects, read
 * as $order->address->street.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Fields
{
    /**
     * @param array<string, mixed> $fields Each field's name with its description.
     */
    public function __construct(
        public array $fields,
    ) {}
}
