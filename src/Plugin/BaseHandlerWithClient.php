<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Plugin;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Task\Task;
use RuntimeException;
use parallel\Runtime;

/**
 * This is the parent class to handle erroneous queries via Manticore client requests
 */
abstract class BaseHandlerWithClient extends BaseHandler {
	/** @var HTTPClient $manticoreClient */
	protected HTTPClient $manticoreClient;

	/**
	 * Process the request and return self for chaining
	 *
	 * @param Runtime $runtime
	 * @return Task
	 * @throws RuntimeException
	 */
	abstract public function run(Runtime $runtime): Task;

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return ['manticoreClient'];
	}

	/**
	 * Instantiating the http client to execute requests to Manticore server
	 *
	 * @param HTTPClient $client
	 * $return HTTPClient
	 */
	public function setManticoreClient(HTTPClient $client): HTTPClient {
		$this->manticoreClient = $client;
		return $this->manticoreClient;
	}
}
