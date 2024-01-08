<?php declare(strict_types=1);

/*
	Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 2 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Task;

use Closure;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;

/**
 * The most important thing for this class you should call
 * Task::init() first with runtime file and [init.php]
 * Task::setSettings() with passed settings before you can use anything [main.php]
 */
final class Task {
	/** @var int */
	protected int $id;

	/**
	 * This flag shows that this task is deffered and
	 * we can return response to client asap
	 *
	 * @var bool $isDeferred
	 */
	protected bool $isDeferred = false;

	/** @var array<string, array<callable>> */
	protected array $callbacks = [];

	/** @var int|false $future */
	protected int|false $future;

	/**
	 * Current task status
	 *
	 * @var TaskStatus $status
	 */
	protected TaskStatus $status;
	protected GenericError $error;
	protected TaskResult $result;

	/** @var ?Settings $settings */
	protected static ?Settings $settings = null;

	/**
	 * @param mixed[] $argv
	 * @return void
	 */
	public function __construct(protected array $argv = []) {
		$this->id = (int)(microtime(true) * 10000);
		$this->status = TaskStatus::Pending;
	}

	/**
	 * Set settings for usage in function run
	 * @param Settings $settings
	 * @return void
	 */
	public static function setSettings(Settings $settings): void {
		static::$settings = $settings;
	}

	/**
	 * Check if this task is deferred
	 * @return bool
	 */
	public function isDeferred(): bool {
		return $this->isDeferred;
	}

	/**
	 * Get current task ID
	 *
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * Main entry point to create a task
	 * @param Closure $fn
	 * @param mixed[] $argv
	 * @return static
	 */
	public static function create(Closure $fn, array $argv = []): static {
		return new static([$fn, $argv]);
	}

	/**
	 * Set task to be deferred
	 * @return static
	 */
	public function defer(): static {
		$this->isDeferred = true;
		return $this;
	}

	/**
	 * Launch the current task
	 *
	 * @return static
	 */
	public function run(): static {
		// Run callbacks before
		$this->processCallbacks('run');
		$this->status = TaskStatus::Running;
		$this->future = go(
			function (): void {
				try {
					[$fn, $argv] = $this->argv;
					/** @var Closure $fn */
					/** @var array<mixed> $argv */
					$this->result = $fn(...$argv);
				} catch (Throwable $t) {
					[$errorClass, $errorMessage] = [$t::class, $t->getMessage()];
					$e = new GenericError("$errorClass: $errorMessage");
					if ($errorMessage) {
						$e->setResponseError($errorMessage);
					}
					$this->error = $e;
				} finally {
					$this->status = TaskStatus::Finished;
					$this->processCallbacks();
				}
			}
		);

		if (!$this->future) {
			$this->status = TaskStatus::Failed;
			$this->error = new GenericError("Failed to run task: {$this->id}");
		}
		return $this;
	}

	/**
	 * Blocking call to wait till the task is finished
	 *
	 * @param bool $exceptionOnError
	 *  If we should throw exception in case of failure
	 * 	or just simply return the current status
	 * 	and give possibility to caller handle it and check
	 *
	 * @return TaskStatus
	 * @throws GenericError
	 */
	public function wait(bool $exceptionOnError = false): TaskStatus {
		Coroutine::join([$this->future]);
		if ($exceptionOnError && !$this->isSucceed()) {
			throw $this->getError();
		}

		return $this->status;
	}

	/**
	 * Get current status of launched task
	 *
	 * @return TaskStatus
	 */
	public function getStatus(): TaskStatus {
		return $this->status;
	}

	/**
	 * Shortcut to check if the task is still running
	 *
	 * @return bool
	 */
	public function isRunning(): bool {
		return $this->getStatus() === TaskStatus::Running;
	}

	/**
	 * @return bool
	 */
	public function isSucceed(): bool {
		return !$this->isRunning() && !isset($this->error);
	}

	/**
	 * Register callback that will be handled before execution
	 * Useful to run hooks or something like this
	 * @param string $ns One of success, failure or run
	 * @param callable $fn
   * @return static
   */
	public function on(string $ns, callable $fn): static {
		$this->callbacks[$ns] ??= [];
		$this->callbacks[$ns][] = $fn;
		return $this;
	}

	/**
	 * Process all callbacks if we have any
	 * @param ?string $ns
	 * @return static
	 */
	protected function processCallbacks(?string $ns = null): static {
		if (!isset($ns)) {
			$ns = isset($this->error) ? 'failure' : 'success';
		}
		$callbacks = $this->callbacks[$ns] ?? [];
		foreach ($callbacks as $fn) {
			$fn();
		}
		return $this;
	}

	/**
	 * Just getter for current error
	 *
	 * @return GenericError
	 * @throws RuntimeException
	 */
	public function getError(): GenericError {
		if (!isset($this->error)) {
			throw new RuntimeException('There error was not set, you should call isScucceed first.');
		}
		return $this->error;
	}

	/**
	 * Just getter for result of future
	 *
	 * @return TaskResult
	 * @throws RuntimeException
	 */
	public function getResult(): TaskResult {
		if (!isset($this->result)) {
			throw new RuntimeException('There result was not set, you should be sure that isSucceed returned true.');
		}

		return $this->result;
	}
}
