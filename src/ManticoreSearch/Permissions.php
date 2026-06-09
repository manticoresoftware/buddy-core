<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Client-side check of the daemon's user permissions.
 *
 * The daemon enforces permissions itself for every statement it can parse,
 * but statements that fail to parse (e.g. cluster-prefixed sharded
 * CREATE/DROP) skip that check and arrive at Buddy ungated. Plugins handling
 * such statements gate them here: isActionAllowed() reads the permissions
 * the daemon exposes via SHOW PERMISSIONS FOR and mirrors the daemon's rule
 * evaluation (ProcessPerms in src/auth/auth_perms.cpp: the first rule
 * matching the action and target — exactly or by * / ? wildcard — decides,
 * no match denies).
 */
final class Permissions {
	public const ACTION_READ = 'read';
	public const ACTION_WRITE = 'write';
	public const ACTION_SCHEMA = 'schema';

	/**
	 * Check that the user is allowed to perform the action on the target.
	 * An empty user means auth is disabled, so everything is allowed.
	 *
	 * @param Client $systemClient client delegated to system.buddy
	 * @param string $user
	 * @param string $action
	 * @param string $target
	 * @return bool
	 */
	public static function isActionAllowed(Client $systemClient, string $user, string $action, string $target): bool {
		if ($user === '' || $user === Client::SYSTEM_USER) {
			return true;
		}

		$escapedUser = str_replace("'", "\\'", $user);
		$resp = $systemClient->sendRequest("SHOW PERMISSIONS FOR '{$escapedUser}'");
		if ($resp->hasError()) {
			Buddy::debug("Permissions: SHOW PERMISSIONS FOR '{$user}' failed: {$resp->getError()}");
			return false;
		}

		/** @var array{0?:array{data?:array<array{username:string,action:string,target:string,allow:bool|int|string}>}} $result */
		$result = $resp->getResult();
		return self::evaluateRules($result[0]['data'] ?? [], $action, $target);
	}

	/**
	 * Mirror of the daemon rule evaluation: the first rule matching the
	 * action and target (exactly or by wildcard) decides; no match denies.
	 * Rules arrive from SHOW PERMISSIONS FOR in daemon storage order, the
	 * same order the daemon itself evaluates them in.
	 *
	 * @param array<array{username:string,action:string,target:string,allow:bool|int|string}> $rules
	 * @param string $action
	 * @param string $target
	 * @return bool
	 */
	public static function evaluateRules(array $rules, string $action, string $target): bool {
		foreach ($rules as $rule) {
			if ($rule['action'] !== $action) {
				continue;
			}

			$ruleTarget = trim($rule['target']);
			$isWildcard = strpbrk($ruleTarget, '*?') !== false;
			if (!$isWildcard && $ruleTarget === $target) {
				return self::parseAllowFlag($rule['allow']);
			}
			if ($isWildcard && ($ruleTarget === '*' || self::wildcardMatch($ruleTarget, $target))) {
				return self::parseAllowFlag($rule['allow']);
			}
		}
		return false;
	}

	/**
	 * @param string $pattern
	 * @param string $value
	 * @return bool
	 */
	private static function wildcardMatch(string $pattern, string $value): bool {
		$regex = '/\A' . strtr(preg_quote($pattern, '/'), ['\*' => '.*', '\?' => '.']) . '\z/';
		return (bool)preg_match($regex, $value);
	}

	/**
	 * @param bool|int|string $allow
	 * @return bool
	 */
	private static function parseAllowFlag(bool|int|string $allow): bool {
		return in_array(strtolower((string)$allow), ['1', 'true', 'yes'], true);
	}
}
