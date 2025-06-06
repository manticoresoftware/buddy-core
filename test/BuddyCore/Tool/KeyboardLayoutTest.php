<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyUnitTest\Lib;

use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/** @package Manticoresearch\BuddyUnitTest\Lib */
class KeyboardLayoutTest extends TestCase {

	use TestInEnvironmentTrait;

	/**
	 * Test single language modification in the input string
	 */
	public function testSingleLanguageConversion(): void {
		$input = 'ghbdtn';
		$KL = new KeyboardLayout('ru');
		$output = $KL->convert($input, 'us');
		$this->assertEquals('привет', $output);

		$input = 'руддщ цщкдв';
		$KL = new KeyboardLayout('us');
		$output = $KL->convert($input, 'ru');
		$this->assertEquals('hello world', $output);
	}

	/**
	 * Test mixed language modification in the input string
	 */
	public function testMixedLanguageConversion(): void {
		$input = 'ghbdtn как дела';
		$KL = new KeyboardLayout('ru');
		$output = $KL->convert($input, 'us');
		$this->assertEquals('привет как дела', $output);
		$input = 'руддщ world';
		$KL = new KeyboardLayout('us');
		$output = $KL->convert($input, 'ru');
		$this->assertEquals('hello world', $output);
	}

	/**
	 * Test that the original language is untouched in conversion
	 */
	public function testSameLayoutUntouched(): void {
		$input = 'hello world';
		$KL = new KeyboardLayout('us');
		$output = $KL->convert($input, 'de');
		$this->assertEquals('hello world', $output);
	}

	/**
	 * Get all supported languages that we have in the keyboard layout config
	 */
	public function testGetSupportedLanguages(): void {
		$langs = KeyboardLayout::getSupportedLanguages();
		$supportedLangs = ['be','bg','br','ch','de','dk','es','fr','uk','gr','it','no','pt','ru','se','ua','us'];
		$this->assertEquals($supportedLangs, $langs);
	}

	public function testCombineMany(): void {
		$langs = ['ru', 'us'];
		$input = 'руддщ world';
		$result = KeyboardLayout::combineMany($input, $langs);
		$this->assertEquals(['руддщ world', 'hello world', 'руддщ цщкдв'], $result);

		$langs = ['ru', 'us', 'de'];
		$input = 'руддщ world';
		$result = KeyboardLayout::combineMany($input, $langs);
		// We get unique combinations as output, but real combinatinos are 6
		$this->assertEquals(['hello world','руддщ world','руддщ цщкдв'], $result);
	}
}
