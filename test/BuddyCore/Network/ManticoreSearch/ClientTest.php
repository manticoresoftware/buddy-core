<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

//use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {

	use TestProtectedTrait;
	use TestInEnvironmentTrait;

	/**
	 * @var HTTPClient $client
	 */
	private $client;

	/**
	 * @var ReflectionClass<HTTPClient> $refCls
	 */
	private $refCls;

	/**
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::setBuddyVersion();
	}

	protected function setUp(): void {
		$this->client = new HTTPClient();
		$this->refCls = new \ReflectionClass(HTTPClient::class);
	}

	public function testManticoreHTTPClientCreate(): void {
		$this->assertInstanceOf(HTTPClient::class, $this->client);
		$parsedUrl = parse_url(HTTPClient::DEFAULT_URL);
		$host = $parsedUrl['host'];
		$port = $parsedUrl['port'];
		$this->assertEquals(
			$host,
			$this->refCls->getProperty('host')->getValue($this->client)
		);
		$this->assertEquals(
			$port,
			$this->refCls->getProperty('port')->getValue($this->client)
		);

		$client = new HTTPClient(new Response(), 'localhost:1000');
		$this->assertInstanceOf(HTTPClient::class, $client);
	}

	public function testResponseUrlSetOk(): void {
		$url = 'http://localhost:1000';
		$this->client->setServerUrl($url);
		$this->assertEquals('localhost', $this->refCls->getProperty('host')->getValue($this->client));
		$this->assertEquals(1000, $this->refCls->getProperty('port')->getValue($this->client));
	}

	// public function testResponseUrlSetFail(): void {
	// 	$url = 'some_unvalid_url';
	// 	$this->expectException(ManticoreSearchClientError::class);
	// 	$this->expectExceptionMessage("Manticore request error: Malformed request url '$url' passed");
	// 	$this->client->setServerUrl($url);
	// }

}
