<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\SystemClient;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use PHPUnit\Framework\TestCase;

class SystemClientTest extends TestCase {

	use TestInEnvironmentTrait;

	/**
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::setBuddyVersion();
	}

	public function testIdentityIsPinnedToSystemUser(): void {
		$client = new SystemClient();
		$this->assertSame(Client::SYSTEM_USER, $this->getDelegatedUser($client));
	}

	public function testSetDelegatedUserThrows(): void {
		$client = new SystemClient();
		$this->expectException(LogicException::class);
		$client->setDelegatedUser('alice');
	}

	public function testClearDelegatedUserThrows(): void {
		$client = new SystemClient();
		$this->expectException(LogicException::class);
		$client->clearDelegatedUser();
	}

	public function testGetSystemClientIsMemoizedAndKeepsOriginalIdentity(): void {
		$client = new Client();
		$client->setDelegatedUser('alice');

		$system = $client->getSystemClient();
		$this->assertInstanceOf(SystemClient::class, $system);
		$this->assertSame($system, $client->getSystemClient());

		$this->assertSame('alice', $this->getDelegatedUser($client));
		$this->assertSame(Client::SYSTEM_USER, $this->getDelegatedUser($system));
	}

	public function testSystemClientReturnsItself(): void {
		$system = (new Client())->getSystemClient();
		$this->assertSame($system, $system->getSystemClient());
	}

	/**
	 * @param Client $client
	 * @return ?string
	 */
	private function getDelegatedUser(Client $client): ?string {
		$ref = new ReflectionProperty(Client::class, 'delegatedUser');
		/** @var ?string */
		return $ref->getValue($client);
	}
}
