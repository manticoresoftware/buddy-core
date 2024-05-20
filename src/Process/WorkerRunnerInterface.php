<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Process;

/**
 * The worker runner is processed in the following way. First, we call init method and after run.
 * When we get some signal to stop it we call stop or stop is also called when run returned control to the main process.
 */
interface WorkerRunnerInterface {
	public function init(): void;
	public function run(): void;
	public function stop(): void;
}
