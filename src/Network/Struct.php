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
use Exception;
use JsonSerializable;

/**
 * @package Manticoresearch\Buddy\Core\Network
 * @template TKey of string|int
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 */

final class Struct implements JsonSerializable, ArrayAccess {
	const JSON_DEPTH = 512;
	const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE;

	/**
	 * @param array<TKey, TValue> $data
	 * @param array<string> $bigIntFields
	 * @return void
	 */
	public function __construct(
		private array $data,
		private array $bigIntFields = []
	) {
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
		// TODO: replace with json_validate once we more to 8.3
		$result = json_decode($json, true, static::JSON_DEPTH, static::JSON_FLAGS);
		return !!$result;
	}

	/**
	 * Create Struct from JSON string with paths preservation for modified unsigned big integers
	 * @param string $json
	 * @return Struct<TKey, TValue>
	 */
	public static function fromJson(string $json): self {
		/** @var array<TKey, TValue> */
		$result = json_decode($json, true, static::JSON_DEPTH, static::JSON_FLAGS);
		$bigIntFields = [];
		if (static::hasBigInt($json)) {
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
		throw new Exception('Use toJson method instead');
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
		if (!isset($json)) {
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

		return $pattern . '("?)([^"{}[\\],]+)\1/';
	}

	/**
	 * Traverse the data and track all fields that are big integers
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
			if (is_string($value) && is_numeric($originalValue) && strlen($value) > 9) {
				$bigIntFields[] = $currentPath;
			} elseif (is_array($value) && is_array($originalValue)) {
				static::traverseAndTrack($value, $originalValue, $bigIntFields, $currentPath);
			}
		}
	}

	/**
	 * Check if the JSON string contains any big integers
	 * @param string $json
	 * @return bool
	 */
	private static function hasBigInt(string $json): bool {
		return !!preg_match('/(?<!")\b[1-9]\d{18,}\b(?!")/', $json);
	}
}
