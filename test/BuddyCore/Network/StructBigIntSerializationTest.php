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
	 * Data provider with real ManticoreSearch response data types
	 * Based on actual ManticoreSearch responses showing how different field types are returned
	 *
	 * @return array<string,array{mixed,array<string>,array<string,string>}>
	 */
	public static function realManticoreResponseProvider(): array {
		return [
			'complete_data_type_coverage' => [
				[
					'columns' => [
						['id' => ['type' => 'long long']],           // bigint (should be unquoted)
						['title' => ['type' => 'string']],           // string (should stay quoted)
						['price' => ['type' => 'float']],            // float (should stay unquoted)
						['count' => ['type' => 'long']],             // integer (should stay unquoted)
						['is_active' => ['type' => 'long']],         // boolean-as-int (should stay unquoted)
						['tags' => ['type' => 'string']],            // MVA as string (should stay quoted)
						['meta' => ['type' => 'string']],            // JSON as string (should stay quoted)
					],
					'data' => [
						[
							'id' => 5623974933752184833,              // bigint (should be unquoted)
							'title' => '000123',                     // numeric-looking string (should stay quoted)
							'price' => 19.990000,                    // float (should stay unquoted)
							'count' => 100,                          // int (should stay unquoted)
							'is_active' => 1,                        // bool-as-int (should stay unquoted)
							'tags' => '1,2,3',                       // MVA string (should stay quoted)
							'meta' => '{"category":"electronics","rating":4.500000}', // JSON (should stay quoted)
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id'],  // Only id is bigint
				[
					'bigint_unquoted' => '"id":5623974933752184833',
					'string_quoted' => '"title":"000123"',
					'float_unquoted' => '"price":19.99',
					'int_unquoted' => '"count":100',
					'bool_unquoted' => '"is_active":1',
					'mva_quoted' => '"tags":"1,2,3"',
					'json_quoted' => '"meta":"{\"category\":\"electronics\"',
				],
			],
			'edge_case_numeric_strings' => [
				[
					'columns' => [
						['id' => ['type' => 'long long']],
						['code' => ['type' => 'string']],
						['tags' => ['type' => 'string']],
						['meta' => ['type' => 'string']],
					],
					'data' => [
						[
							'id' => 9223372036854775807,             // bigint (should be unquoted)
							'code' => '0000000000',                   // original bug scenario (should stay quoted)
							'tags' => '2808348671,2808348672',       // MVA with large numbers (should stay quoted)
							'meta' => '{"numbers":[1,2.500000,true,false,null],"text":"000456"}', // complex JSON
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id'],
				[
					'bigint_unquoted' => '"id":9223372036854775807',
					'code_quoted' => '"code":"0000000000"',
					'tags_quoted' => '"tags":"2808348671,2808348672"',
					'meta_quoted' => '"meta":"{\"numbers\":[1,2.500000,true,false,null]',
				],
			],
		];
	}

	/**
	 * Data provider for edge cases with numeric-looking strings
	 * These are critical because the regex could potentially affect them
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function edgeCaseDataProvider(): array {
		return [
			'string_that_looks_like_bigint' => [
				'9223372036854775807',
				'string that looks exactly like a bigint',
			],
			'original_bug_scenario' => [
				'0000000000',
				'original bug scenario - zero-padded string',
			],
			'negative_number_string' => [
				'-9223372036854775808',
				'negative number as string',
			],
			'mva_with_large_numbers' => [
				'2808348671,2808348672',
				'MVA with large integers as comma-separated string',
			],
			'json_with_mixed_numbers' => [
				'{"numbers":[1,2.500000,true,false,null],"text":"000456"}',
				'JSON string containing various number formats',
			],
			'high_precision_decimal' => [
				'123.456789012345',
				'high precision decimal as string',
			],
			'string_with_spaces_and_numbers' => [
				' 123 456 ',
				'string with spaces and numbers',
			],
			'hex_like_string' => [
				'0x123456789',
				'hex-like string',
			],
			'binary_like_string' => [
				'0b101010',
				'binary-like string',
			],
		];
	}

	/**
	 * Data provider for mixed data type scenarios
	 * Simulates real database responses with various field types
	 *
	 * @return array<string,array{array<string,mixed>,array<string>,array<string>}>
	 */
	public static function mixedDataTypeScenarios(): array {
		return [
			'database_row_simulation' => [
				[
					'id' => 9223372036854775807,           // bigint
					'user_id' => 12345,                    // regular int
					'balance' => 1234.56,                  // float
					'is_premium' => 1,                     // boolean as int
					'permissions' => '1,5,10,15',          // MVA as string
					'settings' => '{"theme":"dark","lang":"en"}', // JSON as string
					'code' => '00001',                     // numeric string
					'phone' => '+1-555-0123',              // string with numbers
				],
				['id'],  // only id is bigint
				['permissions', 'settings', 'code', 'phone'], // fields that must stay quoted
			],
			'meta_response_with_bigints' => [
				[
					'id' => 5623974933752184833,
					'data' => '000000',
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['id'],
				['data'], // the data field should stay quoted
			],
		];
	}

	/**
	 * Test real ManticoreSearch data types to ensure comprehensive coverage
	 *
	 * @dataProvider realManticoreResponseProvider
	 * @param array<array<string,mixed>> $inputData
	 * @param array<string> $bigintFields
	 * @param array<string,string> $expectedPatterns
	 * @return void
	 */
	public function testRealManticoreDataTypes(array $inputData, array $bigintFields, array $expectedPatterns): void {
		$struct = Struct::fromData($inputData, $bigintFields);
		$json = $struct->toJson();

		// Verify JSON is valid
		/** @var array<array<string,mixed>>|null $decoded */
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'Generated JSON should be valid and parseable');
		$this->assertIsArray($decoded, 'Decoded JSON should be an array');

		// Verify all expected patterns are present
		foreach ($expectedPatterns as $description => $pattern) {
			$this->assertStringContainsString(
				$pattern,
				$json,
				"Pattern '{$description}' should be present in JSON output"
			);
		}

		// Verify bigint fields are unquoted while others maintain their correct format
		foreach ($bigintFields as $fieldPath) {
			$pathParts = explode('.', $fieldPath);
			$currentData = $inputData;

			// Validate path traversal using assertions
			foreach ($pathParts as $part) {
				$this->assertIsArray(
					$currentData,
					"Path traversal failed at '{$part}' for field path '{$fieldPath}'"
				);
				$this->assertArrayHasKey(
					$part,
					$currentData,
					"Path component '{$part}' not found in field path '{$fieldPath}'"
				);
				$currentData = $currentData[$part];
			}

			// At this point, $currentData should be the final scalar value
			$this->assertIsScalar(
				$currentData,
				"Bigint field '{$fieldPath}' should be scalar, got " . gettype($currentData)
			);

			$bigintValue = (string)$currentData;
			$this->assertStringContainsString(
				'":' . $bigintValue,
				$json,
				"Bigint field '{$fieldPath}' should be unquoted"
			);
		}
	}

	/**
	 * Test edge cases where strings contain numeric content
	 * These are critical because the regex could potentially affect them
	 *
	 * @dataProvider edgeCaseDataProvider
	 * @param string $stringField
	 * @param string $description
	 * @return void
	 */
	public function testNumericStringEdgeCases(string $stringField, string $description): void {
		$data = [
			'bigint_field' => 9223372036854775807, // Real bigint
			'string_field' => $stringField,         // String that might look numeric
			'normal_field' => 'test',
		];

		$struct = Struct::fromData($data, ['bigint_field']);
		$json = $struct->toJson();

		// Verify JSON is valid
		/** @var array<string,mixed>|null $decoded */
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, "Generated JSON should be valid for {$description}");
		$this->assertIsArray($decoded, "Decoded JSON should be an array for {$description}");

		// Verify bigint is unquoted
		$this->assertStringContainsString('"bigint_field":9223372036854775807', $json);

		// Verify string field maintains quotes (this is the critical test)
		$expectedStringJson = '"string_field":"' . addslashes($stringField) . '"';
		$this->assertStringContainsString(
			$expectedStringJson,
			$json,
			"String field should maintain quotes for {$description}"
		);

		// Verify decoded values with proper type checking
		$this->assertEquals(9223372036854775807, $decoded['bigint_field']);
		$this->assertEquals($stringField, $decoded['string_field']);
	}

	/**
	 * Test mixed data type scenarios simulating real database responses
	 *
	 * @dataProvider mixedDataTypeScenarios
	 * @param array<string,mixed> $data
	 * @param array<string> $bigintFields
	 * @param array<string> $criticalQuotes
	 * @return void
	 */
	public function testMixedDataTypeScenarios(array $data, array $bigintFields, array $criticalQuotes): void {
		$struct = Struct::fromData($data, $bigintFields);
		$json = $struct->toJson();

		// Verify JSON is valid
		/** @var array<string,mixed>|null $decoded */
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'Mixed data type JSON should be valid');
		$this->assertIsArray($decoded, 'Decoded JSON should be an array');

		// Verify bigint fields are unquoted
		foreach ($bigintFields as $field) {
			if (!isset($data[$field])) {
				continue;
			}

			$value = is_scalar($data[$field]) ? (string)$data[$field] : '';
			$this->assertStringContainsString(
				'"' . $field . '":' . $value,
				$json,
				"Bigint field '{$field}' should be unquoted"
			);
		}

		// Verify critical string fields maintain quotes
		foreach ($criticalQuotes as $field) {
			if (!isset($data[$field])) {
				continue;
			}

			$value = is_scalar($data[$field]) ? (string)$data[$field] : '';
			$expectedPattern = '"' . $field . '":"' . addslashes($value) . '"';
			$this->assertStringContainsString(
				$expectedPattern,
				$json,
				"Critical string field '{$field}' should maintain quotes"
			);
		}
	}

	/**
	 * Test float precision preservation
	 * Ensures that float values are not affected by the bigint regex
	 *
	 * @return void
	 */
	public function testFloatPrecisionPreservation(): void {
		$data = [
			'id' => 9223372036854775807,
			'price' => 123.456789012345,
			'rate' => 0.000001,
			'negative_float' => -456.789,
		];

		$struct = Struct::fromData($data, ['id']);
		$json = $struct->toJson();

		// Verify JSON is valid
		/** @var array<string,mixed>|null $decoded */
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'Float precision JSON should be valid');

		// Verify bigint is unquoted
		$this->assertStringContainsString('"id":9223372036854775807', $json);

		// Verify floats remain unquoted and preserve precision
		$this->assertStringContainsString('"price":123.456789012345', $json);
		$this->assertStringContainsString('"rate":1.0e-6', $json);
		$this->assertStringContainsString('"negative_float":-456.789', $json);

		// Verify decoded values are correct
		$this->assertEquals(9223372036854775807, $decoded['id']);
		$this->assertEquals(123.456789012345, $decoded['price']);
		$this->assertEquals(0.000001, $decoded['rate']);
		$this->assertEquals(-456.789, $decoded['negative_float']);
	}

	/**
	 * Test MVA (Multi-Value Attribute) string preservation
	 * MVA fields are returned as comma-separated strings and must stay quoted
	 *
	 * @return void
	 */
	public function testMVAStringPreservation(): void {
		$data = [
			'id' => 9223372036854775807,
			'tags' => '1,2,3,100,200',
			'permissions' => '2808348671,2808348672,2808348673',
			'empty_mva' => '',
		];

		$struct = Struct::fromData($data, ['id']);
		$json = $struct->toJson();

		// Verify JSON is valid
		/** @var array<string,mixed>|null $decoded */
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'MVA string JSON should be valid');

		// Verify bigint is unquoted
		$this->assertStringContainsString('"id":9223372036854775807', $json);

		// Verify MVA strings maintain quotes
		$this->assertStringContainsString('"tags":"1,2,3,100,200"', $json);
		$this->assertStringContainsString('"permissions":"2808348671,2808348672,2808348673"', $json);
		$this->assertStringContainsString('"empty_mva":""', $json);

		// Verify decoded values are correct
		$this->assertEquals(9223372036854775807, $decoded['id']);
		$this->assertEquals('1,2,3,100,200', $decoded['tags']);
		$this->assertEquals('2808348671,2808348672,2808348673', $decoded['permissions']);
		$this->assertEquals('', $decoded['empty_mva']);
	}

	/**
	 * Test JSON string field preservation
	 * JSON fields are stored as strings and must maintain their quotes
	 *
	 * @return void
	 */
	public function testJSONStringPreservation(): void {
		$data = [
			'id' => 9223372036854775807,
			'settings' => '{"theme":"dark","lang":"en","version":1}',
			'metadata' => '{"numbers":[1,2.5,true,false,null],"text":"000456"}',
			'empty_json' => '{}',
		];

		$struct = Struct::fromData($data, ['id']);
		$json = $struct->toJson();

		// Verify JSON is valid
		/** @var array<string,mixed>|null $decoded */
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'JSON string JSON should be valid');

		// Verify bigint is unquoted
		$this->assertStringContainsString('"id":9223372036854775807', $json);

		// Verify JSON strings maintain quotes
		$this->assertStringContainsString(
			'"settings":"{\\"theme\\":\\"dark\\",\\"lang\\":\\"en\\",\\"version\\":1}"',
			$json
		);
		$this->assertStringContainsString(
			'"metadata":"{\\"numbers\\":[1,2.5,true,false,null],\\"text\\":\\"000456\\"}"',
			$json
		);
		$this->assertStringContainsString('"empty_json":"{}"', $json);

		// Verify decoded values are correct
		$this->assertEquals(9223372036854775807, $decoded['id']);
		$this->assertEquals('{"theme":"dark","lang":"en","version":1}', $decoded['settings']);
		$this->assertEquals('{"numbers":[1,2.5,true,false,null],"text":"000456"}', $decoded['metadata']);
		$this->assertEquals('{}', $decoded['empty_json']);
	}

	/**
	 * Test negative number handling
	 * Ensures negative bigints and regular numbers work correctly
	 *
	 * @return void
	 */
	public function testNegativeNumberHandling(): void {
		$data = [
			'negative_bigint' => -9223372036854775808,
			'negative_int' => -42,
			'negative_float' => -123.456,
			'string_negative' => '-999',
		];

		$struct = Struct::fromData($data, ['negative_bigint']);
		$json = $struct->toJson();

		// Verify JSON is valid
		/** @var array<string,mixed>|null $decoded */
		$decoded = json_decode($json, true);
		$this->assertNotNull($decoded, 'Negative number JSON should be valid');

		// Verify negative bigint is unquoted
		$this->assertStringContainsString('"negative_bigint":-9.223372036854776e+18', $json);

		// Verify other negative numbers remain unquoted
		$this->assertStringContainsString('"negative_int":-42', $json);
		$this->assertStringContainsString('"negative_float":-123.456', $json);

		// Verify negative string maintains quotes
		$this->assertStringContainsString('"string_negative":"-999"', $json);

		// Verify decoded values are correct
		$this->assertEquals(-9223372036854775808, $decoded['negative_bigint']);
		$this->assertEquals(-42, $decoded['negative_int']);
		$this->assertEquals(-123.456, $decoded['negative_float']);
		$this->assertEquals('-999', $decoded['string_negative']);
	}

	/**
	 * Test the actual Client.php:191 scenario that was failing
	 * This simulates the exact bug scenario with SHOW META responses
	 *
	 * @return void
	 */
	public function testClientMetaResponseIntegration(): void {
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
		$this->assertNotNull(json_decode($response), 'Client response should be valid JSON');

		// Verify the specific issue is fixed
		$this->assertStringNotContainsString(
			',"s":0000000000,', $response,
			'String field should not lose its quotes'
		);
		$this->assertStringContainsString(
			',"s":"0000000000",', $response,
			'String field should maintain its quotes'
		);

		// Verify bigint is unquoted
		$this->assertStringContainsString('"id":5047479470261279290', $response);

		// Verify other string field maintains quotes
		$this->assertStringContainsString('"v":"0.44721356,0.89442712"', $response);
	}
}
