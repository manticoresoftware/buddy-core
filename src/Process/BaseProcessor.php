<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Process;

abstract class BaseProcessor {
	/** @var Process $process */
	protected Process $process;

	final public function __construct() {
		$this->process = Process::create($this);
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
		$this->process->destroy();
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
