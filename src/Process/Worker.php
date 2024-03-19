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
	/**
	 * @var string $id Unique identified for the worker,
	 *  assigned on the start for idnetification
	 */
	public readonly string $id;

	/** @var SwooleProcess $process */
	protected SwooleProcess $process;

	/**
	 * Create a new wrapper on givent closure that we will put into the swoole process
	 * @param callable $fn
	 * @return void
	 */
	final public function __construct(callable $fn) {
		$this->id = uniqid();
		$this->process = new SwooleProcess($fn);
	}

	/**
	 * Start the current process
	 * @return static
	 */
	public function start(): static {
		$this->process->start();
		return $this;
	}

	/**
	 * Stop the current process
	 * @return static
	 */
	public function stop(): static {
		$this->process->exit();
		return $this;
	}

	/**
	 * Get the current state of the worker if it's running
	 * @return bool
	 */
	public function isRunning(): bool {
		$status = $this->process->wait(false);
		return !!$status;
	}
}
