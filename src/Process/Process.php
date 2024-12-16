<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*
*/

namespace Manticoresearch\Buddy\Core\Process;

use Exception;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Strings;
use Swoole\Process as SwooleProcess;

/** @package Manticoresearch\Buddy\Core\Process */
final class Process {
	/** @var array<string,Worker> $workers */
	protected array $workers = [];

	/**
	 * @param SwooleProcess $process
	 * @return void
	 */
	public function __construct(public readonly SwooleProcess $process) {
	}

	/**
	 * Create a worker with given id
	 * @param  WorkerRunnerInterface $runner
	 * @param  string|null $id
	 * @return Worker
	 */
	public static function createWorker(WorkerRunnerInterface $runner, ?string $id = null): Worker {
		if (!isset($id)) {
			$id = uniqid();
		}

		return new Worker($id, $runner);
	}

	/**
	 * Add extra worker to current process, so base processor can control it
	 * @param Worker $worker
	 * @param bool $shouldStart if we should start instantly the worker
	 * @return static
	 */
	public function addWorker(Worker $worker, bool $shouldStart = false): static {
		if (isset($this->workers[$worker->id])) {
			throw new Exception("Failed to add worker with cuz id '{$worker->id}' exists already");
		}

		$this->workers[$worker->id] = $worker;
		if ($shouldStart) {
			$worker->start();
		}
		return $this;
	}

	/**
	 * Remove the given worker from the pool and stop it
	 * @param Worker $worker
	 * @return static
	 */
	public function removeWorker(Worker $worker): static {
		if (!isset($this->workers[$worker->id])) {
			throw new Exception("Missing worker with id '{$worker->id}' in the pool");
		}

		$worker = $this->workers[$worker->id];
		if ($worker->isRunning()) {
			$worker->stop();
		}
		unset($this->workers[$worker->id]);

		return $this;
	}

	/**
	 * Fetch worker from the pool
	 * @param  string $id
	 * @return Worker
	 */
	public function getWorker(string $id): Worker {
		if (!isset($this->workers[$id])) {
			throw new Exception("Missing worker with id '{$id}' in the pool");
		}

		return $this->workers[$id];
	}

	/**
	 * Get all workers we set
	 * @return array<string,Worker>
	 */
	public function getWorkers(): array {
		return $this->workers;
	}

	/**
	 * Start all workers that is not running
	 * @return static
	 */
	public function startWorkers(): static {
		foreach ($this->workers as $worker) {
			if ($worker->isRunning()) {
				continue;
			}
			$worker->start();
		}

		return $this;
	}

	/**
	 * Stop all workers that are running
	 * @return static
	 */
	public function stopWorkers(): static {
		foreach ($this->workers as $worker) {
			if (!$worker->isRunning()) {
				continue;
			}
			$worker->stop();
		}

		return $this;
	}

	/**
	 * Create a new process based on the given instance
	 * @param BaseProcessor $processor
	 * @return static
	 */
	public static function create(BaseProcessor $processor): static {
		$process = new SwooleProcess(
			static function (SwooleProcess $worker) use ($processor) {
				$name = Buddy::getProcessName(Strings::classNameToIdentifier($processor::class));
				swoole_set_process_name($name);
				chdir(sys_get_temp_dir());
				/** @phpstan-ignore-next-line */
				while (true) {
					$reader = ProcessReader::read($worker);
					foreach ($reader as $msg) {
						/** @var string $msg */
						$fn = $processor->parseMessage($msg);
						if (!$fn) {
							continue;
						}
						$fn();
					}
					usleep(100);
				}
			}, true, 2
		);
		return new static($process);
	}

	/**
	 * Start the created process that will handle all actions
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
		$this->stopWorkers();
		$this->execute('shutdown');
		return $this;
	}

	/**
	 * Execute the process event in a single one shot way
	 *
	 * @param string $method
	 *  Which method we want to execute
	 * @param mixed[] $args
	 *  Arguments that will be expanded to pass to the method
	 * @return static
	 */
	public function execute(string $method, array $args = []): static {
		Buddy::debugv("[process] execute: $method " . json_encode($args));
		$message = ProcessReader::packMessage([$method, $args]);
		$this->process->write($message);
		return $this;
	}
}
