<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Tool;

/** @package Manticoresearch\Buddy\Core\Tool */
final class Arrays {
	/**
	 * Get all combinations of words with typo and fuzzy correction
	 * @param array<array<string>> $words
	 * @return array<array<string>>
	 */
	public static function getPositionalCombinations(array $words): array {
		$combinations = [[]]; // Initialize with an empty array to start the recursion
		foreach ($words as $choices) {
			$temp = [];
			foreach ($combinations as $combination) {
				foreach ($choices as $choice) {
					$temp[] = array_merge($combination, [$choice]);
				}
			}
			$combinations = $temp;
		}

		return $combinations;
	}

	/**
	 * Set the value in nested array by dot notation path to it and ref
	 * @param array<mixed> $array
	 * @param string $keyPath
	 * @param mixed $value
	 * @return void
	 */
	public static function setValueByDotNotation(array &$array, string $keyPath, mixed &$value): void {
		$keys = explode('.', $keyPath);
		$current = &$array;

		foreach ($keys as $key) {
			if (!isset($current[$key]) || !is_array($current[$key])) {
				$current[$key] = [];
			}
			$current = &$current[$key];
		}

		$current = $value;
	}

}
