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
final class Strings {

	/**
	 * Single iteration implementation of camelcase to underscore
	 * @param string $string
	 * @return string
	 */
	public static function camelcaseToUnderscore(string $string): string {
		$result = '';
		$prevHigh = false;
		for ($i = 0, $max = strlen($string); $i < $max; $i++) {
			$curHigh = $string[$i] >= 'A' && $string[$i] <= 'Z';
			if ($result && !$prevHigh && $curHigh) {
				$result .= '_';
			}

			$result .= $curHigh ? strtolower($string[$i]) : $string[$i];
			$prevHigh = $curHigh;
		}

		return $result;
	}

	/**
	 * Single iteration implementation of separate string to camelcase
	 *
	 * @param string $string
	 * @return string
	 */
	public static function camelcaseBySeparator(string $string, string $separator = '_'): string {
		return lcfirst(str_replace($separator, '', ucwords($string, $separator)));
	}

	/**
   * Convert the class name to it's identifier with dash as separator
	 *
	 * @param string $string
	 * @return string
	 */
	public static function classNameToIdentifier(string $string): string {
		return strtolower(str_replace('\\', '-', $string));
	}
}
