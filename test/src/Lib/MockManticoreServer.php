<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\CoreTest\Lib;

use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use RuntimeException;
use Socket;

final class MockManticoreServer {

	const CREATE_RESPONSE = [
		'fail' => '{"error":"sphinxql: syntax error, unexpected IDENT, expecting '
		. 'CLUSTER or FUNCTION or PLUGIN or TABLE near \'tablee test(col1 text)\'"}',
		'ok' => '[{"total":0,"error":"","warning":""}]',
	];
	const SQL_INSERT_RESPONSE = [
		'fail' => '{"error":"table \'test\' absent, or does not support INSERT"}',
		'ok' => '[{"total":1,"error":"","warning":""}]',
	];
	const JSON_INSERT_RESPONSE = [
		'fail' => '{"error":{"type":"table \'test\' absent, or does not support INSERT"'
		. ',"index":"test"},"status":500}',
		'ok' => '{"_index": "test","_id": 1,"created": true,"result": "created","status": 201}',
	];
	const SHOW_QUERIES_RESPONSE = [
		'fail' => '',
		'ok' => "[{\n"
		. '"columns":[{"id":{"type":"long long"}},{"proto":{"type":"string"}},{"state":{"type":"string"}},'
		. '{"host":{"type":"string"}},{"connid":{"type":"long long"}},{"killed":{"type":"string"}},'
		. '{"last cmd":{"type":"string"}}],'
		. "\n"
		. '"data":['
		. '{"id":1,"proto":"http","state":"query","host":"127.0.0.1:584","connid":19,"killed":"0","last cmd":"select"}'
		. "\n],\n"
		. '"total":1,'
		. "\n"
		. '"error":"",'
		. "\n"
		. '"warning":"",'
		. "\n"
		. '"meta":{"time":"0"}'
		. "\n}]",
	];
	const SHOW_VARIABLES_RESPONSE = [
		'fail' => '',
		'ok' => "[{\n"
		. '"columns":[{"Variable_name":{"type":"string"}},{"Value":{"type":"string"}}],'
		. "\n"
		. '"data":['
		. '{"Variable_name":"autocommit","Value":"1"},'
		. "\n"
		. '{"Variable_name":"auto_optimize","Value":"2"},'
		. "\n"
		. '{"Variable_name":"collation_connection","Value":"libc_ci"},'
		. "\n"
		. '{"Variable_name":"last_insert_id","Value":""},'
		. "\n"
		. '{"Variable_name":"pseudo_sharding","Value":"2"}'
		. "\n],\n"
		. '"total":5,'
		. "\n"
		. '"error":"",'
		. "\n"
		. '"warning":""'
		. "\n}]",
	];
	const SHOW_TABLES_RESPONSE = [
		'fail' => '',
		'ok' => "[{\n"
		. '"columns":[{"Index":{"type":"string"}},{"Type":{"type":"string"}}],'
		. "\n"
		. '"data":['
		. '{"Index":"test","Type":"rt"}'
		. "\n],\n"
		. '"total":1,'
		. "\n"
		. '"error":"",'
		. "\n"
		. '"warning":""'
		. "\n}]",
	];
	const SHOW_META_RESPONSE = [
		'fail' => '',
		'ok' => "[{\n"
		. '"columns":[{"Variable_name":{"type":"string"}},{"Value":{"type":"string"}}],'
		. "\n"
		. '"data":['
		. '{"Variable_name":"time","Value":"0"}'
		. "\n"
		. "\n],\n"
		. '"total":5,'
		. "\n"
		. '"error":"",'
		. "\n"
		. '"warning":""'
		. "\n}]",
	];

	/**
	 * @var Socket|false $socket
	 */
	private $socket;

	/**
	 * @var Socket|false $conn
	 */
	private $conn;

	/**
	 * @var ?int $parentPid
	 */
	private $parentPid = null;

	/**
	 * @var string $reqEndpoint
	 */
	private string $reqEndpoint;

	/**
	 * @param string $addrPort
	 * @param bool $hasErrorResponse
	 * @return void
	 */
	public function __construct(
		private string $addrPort,
		private bool $hasErrorResponse = false,
	) {
	}

	/**
	 * @return void
	 */
	protected function run(): void {
		while ($this->socket !== false) {
			$this->conn = socket_accept($this->socket);
			if ($this->conn === false) {
				$this->checkParentProc();
				usleep(1000);
			} else {
				socket_set_nonblock($this->conn);
				$req = $this->readSocketData();
				if (!trim($req)) {
					exit("<Mock Manticore server terminated: Request parse failure: empty request passed>\n");
				}
				preg_match('/(\n|\r)/', $req, $matches, PREG_OFFSET_CAPTURE);
				$reqUrlData = isset($matches[0][1]) ? substr($req, 0, (int)$matches[0][1]) : '';
				preg_match('/\s\/(.*?)\s/', $reqUrlData, $matches);
				$this->reqEndpoint = $matches[1] ?? '';
				preg_match('/(\n\n|\r\n\r\n|\r\r)/', $req, $matches, PREG_OFFSET_CAPTURE);
				$reqBody = isset($matches[0][1]) ? substr($req, $matches[0][1] + 4) : '';
				$this->process($reqBody);
			}
		}
	}

	/**
	 * @return void
	 */
	public function start(): void {
		$connInfo = parse_url($this->addrPort);
		if ($connInfo === false
			|| (!array_key_exists('host', $connInfo) || !array_key_exists('port', $connInfo))) {
			exit("<Mock Manticore server terminated: Wrong connection data '{$this->addrPort}' passed");
		}

		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($this->socket === false) {
			throw new SocketError('Cannot create a socket');
		}

		if (!socket_bind($this->socket, $connInfo['host'], $connInfo['port']) || !socket_listen($this->socket)) {
			throw new SocketError('Cannot connect to the socket');
		}
		socket_set_nonblock($this->socket);

		echo "<Mock Manticore server started at {$this->addrPort}>";
		$this->checkParentProc();
		$this->run();
	}

	/**
	 * !TODO test implementation for Windows
	 *
	 * Check if the parent process is finished and finish too if true
	 * @return void
	 */
	private function checkParentProc(): void {
		if (PHP_OS_FAMILY === 'Windows') {
			$pid = getmypid();
			$parentPidInfo = shell_exec("wmic process where (processid=$pid) get parentprocessid");
			if (!isset($parentPidInfo) || $parentPidInfo === false) {
				throw new RuntimeException('Cannot check parent state');
			}
			$parentPid = explode("\n", $parentPidInfo);
			$parentPid = (int)$parentPid[1];
		} else {
			$parentPid = posix_getppid();
		}
		if (!isset($this->parentPid)) {
			$this->parentPid = $parentPid;
		} elseif ($parentPid !== $this->parentPid) {
			exit("<Mock Manticore server finished>\n");
		}
	}

	/**
	 * @param mixed $dataPacket
	 * @param bool &$isFinishing
	 * @param bool &$isOk
	 * @return string
	 */
	private function readSocketDataPacket(mixed $dataPacket, bool &$isFinishing, bool &$isOk): string {
		switch ($dataPacket) {
			case false:
				if (in_array(
					socket_last_error(),
					[SOCKET_EINPROGRESS, SOCKET_EALREADY, SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]
				)) {
					if ($isFinishing === false) {
						$isFinishing = true;
						usleep(10000);
						$isOk = true;
					} else {
						$isOk = false;
					}
				} else {
					$isOk = false;
				}
				return '';
			case '':
				$isOk = false;
				return '';
			default:
				$isFinishing = false;
				$isOk = true;
				return is_string($dataPacket) ? $dataPacket : '';
		}
	}

	/**
	 * @return string
	 */
	private function readSocketData(): string {
		$data = '';
		$isOk = false;
		$isFinishing = false;
		do {
			if ($this->conn === false) {
				$isOk = false;
			} else {
				$dataPacket = socket_read($this->conn, 2048, PHP_BINARY_READ);
				$data .= $this->readSocketDataPacket($dataPacket, $isFinishing, $isOk);
			}
		} while ($isOk);

		$data = trim($data);
		return $data;
	}

	/**
	 * @param string $request
	 * @return void
	 */
	private function process(string $request): void {
		// Removing the query prefix if exists
		if (str_starts_with($request, 'query=')) {
			$request = substr($request, 6);
		}
		$responseType = $this->hasErrorResponse ? 'fail' : 'ok';
		$resp = match (true) {
			str_starts_with($request, 'CREATE') => self::CREATE_RESPONSE[$responseType],
			str_starts_with($request, 'INSERT') => self::SQL_INSERT_RESPONSE[$responseType],
			(ManticoreEndpoint::from($this->reqEndpoint) === ManticoreEndpoint::Insert) =>
				self::JSON_INSERT_RESPONSE[$responseType],
			str_starts_with($request, 'SELECT') => self::SHOW_QUERIES_RESPONSE[$responseType],
			str_starts_with($request, 'SHOW+VARIABLES') => self::SHOW_VARIABLES_RESPONSE[$responseType],
			str_starts_with($request, 'SHOW+TABLES') => self::SHOW_TABLES_RESPONSE[$responseType],
			str_starts_with($request, 'SHOW META') => self::SHOW_META_RESPONSE[$responseType],
			default => '',
		};
		$this->sendResponse($resp);
	}

	/**
	 * @param string $resp
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws QueryParseError
	 * @throws ManticoreSearchResponseError
	 * @throws InvalidNetworkRequestError
	 */
	private function sendResponse(string $resp): void {
		if ($this->conn === false) {
			return;
		}
		$respLen = strlen($resp);
		$msg = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n";
		$msg .= "Content-Length: $respLen\r\n\r\n$resp";
		//echo "\n mnt response is $msg";
		socket_write($this->conn, $msg, strlen($msg));
	}
}
