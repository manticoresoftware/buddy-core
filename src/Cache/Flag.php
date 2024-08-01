<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Cache;

use Swoole\Table;

/** @package Manticoresearch\Buddy\Core\Cache */
final class Flag {
	/** @var Table $cache */
	protected Table $cache;

	/**
	 * Initialize the cache with the given capacity
	 * @param int $capacity
	 * @return void
	 */
	public function __construct(int $capacity = 8192) {
		$this->cache = new Table($capacity);
		$this->cache->column('flag', Table::TYPE_INT, 1);
		$this->cache->column('expiry', Table::TYPE_INT, 8);
		$this->cache->create();
	}

	/**
	 * @param string $key
	 * @param bool $value
	 * @param int $ttl Time to live in seconds, zero means forever
	 * @return void
	 */
	public function set(string $key, bool $value, int $ttl = 0): void {
		$hashedKey = md5($key);
		$expiry = $ttl > 0 ? time() + $ttl : 0;
		$this->cache->set(
			$hashedKey, [
			'flag' => (int)$value,
			'expiry' => $expiry,
			]
		);
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public function get(string $key): bool {
		$hashedKey = md5($key);
		/** @var array{flag:int,expiry:int}|null $result */
		$result = $this->cache->get($hashedKey);
		if ($result) {
			if ($result['expiry'] > 0 && $result['expiry'] < time()) {
				$this->remove($key);
				return false;
			}
			return (bool)$result['flag'];
		}
		return false;
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public function has(string $key): bool {
		$hashedKey = md5($key);
		/** @var array{flag:int,expiry:int}|null $result */
		$result = $this->cache->get($hashedKey);
		if ($result) {
			if ($result['expiry'] > 0 && $result['expiry'] < time()) {
				$this->remove($key);
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * @param string $key
	 * @return void
	 */
	public function remove(string $key): void {
		$hashedKey = md5($key);
		$this->cache->delete($hashedKey);
	}

	/** @return void  */
	public function clear(): void {
		$this->cache->destroy();
		$this->cache->create();
	}

	/** @return int  */
	public function getCount(): int {
		return $this->cache->count();
	}
}
