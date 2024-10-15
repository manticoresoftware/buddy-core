<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Network;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class Response {
  /**
	 * Initialize response with string message to be returned (json encoded) and bool flag setting if response has error
   * @param string $data
   * @param bool $hasError
   * @return void
   */
	public function __construct(protected string $data = '', protected bool $hasError = false) {
	}

  /**
	 * Check if the data is empty
   * @return bool
   */
	public function isEmpty(): bool {
		return $this->data === '';
	}

  /**
	 * Return hasError property
   * @return bool
   */
	public function hasError(): bool {
		return $this->hasError;
	}

  /**
	 * Detect if the data message contains information about error
	 * @param mixed $message
   * @return bool
   */
	private static function checkForError(mixed $message): bool {
		return (is_array($message) && !(empty($message['error']) && empty($message[0]['error'])))
		|| (is_string($message) && str_starts_with($message, TableFormatter::ERROR_PREFIX));
	}

	/**
	 * @param TaskResult $result
	 * @param RequestFormat $format
	 * @return static
	 */
	public static function fromResult(TaskResult $result, RequestFormat $format = RequestFormat::JSON): static {
		return static::fromMessageAndMeta($result->getStruct(), $result->getMeta(), $format);
	}

  /**
	 * Create response from the message when we have no error and success in respond
   * @see static::fromStringAndError()
   * @param mixed $message
   * @param RequestFormat $format
   * @return static
   */
	public static function fromMessage(mixed $message, RequestFormat $format = RequestFormat::JSON): static {
		return static::fromMessageAndError($message, [], null, $format);
	}

	/**
	 * @param mixed $message
	 * @param array<string,mixed> $meta
	 * @param RequestFormat $format
	 * @return static
	 */
	public static function fromMessageAndMeta(
		mixed $message = [],
		array $meta = [],
		RequestFormat $format = RequestFormat::JSON
	) {
		return static::fromMessageAndError($message, $meta, null, $format);
	}

  /**
	 * Create response from provided error, useful when we want to return error
   * @see static::fromStringAndError()
   * @param GenericError $error
   * @param RequestFormat $format
   * @return static
   */
	public static function fromError(GenericError $error, RequestFormat $format = RequestFormat::JSON): static {
		$errorMessage = $error->hasResponseErrorBody()
			? $error->getResponseErrorBody()
			: ['error' => $error->getResponseError()];
		return static::fromMessageAndError($errorMessage, [], $error, $format);
	}

  /**
	 * Helper to create empty response with nothing to response (shortcut to use)
   * @return static
   */
	public static function none(): static {
		return new static;
	}

  /**
	 * Create response from the message and include error to it also
   * @param mixed $message
	 * @param array<string,mixed> $meta
   * @param ?GenericError $error
   * @param RequestFormat $format
   * @return static
   */
	public static function fromMessageAndError(
		mixed $message = [],
		array $meta = [],
		?GenericError $error = null,
		RequestFormat $format = RequestFormat::JSON
	): static {
		$responseError = $error?->getResponseError();
		if ($responseError && is_array($message)) {
			if ($format === RequestFormat::JSON) {
				$message = [];
			}
			if ($error->hasResponseErrorBody()) {
				$message = $error->getResponseErrorBody() + $message;
			} else {
				$message['error'] = $responseError;
			}
		}
		$payload = [
			'version' => Buddy::PROTOCOL_VERSION,
			'type' => "{$format->value} response",
			'message' => $message,
			'meta' => $meta ?: null,
			'error_code' => $error?->getResponseErrorCode() ?? 200,
		];

		return new static(
			json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE),
			self::checkForError($message)
		);
	}

  /**
   * This magic helps us to keep things simple :)
   *
   * @return string
   */
	public function __toString(): string {
		return $this->data;
	}
}
