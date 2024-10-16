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
	 * @var array<string,string> $meta
	 */
	protected array $meta = [];

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
	 * @param array<string,string> $meta
	 * @return static
	 */
	public function setMeta(array $meta): static {
		$this->meta = $meta;
		return $this;
	}

	/**
	 * Return the meta data from the request
	 * @return array<string,string>
	 */
	public function getMeta(): array {
		return $this->meta;
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
		$data = json_decode($this->body, true);
		if (!is_array($data)) {
			throw new ManticoreSearchResponseError('Invalid JSON found');
		}
		if (empty($data)) {
			return;
		}
		if (array_is_list($data)) {
			/** @var array<string,string> */
			$data = $data[0];
		}
		if (array_key_exists('error', $data) && is_string($data['error']) && $data['error'] !== '') {
			$this->error = $data['error'];
		} else {
			$this->error = null;
		}
		foreach (['columns', 'data'] as $prop) {
			if (!array_key_exists($prop, $data) || !is_array($data[$prop])) {
				continue;
			}
			$this->$prop = $data[$prop];
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
