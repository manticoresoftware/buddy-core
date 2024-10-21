<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Process;

use Manticoresearch\Buddy\Core\Tool\Strings;
use Swoole\Process as SwooleProcess;

/**
 * This is just wrapper to hide Swoole to external plugins
 */
final class Worker {
	/** @var SwooleProcess $process */
	protected readonly SwooleProcess $process;

	/**
	 * Create a new wrapper on givent closure that we will put into the swoole process
	 * @param string $id
	 * @param WorkerRunnerInterface $runner
	 * process to run
	 */
	final public function __construct(public readonly string $id, WorkerRunnerInterface $runner) {
		$workerFn = function () use ($runner) {
			pcntl_async_signals(true);
			pcntl_signal(SIGTERM, $runner->stop(...));
			$runner->init();
			$runner->run();
			$runner->stop();
		};
		$this->process = new SwooleProcess($workerFn);
		$this->process->name(Strings::classNameToIdentifier(static::class) . ' [' . $id . ']');
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
	 * Stop handle that actually send kill and process stop on its own
	 * @return static
	 */
	public function stop(): static {
		SwooleProcess::kill($this->process->pid, SIGTERM);
		return $this;
	}

	/**
	 * Get the current state of the worker if it's running
	 * @return bool
	 */
	public function isRunning(): bool {
		return !!SwooleProcess::kill($this->process->pid, 0);
	}
}
