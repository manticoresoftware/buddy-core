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
	 * @var array<int|string,mixed|array<int|string,mixed>> $data
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
			$this->data = array_map(
				function ($rowData) use ($fn) {
					/** @var array<mixed> $rowData */
					return array_map($fn, $rowData);
				}, $this->data
			);
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
			$this->data = array_map(
				function ($rowData) use ($fn) {
					/** @var array<mixed> $rowData */
					return array_filter($rowData, $fn);
				}, $this->data
			);
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
				/** @var array<string,mixed> $row */
				$row = $this->data[0];
				$this->data[0] = array_merge($row, $data);
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
			$this->updateMultipleRowsResult();
		} else {
			$this->updateSingleRowResult();
		}

		return $this->result;
	}

	/**
 * Update result for multiple rows case
 */
	private function updateMultipleRowsResult(): void {
		foreach ($this->result as $key => $row) {
			if (!is_array($row)) {
				continue;
			}

			/** @var array<string,mixed> $row */
			if (!isset($row['data']) || !isset($this->data[$key])) {
				continue;
			}

			$item = $row;
			$item['data'] = $this->data[$key];
			$this->result[$key] = $item;
		}
	}

	/**
 * Update result for single row case
 */
	private function updateSingleRowResult(): void {
		$resultArray = $this->result->toArray();

		if (is_array($resultArray)
			&& isset($resultArray[0])
			&& is_array($resultArray[0])
			&& isset($resultArray[0]['data'])) {
			// Handle single row in array
			$item = $resultArray[0];
			$item['data'] = $this->data;
			$this->result[0] = $item;
		} elseif (is_array($resultArray) && isset($resultArray['data'])) {
			// Handle single response without array wrapper
			$this->result['data'] = $this->data;
		}
	}

	/**
	 * @return array<int|string,mixed>
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
				/** @var array<string,mixed> */
				return $this->data[$rowIndex];
			}

			/** @var array<string,mixed> */
			return $this->data[0] ?? [];
		}
		/** @var array<string,mixed> */
		return $this->data[0] ?? [];
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
		/** @var array<int|string,mixed> $emptyData */
		$emptyData = [];
		$this->data = $emptyData;
		$this->columnsPerRow = [];
		$this->isMultipleRows = false;

		$this->parseStructure($struct);
	}

	/**
	 * @param Struct<int|string, mixed> $struct
	 * @return void
	 */
	protected function parseStructure(Struct $struct): void {
		// Detect if we have multiple rows
		if ($struct->isList()) {
			$this->isMultipleRows = sizeof($struct) > 1;

			if ($this->isMultipleRows) {
				$this->parseMultipleRows($struct);
			} else {
				$this->parseSingleRowList($struct);
			}
		} else {
			$this->parseDirectObject($struct);
		}
	}

	/**
	 * @param Struct<int|string, mixed> $struct
	 * @return void
	 */
	protected function parseSingleRowList(Struct $struct): void {
		// Single row in an array - original behavior
		$structArray = $struct->toArray();
		/** @var array<string,mixed> $data */
		$data = isset($structArray[0]) && is_array($structArray[0]) ? $structArray[0] : [];
		$this->parseRowData($data, 0);

		// For backward compatibility - assign data from the first row
		if (!isset($data['data']) || !is_array($data['data'])) {
			return;
		}

		/** @var array<string,mixed> $dataRow */
		$dataRow = $data['data'];
		$this->data[0] = $dataRow;
	}

	/**
	 * @param Struct<int|string, mixed> $struct
	 * @return void
	 */
	protected function parseDirectObject(Struct $struct): void {
		// Handle direct object response (not in an array)
		/** @var array<string,mixed> $structArray */
		$structArray = $struct->toArray();
		$this->parseRowData($structArray, 0);

		// For backward compatibility
		if (!isset($structArray['data']) || !is_array($structArray['data'])) {
			return;
		}

		/** @var array<string,mixed> $dataRow */
		$dataRow = $structArray['data'];
		$this->data[0] = $dataRow;
	}

	/**
	 * @param Struct<int|string, mixed> $struct
	 * @return void
	 */
	protected function parseMultipleRows(Struct $struct): void {
		// Extract data from each row
		foreach ($struct as $index => $rowData) {
			/** @var int $index */
			/** @var array<string,mixed> $rowData */
			$this->processRowData($rowData, $index);

			// Get error, warning, and total from the first row for backward compatibility
			if ($index !== 0) {
				continue;
			}

			$this->extractMetadata($rowData);
		}
	}

	/**
	 * @param array<string,mixed> $rowData
	 * @param int $index
	 * @return void
	 */
	protected function processRowData(array $rowData, int $index): void {
		if (isset($rowData['data']) && is_array($rowData['data'])) {
			/** @var array<string,mixed> $dataRow */
			$dataRow = $rowData['data'];
			$this->data[$index] = $dataRow;
			// Set hasData if any row has data
			$this->hasData = true;
		} else {
			$this->data[$index] = [];
		}

		// Parse columns for each row
		$this->parseRowData($rowData, $index);
	}

	/**
	 * @param array<string,mixed> $rowData
	 * @return void
	 */
	protected function extractMetadata(array $rowData): void {
		if (isset($rowData['error']) && is_string($rowData['error'])) {
			$this->error = $rowData['error'];
		}
		if (isset($rowData['warning']) && is_string($rowData['warning'])) {
			$this->warning = $rowData['warning'];
		}
		if (!isset($rowData['total'])) {
			return;
		}

		/** @var array{total:int|string} $rowData */
		$this->total = (int)$rowData['total'];
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
			(!isset($rowData['error'])) &&
			!isset($rowData['total']);

		// Assign only if not raw
		if ($this->isRaw) {
			return;
		}

		// Store row specific data
		if (isset($rowData['columns']) && is_array($rowData['columns'])) {
			/** @var array<string,mixed> $columns */
			$columns = $rowData['columns'];
			$this->columnsPerRow[$rowIndex] = $columns;
		}

		// Set global data for the first row or single row case
		if ($rowIndex !== 0) {
			return;
		}

		$struct = Struct::fromData($rowData);
		$structArray = $struct->toArray();

		// Handle error
		if (isset($structArray['error']) && is_string($structArray['error'])) {
			$this->error = $structArray['error'];
		}

		// Handle warning
		if (isset($structArray['warning']) && is_string($structArray['warning'])) {
			$this->warning = $structArray['warning'];
		}

		// Handle total
		if (isset($structArray['total']) && is_numeric($structArray['total'])) {
			$this->total = (int)$structArray['total'];
		}

		// Set hasData flag
		$this->hasData = $this->hasData || isset($rowData['data']);
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
		$structArray = $struct->toArray();
		if (isset($structArray[$key])) {
			// Type check and conversion
			if ($key === 'error' || $key === 'warning') {
				$this->$key = is_string($structArray[$key]) ? $structArray[$key] : '';
			} elseif ($key === 'total') {
				$this->$key = is_numeric($structArray[$key]) ? (int)$structArray[$key] : 0;
			}
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
