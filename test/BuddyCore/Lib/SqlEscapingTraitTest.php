<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\CoreTest\Lib\SqlEscapingTraitTestClass;
use PHPUnit\Framework\TestCase;

class SqlEscapingTraitTest extends TestCase {
	private SqlEscapingTraitTestClass $testClass;

	public function testSqlEscapeSpecialCharacters(): void {
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('sqlEscape');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, "line1\nline2\r\"quoted\"\\slash\0\x1a'");
		$this->assertEquals('line1\\nline2\\r\\"quoted\\"\\\\slash\\0\\Z\\\'', $result);
	}

	public function testQuoteWrapsEscapedString(): void {
		$reflection = new ReflectionClass($this->testClass);
		$method = $reflection->getMethod('quote');
		$method->setAccessible(true);

		$result = $method->invoke($this->testClass, "O'Reilly");
		$this->assertEquals("'O\\'Reilly'", $result);
	}

	protected function setUp(): void {
		$this->testClass = new SqlEscapingTraitTestClass();
	}
}
