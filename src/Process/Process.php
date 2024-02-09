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
	/**
	 * @param SwooleProcess $process
	 * @return void
	 */
	public function __construct(public readonly string $name, public readonly SwooleProcess $process) {
	}

	/**
	 * Create a new process based on the given instance
	 * @param  BaseProcessor $processor
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
		Buddy::debug("[process] execute: $method " . json_encode($args));
		$this->process->write(serialize([$method, $args]));
		return $this;
	}
}
