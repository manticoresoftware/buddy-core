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
	const SYSTEM_METHODS = ['pause', 'resume'];

	/** @var Process $process */
	protected Process $process;
	protected Client $client;
	protected bool $isPaused = false;

	/**
	 * @param ?callable $initFn
	 */
	final public function __construct(?callable $initFn = null) {
		$this->process = Process::create($this, $initFn);
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
	 * Initialization step in case if it's required to run once on Buddy start
	 * @return void
	 */
	public function start(): void {
	}

	/**
	 * Shutdown step that we run once buddy is stopping
	 * @return void
	 */
	public function stop(): void {
		$this->process->stop();
	}

	/**
	 * Temporarely suspend the process
	 * This is method to use with execute only
	 * @return static
	 */
	public function pause(): static {
		$this->isPaused = true;
		return $this;
	}

	/**
	 * Parse and return callable function to run in case we able to do so
	 * and not yet paused, when paused - do nothing
	 * @param string $message received message from the process read function
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
	 * Add self-removable ticker to run periodicaly
	 * Due to some limitations it should be called for methods
	 * That returns true to remove and false when keep going
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
	 * Reserverd events: pause, resume
	 * @param  string $method
	 * @param  array<mixed>  $args
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
