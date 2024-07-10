<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\RecursionContext\InvalidArgumentException;

final class StructTest extends TestCase {
	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBasicArrayAccess(): void {
		$data = ['foo' => 'bar', 'baz' => 42];
		$struct = new Struct($data);

		$this->assertEquals('bar', $struct['foo']);
		$this->assertEquals(42, $struct['baz']);
		/** @phpstan-ignore-next-line */
		$this->assertNull($struct['nonexistent']);

		/** @phpstan-ignore-next-line */
		$struct['new'] = 'value';
		/** @phpstan-ignore-next-line */
		$this->assertEquals('value', $struct['new']);

		$this->assertTrue(isset($struct['foo']));
		/** @phpstan-ignore-next-line */
		$this->assertFalse(isset($struct['nonexistent']));

		unset($struct['foo']);
		$this->assertFalse(isset($struct['foo']));
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testToArray(): void {
		$data = ['foo' => 'bar', 'baz' => 42];
		$struct = new Struct($data);

		$this->assertEquals($data, $struct->toArray());
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testAddBigIntField(): void {
		$struct = new Struct(['id' => '9223372036854775807']);
		$struct->addBigIntField('id');

		$json = $struct->toJson();
		$this->assertStringContainsString('"id":9223372036854775807', $json);
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testFromJsonWithBigInt(): void {
		$json = '{"id": 9223372036854775807, "name": "test"}';
		$struct = Struct::fromJson($json);

		$this->assertEquals('9223372036854775807', $struct['id']);
		$this->assertEquals('test', $struct['name']);

		$serialized = $struct->toJson();
		$this->assertStringContainsString('"id":9223372036854775807', $serialized);
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testFromJsonWithNestedBigInt(): void {
		$json = '{"data": {"id": 9223372036854775807}, "other": 42}';
		$struct = Struct::fromJson($json);

		/** @phpstan-ignore-next-line */
		$this->assertEquals('9223372036854775807', $struct['data']['id']);
		$this->assertEquals(42, $struct['other']);

		$serialized = $struct->toJson();
		$this->assertStringContainsString('"id":9223372036854775807', $serialized);
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testFromJsonWithArrayOfBigInts(): void {
		$json = '{"ids": [9223372036854775807, 9223372036854775806]}';
		$struct = Struct::fromJson($json);

		$this->assertEquals(['9223372036854775807', '9223372036854775806'], $struct['ids']);

		$serialized = $struct->toJson();
		$this->assertStringContainsString('"ids":[9223372036854775807,9223372036854775806]', $serialized);
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testJsonSerializeWithMixedTypes(): void {
		$data = [
			'bigint' => '9223372036854775807',
			'string' => 'test',
			'int' => 42,
			'float' => 3.14,
			'bool' => true,
			'null' => null,
			'nested' => [
				'bigint' => '9223372036854775806',
			],
		];
		$struct = new Struct($data);
		$struct->addBigIntField('bigint');
		$struct->addBigIntField('nested.bigint');

		$json = $struct->toJson();
		$this->assertStringContainsString('"bigint":9223372036854775807', $json);
		$this->assertStringContainsString('"string":"test"', $json);
		$this->assertStringContainsString('"int":42', $json);
		$this->assertStringContainsString('"float":3.14', $json);
		$this->assertStringContainsString('"bool":true', $json);
		$this->assertStringContainsString('"null":null', $json);
		$this->assertStringContainsString('"bigint":9223372036854775806', $json);
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testEdgeCasesForBigIntParsing(): void {
		$json = '{"edge1": 9223372036854775808, "edge2": -9223372036854775809, "normal": 42}';
		$struct = Struct::fromJson($json);

		$this->assertEquals('9223372036854775808', $struct['edge1']);
		$this->assertEquals('-9223372036854775809', $struct['edge2']);
		$this->assertEquals(42, $struct['normal']);

		$serialized = $struct->toJson();
		$this->assertStringContainsString('"edge1":9223372036854775808', $serialized);
		$this->assertStringContainsString('"edge2":-9223372036854775809', $serialized);
		$this->assertStringContainsString('"normal":42', $serialized);
	}

	/**
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBigIntFieldsPreservationAcrossOperations(): void {
		$json = '{"id": 9223372036854775807, "data": {"nested_id": 9223372036854775806}}';
		$struct = Struct::fromJson($json);

		// Modify and add new fields
		$struct['new_field'] = '9223372036854775805';
		$struct->addBigIntField('new_field');

		$serialized = $struct->toJson();
		$this->assertStringContainsString('"id":9223372036854775807', $serialized);
		$this->assertStringContainsString('"nested_id":9223372036854775806', $serialized);
		$this->assertStringContainsString('"new_field":9223372036854775805', $serialized);
	}
}
