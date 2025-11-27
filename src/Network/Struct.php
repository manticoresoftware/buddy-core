<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Network;

use ArrayAccess;
use Countable;
use Exception;
use Iterator;
use JsonSerializable;

/**
 * @package Manticoresearch\Buddy\Core\Network
 * @template TKey of string|int
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 * @implements Iterator<TKey, TValue>
 */

final class Struct implements JsonSerializable, ArrayAccess, Countable, Iterator {
	const JSON_DEPTH = 512;
	const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE;

	/**
	 * @var int Iterator position
	 */
	private int $position = 0;

	/**
	 * @var array<TKey> Array of keys for iterator
	 */
	private array $keys = [];

	/**
	 * @param array<TKey, TValue> $data
	 * @param array<string> $bigIntFields
	 * @return void
	 */
	public function __construct(
		private array $data,
		private array $bigIntFields = []
	) {
		$this->keys = array_keys($this->data);
	}

	/**
	 * @param TKey $name
	 * @return TValue|null
	 */
	public function offsetGet(mixed $name): mixed {
		return $this->data[$name] ?? null;
	}

	/**
	 * @param TKey $name
	 * @param TValue $value
	 * @return void
	 */
	public function offsetSet(mixed $name, mixed $value): void {
		$this->data[$name] = $value;
		$this->keys = array_keys($this->data);
	}

	/**
	 * @param TKey $name
	 * @return bool
	 */
	public function offsetExists(mixed $name): bool {
		return isset($this->data[$name]);
	}

	/**
	 * @param TKey $name
	 * @return void
	 */
	public function offsetUnset(mixed $name): void {
		unset($this->data[$name]);
		$this->keys = array_keys($this->data);
	}

	/**
	 * Get the data as raw array
	 *
	 * @return array<TKey, TValue>
	 */
	public function toArray(): array {
		/** @var array<TKey, TValue> */
		return (array)$this->data;
	}

	/**
	 * @param callable $fn
	 * @return Struct<TKey, TValue>
	 */
	public function map(callable $fn): self {
		$this->data = array_map($fn, $this->data);
		return $this;
	}

	/**
	 * @return array<string>
	 */
	public function getBigIntFields(): array {
		return $this->bigIntFields;
	}

	/**
	 * Add new bigint field, so we will know how to handle it
	 * @param string $field
	 * @return void
	 */
	public function addBigIntField(string $field): void {
		$this->bigIntFields[] = $field;
	}

	/**
	 * Check if the input JSON is valid structure
	 * @param string $json
	 * @return bool
	 */
	public static function isValid(string $json): bool {
		return simdjson_is_valid($json, static::JSON_DEPTH);
	}

	/**
	 * Create Struct from JSON string with paths preservation for modified unsigned big integers
	 * @param string $json
	 * @return Struct<TKey, TValue>
	 */
	public static function fromJson(string $json): self {
		/** @var array<TKey, TValue> */
		$result = (array)simdjson_decode($json, true, static::JSON_DEPTH);
		$bigIntFields = [];

		// PRIMARY: Extract bigint fields from column metadata if present
		$hasColumns = isset($result['columns']) && is_array($result['columns']);
		if ($hasColumns) {
			static::addBigIntFieldsFromColumns($result, $bigIntFields);
		} elseif (static::hasBigInt($json)) {
			// FALLBACK: Only run heuristic if columns metadata is missing
			// We need here to keep original json decode cuzit has bigIntFields
			/** @var array<TKey, TValue> */
			$modified = json_decode($json, true, static::JSON_DEPTH, static::JSON_FLAGS | JSON_BIGINT_AS_STRING);
			static::traverseAndTrack($modified, $result, $bigIntFields);
			$result = $modified;
		}

		/** @var Struct<TKey, TValue> */
		return new self($result, $bigIntFields);
	}

	/**
	 * @param array<TKey,TValue> $data
	 * @param array<string> $bigIntFields
	 * @return Struct<TKey, TValue>
	 */
	public static function fromData(array $data, array $bigIntFields = []): self {
		return new self($data, $bigIntFields);
	}

	/**
	 * Check if underlying data is list
	 * @return bool
	 */
	public function isList(): bool {
		return $this->data && array_is_list($this->data);
	}

	/**
	 * Check if the key exists in the data
	 * @param string $key
	 * @return bool
	 */
	public function hasKey(string $key): bool {
		return array_key_exists($key, $this->data);
	}

	/**
	 * Serialization implementation with proper integer handling
	 *
	 * @return string
	 */
	public function jsonSerialize(): string {
		return $this->toJson();
	}

	/**
	 * Encode the data to JSON string
	 * @return string
	 */
	public function toJson(): string {
		$serialized = json_encode($this->data);
		if (false === $serialized) {
			throw new Exception('Cannot encode data to JSON');
		}
		if (!$this->bigIntFields) {
			return $serialized;
		}
		$patterns = [];
		foreach ($this->bigIntFields as $dotPath) {
			$patterns[] = static::getReplacePattern($dotPath);
		}

		$json = preg_replace_callback(
			$patterns,
			static function (array $matches): string {
				/** @var int $pos */
				$pos = strrpos($matches[0], ':');
				$field = substr($matches[0], 0, $pos);
				return $field . ':' . $matches[2];
			},
			$serialized
		);
		if ($json === false) {
			throw new Exception('Cannot encode data to JSON');
		}
		return $json;
	}

	/**
	 * Get the pattern and replacement for the given path to accumulate and use in one shot replacement
	 * @param string $path
	 * @return string
	 */
	private static function getReplacePattern(string $path): string {
		$parts = explode('.', $path);
		$pattern = '/';

		foreach ($parts as $index => $part) {
			if ($index === 0) {
				$pattern .= '"' . preg_quote($part, '/') . '"\s*:\s*';
			} else {
				$pattern .= '(?:.*?"' . preg_quote($part, '/') . '"\s*:\s*)?';
			}
		}

		return $pattern . '("?)(\-?\d+)\1/';
	}

	/**
	 * Traverse the data and track all fields that are big integers
	 * Skips fields that were already identified via column metadata
	 *
	 * @param mixed $data
	 * @param mixed $originalData
	 * @param array<string> $bigIntFields
	 * @param string $path
	 * @return void
	 */
	private static function traverseAndTrack(
		mixed &$data,
		mixed $originalData,
		array &$bigIntFields,
		string $path = ''
	): void {
		if (!is_array($data) || !is_array($originalData)) {
			return;
		}

		foreach ($data as $key => &$value) {
			$currentPath = $path ? "$path.$key" : "$key";
			if (!isset($originalData[$key])) {
				continue;
			}

			$originalValue = $originalData[$key];
			if (is_array($value) && is_array($originalValue)) {
				static::traverseAndTrack($value, $originalValue, $bigIntFields, $currentPath);
				continue;
			}

			static::trackBigIntIfNeeded($value, $originalValue, $currentPath, $bigIntFields);
		}
	}

	/**
	 * Check if a numeric string exceeds PHP_INT_MAX/MIN boundaries
	 * Uses optimized string length with conditional trimming for best performance
	 * Handles leading zeros and sign correctly
	 *
	 * @param string $value Numeric string to check
	 * @return bool True if value exceeds 64-bit integer range
	 */
	private static function isBigIntBoundary(string $value): bool {
		$firstChar = $value[0];

		// OPTIMIZATION: Only ltrim if value has leading sign or zeros
		// This avoids expensive function call for ~95% of clean numeric values
		$absValue = ($firstChar === '-' || $firstChar === '0')
			? ltrim($value, '-0')
			: $value;

		$magnitude = strlen($absValue);

		// PHP_INT_MAX has 19 digits, so we can quickly filter most values
		// > 19 digits: definitely a bigint
		if ($magnitude > 19) {
			return true;
		}

		// <= 18 digits: definitely not a bigint
		if ($magnitude < 19) {
			return false;
		}

		// Exactly 19 digits: need string comparison against PHP_INT_MAX/MIN boundaries
		// Note: We must use different boundaries for positive vs negative
		// because PHP_INT_MAX and |PHP_INT_MIN| differ by 1
		if ($firstChar === '-') {
			// Negative: 19-digit negative is bigint if absolute value > |PHP_INT_MIN|
			// |PHP_INT_MIN| = 9223372036854775808
			return $absValue > '9223372036854775808';
		}

		// Positive: 19-digit positive is bigint if > PHP_INT_MAX
		return $absValue > (string)PHP_INT_MAX;
	}

	/**
	 * Check if field is a big integer and add it to tracking list
	 *
	 * @param mixed $value
	 * @param mixed $originalValue
	 * @param string $currentPath
	 * @param array<string> &$bigIntFields
	 * @return void
	 */
	private static function trackBigIntIfNeeded(
		mixed $value,
		mixed $originalValue,
		string $currentPath,
		array &$bigIntFields
	): void {
		if (!is_string($value) || !is_numeric($originalValue)) {
			return;
		}

		// Use precise boundary check instead of arbitrary strlen
		if (!static::isBigIntBoundary($value)) {
			return;
		}

		// Skip if already identified via column metadata
		if (in_array($currentPath, $bigIntFields, true)) {
			return;
		}

		$bigIntFields[] = $currentPath;
	}

	/**
	 * Check if the JSON string contains any big integers
	 * @param string $json
	 * @return bool
	 */
	private static function hasBigInt(string $json): bool {
		return !!preg_match('/(?<!")[1-9]\d{18,}(?!")/', $json);
	}

	/**
	 * Extract bigint field paths from column metadata
	 * Only fields explicitly marked as 'long long' in column definitions are extracted
	 *
	 * @param array<mixed> $response Response with 'columns' metadata
	 * @param array<string> &$bigIntFields Fields array to populate with bigint paths
	 * @return void
	 */
	private static function addBigIntFieldsFromColumns(array $response, array &$bigIntFields): void {
		if (!isset($response['columns']) || !is_array($response['columns'])) {
			return;
		}

		foreach ($response['columns'] as $columnIndex => $columnDef) {
			if (!is_array($columnDef)) {
				continue;
			}

			foreach ($columnDef as $fieldName => $fieldInfo) {
				if (!is_array($fieldInfo) || !(($fieldInfo['type'] ?? null) === 'long long')) {
					continue;
				}

				$bigIntFields[] = "data.{$columnIndex}.{$fieldName}";
			}
		}
	}

	/**
	 * Count elements of an object
	 * @link https://php.net/manual/en/countable.count.php
	 * @return int The custom count as an integer.
	 */
	public function count(): int {
		return sizeof($this->data);
	}

	/**
	 * Return the current element
	 * @link https://php.net/manual/en/iterator.current.php
	 * @return TValue|null Can return any type.
	 */
	public function current(): mixed {
		$key = $this->keys[$this->position] ?? null;
		return $key !== null ? $this->data[$key] : null;
	}

	/**
	 * Move forward to next element
	 * @link https://php.net/manual/en/iterator.next.php
	 * @return void Any returned value is ignored.
	 */
	public function next(): void {
		++$this->position;
	}

	/**
	 * Return the key of the current element
	 * @link https://php.net/manual/en/iterator.key.php
	 * @return TKey|null Scalar on success, or null on failure.
	 */
	public function key(): mixed {
		return $this->keys[$this->position] ?? null;
	}

	/**
	 * Checks if current position is valid
	 * @link https://php.net/manual/en/iterator.valid.php
	 * @return bool The return value will be casted to boolean and then evaluated.
	 */
	public function valid(): bool {
		return isset($this->keys[$this->position]);
	}

	/**
	 * Rewind the Iterator to the first element
	 * @link https://php.net/manual/en/iterator.rewind.php
	 * @return void Any returned value is ignored.
	 */
	public function rewind(): void {
		$this->position = 0;
	}
}
