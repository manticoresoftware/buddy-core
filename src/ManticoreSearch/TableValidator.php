<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

use Manticoresearch\Buddy\Core\Cache\Flag;
use Manticoresearch\Buddy\Core\Error\TableValidationError;

/**
 * The purpose of this class is to offer various validations for Manticore Search tables
 * Currently we use it for fuzzy and autocomplete to check that in cacheable way that table has min_infix_len set
 */
final class TableValidator {
	public function __construct(private Client $client, private Flag $cache, private int $ttl = 30) {
	}

	/**
	 * Perform validation, method should be run inside coroutine
	 * @param string $table
	 * @return bool
	 * @throws TableValidationError
	 */
	public function hasMinInfixLen(string $table): bool {
		$cacheKey = "validate:has_min_infix_len:{$table}";
		if ($this->cache->has($cacheKey)) {
			return true;
		}
		/** @var array{error?:string} */
		$result = $this->client->sendRequest('SHOW CREATE TABLE ' . $table)->getResult();
		if (isset($result['error'])) {
			TableValidationError::throw($result['error']);
		}

		/** @var array{0:array{data:array<array{'Create Table':string}>}} $result */
		$schema  = $result[0]['data'][0]['Create Table'];
		if (false === str_contains($schema, 'min_infix_len')) {
			return false;
		}
		$this->cache->set($cacheKey, true, $this->ttl);
		return true;
	}
}
