<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Process;

use Manticoresearch\Buddy\Core\Tool\Buddy;
use Swoole\Process as SwooleProcess;

final class Process {
	/** @var array<Worker> $workers */
	protected array $workers;

	/**
	 * @param SwooleProcess $process
	 * @return void
	 */
	public function __construct(public readonly string $name, public readonly SwooleProcess $process) {
	}

	/**
	 * Add extra worker to current process, so base processor can control it
	 * @param callable $fn
	 * @param bool $shouldStart if we should start instantly the worker
	 * @param string $name
	 * @return Worker
	 */
	public function addWorker(callable $fn, bool $shouldStart = false, string $name = ''): Worker {
		$worker = new Worker($fn, $name);
		$this->workers[] = $worker;
		if ($shouldStart) {
			$worker->start();
		}
		return $worker;
	}

	/**
	 * Remove the given worker from the pool and stop it
	 * @param Worker $worker
	 * @return static
	 */
	public function removeWorker(Worker $worker): static {
		foreach ($this->workers as $k => $curWorker) {
			if ($curWorker->id !== $worker->id) {
				continue;
			}

			if ($curWorker->isRunning()) {
				$curWorker->stop();
			}
			unset($this->workers[$k]);
			break;
		}

		return $this;
	}

	/**
	 * Get all workers we set
	 * @return array<Worker>
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
				chdir(sys_get_temp_dir());
				while ($msg = $worker->read()) {
					if (!is_string($msg)) {
						throw new \Exception('Incorrect data received');
					}
					$msg = unserialize($msg);
					if (!is_array($msg)) {
						throw new \Exception('Incorrect data received');
					}
					[$method, $args] = $msg;
					$processor->$method(...$args);
				}
			}, true, 2
		);

		return new static($processor::class, $process);
	}

	/**
	 * @return void
	 */
	public function destroy(): void {
		$this->process->exit();
	}

	/**
	 * Start the created process that will handle all actions
	 * @return self
	 */
	public function start(): self {
		$this->process->start();
		return $this;
	}

	/**
	 * Executor of the Metric component in separate thread
	 *
	 * @param string $method
	 *  Which method we want to execute
	 * @param mixed[] $args
	 *  Arguments that will be expanded to pass to the method
	 * @return static
	 */
	public function execute(string $method, array $args = []): static {
		Buddy::debugv("[process] execute: $method " . json_encode($args));
		$this->process->write(serialize([$method, $args]));
		return $this;
	}
}
