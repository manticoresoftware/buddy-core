<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Network\Struct;
use Throwable;

class Response {

	/**
	 * @var array<string,mixed> $data
	 */
	protected array $data;

	/**
	 * @var array<string,mixed> $columns
	 */
	protected array $columns;

	/**
	 * @var ?string $error
	 */
	protected ?string $error;

	/**
	 * @param ?string $body
	 * @return void
	 */
	public function __construct(
		protected ?string $body = null
	) {
		$this->parse();
	}

	/**
	 * @return ?string
	 */
	public function getError(): string|null {
		return $this->error;
	}

	/**
	 * @return string
	 */
	public function getBody(): string {
		return (string)$this->body;
	}

	/**
	 * Get parsed and json decoded reply from the Manticore daemon
	 * @return array<mixed>
	 */
	public function getResult(): array {
		return (array)json_decode($this->getBody(), true);
	}

	/**
	 * Check if we had error on performing our request
	 * @return bool
	 */
	public function hasError(): bool {
		return isset($this->error);
	}

	/**
	 * Run callable function on results and postprocess it with custom logic
	 * @param callable $processor
	 * @param array<mixed> $args
	 * @return void
	 * @throws ManticoreSearchResponseError
	 */
	public function postprocess(callable $processor, array $args = []): void {
		try {
			$this->body = $processor($this->body, $this->data, $this->columns, ...$args);
		} catch (Throwable $e) {
			throw new ManticoreSearchResponseError("Postprocessing function failed to run: {$e->getMessage()}");
		}
		$this->parse();
	}

	/**
	 * Parse the response into the struct
	 * @return void
	 * @throws ManticoreSearchResponseError
	 */
	protected function parse(): void {
		if (!isset($this->body)) {
			return;
		}
		$isValid = Struct::isValid($this->body);
		if (!$isValid) {
			throw new ManticoreSearchResponseError('Invalid JSON found');
		}

		$struct = Struct::fromJson($this->body);
		if ($struct->isList()) {
			/** @var array<string,mixed> */
			$data = $struct[0];
			$struct = Struct::fromData($data, $struct->getBigIntFields());
		}
		if ($struct->hasKey('error') && is_string($struct['error']) && $struct['error'] !== '') {
			$this->error = $struct['error'];
		} else {
			$this->error = null;
		}
		foreach (['columns', 'data'] as $prop) {
			if (!$struct->hasKey($prop) || !is_array($struct[$prop])) {
				continue;
			}
			$this->$prop = $struct[$prop];
		}
	}

	/**
	 * @param string $body
	 * @return self
	 */
	public static function fromBody(string $body): self {
		return new self($body);
	}
}
