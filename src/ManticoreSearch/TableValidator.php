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
		$result = $this->client->sendRequest("SHOW TABLE $table SETTINGS")->getResult();
		if (isset($result['error'])) {
			TableValidationError::throw("no such table '{$table}'");
		}

		/** @var array{0:array{data:array<array{'Variable_name':string,'Value':string}>}} $result */
		$variables  = $result[0]['data'];
		foreach ($variables as $variable) {
			$name = $variable['Variable_name'];
			$value = $variable['Value'];
			if ($name === 'settings' && str_contains($value, 'min_infix_len')) {
				$this->cache->set($cacheKey, true, $this->ttl);
				return true;
			}
		}
		return false;
	}
}
