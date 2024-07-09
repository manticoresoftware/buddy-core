<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Tool;

use RuntimeException;

/** @package Manticoresearch\Buddy\Core\Tool */
final class Arrays {
	/**
	 * Get all combinations of words with typo and fuzzy correction, sorted by relevance based on scoreMap
	 * @param array<array<string>> $words
	 * @param array<string,float> $scoreMap
	 * @return array<array<string>>
	 */
	public static function getPositionalCombinations(array $words, array $scoreMap = []): array {
		$combinations = [[]]; // Initialize with an empty array to start the recursion
		$hasScores = !empty($scoreMap);

		foreach ($words as $choices) {
			$temp = [];
			foreach ($combinations as $combination) {
				foreach ($choices as $choice) {
					$newCombination = array_merge($combination, [$choice]);
					$temp[] = $newCombination;
				}
			}
			$combinations = $temp;
		}

		if ($hasScores) {
			// Calculate relevance scores for each combination
			$scoredCombinations = array_map(
				static function ($combination) use ($scoreMap) {
					$score = 0.0;
					foreach ($combination as $word) {
						$score += $scoreMap[$word] ?? 0.0;
					}
					return ['combination' => $combination, 'score' => $score];
				}, $combinations
			);

			// Sort combinations by relevance score in descending order
			usort(
				$scoredCombinations, static function (array $a, array $b) {
					return $b['score'] <=> $a['score'];
				}
			);

			// Extract sorted combinations
			$combinations = array_map(
				function ($item) {
					return $item['combination'];
				}, $scoredCombinations
			);
		}

		return $combinations;
	}

	/**
	 * Set the value in nested array by dot notation path to it and ref
	 * @param array<string,mixed> $array
	 * @param string $keyPath
	 * @param mixed $value
	 * @return void
	 * @throws RuntimeException
	 */
	public static function setValueByDotNotation(array &$array, string $keyPath, mixed $value): void {
		$keys = explode('.', $keyPath);
		$current = &$array;

		foreach ($keys as $key) {
			if (!is_array($current)) {
				break;
			}
			if (!isset($current[$key])) {
				throw new RuntimeException("Key '$key' does not exist in the array");
			}
			$current = &$current[$key];
		}

		$current = $value;
	}

	/**
	 * Run normalization on the array values
	 * In case empty array passed, return empty array
	 * @param array<int|string,int> $values
	 * @return array<int|string,float>
	 */
	public static function normalizeValues(array $values): array {
		// Nothing to normalize?
		if (empty($values)) {
			return [];
		}

		$min = min($values);
		$max = max($values);

		if (($max - $min) === 0) {
			return array_fill_keys(array_keys($values), 1.0); // Avoid division by zero
		}

		return array_map(
			function ($value) use ($min, $max) {
				return ($value - $min) / ($max - $min);
			}, $values
		);
	}
}
