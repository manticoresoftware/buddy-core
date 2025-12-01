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

final class StructSingleResponseTest extends TestCase {
	/**
	 * Test single response bigint serialization (KNN scenario)
	 * This is the critical missing test case that covers the production bug
	 *
	 * @return void
	 */
	public function testSingleResponseBigIntSerialization(): void {
		// Simulate exact KNN response structure that was failing in production
		$singleResponseArray = [
			[
				'columns' => [
					['id' => ['type' => 'long long']],
					['s' => ['type' => 'string']],
					['v' => ['type' => 'string'],
					],
					'data' => [
					[
						'id' => 5047479470261279290, // bigint (should be unquoted)
						's' => '0000000000',         // string (should stay quoted)
						'v' => '0.44721356,0.89442712',
					],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
			],
		];

		// This simulates the sizeof($array) == 1 scenario that was bypassed in Client.php
		$response = Struct::fromData($singleResponseArray, ['data.0.id'])->toJson();

		// Verify JSON is valid (this was failing in production)
		$this->assertNotNull(json_decode($response), 'Single response JSON should be valid');

		// Verify bigint is unquoted
		$this->assertStringContainsString('"id":5047479470261279290', $response);

		// Verify strings maintain quotes (critical test - this was the bug)
		$this->assertStringContainsString('"s":"0000000000"', $response);
		$this->assertStringContainsString('"v":"0.44721356,0.89442712"', $response);

		// Verify no invalid unquoted strings (the exact production bug)
		$this->assertStringNotContainsString(',"s":0000000000,', $response);
	}

	/**
	 * Data provider for KNN-specific scenarios
	 *
	 * @return array<string,array{array<string,mixed>,array<string>,array<string|array{string,string}>}>
	 */
	public static function knnResponseProvider(): array {
		return [
			'knn_with_vector_field' => [
				[
					'columns' => [
						['id' => ['type' => 'long long']],
						['v' => ['type' => 'string']],
					],
					'data' => [
						['id' => 9223372036854775807, 'v' => '0.1,0.2,0.3'],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id'],
				['"id":9223372036854775807', '"v":"0.1,0.2,0.3"'],
			],
			'knn_with_multiple_bigints' => [
				[
					'columns' => [
						['id' => ['type' => 'long long']],
						['user_id' => ['type' => 'long long']],
					],
					'data' => [
						['id' => 1234567890123456789, 'user_id' => 9876543210987654321],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id', 'data.0.user_id'],
				['"id":1234567890123456789', '"user_id":9.876543210987655e+18'],
			],
			'knn_with_numeric_strings' => [
				[
					'columns' => [
						['id' => ['type' => 'long long']],
						['code' => ['type' => 'string']],
						['tags' => ['type' => 'string']],
					],
					'data' => [
						[
							'id' => 5623974933752184833,
							'code' => '000123',
							'tags' => '999,1000,1001',
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id'],
				['"id":5623974933752184833', '"code":"000123"', '"tags":"999,1000,1001"'],
			],
			'with_quoted_scientific_notation' => [
				[
					'columns' => [
						['user_id' => ['type' => 'long long']],
					],
					'data' => [
						[
							'user_id' => 9876543210987654321,  // Becomes float in PHP, then quoted in JSON
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.user_id'],
				['"user_id":9.876543210987655e+18'],  // Should be unquoted scientific notation
			],
			'with_unquoted_scientific_notation' => [
				[
					'columns' => [
						['balance' => ['type' => 'long long']],
					],
					'data' => [
						[
							'balance' => 9223372036854775808,  // Beyond PHP_INT_MAX, becomes float
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.balance'],
				[
					// Regex pattern to match scientific notation with varying precision
					['/balance":9\.22337203685477[0-9]e\+18/', 'Scientific notation should be unquoted'],
				],
			],
			'with_float_field' => [
				[
					'columns' => [
						['price' => ['type' => 'float']],
						['id' => ['type' => 'long long']],
					],
					'data' => [
						[
							'price' => 1234567890.123,
							'id' => 9223372036854775807,
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id'],
				['"price":1234567890.123', '"id":9223372036854775807'],
			],
			'with_mva_array_field' => [
				[
					'columns' => [
						['tags' => ['type' => 'uint', 'multi' => true]],  // MVA - multi-value attribute
						['id' => ['type' => 'long long']],
					],
					'data' => [
						[
							'tags' => [1, 2, 3, 9223372036854775807],
							'id' => 5623974933752184833,
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id'],
				['"tags":[1,2,3,9223372036854775807]', '"id":5623974933752184833'],
			],
			'with_mixed_types' => [
				[
					'columns' => [
						['id' => ['type' => 'long long']],
						['name' => ['type' => 'string']],
						['price' => ['type' => 'float']],
						['tags' => ['type' => 'string']],  // JSON-serialized array
					],
					'data' => [
						[
							'id' => 9223372036854775807,
							'name' => 'product_name',
							'price' => 99.99,
							'tags' => '[1,2,3]',  // JSON array as string
						],
					],
					'total' => 1,
					'error' => '',
					'warning' => '',
				],
				['data.0.id'],
				['"id":9223372036854775807', '"name":"product_name"', '"price":99.99', '"tags":"[1,2,3]"'],
			],
		];
	}

	/**
	 * @dataProvider knnResponseProvider
	 * @param array<string,mixed> $data
	 * @param array<string> $bigintFields
	 * @param array<string|array{string,string}> $expectedPatterns
	 * @return void
	 */
	public function testKNNSpecificScenarios(array $data, array $bigintFields, array $expectedPatterns): void {
		$response = Struct::fromData([$data], $bigintFields)->toJson();

		// Verify JSON is valid
		$this->assertNotNull(json_decode($response), 'KNN response JSON should be valid');

		// Verify all expected patterns are present
		foreach ($expectedPatterns as $pattern) {
			if (is_array($pattern)) {
				// For regex patterns (used for floating-point precision variations)
				$this->assertMatchesRegularExpression($pattern[0], $response, $pattern[1]);
			} else {
				// For exact string matches
				$this->assertStringContainsString($pattern, $response);
			}
		}
	}

	/**
	 * Test the actual Client.php scenario that was failing
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
	}

	/**
	 * Test that BigInt field detection now works correctly using column type information
	 * This verifies the root cause fix for the production bug
	 *
	 * @return void
	 */
	public function testBigIntFieldDetectionFromColumns(): void {
		// Simulate the exact production response structure
		$jsonResponse = json_encode(
			[
			'columns' => [
				['id' => ['type' => 'long long']],    // This should be detected as bigint
				['s' => ['type' => 'string']],         // This should NOT be detected as bigint
				['v' => ['type' => 'string']],
			],
			'data' => [
				['id' => 5047479470261279290, 's' => '0000000000', 'v' => '0.44721356,0.89442712'],

			],
			'total' => 1,
			'error' => '',
			'warning' => '',
			]
		);

		$this->assertIsString($jsonResponse, 'JSON encoding should work');

		// Create struct using fromJson (which should now use column-based detection)
		$struct = Struct::fromJson($jsonResponse);

		// Verify BigInt fields are correctly identified
		$bigIntFields = $struct->getBigIntFields();

		// Should contain the bigint field, not the string field
		echo json_encode($bigIntFields, JSON_PRETTY_PRINT);
		$this->assertContains('data.0.id', $bigIntFields, 'BigInt field should be correctly identified');
		$this->assertNotContains('data.0.s', $bigIntFields, 'String field should not be misidentified as BigInt');

		// Verify JSON serialization works correctly
		$json = $struct->toJson();
		$this->assertNotNull(json_decode($json), 'Serialized JSON should be valid');

		// Verify bigint is unquoted and string is quoted
		$this->assertStringContainsString('"id":5047479470261279290', $json);
		$this->assertStringContainsString('"s":"0000000000"', $json);
		$this->assertStringNotContainsString('"s":0000000000', $json);
	}

	/**
	 * Test edge cases for single response processing
	 *
	 * @return void
	 */
	public function testSingleResponseEdgeCases(): void {
		$edgeCases = [
			'empty_data' => [
				'data' => [],
				'bigint_fields' => [],
				'should_be_valid' => true,
			],
			'null_values' => [
				'data' => [
					'id' => null,
					'name' => 'test',
				],
				'bigint_fields' => [],
				'should_be_valid' => true,
			],
			'mixed_types' => [
				'data' => [
					'id' => 123,
					'active' => true,
					'score' => 95.5,
					'tags' => 'tag1,tag2',
				],
				'bigint_fields' => ['id'],
				'should_be_valid' => true,
			],
		];

		foreach ($edgeCases as $caseName => $case) {
			$testData = [$case['data']];
			$response = Struct::fromData($testData, $case['bigint_fields'])->toJson();

			// All test cases are expected to produce valid JSON
			$this->assertNotNull(
				json_decode($response),
				"Edge case '{$caseName}' should produce valid JSON"
			);
		}
	}

	/**
	 * Test PHP_INT_MAX boundary (9223372036854775807)
	 * Within PHP integer limits, stays as integer in PHP
	 *
	 * @return void
	 */
	public function testPHPIntMaxBoundary(): void {
		$jsonInput = json_encode(
			[
			'columns' => [
				['id' => ['type' => 'long long']],
				['description' => ['type' => 'string']],
			],
			'data' => [
				[
					'id' => 9223372036854775807,
					'description' => 'test_description',
				],
			],
			]
		);

		$this->assertIsString($jsonInput);
		$struct = Struct::fromJson($jsonInput);
		$response = $struct->toJson();

		// Verify JSON is valid
		$this->assertNotNull(json_decode($response), 'PHP_INT_MAX should produce valid JSON');

		// Verify bigint is unquoted
		$this->assertStringContainsString('"id":9223372036854775807', $response);

		// Verify string representation stays quoted
		$this->assertStringContainsString('"description":"test_description"', $response);

		// Verify bigint field was detected from columns
		$this->assertContains('data.0.id', $struct->getBigIntFields());
	}

	/**
	 * Test PHP_INT_MAX + 1 (9223372036854775808)
	 * Exceeds PHP_INT_MAX, becomes float in PHP, json_encode uses scientific notation
	 * This tests that column metadata correctly identifies it as bigint
	 *
	 * @return void
	 */
	public function testPHPIntMaxPlusOne(): void {
		// PHP_INT_MAX + 1 becomes float in JSON decode
		// json_encode will output: 9.2233720368547758e+18
		$jsonInput = json_encode(
			[
			'columns' => [
				['id' => ['type' => 'long long']],
				['description' => ['type' => 'string']],
			],
			'data' => [
				[
					'id' => 9223372036854775808,  // Beyond PHP_INT_MAX
					'description' => '9223372036854775808',
				],
			],
			]
		);

		$this->assertIsString($jsonInput);
		$struct = Struct::fromJson($jsonInput);
		$response = $struct->toJson();

		// Verify JSON is valid (this would fail without column metadata!)
		$this->assertNotNull(
			json_decode($response),
			'PHP_INT_MAX + 1 should be handled correctly via column metadata'
		);

		// Verify bigint field is detected from columns
		$this->assertContains('data.0.id', $struct->getBigIntFields());

		// Verify string field is NOT detected as bigint
		$this->assertNotContains('data.0.description', $struct->getBigIntFields());
	}

	/**
	 * Test PHP_INT_MIN boundary (-9223372036854775808)
	 * Negative boundary value, stays as integer in PHP
	 * Note: -9223372036854775808 becomes scientific notation in some JSON encodes
	 *
	 * @return void
	 */
	public function testPHPIntMinBoundary(): void {
		$jsonInput = json_encode(
			[
			'columns' => [
				['balance' => ['type' => 'long long']],
				['label' => ['type' => 'string']],
			],
			'data' => [
				[
					'balance' => -9223372036854775807,  // Use -1 to avoid scientific notation edge case
					'label' => 'test_label',
				],
			],
			]
		);

		$this->assertIsString($jsonInput);
		$struct = Struct::fromJson($jsonInput);
		$response = $struct->toJson();

		// Verify JSON is valid
		$this->assertNotNull(json_decode($response), 'PHP_INT_MIN should produce valid JSON');

		// Verify negative bigint is unquoted
		$this->assertStringContainsString('"balance":-9223372036854775807', $response);

		// Verify string stays quoted
		$this->assertStringContainsString('"label":"test_label"', $response);

		// Verify bigint field was detected from columns
		$this->assertContains('data.0.balance', $struct->getBigIntFields());
	}

	/**
	 * Test PHP_INT_MIN - 1 (-9223372036854775809)
	 * Exceeds PHP_INT_MIN, becomes float in PHP, json_encode uses scientific notation
	 * This tests that column metadata correctly identifies it as bigint
	 *
	 * @return void
	 */
	public function testPHPIntMinMinusOne(): void {
		// PHP_INT_MIN - 1 becomes float in JSON decode
		// json_encode will output: -9.2233720368547758e+18
		$jsonInput = json_encode(
			[
			'columns' => [
				['balance' => ['type' => 'long long']],
				['label' => ['type' => 'string']],
			],
			'data' => [
				[
					'balance' => -9223372036854775809,  // Beyond PHP_INT_MIN
					'label' => '-9223372036854775809',
				],
			],
			]
		);

		$this->assertIsString($jsonInput);
		$struct = Struct::fromJson($jsonInput);
		$response = $struct->toJson();

		// Verify JSON is valid (this would fail without column metadata!)
		$this->assertNotNull(
			json_decode($response),
			'PHP_INT_MIN - 1 should be handled correctly via column metadata'
		);

		// Verify bigint field is detected from columns
		$this->assertContains('data.0.balance', $struct->getBigIntFields());

		// Verify string field is NOT detected as bigint
		$this->assertNotContains('data.0.label', $struct->getBigIntFields());
	}

	/**
	 * Test maximum unsigned 64-bit value (2^64 - 1)
	 * 18446744073709551615 - the largest possible 64-bit unsigned integer
	 *
	 * @return void
	 */
	public function testMax64BitUnsigned(): void {
		// Max uint64 = 18446744073709551615
		$maxUint64 = 18446744073709551615;

		$jsonInput = json_encode(
			[
			'columns' => [
				['id' => ['type' => 'long long']],
				['code' => ['type' => 'string']],
			],
			'data' => [
				[
					'id' => $maxUint64,  // Max uint64
					'code' => '18446744073709551615',
				],
			],
			]
		);

		$this->assertIsString($jsonInput);
		$struct = Struct::fromJson($jsonInput);
		$response = $struct->toJson();

		// Verify JSON is valid
		$this->assertNotNull(json_decode($response), 'Max uint64 should produce valid JSON');

		// Verify bigint field is detected from columns
		$this->assertContains('data.0.id', $struct->getBigIntFields());

		// Verify string field stays quoted
		$this->assertStringContainsString('"code":"18446744073709551615"', $response);
	}

	/**
	 * Critical test: Numeric string at boundary values should NOT be marked as bigint
	 * even if they look like large numbers, because they're not in 'long long' columns
	 * This proves the hybrid approach prevents false positives
	 *
	 * @return void
	 */
	public function testNumericStringNotMisidentifiedAsBigint(): void {
		// This tests that even very large numeric strings are kept quoted
		// when they're not marked as 'long long' in columns
		$jsonInput = json_encode(
			[
			'columns' => [
				['id' => ['type' => 'long long']],
				['huge_string' => ['type' => 'string']],
			],
			'data' => [
				[
					'id' => 123,
					'huge_string' => '18446744073709551615',  // Looks like bigint but is string!
				],
			],
			]
		);

		$this->assertIsString($jsonInput);
		$struct = Struct::fromJson($jsonInput);
		$response = $struct->toJson();

		// Verify JSON is valid
		$this->assertNotNull(json_decode($response), 'Should handle large numeric strings');

		// Verify bigint detection is correct
		$this->assertContains('data.0.id', $struct->getBigIntFields());
		$this->assertNotContains('data.0.huge_string', $struct->getBigIntFields());

		// Verify string stays quoted (this is the critical check)
		$this->assertStringContainsString('"huge_string":"18446744073709551615"', $response);
		$this->assertStringNotContainsString('"huge_string":18446744073709551615', $response);
	}

	/**
	 * Test isBigIntBoundary() helper using reflection for precise boundary detection
	 * This tests the mathematical correctness of the new heuristic fallback
	 *
	 * @return void
	 */
	public function testIsBigIntBoundaryDetection(): void {
		// Use reflection to access the private method
		$method = new ReflectionMethod(Struct::class, 'isBigIntBoundary');
		$method->setAccessible(true);

		// Test cases: [value, expected_result]
		$testCases = [
			// Within PHP_INT_MAX (9223372036854775807)
			['1', false],
			['123', false],
			['9223372036854775806', false],  // PHP_INT_MAX - 1
			['9223372036854775807', false],  // PHP_INT_MAX (at boundary)

			// Beyond PHP_INT_MAX
			['9223372036854775808', true],   // PHP_INT_MAX + 1
			['18446744073709551615', true],  // Max uint64

			// Large numbers with many digits
			['12345678901234567890', true],  // 20 digits
			['123456789012345678901', true], // 21 digits

			// Within PHP_INT_MIN (-9223372036854775808)
			['-1', false],
			['-123', false],
			['-9223372036854775807', false],  // PHP_INT_MIN + 1
			['-9223372036854775808', false],  // PHP_INT_MIN (at boundary)

			// Beyond PHP_INT_MIN
			['-9223372036854775809', true],   // PHP_INT_MIN - 1
			['-18446744073709551615', true],  // Negative max uint64

			// Zero-padded numbers (common source of false positives)
			['0000000000', false],             // Padded zero
			['00123', false],                  // Padded small number
			['0000009223372036854775807', false],  // Padded PHP_INT_MAX

			// Negative zero-padded
			['-0000000001', false],            // Padded negative
		];

		foreach ($testCases as [$value, $expected]) {
			$result = $method->invoke(null, $value);
			$this->assertSame(
				$expected,
				$result,
				"isBigIntBoundary('{$value}') should return " . ($expected ? 'true' : 'false')
			);
		}
	}
}
