<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Task;

/**
 * Simple struct for task result data
 */
final class TaskResult {
	/** @var array<mixed> */
	protected array $columns = [];

	/** @var int  */
	protected int $total = 0;

	/** @var string|array<mixed> If we set this flag, we will return only data and skip all other fields */
	protected string|array $raw;

	/**
	 * Initialize the empty result
	 * @param array<mixed> $data
	 * @param string $error
	 * @param string $warning
	 * @return void
	 */
	public function __construct(protected array $data, protected string $error, protected string $warning) {
		$this->total = sizeof($data);
	}

	/**
	 * Entrypoint to create raw result, that we probably need in some cases
	 * For example, elastic like responses, cli tables and so on
	 * Prefer to not use raw when you return standard structure to the client response
	 * @param string|array<mixed> $raw
	 * @return static
	 */
	public static function raw(string|array $raw): static {
		$obj = new static([], '', '');
		$obj->raw = $raw;
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
	 * Entrypoint to the object creation with error ocurred
	 * @param string $error
	 * @return static
	 */
	public static function withError(string $error): static {
		return new static([], $error, '');
	}

	/**
	 * Entrypoint to the object creation with error ocurred
	 * @param string $warning
	 * @return static
	 */
	public static function withWarning(string $warning): static {
		return new static([], '', $warning);
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
	 * Get resulting struct without JSON encoding
	 * @return string|array<mixed>|array{0:array{columns?:array<mixed>,data?:array<mixed>,total:int,error:string,warning:string}}
	 */
	public function getStruct(): string|array {
		if (isset($this->raw)) {
			return $this->raw;
		}
		$struct = [
			'total' => $this->total,
			'error' => $this->error,
			'warning' => $this->warning,
		];

		if ($this->columns) {
			$struct['columns'] = $this->columns;
		}

		if ($this->data) {
			$struct['data'] = $this->data;
		}
		return [$struct];
	}
}
