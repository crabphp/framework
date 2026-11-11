<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Field;
use Crab\Database\ColumnName;
use Crab\Database\Json;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    public function testAPlainPropertyUsesTheSameConversionsAsARecord(): void
    {
        $field = Field::of(new \ReflectionProperty(PlainFields::class, 'id'), 'row_id', true, 7);
        self::assertSame('id', $field->property);
        self::assertSame('row_id', $field->column);
        self::assertSame(Field::INT, $field->type);
        self::assertFalse($field->nullable);
        self::assertTrue($field->hasDefault);
        self::assertSame(7, $field->default);
        self::assertSame(42, $field->fromColumn('42'));
        self::assertSame(42, $field->toColumn(42));
    }

    public function testAttributesCanBeInstantiatedFromAPlainProperty(): void
    {
        $property = new \ReflectionProperty(PlainFields::class, 'settings');
        self::assertSame('options', $property->getAttributes(ColumnName::class)[0]->newInstance()->name);
        self::assertInstanceOf(Json::class, $property->getAttributes(Json::class)[0]->newInstance());
        $field = Field::of($property, 'options', false);
        self::assertSame(['enabled' => true], $field->fromColumn('{"enabled":true}'));
        self::assertSame('{"enabled":true}', $field->toColumn(['enabled' => true]));
    }

    public function testTheKindNamesTheMapperInADeclarationError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(PlainFields::class
        . '::$date is a DateTime; declare it DateTimeImmutable, as a model does not change.');
        Field::of(new \ReflectionProperty(PlainFields::class, 'date'), 'date', false, kind: 'model');
    }

    public function testAValueErrorNamesTheDeclaringClassAndNeverTheValue(): void
    {
        $field = Field::of(new \ReflectionProperty(InheritedFields::class, 'id'), 'row_id', false);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(PlainFields::class
        . '::$id takes int, and the column "row_id" held text that is not one.');
        $field->fromColumn('secret');
    }
}

class PlainFields
{
    public int $id;
    #[ColumnName('options'), Json]
    public array $settings;
    public \DateTime $date;
}

final class InheritedFields extends PlainFields {}
