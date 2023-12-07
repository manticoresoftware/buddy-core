<?php declare(strict_types=1);

/*
	Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 2 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Task;

use Closure;
use RuntimeException;
use Swoole\Table;

/**
 * Simple container for running tasks
 */
final class TaskPool {
	/** @var Table */
	protected static Table $pool;

	/**
	 * Get current pool
	 * @return Table
	 */
	public static function pool(): Table {
		if (!isset(static::$pool)) {
			static::$pool = new Table(1024);
			static::$pool->column('id', Table::TYPE_STRING, 24);
			static::$pool->column('host', Table::TYPE_STRING, 24);
			static::$pool->column('body', Table::TYPE_STRING, 64);
			static::$pool->create();
		}
		return static::$pool;
	}

	/**
	 * Add new task to the pool, so we can understand what is running now
	 * @param string $id
	 * @param string $body
	 * @param string $host
	 * @return Closure
	 */
	public static function add(string $id, string $body, string $host = 'localhost'): Closure {
		if (static::pool()->exists($id)) {
			throw new RuntimeException("Task {$id} already exists");
		}

		static::pool()->set(
			$id, [
			'id' => substr($id, 0, 24),
			'host' => substr($host, 0, 24),
			'body' => substr($body, 0, 64),
			]
		);

		return static function () use ($id) {
			static::remove($id);
		};
	}

	/**
	 * Remove the specified task from the pool, so we will not count it when it's done
	 * @param string $id
	 * @return void
	 */
	public static function remove(string $id): void {
		if (!static::pool()->exists($id)) {
			throw new RuntimeException("Task {$id} does not exist");
		}
		static::pool()->delete($id);
	}

	/**
	 * Get all active tasks in the pool
	 *
	 * @return Table
	 */
	public static function getList(): Table {
		return static::pool();
	}

	/**
	 * Get total count of running tasks in a pool
	 *
	 * @return int
	 */
	public static function getCount(): int {
		return static::pool()->count();
	}
}
