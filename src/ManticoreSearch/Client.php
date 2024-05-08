<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */


namespace Manticoresearch\Buddy\Core\ManticoreSearch;

use Ds\Map;
use Ds\Vector;
use Exception;
use Generator;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RuntimeException;
use Swoole\Coroutine\Http\Client as HttpClient;

class Client {
	const CONTENT_TYPE_HEADER = "Content-Type: application/x-www-form-urlencoded\n";
	const URL_PREFIX = 'http://';
	const HTTP_REQUEST_TIMEOUT = 300;
	const DEFAULT_URL = 'http://127.0.0.1:9308';

	/**
	 * @var string $response
	 */
	protected string $response;

	/** @var string $host */
	protected string $host;

	/** @var int $port */
	protected int $port;

	/** @var string $buddyVersion */
	protected string $buddyVersion;

	/** @var Settings */
	protected Settings $settings;

	/** @var Vector<HttpClient> $connections */
	protected Vector $connections;

	/**
	 * Initialize the Client that will use provided
	 * @param ?Response $responseBuilder
	 * @param ?string $url
	 * @return void
	 */
	public function __construct(
		protected ?Response $responseBuilder = null,
		?string $url = null
	) {
		// If no url passed, set default one
		if (!$url) {
			$url = static::DEFAULT_URL;
		}
		$this->setServerUrl($url);
		$this->connections = new Vector();
		$this->buddyVersion = Buddy::getVersion();
	}

	/**
	 * Set Response Builder
	 * @param Response $responseBuilder
	 * @return static
	 */
	public function setResponseBuilder(Response $responseBuilder): static {
		$this->responseBuilder = $responseBuilder;
		return $this;
	}

	/**
	 * Set server URL of Manticore searchd to send requests to
	 * @param string $url it supports http:// prefixed and not
	 * @return static
	 */
	public function setServerUrl($url): static {
		if (str_starts_with($url, static::URL_PREFIX)) {
			$url = substr($url, strlen(static::URL_PREFIX));
		}
		$this->host = (string)strtok($url, ':');
		$this->port = (int)strtok(':');
		return $this;
	}

	/**
	 * Send the request where request represents the SQL query to be send
	 * @param string $request
	 * @param ?string $path
	 * @param bool $disableAgentHeader
	 * @param bool $isAsync
	 * @return Response
	 */
	public function sendRequest(
		string $request,
		?string $path = null,
		bool $disableAgentHeader = false,
		bool $isAsync = true,
	): Response {
		$t = microtime(true);
		if (!isset($this->responseBuilder)) {
			throw new RuntimeException("'responseBuilder' property of ManticoreHTTPClient class is not instantiated");
		}
		if ($request === '') {
			throw new ManticoreSearchClientError('Empty request passed');
		}
		if (!$path) {
			$path = Endpoint::Sql->value;
		}
		if (str_ends_with($path, 'bulk')) {
			$contentTypeHeader = 'application/x-ndjson';
		} else {
			$contentTypeHeader = 'application/x-www-form-urlencoded';
		}
		// We urlencode all the requests to the /sql endpoint
		if (str_starts_with($path, 'sql')) {
			$request = 'query=' . urlencode($request);
		}
		$userAgentHeader = $disableAgentHeader ? '' : "Manticore Buddy/{$this->buddyVersion}";
		$headers = [
			'Content-Type' => $contentTypeHeader,
			'User-Agent' => $userAgentHeader,
			'Connection' => 'close',
		];
		$method = $isAsync ? 'runAsyncRequest' : 'runSyncRequest';
		$this->response = $this->$method($path, $request, $headers);

		if ($this->response === '') {
			throw new ManticoreSearchClientError('No response passed from server');
		}
		$result = $this->responseBuilder->fromBody($this->response);
		$time = (int)((microtime(true) - $t) * 1000000);
		Buddy::debugv("[{$time}µs] manticore request: $request");
		return $result;
	}

	/**
	 * Run the async request that is not blocking and must be run inside a coroutine
	 * @param string $path
	 * @param string $request
	 * @param array<string,string> $headers
	 * @return string
	 */
	protected function runAsyncRequest(string $path, string $request, array $headers): string {
		$client = $this->getHttpClient();
		defer(
			function () use ($client) {
				$this->connections->push($client);
			}
		);
		$client->set(['timeout' => -1]);
		$client->setHeaders($headers);
		$client->post("/$path", $request);
		return $client->body;
	}

	/**
	 * Run the old styled sync client request that is blocking
	 * @param string $path
	 * @param string $request
	 * @param array<string,string> $headers
	 * @return string
	 */
	protected function runSyncRequest(string $path, string $request, array $headers): string {
		$contextOptions = [
			'http' => [
				'method' => 'POST',
				'header' => implode(
					"\r\n", array_map(
						fn($key, $value) => "$key: $value",
						array_keys($headers),
						$headers
					)
				),
				'content' => $request,
				'timeout' => -1, // No timeout
			],
		];
		$context = stream_context_create($contextOptions);
		$protocol = static::URL_PREFIX;
		$url = "{$protocol}{$this->host}:{$this->port}/$path";

		return (string)file_get_contents($url, false, $context);
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

	/**
	 * @param string $table
	 * @return bool
	 */
	public function hasTable(string $table): bool {
		/** @var array<array{total:int}>}> $res */
		$res = $this->sendRequest("SHOW TABLES LIKE '$table'")->getResult();
		return !!$res[0]['total'];
	}

	/**
	 * Get cached settings or fetch if not
	 * @return Settings
	 */
	public function getSettings(): Settings {
		if (!isset($this->settings)) {
			$this->settings = $this->fetchSettings();
		}
		return $this->settings;
	}

	/**
	 * Extractd logic to fetch manticore settings and store it in class property
	 * @return Settings
	 */
	protected function fetchSettings(): Settings {
		$resp = $this->sendRequest('SHOW SETTINGS', isAsync: false);
		/** @var array{0:array{columns:array<mixed>,data:array{Setting_name:string,Value:string}}} */
		$data = (array)json_decode($resp->getBody(), true);
		$settings = new Vector();
		foreach ($data[0]['data'] as ['Setting_name' => $key, 'Value' => $value]) {
			// If the key is plugin_dir check env first and after choose
			// most priority
			if ($key === 'common.plugin_dir') {
				$value = getenv('PLUGIN_DIR') ?: $value;
			}
			$settings->push(
				new Map(
					[
					'key' => $key,
					'value' => $value,
					]
				)
			);

			if ($key !== 'configuration_file') {
				continue;
			}

			Buddy::debug("using config file = '$value'");
			putenv("SEARCHD_CONFIG={$value}");
		}

		// Gather variables also
		$resp = $this->sendRequest('SHOW VARIABLES', isAsync: false);
		/** @var array{0:array{columns:array<mixed>,data:array{Setting_name:string,Value:string}}} */
		$data = (array)json_decode($resp->getBody(), true);
		foreach ($data[0]['data'] as ['Variable_name' => $key, 'Value' => $value]) {
			$settings->push(
				new Map(
					[
					'key' => $key,
					'value' => $value,
					]
				)
			);
		}

		// Finally build the settings
		return Settings::fromVector($settings);
	}

	/**
	 * Get HTTP client to communicate and cache it for future use
	 * @return HttpClient
	 */
	protected function getHttpClient(): HttpClient {
		if ($this->connections->isEmpty()) {
			$client = new HttpClient($this->host, $this->port);
		} else {
			$client = $this->connections->pop();
		}
		return $client;
	}

}
