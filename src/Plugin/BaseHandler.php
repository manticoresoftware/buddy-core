<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Plugin;

use Manticoresearch\Buddy\Core\Task\Task;

abstract class BaseHandler {
	/** @var array<string,array<callable>> $hooks */
	protected static array $hooks = [];

	/** @return Task */
	abstract public function run(): Task;

	/** @return array<string> */
	abstract public function getProps(): array;

	/**
	 * Register hook with closure processor that will receive its data and may do something
	 * @param string $name
	 * @param callable $fn
	 * @return void
	 */
	public static function registerHook(string $name, callable $fn): void {
		static::$hooks[$name] ??= [];
		static::$hooks[$name][] = $fn;
	}

	/**
	 * Process hook and pass the data that will be send to the callable function
	 * @param string $name name of hook to process
	 * @param array<mixed> $data Must be list of arguments to pass to a registered callable
	 * @return void
	 */
	protected static function processHook(string $name, array $data = []): void {
		if (!isset(static::$hooks[$name])) {
			return;
		}

		foreach (static::$hooks[$name] as $fn) {
			$fn(...$data);
		}
	}
}
