<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\CoreTest\Lib\MockManticoreServer;
use Manticoresearch\Buddy\CoreTest\Trait\TestHTTPServerTrait;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class GetManticoreResponseTest extends TestCase {

	/**
	 * @var HTTPClient $httpClient
	 */
	private $httpClient;

	use TestHTTPServerTrait;
	use TestInEnvironmentTrait;
	use TestProtectedTrait;

	/**
	 * @param bool $isInErrorMode
	 */
	protected function setUpServer(bool $isInErrorMode): void {
		self::setBuddyVersion();
		$serverUrl = self::setUpMockManticoreServer($isInErrorMode);
		$this->httpClient = new HTTPClient($serverUrl);
	}

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	public function testOkResponsesToSQLRequest(): void {
		echo "\nTesting Manticore success response to SQL request\n";
		$this->setUpServer(false);

		$query = 'CREATE TABLE IF NOT EXISTS test(col1 text)';
		$mntResp = Response::fromBody(MockManticoreServer::CREATE_RESPONSE['ok']);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'INSERT INTO test(col1) VALUES("test")';
		$mntResp = Response::fromBody(MockManticoreServer::SQL_INSERT_RESPONSE['ok']);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'SELECT * FROM @@system.sessions';
		$mntResp = Response::fromBody(MockManticoreServer::SHOW_QUERIES_RESPONSE['ok']);
		$this->assertEquals($mntResp->getResult(), $this->httpClient->sendRequest($query)->getResult());
	}

	public function testFailResponsesToSQLRequest(): void {
		echo "\nTesting Manticore fail response to SQL request\n";
		$this->setUpServer(true);

		$query = 'CREATE TABLE IF NOT EXISTS testcol1 text';
		$mntResp = Response::fromBody(MockManticoreServer::CREATE_RESPONSE['fail']);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'INSERT INTO test(col1) VALUES("test")';
		$mntResp = Response::fromBody(MockManticoreServer::SQL_INSERT_RESPONSE['fail']);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'SELECT connid AS ID FROM @@system.sessions';
		$this->expectException(ManticoreSearchResponseError::class);
		$this->expectExceptionMessage('Trying to parse empty response');
		$this->httpClient->sendRequest($query);
	}

	public function testOkResponsesToJSONRequest(): void {
		echo "\nTesting Manticore success response to JSON request\n";
		$this->setUpServer(false);
		$query = '{"index":"test","id":1,"doc":{"col1" : 1}}';
		$mntResp = Response::fromBody(MockManticoreServer::JSON_INSERT_RESPONSE['ok']);
		$this->assertEquals(
			$mntResp,
			$this->httpClient->sendRequest($query, ManticoreEndpoint::Insert->value)
		);
	}

	public function testFailResponsesToJSONRequest(): void {
		echo "\nTesting Manticore fail response to JSON request\n";
		$this->setUpServer(true);
		$query = '{"index":"test","id":1,"doc":{"col1" : 1}}';
		$mntResp = Response::fromBody(MockManticoreServer::JSON_INSERT_RESPONSE['fail']);
		$this->assertEquals(
			$mntResp,
			$this->httpClient->sendRequest($query, ManticoreEndpoint::Insert->value)
		);
	}
}
