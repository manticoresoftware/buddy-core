<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Core\ManticoreSearch\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rule evaluation mirrored from the daemon (ProcessPerms in
 * src/auth/auth_perms.cpp): first matching rule wins, * and ? wildcards,
 * default deny. If the daemon evaluation changes, this test must be
 * updated together with Permissions::evaluateRules().
 */
class PermissionsTest extends TestCase {
	public function testExactTargetMatch(): void {
		$this->assertTrue(Permissions::evaluateRules([self::rule('schema', 'orders', '1')], 'schema', 'orders'));
		$this->assertFalse(Permissions::evaluateRules([self::rule('schema', 'orders', '0')], 'schema', 'orders'));
		$this->assertFalse(Permissions::evaluateRules([self::rule('schema', 'other', '1')], 'schema', 'orders'));
	}

	public function testWildcardTargetMatch(): void {
		$this->assertTrue(Permissions::evaluateRules([self::rule('schema', '*', '1')], 'schema', 'orders'));
		$this->assertTrue(
			Permissions::evaluateRules([self::rule('schema', 'logs_*', '1')], 'schema', 'logs_public')
		);
		$this->assertFalse(Permissions::evaluateRules([self::rule('schema', 'logs_*', '1')], 'schema', 'orders'));
		$this->assertTrue(Permissions::evaluateRules([self::rule('schema', 'shard_?', '1')], 'schema', 'shard_1'));
		$this->assertFalse(Permissions::evaluateRules([self::rule('schema', 'shard_?', '1')], 'schema', 'shard_10'));
	}

	public function testFirstMatchingRuleWins(): void {
		$denyFirst = [
			self::rule('schema', 'logs_*', '0'),
			self::rule('schema', 'logs_public', '1'),
		];
		$this->assertFalse(Permissions::evaluateRules($denyFirst, 'schema', 'logs_public'));

		$allowFirst = [
			self::rule('schema', 'logs_public', '1'),
			self::rule('schema', 'logs_*', '0'),
		];
		$this->assertTrue(Permissions::evaluateRules($allowFirst, 'schema', 'logs_public'));
	}

	public function testActionMismatchIsIgnored(): void {
		$rules = [
			self::rule('read', '*', '1'),
			self::rule('write', '*', '1'),
		];
		$this->assertFalse(Permissions::evaluateRules($rules, 'schema', 'orders'));
	}

	public function testDefaultDeny(): void {
		$this->assertFalse(Permissions::evaluateRules([], 'schema', 'orders'));
	}

	public function testAllowFlagFormats(): void {
		$this->assertTrue(Permissions::evaluateRules([self::rule('schema', '*', '1')], 'schema', 'orders'));
		$this->assertTrue(Permissions::evaluateRules([self::rule('schema', '*', 'true')], 'schema', 'orders'));
		$this->assertTrue(Permissions::evaluateRules([self::rule('schema', '*', true)], 'schema', 'orders'));
		$this->assertFalse(Permissions::evaluateRules([self::rule('schema', '*', '0')], 'schema', 'orders'));
		$this->assertFalse(Permissions::evaluateRules([self::rule('schema', '*', 'false')], 'schema', 'orders'));
		$this->assertFalse(Permissions::evaluateRules([self::rule('schema', '*', false)], 'schema', 'orders'));
	}

	/**
	 * @param string $action
	 * @param string $target
	 * @param bool|int|string $allow
	 * @return array{username:string,action:string,target:string,allow:bool|int|string}
	 */
	private static function rule(string $action, string $target, bool|int|string $allow): array {
		return [
			'username' => 'test_user',
			'action' => $action,
			'target' => $target,
			'allow' => $allow,
		];
	}
}
