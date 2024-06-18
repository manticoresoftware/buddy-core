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
		$configPath = __DIR__ . '/../../config/keyboard-layout.json';
		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			throw new Exception("Unable to read keyboard layout config file at '$configPath'");
		}

		/** @var array<string,array<string>> $langMap */
		$langMap = json_decode($configContent, true);
		if (!isset($langMap[$targetLang])) {
			throw new Exception("Unknown language code '$targetLang'");
		}
		static::$langMap = $langMap;
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
	 * Get the list of all supported language keyboard layouts codes
	 * @return array<string>
	 */
	public static function getSupportedLanguages(): array {
		return array_keys(static::$langMap);
	}
}
