<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Network;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;

final class Response {
  /**
   * @param string $data
   * @return void
   */
	public function __construct(protected string $data = '') {
	}

  /**
   * @return bool
   */
	public function isEmpty(): bool {
		return $this->data === '';
	}

  /**
   * @see static::fromStringAndError()
   * @param mixed $message
   * @param RequestFormat $format
   * @return static
   */
	public static function fromMessage(mixed $message, RequestFormat $format = RequestFormat::JSON): static {
		return static::fromMessageAndError($message, null, $format);
	}

  /**
   * @see static::fromStringAndError()
   * @param GenericError $error
   * @param RequestFormat $format
   * @return static
   */
	public static function fromError(GenericError $error, RequestFormat $format = RequestFormat::JSON): static {
		return static::fromMessageAndError(
			[[
				'total' => 0,
				'warning' => '',
				'error' => $error->getResponseError(),
			],
			], $error, $format
		);
	}

  /**
   * @return static
   */
	public static function none(): static {
		return new static;
	}

  /**
   * @param mixed $message
   * @param ?GenericError $error
   * @param RequestFormat $format
   * @return static
   */
	public static function fromMessageAndError(
		mixed $message = [],
		?GenericError $error = null,
		RequestFormat $format = RequestFormat::JSON
	): static {
		$payload = [
			'version' => 1,
			'type' => "{$format->value} response",
			'message' => $message,
			'error' => $error?->getResponseError() ?? '',
		];

		return new static(
			json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE)
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
