<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
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
		ConfigManager::init();
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

		$client = new HTTPClient('localhost:1000');
		$this->assertInstanceOf(HTTPClient::class, $client);
	}

	public function testResponseUrlSetOk(): void {
		$url = 'http://localhost:1000';
		$this->client->setServerUrl($url);
		$this->assertEquals('localhost', $this->refCls->getProperty('host')->getValue($this->client));
		$this->assertEquals(1000, $this->refCls->getProperty('port')->getValue($this->client));
	}

	public function testUnixSocketUrlSetOk(): void {
		$url = 'unix:/tmp/manticore_data/searchd.sock';
		$this->client->setServerUrl($url);

		$this->assertEquals($url, $this->refCls->getProperty('host')->getValue($this->client));
		$this->assertEquals(0, $this->refCls->getProperty('port')->getValue($this->client));
		$this->assertEquals($url, $this->client->getServerUrl());

		$systemClient = $this->client->getSystemClient();
		$this->assertEquals($url, $systemClient->getServerUrl());
	}

	public function testUnixSocketSyncRequest(): void {
		$this->runUnixSocketRequestTest(true);
	}

	/** @runInSeparateProcess */
	public function testUnixSocketRequestInsideCoroutine(): void {
		$this->runUnixSocketRequestTest(false);
	}

	private function runUnixSocketRequestTest(bool $forceSync): void {
		if (!function_exists('pcntl_fork')) {
			$this->markTestSkipped('pcntl is required for the Unix socket transport test');
		}

		$socketPath = sys_get_temp_dir() . '/buddy-core-' . getmypid() . '.sock';
		$server = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);
		$this->assertIsResource($server, $errorMessage);

		$pid = pcntl_fork();
		if ($pid === 0) {
			$connection = stream_socket_accept($server, 5);
			if ($connection === false) {
				exit(1);
			}
			while (($line = fgets($connection)) !== false && trim($line) !== '') {
			}
			$body = '[{"total":1,"error":"","warning":"","columns":[],"data":[]}]';
			fwrite(
				$connection,
				"HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}"
			);
			fclose($connection);
			fclose($server);
			exit(0);
		}

		try {
			$client = new HTTPClient("unix:{$socketPath}");
			if ($forceSync) {
				$client->setForceSync();
			}
			$response = null;
			if ($forceSync) {
				$response = $client->sendRequest('SHOW STATUS');
			} else {
				/** @phpstan-ignore-next-line Swoole extension function is not included in the test stubs */
				Swoole\Coroutine\run(
					function () use ($client, &$response): void {
						$response = $client->sendRequest('SHOW STATUS');
					}
				);
			}
			$this->assertNotNull($response);
			$this->assertStringContainsString('"total":1', $response->getBody());
		} finally {
			fclose($server);
			pcntl_waitpid($pid, $status);
			@unlink($socketPath);
		}
	}

	// public function testResponseUrlSetFail(): void {
	// 	$url = 'some_unvalid_url';
	// 	$this->expectException(ManticoreSearchClientError::class);
	// 	$this->expectExceptionMessage("Manticore request error: Malformed request url '$url' passed");
	// 	$this->client->setServerUrl($url);
	// }


	public function testAsyncFailuresDoNotDrainConnectionPool(): void {
		$executor = trim((string)shell_exec('command -v manticore-executor'));
		if ($executor === '') {
			$this->markTestSkipped('manticore-executor is required for the coroutine deadlock regression test');
		}

		$repoRoot = dirname(__DIR__, 4);
		$autoloadCandidates = [
			$repoRoot . '/vendor/autoload.php',
			dirname($repoRoot, 2) . '/autoload.php',
		];
		$autoload = '';
		foreach ($autoloadCandidates as $candidate) {
			if (is_file($candidate)) {
				$autoload = $candidate;
				break;
			}
		}
		$versionFile = $repoRoot . '/test/src/MOCK_APP_VERSION';
		if ($autoload === '' || !is_file($versionFile)) {
			$this->markTestSkipped('Required test bootstrap files are missing');
		}

		$scriptFile = tempnam(sys_get_temp_dir(), 'buddy-core-deadlock-');
		if ($scriptFile === false) {
			throw new \RuntimeException('Failed to create temporary script file');
		}

		$script = <<<'PHP'
<?php declare(strict_types=1);

require '__AUTOLOAD__';

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use function Swoole\Coroutine\run;

Buddy::setVersionFile('__VERSION_FILE__');
ConfigManager::init();

run(static function (): void {
    $client = new Client('http://127.0.0.1:9');
    for ($i = 1; $i <= 100; $i++) {
        try {
            $client->sendRequest('SHOW STATUS');
            echo "ok {$i}\n";
        } catch (Throwable $e) {
            echo "err {$i}: " . $e->getMessage() . "\n";
        }
    }
    echo "completed\n";
});
PHP;
		$script = str_replace(
			['__AUTOLOAD__', '__VERSION_FILE__'],
			[addslashes($autoload), addslashes($versionFile)],
			$script
		);
		file_put_contents($scriptFile, $script);

		try {
			$output = [];
			$returnVar = 0;
			exec($executor . ' ' . escapeshellarg($scriptFile) . ' 2>&1', $output, $returnVar);
			$stdout = implode(PHP_EOL, $output);
			$this->assertStringContainsString('completed', $stdout);
			$this->assertStringNotContainsString('[FATAL ERROR]', $stdout);
			$this->assertStringNotContainsString('all coroutines (count: 1) are asleep - deadlock!', $stdout);
			$this->assertStringNotContainsString('Channel::~Channel()', $stdout);
		} finally {
			@unlink($scriptFile);
		}
	}

	/**
	 * Regression for the /metrics fd leak (issue #686). QueryProcessor clones the
	 * shared client per request. If Client::__clone() builds a ConnectionPool whose
	 * factory closure captures $this, Client and its pool form a reference cycle, so
	 * the pooled keep-alive socket survives until the cycle collector runs -> fd leak.
	 * A correct clone must be released by refcount the instant it goes out of scope.
	 * @return void
	 */
	public function testClonedClientIsReleasedByRefcountWithoutCycleCollector(): void {
		gc_collect_cycles();

		$clone = clone $this->client;
		$ref = WeakReference::create($clone);
		unset($clone);

		// No gc_collect_cycles() here on purpose: only refcounting may free it.
		$this->assertNull(
			$ref->get(),
			'Cloned Client survived refcount drop: Client<->connectionPool reference cycle present (fd leak)'
		);
	}

	/**
	 * Regression test for issue #4826: SHOW META stripping must not re-encode and
	 * re-parse the response body, which corrupted big integers and could produce
	 * invalid JSON on large responses.
	 * @return void
	 */
	public function testShowMetaStripsMetaRowWithoutReserialization(): void {
		if (!function_exists('pcntl_fork')) {
			$this->markTestSkipped('pcntl is required for the SHOW META strip test');
		}

		$socketPath = sys_get_temp_dir() . '/buddy-core-showmeta-' . getmypid() . '.sock';
		$server = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);
		$this->assertIsResource($server, $errorMessage);

		// Body ids are built by decimal string concat; expected ids are derived
		// independently by successively incrementing a hand-written uint64 literal,
		// so an arithmetic error on either side diverges and fails the test.
		// Numeric-looking strings cover the corruption that the removed
		// serialize-and-parse round trip could introduce.
		$expectedIds = ['9223372036854775808'];
		$dataRows = [];
		foreach (range(0, 899) as $i) {
			$dataRows[] = '{"id":' . '922337203685477' . (5808 + $i)
				. ',"code":"0000000000","number_ticket_vendor":"22335618161513141414"}';
			if ($i <= 0) {
				continue;
			}

			$expectedIds[] = str_increment($expectedIds[$i - 1]);
		}
		$body = '['
			. '{"columns":[{"id":{"type":"long long"}}],'
			. '"data":[' . implode(',', $dataRows) . '],'
			. '"total":900,"error":"","warning":""},'
			. '{"columns":[{"Variable_name":{"type":"string"}},{"Value":{"type":"string"}}],'
			. '"data":[{"Variable_name":"total","Value":"900"}],"total":1,"error":"","warning":""}'
			. ']';
		$pid = pcntl_fork();
		if ($pid === 0) {
			$connection = stream_socket_accept($server, 5);
			if ($connection === false) {
				exit(1);
			}
			while (($line = fgets($connection)) !== false && trim($line) !== '') {
			}
			fwrite(
				$connection,
				"HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}"
			);
			fclose($connection);
			fclose($server);
			exit(0);
		}

		try {
			$client = new HTTPClient("unix:{$socketPath}");
			$client->setForceSync();
			$response = $client->sendRequest('SELECT id FROM t', 'sql?mode=raw');
			$this->assertSame(['total' => '900'], $response->getMeta());
			$this->assertFalse($response->hasMultipleRows());
			$this->assertSame(900, $response->getTotal());
			$resultStruct = $response->getResult();
			/** @var array<int,array{data:array<int,array{id:string,code:string,number_ticket_vendor:string}>}> $result */
			$result = $resultStruct->toArray();
			// Big integers must stay lossless strings in every row, without re-serialization
			$this->assertCount(900, $result[0]['data']);
			// Hand-written sentinels, independent of the generator above
			$this->assertSame('9223372036854775808', $result[0]['data'][0]['id']);
			$this->assertSame('9223372036854776257', $result[0]['data'][449]['id']);
			$this->assertSame('9223372036854776707', $result[0]['data'][899]['id']);
			foreach ($result[0]['data'] as $i => $row) {
				$this->assertSame($expectedIds[$i], $row['id']);
				$this->assertSame('0000000000', $row['code']);
				$this->assertSame('22335618161513141414', $row['number_ticket_vendor']);
			}
			$this->assertContains('0.data.0.id', $resultStruct->getBigIntFields());
			$this->assertContains('0.data.899.id', $resultStruct->getBigIntFields());
			$this->assertNotContains('0.data.0.code', $resultStruct->getBigIntFields());
			$this->assertNotContains(
				'0.data.0.number_ticket_vendor',
				$resultStruct->getBigIntFields()
			);
			// The daemon body stays raw; SHOW META is removed from parsed response state only
			$this->assertSame($body, $response->getBody());
			$this->assertTrue(Struct::isValid($response->getBody()));
		} finally {
			fclose($server);
			pcntl_waitpid($pid, $status);
			@unlink($socketPath);
		}
	}

}
