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
	 * @var array<int|string,mixed>
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
	 * @var ?string $warning
	 */
	protected ?string $warning;

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
	public function getError(): ?string {
		return $this->error;
	}

	/**
	 * @return ?string
	 */
	public function getWarning(): ?string {
		return $this->warning;
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
	public function mapData(callable $fn): static {
		$this->data = array_map($fn, $this->data);
		return $this;
	}

	/**
	 * @param callable $fn
	 * @return static
	 */
	public function filterData(callable $fn): static {
		$this->data = array_filter($this->data, $fn);
		return $this;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return static
	 */
	public function extendData(array $data): static {
		$this->data = array_merge($this->data, $data);
		return $this;
	}

	/**
	 * Apply some function to the whole result
	 * @param callable $fn
	 * @return static
	 */
	public function apply(callable $fn): static {
		$this->result = $fn($this->result);
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
		// We should replace data as result of applying modifier functions
		// like filter, map or whatever
		// @phpstan-ignore-next-line
		if (isset($this->result[0]['data'])) {
			$this->result[0]['data'] = $this->data;
		} else {
			$this->result['data'] = $this->data;
		}

		return $this->result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getData(): array {
		return $this->data;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getColumns(): array {
		return $this->columns;
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
	 * Check if we had warning on performing our request
	 * @return bool
	 */
	public function hasWarning(): bool {
		return isset($this->warning);
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

		$this->assign($result, 'error');
		$this->assign($result, 'warning');

		foreach (['columns', 'data'] as $prop) {
			if (!array_key_exists($prop, $result) || !is_array($result[$prop])) {
				continue;
			}
			$this->$prop = $result[$prop];
		}
	}

	/**
	 * @param array<string,mixed> $result
	 * @param string $key
	 * @return void
	 */
	public function assign(array $result, string $key): void {
		if (array_key_exists($key, $result) && is_string($result[$key]) && $result[$key] !== '') {
			$this->$key = $result[$key];
		} else {
			$this->$key = null;
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
