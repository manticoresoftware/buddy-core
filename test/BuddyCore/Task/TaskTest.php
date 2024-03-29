<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyUnitTest\Lib;

use Exception;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Task\TaskStatus;
use Manticoresearch\Buddy\CoreTest\Lib\BuddyRequestError;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use stdClass;

class TaskTest extends TestCase {

	use TestInEnvironmentTrait;

	public function testTaskParallelRunSucceed(): void {
		echo "\nTesting the task parallel run succeed\n";
		$task = Task::create(
			function (): TaskResult {
				usleep(2000000);
				return TaskResult::raw('ok');
			}
		);
		$this->assertEquals(false, $task->isDeferred());
		$this->assertEquals(TaskStatus::Pending, $task->getStatus());
		$task->run();
		// $this->assertEquals(TaskStatus::Running, $task->getStatus());
		$this->assertEquals(TaskStatus::Finished, $task->getStatus());
		$this->assertEquals(true, $task->isSucceed());
		$this->assertEquals('ok', $task->getResult()->getStruct());
	}

	public function testTaskParallelRunWithArgumentsSucceed(): void {
		echo "\nTesting the task parallel run with arguments succeed\n";
		$arg = new stdClass();
		$arg->name = 'test';
		$arg->value = 123;

		$task = Task::create(
			function (stdClass $arg): TaskResult {
				usleep(2000000);
				return TaskResult::raw((array)$arg);
			},
			[$arg]
		);

		$this->assertEquals(TaskStatus::Pending, $task->getStatus());
		$task->run();
		// $this->assertEquals(TaskStatus::Running, $task->getStatus());
		// usleep(2500000);
		$this->assertEquals(TaskStatus::Finished, $task->getStatus());
		$this->assertEquals(true, $task->isSucceed());
		$this->assertEquals((array)$arg, $task->getResult()->getStruct());
	}

	public function testTaskReturnsGenericErrorOnException(): void {
		echo "\nTesting the task's exception converts to generic error\n";
		$errorMessage = 'Here we go';
		$task = Task::create(
			function () use ($errorMessage): bool {
				throw new Exception($errorMessage);
			}
		);
		$this->assertEquals(TaskStatus::Pending, $task->getStatus());
		$task->run();
		// $this->assertEquals(TaskStatus::Running, $task->getStatus());
		// usleep(500000);
		$this->assertEquals(TaskStatus::Finished, $task->getStatus());
		$this->assertEquals(false, $task->isSucceed());
		$error = $task->getError();
		$this->assertEquals(true, $error instanceof GenericError);
		$this->assertEquals(Exception::class . ': ' . $errorMessage, $error->getMessage());
		$this->assertEquals($errorMessage, $error->getResponseError());
	}

	public function testTaskReturnsGenericErrorOnCustomException(): void {
		echo "\nTesting the task's custom exception converts to generic error\n";
		$errorMessage = 'Custom error message';
		$task = Task::create(
			function () use ($errorMessage): bool {
				throw new BuddyRequestError($errorMessage);
			}
		);
		$this->assertEquals(TaskStatus::Pending, $task->getStatus());
		$task->run();
		// $this->assertEquals(TaskStatus::Running, $task->getStatus());
		// usleep(500000);
		$this->assertEquals(TaskStatus::Finished, $task->getStatus());
		$this->assertEquals(false, $task->isSucceed());
		$error = $task->getError();
		$this->assertEquals(true, $error instanceof BuddyRequestError);
		$this->assertEquals($errorMessage, $error->getMessage());
		$this->assertEquals('Something went wrong', $error->getResponseError());
	}

	public function testTaskDeferredHasFLag(): void {
		echo "\nTesting the task parallel run has deferred flag\n";
		$task = Task::create(
			function (): TaskResult {
				usleep(2000000);
				return TaskResult::raw('ok');
			}
		)->defer();
		$this->assertEquals(true, $task->isDeferred());
		$this->assertEquals(TaskStatus::Pending, $task->getStatus());
		$task->run();
		// $this->assertEquals(TaskStatus::Running, $task->getStatus());
		// usleep(2500000);
		$this->assertEquals(TaskStatus::Finished, $task->getStatus());
		$this->assertEquals(true, $task->isSucceed());
		$this->assertEquals('ok', $task->getResult()->getStruct());
	}
}
