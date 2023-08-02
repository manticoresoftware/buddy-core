<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Plugin;

use Closure;
use Manticoresearch\Buddy\Core\Task\Task;
use parallel\Runtime;
use RuntimeException;

abstract class BaseHandler {
	/** @var array<string,array<callable>> $hooks */
	protected static array $hooks = [];

	/** @var Closure */
	protected Closure $internalQueryProcessor;

	/** @return Task */
	abstract public function run(Runtime $runtime): Task;

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

	/**
	 * This method sets the interla query processor closure to allow us execute
	 * queries that uses other plugins internally without creating http
	 * and passing it all to the manticore daemon
	 * @param callable $fn [description]
	 * @return void
	 */
	public function setInternalQueryProcessor(callable $fn): void {
		$this->internalQueryProcessor = $fn;
	}

	/**
	 * The helper method to run query in internal mode
	 * @return callable
	 */
	protected function getInternalQuery(): callable {
		if (!isset($this->internalQueryProcessor)) {
			throw new RuntimeException('Internal Query Processor is not set');
		}

		return $this->internalQueryProcessor;
	}
}
