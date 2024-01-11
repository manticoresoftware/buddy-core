<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Network;

use Ds\Vector;
use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;

final class Request {
	const PAYLOAD_FIELDS = [
		'type' => 'string',
		'error' => 'string',
		'message' => 'array',
		'version' => 'integer',
	];
	const MESSAGE_FIELDS = ['path_query' => 'string', 'body' => 'string'];

	/** @var string $id Request id from header Request-ID */
	public string $id;
	public float $time;

	public ManticoreEndpoint $endpointBundle;
	public RequestFormat $format;
	public ManticoreSettings $settings;
	public string $path;
	public string $error;
	public string $payload;
	public int $version;

	/**
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * Create default filled request
	 *
	 * @param string $id
	 * @return static
	 */
	public static function default(string $id = '0'): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		$self->endpointBundle = ManticoreEndpoint::Sql;
		$self->settings = ManticoreSettings::fromVector(new Vector());
		$self->path = ManticoreEndpoint::Sql->value;
		$self->format = RequestFormat::JSON;
		$self->error = '';
		$self->payload = '{}';
		$self->version = 1;
		return $self;
	}

	/**
	 * Create request from string and validate that it's ok for us
	 *
	 * @param string $data
	 * @param string $id
	 * @return static
	 */
	public static function fromString(string $data, string $id = '0'): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		$self->parseOrFail(static::validateOrFail($data));
		return $self;
	}

	/**
	 * Helper to create request from prepare array data
	 * It can be useful for tests
	 *
	 * @param array{
	 * 	error:string,
	 * 	payload:string,
	 * 	version:int,
	 * 	format:RequestFormat,
	 * 	endpointBundle:ManticoreEndpoint,
	 *  path:string
	 * } $data
	 * @param string $id
	 * @return static
	 */
	public static function fromArray(array $data, string $id = '0'): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		foreach ($data as $k => $v) {
			$self->$k = $v;
		}
		return $self;
	}

	/**
	 * This method is same as fromArray but applied to payload
	 *
	 * @param array{type:string,error:string,message:array{path_query:string,body:string},version:int} $payload
	 * @param string $id
	 * @return static
	 */
	public static function fromPayload(array $payload, string $id = '0'): static {
		$self = new static;
		$self->id = $id;
		$self->time = microtime(true);
		return $self->parseOrFail($payload);
	}

	/**
	 * Validate input data before we will parse it into a request
	 *
	 * @param string $data
	 * @return array{type:string,error:string,message:array{path_query:string,body:string},version:int}
	 * @throws InvalidNetworkRequestError
	 */
	public static function validateOrFail(string $data): array {
		if ($data === '') {
			throw new InvalidNetworkRequestError('The payload is missing');
		}
		/** @var array{type:string,error:string,message:array{path_query:string,body:string},version:int} */
		$result = json_decode($data, true);
		if (!is_array($result)) {
			throw new InvalidNetworkRequestError('Invalid request payload is passed');
		}

		return $result;
	}

	/**
	 * @param array{type:string,error:string,message:array{path_query:string,body:string},version:int} $payload
	 * @return static
	 * @throws InvalidNetworkRequestError
	 */
	protected function parseOrFail(array $payload): static {
		static::validateInputFields($payload, static::PAYLOAD_FIELDS);

		// Checking if request format and endpoint are supported
		/** @var array{path:string,query?:string} $urlInfo */
		$urlInfo = parse_url($payload['message']['path_query']);
		$path = ltrim($urlInfo['path'], '/');
		if ($path === 'sql' && isset($urlInfo['query'])) {
			// We need to keep the query parameters part in the sql queries
			// as it's required for the following requests to Manticore
			$path .= '?' . $urlInfo['query'];
		} elseif (str_ends_with($path, '/_bulk')) {
			// Convert the elastic bulk request path to the Manticore one
			$path = '_bulk';
		}
		if (str_contains($path, '/_doc/') || str_contains($path, '/_create/')
			|| str_ends_with($path, '/_doc') || str_ends_with($path, '/_create')) {
			// We don't differentiate elastic-like insert and replace queries here
			// since this is irrelevant for the following Buddy processing logic
			$endpointBundle = ManticoreEndpoint::Insert;
		} elseif (str_ends_with($path, '/_mapping')) {
			$endpointBundle = ManticoreEndpoint::Elastic;
		} else {
			$endpointBundle = match ($path) {
				'bulk', '_bulk' => ManticoreEndpoint::Bulk,
				'cli' => ManticoreEndpoint::Cli,
				'cli_json' => ManticoreEndpoint::CliJson,
				'search' => ManticoreEndpoint::Search,
				'sql?mode=raw', 'sql', '' => ManticoreEndpoint::Sql,
				'insert', 'replace' => ManticoreEndpoint::Insert,
				'_license' => ManticoreEndpoint::Elastic,
				default => throw new InvalidNetworkRequestError(
					"Do not know how to handle '{$payload['message']['path_query']}' path_query"
				),
			};
		}

		$format = match ($payload['type']) {
			'unknown json request' => RequestFormat::JSON,
			'unknown sql request' => RequestFormat::SQL,
			default => throw new InvalidNetworkRequestError("Do not know how to handle '{$payload['type']}' type"),
		};
		$this->path = $path;
		$this->format = $format;
		$this->endpointBundle = $endpointBundle;
		$this->payload = static::removeComments($payload['message']['body']);
		$this->error = $payload['error'];
		$this->version = $payload['version'];
		return $this;
	}

	/**
	 * Helper function to do recursive validation of input fields
	 *
	 * @param array{
	 * 		path_query: string,
	 * 		body: string
	 * 	}|array{
	 * 		type:string,
	 * 		error:string,
	 * 		message:array{path_query:string,body:string},
	 * 		version:int
	 * 	} $payload
	 * @param array<string> $fields
	 * @return void
	 */
	protected function validateInputFields(array $payload, array $fields): void {
		foreach ($fields as $k => $type) {
			if (!array_key_exists($k, $payload)) {
				throw new InvalidNetworkRequestError("Mandatory field '$k' is missing");
			}

			if (gettype($payload[$k]) !== $type) {
				throw new InvalidNetworkRequestError("Field '$k' must be a $type");
			}

			if ($k !== 'message' || !is_array($payload[$k])) {
				continue;
			}

			static::validateInputFields($payload[$k], static::MESSAGE_FIELDS);
		}
	}

	/**
	 * Remove all types of comments from the query, because we do not use it for now
	 * @param string $query
	 * @return string
	 * @throws QueryParseError
	 */
	protected static function removeComments(string $query): string {
		$query = preg_replace_callback(
			'/((\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')|(--[^"\r\n]*|#[^"\r\n]*|\/\*[^!][\s\S]*?\*\/))/',
			function ($matches) {
				if (strpos($matches[0], '--') === 0
				|| strpos($matches[0], '#') === 0
				|| strpos($matches[0], '/*') === 0) {
					return '';
				}

				return $matches[0];
			},
			$query
		);

		if ($query === null) {
			QueryParseError::throw(
				'Error while removing comments from the query using regex: '.  preg_last_error_msg()
			);
		}
		/** @var string $query */
		return trim($query);
	}
}
