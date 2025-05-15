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
	 * @var array<int,array<string,mixed>> $columnsPerRow
	 */
	protected array $columnsPerRow = [];

	/**
	 * @var array<array<string,mixed>> $data
	 */
	protected array $data = [];

	/** @var bool */
	protected bool $hasData = false;

	/**
	 * @var string $error
	 */
	protected string $error = '';

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
	 * @var bool $isMultipleRows
	 */
	protected bool $isMultipleRows = false;

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
		if ($this->isMultipleRows) {
			$this->data = array_map(function($rowData) use ($fn) {
				return array_map($fn, $rowData);
			}, $this->data);
		} else {
			$this->data = array_map($fn, $this->data);
		}
		return $this;
	}

	/**
	 * @param callable $fn
	 * @return static
	 */
	public function filterData(callable $fn): static {
		if ($this->isMultipleRows) {
			$this->data = array_map(function($rowData) use ($fn) {
				return array_filter($rowData, $fn);
			}, $this->data);
		} else {
			$this->data = array_filter($this->data, $fn);
		}
		return $this;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return static
	 */
	public function extendData(array $data): static {
		if ($this->isMultipleRows) {
			// If multiple rows, extend the first row by default
			if (!empty($this->data)) {
				$this->data[0] = array_merge($this->data[0], $data);
			} else {
				$this->data[] = $data;
			}
		} else {
			$this->data = array_merge($this->data, $data);
		}
		return $this;
	}

	/**
	 * Apply some function to the whole result
	 * @param callable $fn
	 * @return static
	 */
	public function apply(callable $fn): static {
		// We restruct it due to we unable to do unset and indirect modifications
		$this->result = Struct::fromData($fn($this->result->toArray()));
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

		// Update the result with any modified data
		if ($this->isMultipleRows) {
			// Handle multiple rows
			foreach ($this->result as $key => $row) {
				if (is_array($row) && isset($row['data']) && isset($this->data[$key])) {
					$item = $row;
					$item['data'] = $this->data[$key];
					$this->result[$key] = $item;
				}
			}
		} else {
			// Handle single row in array (original behavior)
			if (isset($this->result[0]['data'])) {
				$item = $this->result[0];
				$item['data'] = $this->data;
				$this->result[0] = $item;
			} elseif (isset($this->result['data'])) {
				// Handle single response without array wrapper
				$this->result['data'] = $this->data;
			}
		}

		return $this->result;
	}

	/**
	 * @return array<string,mixed>|array<array<string,mixed>>
	 */
	public function getData(): array {
		return $this->data;
	}

	/**
	 * Returns data row at specific index or the first/only row if no index specified
	 * @param int|null $rowIndex
	 * @return array<string,mixed>
	 */
	public function getDataRow(?int $rowIndex = null): array {
		if ($this->isMultipleRows) {
			if ($rowIndex !== null && isset($this->data[$rowIndex])) {
				return $this->data[$rowIndex];
			}
			return $this->data[0] ?? [];
		}
		return $this->data;
	}

	/**
	 * Get columns for a specific row
	 * @param int $idx Row index (defaults to 0 for backward compatibility)
	 * @return array<string,mixed>
	 */
	public function getColumns(int $idx = 0): array {
		return $this->columnsPerRow[$idx] ?? $this->columnsPerRow[0] ?? [];
	}

	/**
	 * Get all columns for all rows
	 * @return array<int,array<string,mixed>>
	 */
	public function getAllColumns(): array {
		return $this->columnsPerRow;
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
	 * @return bool
	 */
	public function hasMultipleRows(): bool {
		return $this->isMultipleRows;
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
			$this->body = $processor($this->body, $this->result, $this->getColumns(), ...$args);
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

		// Reset data collection
		$this->data = [];
		$this->columnsPerRow = [];
		$this->isMultipleRows = false;

		// Detect if we have multiple rows
		if ($struct->isList()) {
			$this->isMultipleRows = count($struct) > 1;

			if ($this->isMultipleRows) {
				// Extract data from each row
				foreach ($struct as $index => $rowData) {
					if (isset($rowData['data'])) {
						$this->data[$index] = $rowData['data'];
						// Set hasData if any row has data
						$this->hasData = true;
					} else {
						$this->data[$index] = [];
					}

					// Parse columns for each row
					$this->parseRowData($rowData, $index);

					// Get error and warning from the first row for backward compatibility
					if ($index === 0) {
						if (isset($rowData['error'])) {
							$this->error = $rowData['error'];
						}
						if (isset($rowData['warning'])) {
							$this->warning = $rowData['warning'];
						}
						if (isset($rowData['total'])) {
							$this->total = $rowData['total'];
						}
					}
				}
			} else {
				// Single row in an array - original behavior
				/** @var array<string,mixed> */
				$data = $struct[0];
				$this->parseRowData($data, 0);
				$structForBackwardCompatibility = Struct::fromData($data, $struct->getBigIntFields());

				// For backward compatibility - assign data from the first row
				if (isset($data['data'])) {
					$this->data = $data['data'];
				}
			}
		} else {
			// Handle direct object response (not in an array)
			$this->parseRowData($struct->toArray(), 0);

			// For backward compatibility
			if (isset($struct['data'])) {
				$this->data = $struct['data'];
			}
		}
	}

	/**
	 * Parse metadata from a row
	 * @param array<string,mixed> $rowData
	 * @param int $rowIndex
	 * @return void
	 */
	protected function parseRowData(array $rowData, int $rowIndex): void {
		// Check if this is type of response that is not our scheme
		$this->isRaw = !isset($rowData['warning']) &&
			(!isset($rowData['error']) || !is_string($rowData['error'] ?? null)) &&
			!isset($rowData['total']);

		// Assign only if not raw
		if ($this->isRaw) {
			return;
		}

		// Store row specific data
		if (isset($rowData['columns'])) {
			$this->columnsPerRow[$rowIndex] = $rowData['columns'];
		}

		// Set global data for the first row or single row case
		if ($rowIndex === 0) {
			$struct = Struct::fromData($rowData);
			$this->assign($struct, 'error')
				->assign($struct, 'warning')
				->assign($struct, 'total');

			// Set hasData flag
			$this->hasData = $this->hasData || isset($rowData['data']);
		}

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

