<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

final class StructBigIntSerializationTest extends TestCase {
	/**
	 * Test that reproduces the specific JSON serialization bug where string values
	 * lose their quotes when bigint fields are processed.
	 *
	 * Bug: String "0000000000" becomes 0000000000 (invalid JSON)
	 * Root cause: Overly broad regex pattern in getReplacePattern() method
	 *
	 * @return void
	 */
	public function testBigIntSerializationDoesNotAffectStringFields(): void {
		// This is the exact scenario that was failing
		$inputData = [
			[
				'columns' => [
					['id' => ['type' => 'long long']],
					['s' => ['type' => 'string']],
					['v' => ['type' => 'string']],
				],
				'data' => [
					[
						'id' => 5047479470261279290, // This is a bigint
						's' => '0000000000',         // This should remain a quoted string
						'v' => '0.44721356,0.89442712',
					],
				],
				'total' => 1,
				'error' => '',
				'warning' => '',
			],
		];

		// Create struct with bigint field marked (as Client.php does)
		$struct = Struct::fromData($inputData, ['data.0.id']);

		// Get the JSON output
		$json = $struct->toJson();

		// Parse the JSON to verify it's valid
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'Generated JSON should be valid and parseable');

		// Verify the bigint field is correctly handled (no quotes)
		$this->assertStringContainsString(
			'"id":5047479470261279290', $json,
			'Bigint field should be serialized without quotes'
		);

		// Verify string fields maintain their quotes (this was the bug)
		$this->assertStringContainsString(
			'"s":"0000000000"', $json,
			'String fields should maintain their quotes'
		);
		$this->assertStringContainsString(
			'"v":"0.44721356,0.89442712"', $json,
			'String fields should maintain their quotes'
		);

		// Verify the decoded values are correct
		$this->assertEquals(5047479470261279290, $decoded[0]['data'][0]['id']);
		$this->assertEquals('0000000000', $decoded[0]['data'][0]['s']);
		$this->assertEquals('0.44721356,0.89442712', $decoded[0]['data'][0]['v']);
	}

	/**
	 * Test various edge cases where string values might be confused with bigints
	 *
	 * @return void
	 */
	public function testStringFieldsWithNumericContentAreNotAffected(): void {
		$testCases = [
			['field' => '0000000000', 'description' => 'zero-padded string'],
			['field' => '1234567890123456789', 'description' => 'long numeric string'],
			['field' => '00123', 'description' => 'zero-padded numeric string'],
			['field' => '1.23456789', 'description' => 'decimal string'],
			['field' => '+1234567890', 'description' => 'string with plus sign'],
			['field' => '-9876543210', 'description' => 'string with minus sign'],
			['field' => '1e10', 'description' => 'scientific notation string'],
		];

		foreach ($testCases as $testCase) {
			$data = [
				'bigint_field' => 9223372036854775807, // Real bigint
				'string_field' => $testCase['field'],   // String that might look numeric
				'normal_field' => 'test',
			];

			$struct = Struct::fromData($data, ['bigint_field']);
			$json = $struct->toJson();

			// Verify JSON is valid
			$decoded = json_decode($json, true);
			$this->assertNotNull($decoded, "Generated JSON should be valid for {$testCase['description']}");

			// Verify bigint is unquoted
			$this->assertStringContainsString('"bigint_field":9223372036854775807', $json);

			// Verify string field maintains quotes
			$expectedStringJson = '"string_field":"' . $testCase['field'] . '"';
			$this->assertStringContainsString(
				$expectedStringJson, $json,
				"String field should maintain quotes for {$testCase['description']}"
			);

			// Verify decoded values
			$this->assertEquals(9223372036854775807, $decoded['bigint_field']);
			$this->assertEquals($testCase['field'], $decoded['string_field']);
		}
	}

	/**
	 * Test nested structures with mixed bigint and string fields
	 *
	 * @return void
	 */
	public function testNestedStructuresWithMixedTypes(): void {
		$data = [
			'level1' => [
				'bigint' => 5047479470261279290,
				'string' => '0000000000',
				'level2' => [
					'bigint' => 9223372036854775807,
					'string' => '1234567890123456789',
				],
			],
		];

		$struct = Struct::fromData($data, ['level1.bigint', 'level1.level2.bigint']);
		$json = $struct->toJson();

		// Verify JSON is valid
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'Nested structure JSON should be valid');

		// Verify bigints are unquoted
		$this->assertStringContainsString('"bigint":5047479470261279290', $json);
		$this->assertStringContainsString('"bigint":9223372036854775807', $json);

		// Verify strings maintain quotes
		$this->assertStringContainsString('"string":"0000000000"', $json);
		$this->assertStringContainsString('"string":"1234567890123456789"', $json);

		// Verify structure integrity
		$this->assertEquals('0000000000', $decoded['level1']['string']);
		$this->assertEquals('1234567890123456789', $decoded['level1']['level2']['string']);
	}

	/**
	 * Test the actual Client.php scenario that was failing
	 *
	 * @return void
	 */
	public function testClientMetaResponseScenario(): void {
		// Simulate the exact data structure from Client.php:191
		$array = [
			[
				'columns' => [
					['id' => ['type' => 'long long']],
					['s' => ['type' => 'string']],
					['v' => ['type' => 'string']],
				],
				'data' => [
					['id' => 5047479470261279290, 's' => '0000000000', 'v' => '0.44721356,0.89442712'],
				],
				'total' => 1,
				'error' => '',
				'warning' => '',
			],
		];

		// This is the exact line that was causing the issue
		$response = Struct::fromData($array, ['data.0.id'])->toJson();

		// Verify the response is valid JSON
		$this->assertNotNull(json_decode($response), 'Response should be valid JSON');

		// Verify the specific issue is fixed
		$this->assertStringNotContainsString(
			',"s":0000000000,', $response,
			'String field should not lose its quotes'
		);
		$this->assertStringContainsString(
			',"s":"0000000000",', $response,
			'String field should maintain its quotes'
		);
	}
}
