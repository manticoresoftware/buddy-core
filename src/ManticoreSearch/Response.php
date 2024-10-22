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
	 * @var array<string,mixed>
	 */
	protected array $result;

	/**
	 * @var array<string,mixed> $columns
	 */
	protected array $columns;

	/**
	 * @var array<string,mixed> $data
	 */
	protected array $data;

	/**
	 * @var ?string $error
	 */
	protected ?string $error;

	/**
	 * @var array<string,string> $meta
	 */
	protected array $meta = [];

	/**
	 * @param string $body
	 * @return void
	 */
	private function __construct(
		protected string $body
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
		return $this->body;
	}

	/**
	 * @param callable $fn
	 * @return static
	 */
	public function filterResult(callable $fn): static {
		$this->result = array_map($fn, $this->result);
		return $this;
	}

	/**
	 * Get parsed and json decoded reply from the Manticore daemon
	 * @return array<mixed>
	 */
	public function getResult(): array {
		if (!isset($this->result)) {
			throw new ManticoreSearchResponseError('Trying to access result with no response created');
		}
		return $this->result;
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
			$this->body = $processor($this->body, $this->result, $this->columns, ...$args);
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
		if (!$this->body) {
			throw new ManticoreSearchResponseError('Trying to parse empty response');
		}

		$result = json_decode($this->body, true);
		if (!is_array($result)) {
			throw new ManticoreSearchResponseError('Invalid JSON found');
		}

		$this->result = $result;
		if ($result && array_is_list($result)) {
			/** @var array<string,string> */
			$result = $result[0];
		}

		if (array_key_exists('error', $result) && is_string($result['error']) && $result['error'] !== '') {
			$this->error = $result['error'];
		} else {
			$this->error = null;
		}
		foreach (['columns', 'data'] as $prop) {
			if (!array_key_exists($prop, $result) || !is_array($result[$prop])) {
				continue;
			}
			$this->$prop = $result[$prop];
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
