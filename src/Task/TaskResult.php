<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Task;

/**
 * Simple struct for task result data
 */
final class TaskResult {
	/**
	 * Initialize the result and set the message that represents response from the task
	 * @param array<mixed>|string $message
	 * @return void
	 */
	public function __construct(protected array|string $message = []) {
	}

	/**
	 * Set current message
	 * @param array<mixed>|string $message
	 * @return void
	 */
	public function setMessage(array|string $message): void {
		$this->message = $message;
	}

	/**
	 * Get previously set message
	 * @return array<mixed>|string
	 */
	public function getMessage(): array|string {
		return $this->message;
	}
}
