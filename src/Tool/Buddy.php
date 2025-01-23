<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Tool;

use Throwable;

/** @package Manticoresearch\Buddy\Core\Tool */
final class Buddy {

	const PROTOCOL_VERSION = 3;

	/** @var string */
	protected static string $versionFile;

	/**
	 * Set version file, that we will use to read the Buddy version
	 * @param string $file
	 * @return void
	 */
	public static function setVersionFile(string $file): void {
		static::$versionFile = $file;
	}


	/**
	 * Print INFO message, that is important to be in production also
	 *
	 * @param string $message
	 * @param string $eol
	 * @return void
	 */
	public static function info(string $message, string $eol = PHP_EOL): void {
		echo "[i] {$message} {$eol}";
	}

	/**
	 * This is helper to display debug info in debug mode
	 *
	 * @param string $message
	 * @param string $eol
	 * @param int $verbosity 1
	 * @return void
	 */
	public static function debug(string $message, string $eol = PHP_EOL, int $verbosity = 1): void {
		$debug = (int)getenv('DEBUG');
		if ($debug < $verbosity) {
			return;
		}

		echo "{$message} {$eol}";
	}

	/**
	 * Wrapper to display message with higher level of the verbosity
	 *
	 * @param string $message
	 * @param string $eol
	 * @return void
	 */
	public static function debugv(string $message, string $eol = PHP_EOL): void {
		static::debug($message, $eol, 2);
	}

	/**
	 * Warning that means you need to take a care about it but still continue
	 * @param string $message
	 * @param string $eol
	 * @return void
	 */
	public static function warning(string $message, string $eol = PHP_EOL): void {
		echo "[!] {$message} {$eol}";
	}

	/**
	 * This method write unrecoverable error to the log
	 * @param Throwable $t
	 * @param string $prefix
	 * @param string $eol
	 * @return void
	 */
	public static function error(Throwable $t, string $prefix = '', string $eol = PHP_EOL): void {
		$file = $t->getFile();
		$line = $t->getLine();
		$class = pathinfo($file, PATHINFO_FILENAME);
		$trace = $t->getTraceAsString();
		$prefix = $prefix ? "<$prefix> " : '';
		$callerInfo = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
		$callerFile = $callerInfo['file'] ?? 'unknown';
		$callerLine = $callerInfo['line'] ?? 'unknown';
		$callerClass = pathinfo($callerFile, PATHINFO_FILENAME);

		$message = "[X] <Thrown: $class:$line> <Logged: $callerClass:$callerLine> {$prefix}{$t->getMessage()} {$eol}";
		fwrite(STDERR, $message);
		Buddy::debugv($trace);
	}

	/**
	 * Get version that is read from the file we provided before
	 * Normally it's done on initialization stage of the Buddy base
	 * @return string
	 */
	public static function getVersion(): string {
		static $version;
		if (!isset($version)) {
			$version = trim((string)file_get_contents(static::$versionFile));
			// If this is template, detect
			if ($version[0] === '$') {
				$gitDir = dirname(static::$versionFile);
				try {
					$version = static::getVersionFromGit($gitDir);
				} catch (Throwable) {
					$version = 'x.x.x';
				}
			}
		}
		return $version;
	}

	/**
	 * @param string $gitDir
	 * @return string
	 */
	protected static function getVersionFromGit(string $gitDir): string {

		$latestTag = trim(
			(string)shell_exec(
				'cd ' . escapeshellarg($gitDir) .
					' && git describe --tags --abbrev=0 2>/dev/null'
			)
		);

		$commitsAfterTag = trim(
			(string)shell_exec(
				'cd ' . escapeshellarg($gitDir) .
					' && git rev-list ' . escapeshellarg($latestTag) . '..HEAD --count 2>/dev/null'
			)
		);

		$gitHead = trim(
			(string)shell_exec(
				'cd ' . escapeshellarg($gitDir) .
					' && git rev-parse --short=6 HEAD 2>/dev/null'
			)
		);

		$version = $latestTag ?: 'x.x.x';
		if ($commitsAfterTag && $commitsAfterTag !== '0') {
			$version .= '-' . $commitsAfterTag;
		}
		if ($gitHead) {
			$version .= '-g' . $gitHead;
		}
		return $version;
	}

	/**
	 * Get the process name with suffix and id if passed
	 *
	 * @param string $name The base name to use
	 * @param null|string $suffix
	 * @param null|int $id
	 * @return string
	 */
	public static function getProcessName(string $name, ?string $suffix = null, ?int $id = null) : string {
		if ($suffix) {
			$name .= '-' . $suffix;
		}
		if (isset($id)) {
			$name .= ' [' . $id . ']';
		}
		return $name;
	}
}
