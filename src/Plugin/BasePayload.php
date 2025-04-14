<?php declare(strict_types=1);

/*
 Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Plugin;

use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Process\BaseProcessor;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;

/**
 * @phpstan-template T of array
 */
abstract class BasePayload {
	protected Settings $manticoreSettings;

	/**
	 * Minimum required version of the pluggable system
	 * Override this in child classes if they require a newer version
	 * @return int
	 */
	public static function getRequiredVersion(): int {
		return 1;
	}

	/** @var SqlQueryParser<T> */
	public static SqlQueryParser $sqlQueryParser;
	/**
	 * Get processors that plugin supports, list of classes
	 * @return array<BaseProcessor>
	 */
	public static function getProcessors(): array {
		return [];
	}

	/**
	 * Get info about this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return '';
	}

	/**
	 * Set current settings to use in request
	 *
	 * @param Settings $manticoreSettings
	 * @return static
	 */
	public function setSettings(Settings $manticoreSettings): static {
		$this->manticoreSettings = $manticoreSettings;
		return $this;
	}

	/**
	 * Get current settings
	 * @return Settings
	 */
	public function getSettings(): Settings {
		return $this->manticoreSettings;
	}

	/**
	 * Create payload from the HTTP request from Manticore that we parsed at Buddy base level
	 * @param Request $request
	 * @return static
	 */
	abstract public static function fromRequest(Request $request): static;

	/**
	 * This method validates if this request suites for our plugin or not
	 * @param Request $request
	 * @return bool
	 */
	abstract public static function hasMatch(Request $request): bool;

	/**
	 * Get handler class name that points to default one Handler in the same ns
	 * @return string
	 */
	public function getHandlerClassName(): string {
		$class = static::class;
		$ns = substr($class, 0, strrpos($class, '\\') ?: 0);
		return "$ns\\Handler";
	}

	/**
	 * @param SqlQueryParser<T> $sqlQueryParser
	 * @return void
	 */
	public static function setParser(SqlQueryParser $sqlQueryParser): void {
		static::$sqlQueryParser = $sqlQueryParser;
	}
}
