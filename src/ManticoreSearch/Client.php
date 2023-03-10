<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

use Exception;
use Generator;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RuntimeException;

class Client {
	const CONTENT_TYPE_HEADER = "Content-Type: application/x-www-form-urlencoded\n";
	const URL_PREFIX = 'http://';
	const HTTP_REQUEST_TIMEOUT = 1;
	const DEFAULT_URL = 'http://127.0.0.1:9308';

	/**
	 * @var string $response
	 */
	protected string $response;

	/** @var string $url */
	protected string $url;

	/** @var string $path */
	protected string $path;

	/** @var string $header */
	protected string $header;

	/** @var string $buddyVersion */
	protected string $buddyVersion;

	/**
	 * @param ?Response $responseBuilder
	 * @param ?string $url
	 * @param Endpoint $endpointBundle
	 * @return void
	 */
	public function __construct(
		protected ?Response $responseBuilder = null,
		?string $url = null,
		Endpoint $endpointBundle = Endpoint::Sql
	) {
		// If no url passed, set default one
		if (!$url) {
			$url = static::DEFAULT_URL;
		}
		$this->path = $endpointBundle->value;
		$this->setServerUrl($url);
		$this->buddyVersion = Buddy::getVersion();
		$this->header = static::CONTENT_TYPE_HEADER;
	}

	/**
	 * @param Response $responseBuilder
	 * @return void
	 */
	public function setResponseBuilder(Response $responseBuilder): void {
		$this->responseBuilder = $responseBuilder;
	}

	/**
	 * @param string $url it supports http:// prefixed and not
	 * @return void
	 */
	public function setServerUrl($url): void {
		// $origUrl = $url;
		if (!str_starts_with($url, self::URL_PREFIX)) {
			$url = self::URL_PREFIX . $url;
		}
		// ! we do not have filter extension in production version
		// if (!filter_var($url, FILTER_VALIDATE_URL)) {
		// throw new ManticoreSearchClientError("Malformed request url '$origUrl' passed");
		// }
		$this->url = $url;
	}

	/**
	 * @param string $request
	 * @param ?string $path
	 * @param bool $disableAgentHeader
	 * @return Response
	 */
	public function sendRequest(string $request, string $path = null, bool $disableAgentHeader = false): Response {
		$t = microtime(true);
		if (!isset($this->responseBuilder)) {
			throw new RuntimeException("'responseBuilder' property of ManticoreHTTPClient class is not instantiated");
		}
		if ($request === '') {
			throw new ManticoreSearchClientError('Empty request passed');
		}
		$path ??= $this->path;
		$prefix = (str_starts_with($path, 'sql') ? 'query=' : '');
		$fullReqUrl = "{$this->url}/$path";
		$agentHeader = $disableAgentHeader ? '' : "User-Agent: Manticore Buddy/{$this->buddyVersion}\n";
		$opts = [
			'http' => [
				'method'  => 'POST',
				'header'  => $this->header
					. $agentHeader
					. "Connection: close\n",
				'content' => $prefix . $request,
				'timeout' => static::HTTP_REQUEST_TIMEOUT,
				'ignore_errors' => true,
			],
		];
		$context = stream_context_create($opts);
		$result = file_get_contents($fullReqUrl, false, $context);

		if ($result === false) {
			throw new ManticoreSearchClientError("Cannot connect to server at $fullReqUrl");
		} else {
			$this->response = (string)$result;
			if ($this->response === '') {
				throw new ManticoreSearchClientError('No response passed from server');
			}
		}

		$result = $this->responseBuilder->fromBody($this->response);
		$time = (int)((microtime(true) - $t) * 1000000);
		Buddy::debug("[{$time}??s] manticore request: $request");
		return $result;
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function setPath(string $path): void {
		$this->path = $path ?: Endpoint::Sql->value;
	}

	/**
	 * @param string $header
	 * @return void
	 */
	public function setContentTypeHeader(string $header): void {
		$this->header = $header ? "Content-Type: $header\n" : static::CONTENT_TYPE_HEADER;
	}

	// Bunch of methods to help us reduce copy pasting, maybe we will move it out to separate class
	// but now it's totally fine to have 3-5 methods here for help
	// ---

	/**
	 * Get all tables for this instance by running SHOW TABLES
	 * And filter only required types or any if not specified
	 * @param array<string> $types
	 * @return Generator<array{0:string,1:string}>
	 *  Contains array with table and type as [table, type]
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function getAllTables(array $types = []): Generator {
		/** @var array<array{data:array<array{Type:string,Index:string}>}> $res */
		$res = $this->sendRequest('SHOW TABLES')->getResult();

		// TODO: still not changed to Table in manticore?
		$typesMap = array_flip($types);
		foreach ($res[0]['data'] as ['Type' => $type, 'Index' => $table]) {
			if ($typesMap && !isset($typesMap[$type])) {
				continue;
			}

			yield [$table, $type];
		}
	}

	/**
	 * Validate input tables and return all tables when empty array passed
	 * or validate and throw error if we have missing table in the list
	 * @param array<string> $tables
	 * @param array<string> $types
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 * @throws Exception
	 */
	public function validateTables(array $tables = [], array $types = []): array {
		// Request for all tables first to validate request
		$allTables = array_column(
			iterator_to_array($this->getAllTables($types)),
			0
		);

		// No tables passed? lock all
		if (!$tables) {
			$tables = $allTables;
		}

		// Validate that all tables exist
		$tablesDiff = array_diff($tables, $allTables);
		if ($tablesDiff) {
			throw new Exception(
				sprintf(
					'Query contains missing tables: %s',
					implode(', ', $tablesDiff)
				)
			);
		}

		return $tables;
	}
}
