<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Tool;

final class Buddy {
	const MOCK_VERSION = '1.0.0';

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
	 * This is helper to display debug info in debug mode
	 *
	 * @param string $message
	 * @param string $eol
	 * @return void
	 */
	public static function debug(string $message, string $eol = PHP_EOL): void {
		if (!getenv('DEBUG')) {
			return;
		}

		echo $message . $eol;
	}

	/**
	 * Get version that is read from the file we provided before
	 * Normally it's done on initialization stage of the Buddy base
	 * @return string
	 */
	public static function getVersion(): string {
		static $version;
		if (!isset($version)) {
			// We may need MOCK_VERSION to properly execute unit tests from plugin projects
			$version = isset(static::$versionFile)
				? trim((string)file_get_contents(static::$versionFile))
				: static::MOCK_VERSION;
		}
		return $version;
	}
}
