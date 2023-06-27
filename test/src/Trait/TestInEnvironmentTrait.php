<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\CoreTest\Trait;

use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Tool\Buddy;

trait TestInEnvironmentTrait {

	/**
	 * We may need to set a mock Buddy version for plugin unit tests that use the Core\ManticoreSearch\Client class
	 *
	 * @return void
	 */
	public static function setBuddyVersion(): void {
		Buddy::setVersionFile(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'MOCK_APP_VERSION');
	}

	/**
	 * We may need to set a task runtime file for plugin unit tests that use the Core\Task\Task class
	 * 
	 * @return void
	 */
	public static function setTaskRuntime(): void {
		Task::init(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'runtime.php');
	}

}
