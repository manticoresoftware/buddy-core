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

/** @package Manticoresearch\Buddy\Core\ManticoreSearch */
class Response {
	/**
	 * @var Struct<int|string, mixed>
	 */
	protected Struct $result;

	/** @var bool */
	protected bool $isRaw = false;

	/**
	 * @var array<string,mixed> $columns
	 */
	protected array $columns = [];

	/**
	 * @var array<string,mixed> $data
	 */
	protected array $data = [];

	/** @var bool */
	protected bool $hasData = false;

	/**
	 * @var string|array<string,string> $error
	 */
	protected string|array $error = '';

	/**
	 * @var string $warning
	 */
	protected string $warning = '';

	/**
	 * @var int $total
	 */
	protected int $total = 0;

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
	 * @return null|string|array<string,string>
	 */
	public function getError(): null|string|array {
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
	 * @return Struct<int|string, mixed>
	 */
	public function getResult(): Struct {
		if (!isset($this->result)) {
			throw new ManticoreSearchResponseError('Trying to access result with no response created');
		}
		// We should replace data as result of applying modifier functions
		// like filter, map or whatever
		// @phpstan-ignore-next-line
		if (isset($this->result[0]['data'])) {
			$item = $this->result[0];
			// @phpstan-ignore-next-line
			$item['data'] = $this->data;
			$result[0] = $item;
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
	 * @return int
	 */
	public function getTotal(): int {
		return $this->total;
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
		return !!$this->error;
	}

	/**
	 * Check if we had warning on performing our request
	 * @return bool
	 */
	public function hasWarning(): bool {
		return !!$this->warning;
	}

	/**
	 * @return bool
	 */
	public function hasData(): bool {
		return $this->hasData;
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
		$isValid = Struct::isValid($this->body);
		if (!$isValid) {
			throw new ManticoreSearchResponseError('Invalid JSON found');
		}

		$struct = Struct::fromJson($this->body);
		$this->result = $struct;
		if ($struct->isList()) {
			/** @var array<string,mixed> */
			$data = $struct[0];
			$struct = Struct::fromData($data, $struct->getBigIntFields());
		}

		$this->assign($struct, 'error')
			->assign($struct, 'warning')
			->assign($struct, 'total')
			->assign($struct, 'data')
			->assign($struct, 'columns');

		// A bit tricky but we need to know if we have data or not
		// For table formatter in current architecture
		$this->hasData = $struct->hasKey('data');

		// Check if this is type of response that is not our scheme
		// in this case we just may proxy it as is without any extra
		$this->isRaw = !$struct->hasKey('warning') &&
			!$struct->hasKey('error') &&
			!$struct->hasKey('total');
	}

	/**
	 * @return bool
	 */
	public function isRaw(): bool {
		return $this->isRaw;
	}

	/**
	 * @param Struct<int|string, mixed> $struct
	 * @param string $key
	 * @return static
	 */
	public function assign(Struct $struct, string $key): static {
		if ($struct->hasKey($key)) {
			$this->$key = $struct[$key];
		}
		return $this;
	}

	/**
	 * @param string $body
	 * @return self
	 */
	public static function fromBody(string $body): self {
		return new self($body);
	}
}
