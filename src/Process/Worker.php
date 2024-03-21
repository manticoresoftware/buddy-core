<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Process;

use Swoole\Process as SwooleProcess;

/**
 * This is just wrapper to hide Swoole to external plugins
 */
final class Worker {
	/** @var array<callable> */
	protected array $onStart = [];
	/** @var array<callable> */
	protected array $onStop = [];
	/** @var SwooleProcess $process */
	protected SwooleProcess $process;

	/**
	 * Create a new wrapper on givent closure that we will put into the swoole process
	 * @param callable $fn
	 * @param string $id
	 */
	final public function __construct(public readonly string $id, callable $fn) {
		$this->process = new SwooleProcess($fn);
	}

	/**
	 * Add closure that will be executed on start
	 * @param  callable $fn
	 * @return static
	 */
	public function onStart(callable $fn): static {
		$this->onStart[] = $fn;
		return $this;
	}


	/**
	 * Start the current process
	 * @return static
	 */
	public function start(): static {
		$this->process->start();
		foreach ($this->onStart as $fn) {
			$fn();
		}
		return $this;
	}

	/**
	 * Add closure that will be executedo n stop
	 * @param  callable $fn
	 * @return static
	 */
	public function onStop(callable $fn): static {
		$this->onStop[] = $fn;
		return $this;
	}

	/**
	 * Stop the current process
	 * @return static
	 */
	public function stop(): static {
		foreach ($this->onStop as $fn) {
			$fn();
		}
		$this->process->exit();
		return $this;
	}

	/**
	 * Get the current state of the worker if it's running
	 * @return bool
	 */
	public function isRunning(): bool {
		$status = $this->process->wait(false);
		return !$status;
	}
}
