<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify

	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Error;

use InvalidArgumentException;

class DaemonLogError extends GenericError implements HasDaemonLogEntity {
	private const ALLOWED_SEVERITIES = ['INFO', 'WARN', 'ERROR', 'CRITICAL'];

	private ?string $daemonLogType = null;
	private ?string $daemonLogSeverity = null;
	private ?string $daemonLogMessage = null;

	/**
	 * @param string $responseError
	 * @param string $logMessage
	 * @param string $logType
	 * @param string $logSeverity
	 * @param bool $proxyOriginalError
	 * @return static
	 */
	public static function createWithLog(
		string $responseError,
		string $logMessage,
		string $logType,
		string $logSeverity = 'ERROR',
		bool $proxyOriginalError = false
	): static {
		$self = parent::create($responseError, $proxyOriginalError);

		$logSeverity = strtoupper($logSeverity);
		if ($logType === '' || $logMessage === '' || !in_array($logSeverity, self::ALLOWED_SEVERITIES, true)) {
			throw new InvalidArgumentException('Invalid daemon log entity.');
		}

		$self->daemonLogType = $logType;
		$self->daemonLogSeverity = $logSeverity;
		$self->daemonLogMessage = $logMessage;

		return $self;
	}

	/**
	 * @param string $responseError
	 * @param string $logMessage
	 * @param string $logType
	 * @param string $logSeverity
	 * @param bool $proxyOriginalError
	 * @return static
	 */
	public static function throwWithLog(
		string $responseError,
		string $logMessage,
		string $logType,
		string $logSeverity = 'ERROR',
		bool $proxyOriginalError = false
	): static {
		throw static::createWithLog($responseError, $logMessage, $logType, $logSeverity, $proxyOriginalError);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getDaemonLogEntity(): array {
		if ($this->daemonLogType === null || $this->daemonLogSeverity === null || $this->daemonLogMessage === null) {
			return [];
		}

		return [
			'type' => $this->daemonLogType,
			'severity' => $this->daemonLogSeverity,
			'message' => $this->daemonLogMessage,
		];
	}
}
