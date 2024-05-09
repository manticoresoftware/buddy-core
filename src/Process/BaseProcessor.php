<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Process;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Swoole\Timer;

abstract class BaseProcessor {
	const SYSTEM_METHODS = ['pause', 'resume', 'shutdown'];

	/** @var Process $process */
	protected Process $process;
	protected Client $client;
	protected bool $isPaused = false;

	final public function __construct() {
		$this->process = Process::create($this);
	}

	/**
	 * Set the client to the current process namespace
	 * @param Client $client
	 * @return static
	 */
	final public function setClient(Client $client): static {
		$this->client = $client;
		return $this;
	}

	/**
	 * Initialization step in case if it's required to run once on Buddy start.
	 * It returns timers to register.
	 * We should not invoke anything that must be invoked inside a coroutine here.
	 * All initialization should be done in a lazy load approach inside the forked process.
	 * @return array<array{0:callable,1:int}>
	 */
	public function start(): array {
		return [];
	}

	/**
	 * Shutdown step that we run once the application is stopping
	 * @return void
	 */
	public function stop(): void {
		$this->process->stop();
	}

	/**
	 * Temporarily suspend the process
	 * This is a method to be used with execute only
	 * @return static
	 */
	public function pause(): static {
		$this->isPaused = true;
		return $this;
	}

	/**
	 * Parse and return callable function to run in case we are able to do so
	 * and the process is not yet paused. When paused, do nothing.
	 * @param string $message Received message from the process read function
	 * @return ?callable
	 */
	public function parseMessage(string $message = ''): ?callable {
		$message = unserialize($message);
		if (!is_array($message)) {
			return null;
		}

		[$method, $args] = $message;

		// Always running for system methods to make them execute
		if ($this->isPaused && !in_array($method, static::SYSTEM_METHODS)) {
			return null;
		}

		return fn() => $this->$method(...$args);
	}

	/**
	 * This is method to use with execute only
	 * @return static
	 */
	public function resume(): static {
		$this->isPaused = false;
		return $this;
	}

	/**
	 * Shutdown the server from the loop
	 * @return void
	 */
	public function shutdown(): void {
		Timer::clearAll();
		$this->process->process->exit(0);
	}

	/**
	 * Add self-removable ticker to run periodically.
	 * Due to some limitations, it should be called for methods
	 * that return true to remove and false when keep going.
	 * @param callable    $fn
	 * @param int $period
	 * @return int identifier of the ticker
	 */
	public function addTicker(callable $fn, int $period = 1): int {
		$tickerFn = static function (int $timerId) use ($fn) {
			$result = $fn();
			if ($result !== true) {
				return;
			}

			Timer::clear($timerId);
		};
		return Timer::tick(
			$period * 1000,
			$tickerFn
		);
	}

	/**
	 * Just proxy to the internal process
	 * Reserved events: pause, resume
	 * @param  string $method
	 * @param  array<mixed> $args
	 * @return static
	 */
	public function execute(string $method, array $args = []): static {
		$this->process->execute($method, $args);
		return $this;
	}

	/**
	 * Get internal swoole process
	 * @return Process
	 */
	public function getProcess(): Process {
		return $this->process;
	}
}
