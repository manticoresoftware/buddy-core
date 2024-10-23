<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Task;

use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;

/**
 * Simple struct for task result data
 */
final class TaskResult {
	/** @var array<mixed> */
	protected array $columns = [];

	/** @var int  */
	protected int $total = 0;

	/** @var mixed If we set this flag, we will return only data and skip all other fields */
	protected mixed $raw;

	/** @var array<string,mixed> */
	protected array $meta = [];

	/**
	 * Initialize the empty result
	 * @param array<mixed> $data
	 * @param string $error
	 * @param string $warning
	 *  It must contain HTTP error code that will be returned to client
	 * @return void
	 */
	private function __construct(
		protected array $data,
		protected string $error,
		protected string $warning
	) {
		$this->total = sizeof($data);
	}

	/**
	 * Entrypoint to create raw result, that we probably need in some cases
	 * For example, elastic like responses, cli tables and so on
	 * Prefer to not use raw when you return standard structure to the client response
	 * @param mixed $raw
	 * @return static
	 */
	public static function raw(mixed $raw): static {
		$obj = new static([], '', '');
		$obj->raw = $raw;
		return $obj;
	}

	/**
	 * Create new struct from a raw response of the Manticore to include meta in it also
	 * @param Response $response
	 * @return static
	 */
	public static function fromResponse(Response $response): static {
		if ($response->hasError()) {
			return new static([], $response->getError() ?? '', $response->getWarning() ?? '');
		}

		// No error
		$obj = new static($response->getData(), '', '');
		$obj->columns = $response->getColumns();
		$obj->meta = $response->getMeta();
		return $obj;
	}

	/**
	 * Entrypoint to the object creation with none of data
	 * @return static
	 */
	public static function none(): static {
		return new static([], '', '');
	}

	/**
	 * Entrypoint to the object creation with data
	 * @param array<mixed> $data
	 * @return static
	 */
	public static function withData(array $data): static {
		return new static($data, '', '');
	}

	/**
	 * Entrypoint to the object creation with a single row in the data
	 * @param array<mixed> $row
	 * @return static
	 */
	public static function withRow(array $row): static {
		return new static([$row], '', '');
	}

	/**
	 * Entrypoint to the object creation with total only, may be useful with affected rows only
	 * @param int $total
	 * @return static
	 */
	public static function withTotal(int $total): static {
		$obj = new static([], '', '');
		$obj->total = $total;
		return $obj;
	}

	/**
	 * Entrypoint to the object creation with error occurred
	 * @param string $error
	 * @return static
	 */
	public static function withError(string $error): static {
		return new static([], $error, '');
	}

	/**
	 * Entrypoint to the object creation with error occurred
	 * @param string $warning
	 * @return static
	 */
	public static function withWarning(string $warning): static {
		return new static([], '', $warning);
	}

	/**
	 * Set meta data for the current result
	 * @param array<string,mixed> $meta
	 * @return static
	 */
	public function meta(array $meta): static {
		$this->meta = $meta;
		return $this;
	}

	/**
	 * Set error for the current result
	 * @param string $error
	 * @return static
	 */
	public function error(string $error): static {
		$this->error = $error;
		return $this;
	}

	/**
	 * Set warning for the current result
	 * @param string $warning
	 * @return static
	 */
	public function warning(string $warning): static {
		$this->warning = $warning;
		return $this;
	}

	/**
	 * Set data for the current result
	 * @param array<mixed> $data
	 * @return static
	 */
	public function data(array $data): static {
		$this->data = $data;
		$this->total = sizeof($data);
		return $this;
	}

	/**
	 * Add single row to the final data structure
	 * @param array<mixed> $row
	 * @return static
	 */
	public function row(array $row): static {
		$this->data[] = $row;
		$this->total += 1;
		return $this;
	}

	/**
	 * Add new column to the current result
	 * @param string $name
	 * @param Column $type
	 * @return static
	 */
	public function column(string $name, Column $type): static {
		$this->columns[] = [
			$name => [
				'type' => $type->value,
			],
		];
		return $this;
	}

	/**
	 * Convert the initialized data into the final response encoded with JSON
	 * @return string
	 */
	public function toString(): string {
		$struct = $this->getStruct();
		return is_string($struct) ? $struct : (json_encode($struct) ?: '');
	}

	/**
	 * Get current meta for this result
	 * @return array<string,mixed>
	 */
	public function getMeta(): array {
		return $this->meta;
	}

	/**
	 * Get resulting struct without JSON encoding
	 * @return mixed
	 */
	public function getStruct(): mixed {
		if (isset($this->raw)) {
			return $this->raw;
		}
		$struct = [
			'total' => $this->total,
			'error' => '',
			'warning' => $this->warning,
		];

		if ($this->columns) {
			$struct['columns'] = $this->columns;
		}

		if ($this->data) {
			$struct['data'] = $this->data;
		}
		return $this->error ?
			[
				'error' => $this->error,
			] : [$struct]
		;
	}

	/**
	 * Get struct but in the way of formatted output
	 * @param int $startTime
	 * @return string
	 */
	public function getTableFormatted(int $startTime): string {
		$tableFormatter = new TableFormatter();
		return $tableFormatter->getTable($startTime, $this->data, $this->total, $this->error);
	}
}
