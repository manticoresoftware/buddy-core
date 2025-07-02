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
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\Arrays;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use RuntimeException;
use Swoole\ConnectionPool;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Coroutine\WaitGroup;
use Swoole\Lock;

/**
 * @phpstan-type Variation array{original:string,keywords:array<string>}
 */
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

	/** @var ?string $authToken */
	protected ?string $authToken = null;

	/** @var string $buddyVersion */
	protected string $buddyVersion;

	/** @var Settings */
	protected Settings $settings;

	/** @var ConnectionPool $connectionPool */
	protected ConnectionPool $connectionPool;

	/** @var Map<string,Client> */
	protected Map $clientMap;

	/** @var bool $forceSync */
	protected bool $forceSync = false;

	/**
	 * Initialize the Client that will use provided
	 * @param ?string $url
	 * @param ?string $authToken
	 * @return void
	 */
	public function __construct(?string $url = null, ?string $authToken = null) {
		// If no url passed, set default one
		if (!$url) {
			$url = static::DEFAULT_URL;
		}
		$this->setServerUrl($url);
		$this->setAuthToken($authToken);
		$this->connectionPool = new ConnectionPool(
			function () {
				$client = new HttpClient($this->host, $this->port);
				$client->set(['timeout' => -1]);
				return $client;
			}
		);
		$this->buddyVersion = Buddy::getVersion();
		$this->clientMap = new Map;
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
	 * @param ?string $authToken
	 * @return static
	 */
	public function setAuthToken(?string $authToken): static {
		$this->authToken = $authToken;
		return $this;
	}

	/**
	 * Get current server url
	 * @return string
	 */
	public function getServerUrl(): string {
		return static::URL_PREFIX . $this->host . ':' . $this->port;
	}

	/**
	 * Send the request where request represents the SQL query to be send
	 * @param string $request
	 * @param ?string $path
	 * @param bool $disableAgentHeader
	 * @return Response
	 */
	// @phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh
	public function sendRequest(
		string $request,
		?string $path = null,
		bool $disableAgentHeader = false,
		bool $disableShowMeta = false,
	): Response {
		$t = microtime(true);
		if ($request === '') {
			throw new ManticoreSearchClientError('Empty request passed');
		}
		if (!$path) {
			$path = Endpoint::Sql->value;
		}
		if (str_ends_with($path, 'bulk')) {
			$contentTypeHeader = 'application/x-ndjson';
		} elseif (str_ends_with($path, 'search')) {
			$contentTypeHeader = 'application/json';
		} else {
			$contentTypeHeader = 'application/x-www-form-urlencoded';
		}
		$showMeta = false;
		// We urlencode all the requests to the /sql endpoint
		if (str_starts_with($path, 'sql') && !$disableAgentHeader) {
			// Disabling show meta is a temporary workaround to be able to use 'sql' instead of 'sql?mode=raw'
			// for getting correct values of JSON nested fields until that's fixed in daemon
			$showMeta = !$disableShowMeta && stripos(trim($request), 'SELECT') === 0;
			if ($showMeta) {
				$request .= ';SHOW META';
			}
			$request = 'query=' . rawurlencode($request);
		}
		$userAgentHeader = $disableAgentHeader ? '' : "Manticore Buddy/{$this->buddyVersion}";
		$headers = [
			'Content-Type' => $contentTypeHeader,
			'User-Agent' => $userAgentHeader,
		];
		// Add authorization header if we have token
		if (isset($this->authToken)) {
			$headers['Authorization'] = "Bearer {$this->authToken}";
		}
		$isAsync = Coroutine::getCid() > 0;
		$method = !$this->forceSync && $isAsync ? 'runAsyncRequest' : 'runSyncRequest';
		$response = $this->$method($path, $request, $headers);

		// TODO: rethink and make it better without double json_encode
		$result = Response::fromBody($response);
		if ($showMeta) {
			$struct = $result->getResult();
			$array = $struct->toArray();
			// TODO: Not sure what reason, but in sync request we have only one response
			// But this is should not be blocker for meta, we just will not have it
			if (sizeof($array) > 1) {
				/** @var array{data?:array<array{Variable_name:string,Value:string}>} */
				$metaRow = array_pop($array);
				$response = Struct::fromData($array, $struct->getBigIntFields())->toJson();
				$result = Response::fromBody($response);
			}
			$metaVars = $metaRow['data'] ?? [];
			$meta = [];
			foreach ($metaVars as ['Variable_name' => $name, 'Value' => $value]) {
				$meta[$name] = $value;
			}
			$result->setMeta($meta);
		}

		$this->response = $response;
		$time = (int)((microtime(true) - $t) * 1000000);
		Buddy::debugvv("[{$time}µs] manticore request: $request");
		return $result;
	}

	/**
	 * Send multiple requests with async and get all responses in single run
	 * @param array<array{url:string,request:string,path?:string,disableAgentHeader?:bool}> $requests
	 * @return array<Response>
	 */
	public function sendMultiRequest(array $requests): array {
		if (sizeof($requests) === 1) {
			$request = array_pop($requests);
			$response = $this->sendRequestToUrl(...$request);
			return [$response];
		}

		$wg = new WaitGroup();
		$responses = [];
		$mutex = new Lock();

		foreach ($requests as $request) {
			$wg->add();
			Coroutine::create(
				function () use ($wg, &$responses, $mutex, $request) {
					try {
						$response = $this->sendRequestToUrl(...$request);
						$mutex->lock();
						$responses[] = $response;
						$mutex->unlock();
					} finally {
						$wg->done();
					}
				}
			);
		}

		$wg->wait();
		return $responses;
	}

	/**
	 * Helper function that let us to send request to the specified url and setit back to original
	 * @param string $url
	 * @param string $request
	 * @param ?string $path
	 * @param bool $disableAgentHeader
	 * @return Response
	 */
	public function sendRequestToUrl(
		string $url,
		string $request,
		?string $path = null,
		bool $disableAgentHeader = false
	): Response {
		$client = $this->getClientForUrl($url);
		return $client->sendRequest($request, $path, $disableAgentHeader);
	}

	/**
	 * @param string $url
	 * @return Client
	 */
	protected function getClientForUrl(string $url): Client {
		if (!$url) {
			return $this;
		}

		if (!isset($this->clientMap[$url])) {
			$this->clientMap[$url] = new Client($url);
		}

		/** @var Client */
		return $this->clientMap[$url];
	}

	/**
	 * Force to use sync client instead of async detection
	 * @param bool $value
	 * @return static
	 */
	public function setForceSync(bool $value = true): static {
		$this->forceSync = $value;
		return $this;
	}

	/**
	 * Run the async request that is not blocking and must be run inside a coroutine
	 * @param string $path
	 * @param string $request
	 * @param array<string,string> $headers
	 * @return string
	 */
	protected function runAsyncRequest(string $path, string $request, array $headers): string {
		$try = 0;
		request: $client = $this->connectionPool->get();
		/** @var HttpClient $client */
		$headers['Connection'] = 'keep-alive';
		$client->setMethod('POST');
		$client->setHeaders($headers);
		$client->setData($request);
		$client->execute("/$path");
		if ($client->errCode) {
			/** @phpstan-ignore-next-line */
			if ($client->errCode !== 104 || $try >= 3) {
				$error = "Error while async request: {$client->errCode}: {$client->errMsg}";
				throw new ManticoreSearchClientError($error);
			}

			Buddy::debug('Client: connection reset by peer, repeat: ' . (++$try));
			$client->close();
			goto request;
		}
		$result = $client->body;
		$this->connectionPool->put($client);
		return $result;
	}

	/**
	 * Run the old styled sync client request that is blocking
	 * @param string $path
	 * @param string $request
	 * @param array<string,string> $headers
	 * @return string
	 */
	protected function runSyncRequest(string $path, string $request, array $headers): string {
		$headers['Connection'] = 'close';
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
				'ignore_errors' => true,
			],
		];
		$context = stream_context_create($contextOptions);
		$protocol = static::URL_PREFIX;
		$url = "{$protocol}{$this->host}:{$this->port}/$path";

		try {
			return (string)file_get_contents($url, false, $context);
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();

			// Get the HTTP error code
			$httpCode = 0;
			// @phpstan-ignore-next-line
			if (isset($http_response_header)) {
				// @phpstan-ignore-next-line
				$parts = explode(' ', $http_response_header[0]);
				if (sizeof($parts) > 1) {
					$httpCode = (int)$parts[1];
				}
			}
			$errorMessage = "Error while sync request: {$httpCode}: {$errorMessage}";
			throw new ManticoreSearchClientError($errorMessage);
		}
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

		$typesMap = array_flip($types);
		foreach ($res[0]['data'] as ['Type' => $type, 'Table' => $table]) {
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
		/** @var array{error:string}> $res */
		$res = $this->sendRequest("DESC $table")->getResult();
		return !$res['error'];
	}

	/**
	 * Get all shards for current distributed table from the schema
	 * @param string $table
	 * @return array<array{name:string,url:string}>
	 * @throws RuntimeException
	 */
	public function getTableShards(string $table): array {
		[$locals, $agents] = $this->parseTableShards($table);

		$shards = [];
		// Add locals first
		foreach ($locals as $t) {
			$shards[] = [
				'name' => $t,
				'url' => '',
			];
		}
		// Add agents after
		foreach ($agents as $agent) {
			$ex = explode('|', $agent);
			$host = strtok($ex[0], ':');
			$port = (int)strtok(':');
			$t = strtok(':');
			$shards[] = [
				'name' => (string)$t,
				'url' => "$host:$port",
			];
		}
		$map[$table] = $shards;
		return $shards;
	}

	/**
	 * Helper to parse shards and return local and remote agents for current table
	 * @param string $table
	 * @return array{0:array<string>,1:array<string>}
	 */
	protected function parseTableShards($table): array {
		/** @var array{0:array{data:array<array{"Create Table":string}>}} */
		$res = $this->sendRequest("SHOW CREATE TABLE $table OPTION force=1")->getResult();
		$tableSchema = $res[0]['data'][0]['Create Table'] ?? '';
		if (!$tableSchema) {
			throw new RuntimeException("There is no such table: {$table}");
		}
		if (!str_contains($tableSchema, "type='distributed'")) {
			throw new RuntimeException('The table is not distributed');
		}

		if (!preg_match_all("/local='(?P<local>[^']+)'|agent='(?P<agent>[^']+)'/ius", $tableSchema, $m)) {
			throw new RuntimeException('Failed to match tables from the schema');
		}
		return [
			array_filter($m['local']),
			array_filter($m['agent']),
		];
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
		$resp = $this->sendRequest('SHOW SETTINGS');
		/** @var array{0:array{columns:array<mixed>,data:array{Setting_name:string,Value:string}}} */
		$data = (array)simdjson_decode($resp->getBody(), true);
		$settings = new Vector();
		foreach ($data[0]['data'] as ['Setting_name' => $key, 'Value' => $value]) {
			// If the key is plugin_dir check env first and after choose
			// most priority
			if ($key === 'common.plugin_dir') {
				$value = ConfigManager::get('PLUGIN_DIR') ?: $value;
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
		$resp = $this->sendRequest('SHOW VARIABLES');
		/** @var array{0:array{columns:array<mixed>,data:array{Setting_name:string,Value:string}}} */
		$data = (array)simdjson_decode($resp->getBody(), true);
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
	 * Fetches fuzzy variations for a given query.
	 *
	 * @param string $query The search query to find variations for
	 * @param string $table The table to search in
	 * @param int $distance Maximum edit distance for suggestions
	 * @param int $limit Maximum number of suggestions per word
	 * @return array{0: array<Variation>, 1: array<string, float>} Words and score map
	 */
	public function fetchFuzzyVariations(
		string $query,
		string $table,
		int $distance = 2,
		int $limit = 3
	): array {
		// First, escape the given query
		$query = addcslashes($query, '*%?\'');
		// 1. Tokenize the query first with the keywords function
		$q = "CALL KEYWORDS('{$query}', '{$table}')";
		/** @var array<array{data:array<array{normalized:string,tokenized:string}>}> $keywordsResult */
		$keywordsResult = $this->sendRequest($q)->getResult();
		$normalized = array_column($keywordsResult[0]['data'] ?? [], 'normalized');

		/** @var array<Variation> $words */
		$words = [];
		/** @var array<string,int> $distanceMap */
		$distanceMap = [];
		/** @var array<string,int> $docMap */
		$docMap = [];

		// 2. For each tokenized word, we get the suggestions from the suggest function
		// Track processed tokens to avoid double processing after merges
		$processedTokens = [];
		$i = 0;
		while ($i < sizeof($normalized)) {
			if (isset($processedTokens[$i])) {
				$i++;
				continue;
			}

			// Process suggestion with smart merging
			$consumed = $this->processSuggestion(
				$normalized[$i],
				$table,
				$limit,
				$distance,
				$i,
				$normalized,
				$words,
				$distanceMap,
				$docMap,
				$processedTokens
			);

			$i += max(1, $consumed);
		}

		// 3. Normalize the distance and docs values
		/** @var array<string,float> $docMapNormalized */
		$docMapNormalized = Arrays::normalizeValues($docMap);
		/** @var array<string,float> $distanceMapNormalized */
		$distanceMapNormalized = Arrays::normalizeValues($distanceMap);
		// Discard the original values
		unset($docMap, $distanceMap);

		$scoreMap = $this->calculateScoreMap($docMapNormalized, $distanceMapNormalized);

		return [$words, $scoreMap];
	}

	/**
	 * Smart suggestion processing with greedy merge and backtracking.
	 * Prevents double processing and compares merge quality vs individual results.
	 *
	 * @param string $word The word to get suggestions for
	 * @param string $table The table to search in
	 * @param int $limit Maximum number of suggestions per word
	 * @param int $distance Maximum edit distance for suggestions
	 * @param int $i Current word index
	 * @param array<string> $normalized Array of normalized words
	 * @param array<Variation> $words Reference to words array to be populated
	 * @param array<string,int> $distanceMap Reference to distance map to be populated
	 * @param array<string,int> $docMap Reference to document map to be populated
	 * @param array<int,bool> $processedTokens Reference to processed tokens tracking
	 * @return int Number of tokens consumed (1 for individual, 2+ for merged)
	 */

	/**
	 * Processes suggestions for a word and adds them to the words, distanceMap and docMap arrays.
	 * Now with smart merge logic to prevent double processing and improve merge quality.
	 *
	 * @param string $word The word to get suggestions for
	 * @param string $table The table to search in
	 * @param int $limit Maximum number of suggestions per word
	 * @param int $distance Maximum edit distance for suggestions
	 * @param int $i Current word index
	 * @param array<string> $normalized Array of normalized words
	 * @param array<Variation> $words Reference to words array to be populated
	 * @param array<string,int> $distanceMap Reference to distance map to be populated
	 * @param array<string,int> $docMap Reference to document map to be populated
	 * @param array<int,bool> $processedTokens Reference to processed tokens tracking
	 * @return int Number of tokens consumed (1 for individual, 2+ for merged)
	 */
	private function processSuggestion(
		string $word,
		string $table,
		int $limit,
		int $distance,
		int $i,
		array $normalized,
		array &$words,
		array &$distanceMap,
		array &$docMap,
		array &$processedTokens
	): int {
		/**
		 * @var array<array{
		 *     data: array<array{
		 *         suggest: string,
		 *         distance: int,
		 *         docs: int
		 *     }>
		 * }> $suggestResult
		 */
		$suggestResult = $this
			->sendRequest(
				"CALL SUGGEST('{$word}', '{$table}', {$limit} as limit, {$distance} as max_edits, 1 as non_char)"
			)
			->getResult();
		$suggestions = $suggestResult[0]['data'] ?? [];
		$choices = [];

		foreach ($suggestions as $suggestion) {
			$suggestWord = $suggestion['suggest'];
			$choices[] = $suggestWord;
			$distanceMap[$suggestWord] = $suggestion['distance'];
			$docMap[$suggestWord] = $suggestion['docs'];
		}

		// Smart merge logic - try to merge with next word if conditions are met
		$mergeResult = $this->tryMergeWithNext(
			$word,
			$i,
			$normalized,
			$table,
			$limit,
			$distance,
			$choices,
			$distanceMap
		);
		$tokenCount = $mergeResult ? 2 : 1;

		// Use merged result if it's better, otherwise use individual
		if ($mergeResult) {
			$finalOriginal = $mergeResult['original'];
			$finalChoices = $mergeResult['choices'];

			// Add merged distance and doc mappings
			foreach ($mergeResult['distanceMap'] as $suggWord => $dist) {
				$distanceMap[$suggWord] = $dist;
			}
			foreach ($mergeResult['docMap'] as $suggWord => $docs) {
				$docMap[$suggWord] = $docs;
			}

			// Mark next token as processed
			$processedTokens[$i + 1] = true;
		} else {
			$finalOriginal = $word;
			$finalChoices = $choices;
		}

		// Special case for empty suggestions
		if (!$finalChoices) {
			$distanceMap[$finalOriginal] = 999;
			$docMap[$finalOriginal] = 0;
		}

		$words[] = [
			'original' => $finalOriginal,
			'keywords' => $finalChoices,
		];

		return $tokenCount;
	}

	/**
	 * Quick heuristics to decide if merge should be attempted
	 */
	private function shouldAttemptMerge(string $word, string $nextWord): bool {
		// Performance limits
		$totalLength = strlen($word) + strlen($nextWord);
		if ($totalLength > 15) {
			return false;
		}

		// Don't merge if both words are already long
		if (strlen($word) > 6 && strlen($nextWord) > 6) {
			return false;
		}

		// Always merge single characters
		if (strlen($nextWord) === 1 || strlen($word) === 1) {
			return true;
		}

		// Merge if current word is short
		if (strlen($word) <= 3) {
			return true;
		}

		// More conservative for longer words
		return strlen($word) <= 5 && strlen($nextWord) <= 3;
	}

	/**
	 * Try to merge current word with next word if conditions are met
	 *
	 * @param string $word
	 * @param int $i
	 * @param array<string> $normalized
	 * @param string $table
	 * @param int $limit
	 * @param int $distance
	 * @param array<string> $choices
	 * @param array<string,int> $distanceMap
	 * @return array{original:string,choices:array<string>,distanceMap:array<string,int>,docMap:array<string,int>}|null
	 */
	private function tryMergeWithNext(
		string $word,
		int $i,
		array $normalized,
		string $table,
		int $limit,
		int $distance,
		array $choices,
		array $distanceMap
	): ?array {
		if (!isset($normalized[$i + 1]) || !$this->shouldAttemptMerge($word, $normalized[$i + 1])) {
			return null;
		}

		$nextWord = $normalized[$i + 1];
		$combinedWord = $word . $nextWord;

		$query = 'CALL SUGGEST('
			. "'{$combinedWord}', '{$table}',"
			. "{$limit} as limit,"
			. "{$distance} as max_edits,"
			. '1 as non_char)';
		/** @var array<array{data:array<array{suggest:string,distance:int,docs:int}>}> $combinedSuggestResult */
		$combinedSuggestResult = $this
			->sendRequest($query)
			->getResult();

		$combinedSuggestions = $combinedSuggestResult[0]['data'] ?? [];

		if (empty($combinedSuggestions)) {
			return null;
		}

		$mergedChoices = [];
		$mergedDistanceMap = [];
		$mergedDocMap = [];

		foreach ($combinedSuggestions as $suggestion) {
			$combinedSuggest = $suggestion['suggest'];
			$mergedChoices[] = $combinedSuggest;
			// We add 1 here cuz we already merge with space, so the distance is the same
			$mergedDistanceMap[$combinedSuggest] = $suggestion['distance'] + 1;
			$mergedDocMap[$combinedSuggest] = $suggestion['docs'];
		}

		// Compare merged vs individual quality
		if (!$this->shouldUseMergedResult($choices, $mergedChoices, $distanceMap, $mergedDistanceMap)) {
			return null;
		}

		return [
			'original' => $combinedWord,
			'choices' => $mergedChoices,
			'distanceMap' => $mergedDistanceMap,
			'docMap' => $mergedDocMap,
		];
	}

	/**
	 * Compare merged vs individual results quality
	 *
	 * @param array<string> $individualChoices
	 * @param array<string> $mergedChoices
	 * @param array<string,int> $individualDistanceMap
	 * @param array<string,int> $mergedDistanceMap
	 * @return bool
	 */
	private function shouldUseMergedResult(
		array $individualChoices,
		array $mergedChoices,
		array $individualDistanceMap,
		array $mergedDistanceMap
	): bool {
		// If no individual suggestions but merged has suggestions, use merged
		if (empty($individualChoices) && !empty($mergedChoices)) {
			return true;
		}

		// If individual has suggestions but merged doesn't, use individual
		if (!empty($individualChoices) && empty($mergedChoices)) {
			return false;
		}

		// Both have suggestions - compare quality
		if (!empty($mergedChoices)) {
			// Simple heuristic: prefer merged if it has reasonable quality
			$avgMergedDistance = array_sum($mergedDistanceMap) / sizeof($mergedDistanceMap);
			$avgIndividualDistance = empty($individualDistanceMap)
				? 999
				: array_sum($individualDistanceMap) / sizeof($individualDistanceMap);

			// Prefer merged if distance is not significantly worse
			// (allow +1.5 penalty for merging)
			return $avgMergedDistance <= $avgIndividualDistance + 1.5;
		}

		return false;
	}
	/**
	 * Calculates the score map based on normalized distance and document scores.
	 *
	 * @param array<string,float> $docMapNormalized Normalized document scores
	 * @param array<string,float> $distanceMapNormalized Normalized distance scores
	 * @return array<string,float> Score map with calculated scores
	 */
	private function calculateScoreMap(array $docMapNormalized, array $distanceMapNormalized): array {
		// We are use minimum distance to avoid siutation when less docs affect relevance
		$scoreFn = static function (float $distance, float $docs): float {
			return (float)max($distance + 1, sqrt($docs)) / ($distance + 1);
		};

		/** @var array<string,float> $scoreMap */
		$scoreMap = [];
		foreach ($docMapNormalized as $word => $docScore) {
			if (!isset($distanceMapNormalized[$word])) {
				continue;
			}

			$distanceScore = $distanceMapNormalized[$word];
			$scoreMap[$word] = $scoreFn($docScore, $distanceScore);
		}

		return $scoreMap;
	}
}
