<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Tool;

use Exception;

/** @package Manticoresearch\Buddy\Core\Tool */
final class KeyboardLayout {
	const SPACING = " \n\r\t";

	/** @var array<string,array<string>> $langMap */
	protected static array $langMap;

	/**
	 * Initialize the object with a given language code layout
	 * @param string $targetLang
	 * @return void
	 */
	public function __construct(public string $targetLang) {
		$langMap = static::getLangMap();
		if (!isset($langMap[$targetLang])) {
			throw new Exception("Unknown language code '$targetLang'");
		}
	}

	/**
	 * Convert the input string to the target language layout
	 * @param string $input input phrase
	 * @param string $sourceLang source language that we will use to convert
	 * @return string
	 */
	public function convert(string $input, string $sourceLang): string {
		if (!isset(static::$langMap[$sourceLang])) {
			throw new Exception("Unknown language code '$sourceLang'");
		}
		if ($sourceLang === $this->targetLang) {
			throw new Exception('Cannot convert to the same language');
		}

		$indexMap = array_flip(array_filter(static::$langMap[$sourceLang]));
		$output = '';
		$chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		foreach ($chars as $char) {
			if (strpos(self::SPACING, $char) !== false) {
				$output .= $char;
				continue;
			}
			$index = $indexMap[$char] ?? null;
			$output .= static::$langMap[$this->targetLang][$index] ?? $char;
		}
		return $output;
	}

	/**
	 * Get results of the conversion for all languages in array
	 * @param string $input input phrase
	 * @param array<string> $langs list of languages to convert to
	 * @return array<string>
	 */
	public function convertMany(string $input, array $langs): array {
		$result = [];
		foreach ($langs as $lang) {
			$result[] = $this->convert($input, $lang);
		}
		return $result;
	}

	/**
	 * Convert the input string to the target language layout by using multiple languages
	 * It works almost the same as convertMany but combine ALL possible pairs of languages
	 * @param string $input
	 * @param array<string> $langs
	 * @return array<string>
	 * @throws Exception
	 */
	public static function combineMany(string $input, array $langs): array {
		$result = [];
		$pairs = static::getPairs($langs);
		foreach ($pairs as $target => $sources) {
			$self = new static($target);
			$result = array_merge($result, $self->convertMany($input, $sources));
		}
		return array_values(array_unique($result));
	}

	/**
	 * The same as combineMany but combine all available languages we support
	 * @param string $input
	 * @return array<string>
	 * @throws Exception
	 */
	public static function combineAll(string $input): array {
		return static::combineMany($input, static::getSupportedLanguages());
	}

	/**
	 * Get all available sorted by target language pairs of passed languages
	 * @param array<string> $langs
	 * @return array<string,array<string>>
	 */
	public static function getPairs(array $langs): array {
		$pairs = [];
		// 1. Combine all possible pairs first
		foreach ($langs as $lang1) {
			foreach ($langs as $lang2) {
				if ($lang1 === $lang2) {
					continue;
				}

				$pairs[] = [$lang1, $lang2];
			}
		}

		// 1. Sort pairs by target language
		$sorted = [];
		foreach ($pairs as $pair) {
			$target = $pair[1];
			$source = $pair[0];
			if (!isset($sorted[$target])) {
				$sorted[$target] = [];
			}
			$sorted[$target][] = $source;
		}

		return $sorted;
	}

	/**
	 * Get the list of all supported language keyboard layouts codes
	 * @return array<string>
	 */
	public static function getSupportedLanguages(): array {
		return array_keys(static::getLangMap());
	}

	/**
	 * Lazy loading lang map from the config file and cache it for future usage
	 * @return array<string,array<string>>
	 * @throws Exception
	 */
	protected static function getLangMap(): array {
		if (!isset(static::$langMap)) {
			$configPath = __DIR__ . '/../../config/keyboard-layout.json';
			$configContent = file_get_contents($configPath);
			if ($configContent === false) {
				throw new Exception("Unable to read keyboard layout config file at '$configPath'");
			}

			/** @var array<string,array<string>> $langMap */
			$langMap = json_decode($configContent, true);
			if (!is_array($langMap)) {
				throw new Exception("Invalid keyboard layout config file at '$configPath'");
			}
			static::$langMap = $langMap;
		}
		return static::$langMap;
	}
}
