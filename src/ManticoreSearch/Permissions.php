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
 * Daemon-evaluated permission helpers for plugins.
 *
 * The daemon enforces permissions itself for every statement it can parse,
 * but statements that fail to parse (e.g. cluster-prefixed sharded
 * CREATE/DROP) skip that check and arrive at Buddy ungated. Both helpers
 * here delegate the actual permission evaluation to the daemon, so Buddy
 * never duplicates the rule resolution logic.
 */
final class Permissions {
	private const PERMISSION_DENIED_NEEDLE = 'permission denied';

	/**
	 * Check that the client's current identity has schema (DDL) permission
	 * on the table by probing the daemon with a bare `ALTER TABLE t` (an
	 * option-less no-op): the daemon checks permissions before table
	 * existence, so a "permission denied" reply means no access, while
	 * success or any other error (e.g. unknown table) means the permission
	 * check has already passed. The probe never modifies anything.
	 *
	 * @param Client $userClient client carrying the delegated user identity
	 * @param string $table
	 * @return bool
	 */
	public static function hasSchemaAccess(Client $userClient, string $table): bool {
		$resp = $userClient->sendRequest("ALTER TABLE {$table}");
		if (!$resp->hasError()) {
			return true;
		}

		return stripos((string)$resp->getError(), self::PERMISSION_DENIED_NEEDLE) === false;
	}

	/**
	 * Get the tables visible to the client's current identity as a
	 * name => type map. SHOW TABLES is permission-filtered by the daemon,
	 * so running it on a user-delegated client returns only the tables
	 * that user has grants on — use it to filter listings without any
	 * permission evaluation in Buddy. Returns an empty map on error.
	 *
	 * @param Client $client
	 * @return array<string,string>
	 */
	public static function getAccessibleTables(Client $client): array {
		$resp = $client->sendRequest('SHOW TABLES');
		if ($resp->hasError()) {
			Buddy::debug("Permissions: SHOW TABLES failed: {$resp->getError()}");
			return [];
		}

		/** @var array{0?:array{data?:array<array{Table:string,Type:string}>}} $result */
		$result = $resp->getResult();
		$tables = [];
		foreach ($result[0]['data'] ?? [] as $row) {
			$tables[$row['Table']] = $row['Type'];
		}
		return $tables;
	}
}
