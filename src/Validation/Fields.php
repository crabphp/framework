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
 *             'age?' => 'int',
 *             'address' => ['street' => 'string', 'country' => ['string', new Length(min: 2, max: 2)]],
 *             'lines' => [['productId' => 'int', 'quantity' => ['int', new Range(min: 1)]], new Length(min: 1)],
 *         ])]
 *         Data $order,
 *     )
 *
 * Each field is described by one of these:
 *
 *     'int'                          a type: int, float, string, bool, array, mixed, an enum, a class,
 *                                    DateTimeImmutable or UploadedFileInterface
 *     '?int'                         the same, or null
 *     'bool|int|string'              one of these scalar types
 *     ['int', new Range(min: 1)]     a type, then rules for it
 *     ['street' => 'string']         an object with these fields
 *     [['street' => 'string']]       a list of those; or [['int']], a list of ints
 *     [[...], new Length(min: 1)]    a list, then rules for the list
 *
 * A name ending in "?", 'address?' => [...], is a field that may be left
 * out, whether it describes a scalar, an object or a list; without it the
 * field is sent, as a parameter without a default is passed. Only a type
 * with "?" may be null; an object or a list described in place never is.
 * Rules and nested fields are checked only for non-null values. A supplied
 * null stays in Data::toArray(); an omitted field does not. What is described
 * arrives as Data objects, read as $order->address->street.
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
