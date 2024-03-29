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
	/** @var Process $process */
	protected Process $process;
	protected Client $client;

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
	 * Initialization step in case if it's required to run once on Buddy start
	 * @return void
	 */
	public function start(): void {
		$this->process->start();
	}

	/**
	 * Shutdown step that we run once buddy is stopping
	 * @return void
	 */
	public function stop(): void {
		$this->process->stopWorkers();
		$this->process->destroy();
	}

	/**
	 * Add ticker to run periodicaly
	 * This is should not be called from the inside of the forked process
	 * due to some limitations
	 * @param callable    $fn
	 * @param int $period
	 * @return int identifier of the ticker
	 */
	public static function addTicker(callable $fn, int $period = 1): int {
		return Timer::tick(
			$period * 1000,
			$fn
		);
	}

	/**
	 * Stop given timerId returned by addTicker method
	 * This is should not be called from the inside of the forked process
	 * due to some limitations
	 * @param  int    $timerId
	 * @return bool
	 */
	public static function removeTicker(int $timerId): bool {
		return Timer::clear($timerId);
	}

	/**
	 * Just proxy to the internal process
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
