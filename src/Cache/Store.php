<?php declare(strict_types=1);

/*
	Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Cache;

use Swoole\Table;

/**
 * TTL cache for arbitrary serializable values.
 * Uses Swoole\Table for coroutine-safe cross-worker shared memory.
 * @package Manticoresearch\Buddy\Core\Cache
 */
final class Store {
	protected Table $cache;
	protected int $defaultTtl;

	/**
	 * @param int $defaultTtl Default TTL in seconds (0 = forever)
	 * @param int $capacity Max entries (must be power of 2 for Swoole\Table)
	 * @param int $valueSize Max serialized value size in bytes
	 */
	public function __construct(int $defaultTtl = 30, int $capacity = 4096, int $valueSize = 8192) {
		$this->defaultTtl = $defaultTtl;
		$this->cache = new Table($capacity);
		$this->cache->column('value', Table::TYPE_STRING, $valueSize);
		$this->cache->column('expiry', Table::TYPE_INT, 8);
		$this->cache->create();
	}

	/**
	 * @param string $key
	 * @param mixed $value Must be serializable
	 * @param int|null $ttl TTL in seconds, null = use default
	 */
	public function set(string $key, mixed $value, ?int $ttl = null): void {
		$ttl ??= $this->defaultTtl;
		$hashedKey = md5($key);
		$this->cache->set(
			$hashedKey, [
				'value' => serialize($value),
				'expiry' => $ttl > 0 ? time() + $ttl : 0,
			]
		);
	}

	/**
	 * @param string $key
	 * @return mixed Returns null if not found or expired
	 */
	public function get(string $key): mixed {
		$hashedKey = md5($key);
		/** @var array{value:string,expiry:int}|false $result */
		$result = $this->cache->get($hashedKey);
		if ($result === false) {
			return null;
		}
		if ($result['expiry'] > 0 && $result['expiry'] < time()) {
			$this->cache->delete($hashedKey);
			return null;
		}
		return unserialize($result['value']);
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public function has(string $key): bool {
		return $this->get($key) !== null;
	}

	/**
	 * @param string $key
	 */
	public function remove(string $key): void {
		$this->cache->delete(md5($key));
	}

	/** @return void */
	public function clear(): void {
		$this->cache->destroy();
		$this->cache->create();
	}
}
