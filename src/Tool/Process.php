<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Tool;

final class Process {

	/**
	 * Cross-platform function to get parent pid of manticore process
	 *
	 * @return int
	 */
	public static function getParentPid(): int {
		if (PHP_OS_FAMILY === 'Windows') {
			$pid = getmypid();  // child process ID
			$parentPid = (string)shell_exec("wmic process where (processid=$pid) get parentprocessid");
			$parentPid = explode("\n", $parentPid);
			$parentPid = (int)$parentPid[1];

			return $parentPid;
		}

		return posix_getppid();
	}

	/**
	 * Check wether process is running or not
	 *
	 * @param int $pid
	 * @return bool
	 */
	public static function exists(int $pid): bool {
		$isRunning = false;
		if (PHP_OS_FAMILY === 'Windows') {
			$out = [];
			exec("TASKLIST /FO LIST /FI \"PID eq $pid\"", $out);
			if (sizeof($out) > 1) {
				$isRunning = true;
			}
		} elseif (posix_kill($pid, 0)) {
			$isRunning = true;
		}
		return $isRunning;
	}

}
