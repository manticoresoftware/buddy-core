<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

final class Struct implements JsonSerializable, ArrayAccess {
	/**
	 * @param mixed $data
	 * @return void
	 */
	public function __construct(
		private readonly mixed $data,
		private readonly array $bigIntFields = []
	) {
	}

	/**
	 * @param mixed $name
	 * @return mixed
	 */
	public function offsetGet(mixed $name): mixed {
		return $this->data[$name] ?? null;
	}

	/**
	 * @param mixed $name
	 * @param mixed $value
	 * @return void
	 */
	public function offsetSet(mixed $name, mixed $value): void {
		$this->data[$name] = $value;
	}

	/**
	 * @param mixed $name
	 * @return bool
	 */
	public function offsetExists(mixed $name): bool {
		return isset($this->data[$name]);
	}

	/**
	 * @param mixed $name
	 * @return void
	 */
	public function offsetUnset(mixed $name): void {
		unset($this->data[$name]);
	}

	/**
	 * Get the data as raw array
	 *
	 * @return array<mixed>
	 */
	public function toArray(): array {
		return (array)$this->data;
	}

	/**
	 * Create Struct from JSON string with paths preservation for modified unsigned big integers
	 * @param string $json
	 * @return Struct
	 */
	public static function fromJson(string $json): self {
		$result = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
		$bigIntFields = [];

		$originalData = json_decode($json, true, 512, 0);
		$bigIntFields = [];
		static::traverseAndTrack($result, $originalData, $bigIntFields);

		return new self($result, $bigIntFields);
	}

	/**
	 * Serialization implementation with proper integer handling
	 *
	 * @return string
	 */
	public function jsonSerialize(): string {
		$serialized = json_encode($this->data);

		$patterns = [];
		$replacements = [];
		foreach ($this->bigIntFields as $dotPath) {
			[$pattern, $replacement] = static::getReplacePatterns($serialized, $dotPath);
			$patterns[] = $pattern;
			$replacements[] = $replacement;
		}
		return preg_replace_callback($patterns, $replacements, $serialized);
	}

	/**
	 * Get the pattern and replacement for the given path to accumulate and use in one shot replacement
	 * @param string $path
	 * @return array{string,string}
	 */
	private static function getReplacePatterns(string $path): array {
		$parts = explode('.', $path);
		$pattern = '/';

		foreach ($parts as $index => $part) {
			if ($index === 0) {
				$pattern .= '"' . preg_quote($part, '/') . '"\s*:\s*';
			} else {
				$pattern .= '(?:.*?"' . preg_quote($part, '/') . '"\s*:\s*)?';
			}
		}

		$pattern .= '("?)([^"{}[\\],]+)\1/';

		$replacement = static function ($matches) {
			$value = trim($matches[2], '"');
			if (is_numeric($value) && strpos($value, '.') === false) {
				return rtrim(substr($matches[0], 0, -strlen($matches[2]))) . $value;
			} else {
				return rtrim(substr($matches[0], 0, -strlen($matches[2]))) . $value;
			}
		};
		return [$pattern, $replacement];

	}

	/**
	 * Traverse the data and track all fields that are big integers
	 * @param mixed $data
	 * @param mixed $originalData
	 * @param array $bigIntFields
	 * @param string $path
	 * @return void
	 */
	private static function traverseAndTrack(mixed &$data, mixed $originalData, array &$bigIntFields, string $path = ''): void {
		if (is_array($data)) {
			foreach ($data as $key => &$value) {
				$currentPath = $path ? "$path.$key" : $key;
				if (isset($originalData[$key])) {
					$originalValue = $originalData[$key];
					if (is_string($value) && is_int($originalValue) && strlen($value) > 9) {
						$bigIntFields[] = $currentPath;
					} elseif (is_array($value) && is_array($originalValue)) {
						static::traverseAndTrack($value, $originalValue, $bigIntFields, $currentPath);
					}
				}
			}
		}
	}
}

