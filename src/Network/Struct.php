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
 * @template TKey of string
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 */

final class Struct implements JsonSerializable, ArrayAccess {
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
	 * Add new bigint field, so we will know how to handle it
	 * @param string $field
	 * @return void
	 */
	public function addBigIntField(string $field): void {
		$this->bigIntFields[] = $field;
	}

	/**
	 * Create Struct from JSON string with paths preservation for modified unsigned big integers
	 * @param string $json
	 * @return Struct<TKey, TValue>
	 */
	public static function fromJson(string $json): self {
		$defaultFlags = JSON_INVALID_UTF8_SUBSTITUTE;
		/** @var array<TKey, TValue> */
		$result = json_decode($json, true, 512, $defaultFlags);
		$bigIntFields = [];
		if (static::hasBiInt($json)) {
			/** @var array<TKey, TValue> */
			$modified = json_decode($json, true, 512, $defaultFlags | JSON_BIGINT_AS_STRING);
			static::traverseAndTrack($modified, $result, $bigIntFields);
			$result = $modified;
		}

		/** @var Struct<TKey, TValue> */
		return new self($result, $bigIntFields);
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
			$currentPath = $path ? "$path.$key" : $key;
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
	private static function hasBiInt(string $json): bool {
		return !!preg_match('/(?<!")\b[1-9]\d{18,}\b(?!")/', $json);
	}
}
