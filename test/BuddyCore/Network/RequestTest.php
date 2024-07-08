<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Error\InvalidNetworkRequestError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase {

	use TestProtectedTrait;

	public function testManticoreRequestValidationOk(): void {
		echo "\nTesting the validation of a correct Manticore request\n";
		$payload = [
			'error' => 'some error',
			'type' => 'unknown json request',
			'message' => [
				'path_query' => '/sql?mode=raw',
				'body' => 'some query',
			],
			'version' => 2,
		];
		$request = Request::fromPayload($payload);
		$this->assertInstanceOf(Request::class, $request);
		$this->assertEquals($payload['message']['body'], $request->payload);
		$this->assertEquals(RequestFormat::JSON, $request->format);
		$this->assertEquals($payload['version'], $request->version);
		$this->assertEquals($payload['error'], $request->error);
		$this->assertEquals(ManticoreEndpoint::Sql, $request->endpointBundle);

		$payload['message']['path_query'] = '';
		$request = Request::fromPayload($payload);
		$this->assertEquals(ManticoreEndpoint::Sql, $request->endpointBundle);
	}

	public function testManticoreRequestValidationFail(): void {
		echo "\nTesting the validation of an incorrect Manticore request\n";
		$payload = [
			'error' => 'some error',
			'type' => 'error request',
			'message' => [
				'path_query' => '/cli',
				'body' => 'some query',
			],
			'version' => 2,
		];

		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals("Do not know how to handle 'error request' type", $exMsg);

		$payload['request_type'] = 'trololo';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals("Do not know how to handle 'error request' type", $exMsg);

		$payload['message']['path_query'] = '/test';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals("Do not know how to handle '/test' path_query", $exMsg);

		unset($payload['error']);
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals("Mandatory field 'error' is missing", $exMsg);

		$payload['error'] = 123;
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals("Field 'error' must be a string", $exMsg);

		$payload['error'] = 'some error';
		$payload['message']['body'] = 123;
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals("Field 'body' must be a string", $exMsg);
	}

	/**
	 * @dataProvider utf8QueryProvider
	 */
	public function testManticoreQueryValidationOk(string $query): void {
		$id = (string)random_int(0, 1000000);
		$request = Request::fromString($query, $id);
		$this->assertInstanceOf(Request::class, $request);
		$this->assertEquals($id, $request->id);
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public function utf8QueryProvider(): array {
		return [
			[
				'{"error":"some error","type":"unknown json request",'
				.'"message":{"path_query":"/cli","body":"some query"},'
				.'"version":1}',
			],
			[
				'{"error": "can\'t set ooop=\''.
				'是一个拥有悠久历史和文化的国家，'.'上人口最多的国家，也是世界上最大'
				.'的发展家之一。它位于亚洲东部，拥有广阔的领土和多样的地理环境，包括'
				.'高山、平原、沙漠、森林和海岸线。是联合国安理会常任理事国之一，拥有'
				.'强大的经济和军事实力，同时也是世界第二大经济体 是世界上人口最多的'
				.'国家 是世界上人口最多的国家，也是世界上最大的发展家之一。它位于亚洲'
				.'东部，拥有广阔的领土和多样的地理环境，包括高山、平原、沙漠、森林和'
				.'海岸线。是联合国安理会常任理事国之一，拥有强大的经济和军事实力，同'
				.'时也是世界第二大经济体。在文化方面，是四大文明古国之一，对世界哲学'
				.'、科技、艺术等领域有着深远的影响。政治上，实行的是共产党领导的多党'
				.'合作和政治协商制度。需要注意的是对于国家的描述可能因不同的视角和价'
				.'值观而有所不同。是世界上人口最多的国家，也是世界上最大的发展家之一'
				.'。它位于亚洲东部，拥有广阔的领土和多样的地理环境，包括高山、平原、'
				.'沙漠、森林和海岸线。是联合国安理会常任理事国之一，拥有强大的经济和'
				.'军事实力，同时也是世界第二大经济体。在文化方面，是四大文明古国之一'
				.'，对世界哲学、科技、艺术等领域有着深远的影响。政治上，实行的是共产'
				.'党领导的多党合作和政治协商制度。需要注意的是，对于国家的描述可能因'
				.'不同的视角和价值观而有所不同。是世界上人口最多的国家，也是世界上最'
				.'大的发展家之一。它位于亚洲东部，拥有广阔的领土和多样的地理环境，包'
				.'括高山、平原、沙漠、森林和海岸线。是联合国安理会常任理事国之一，拥'
				.'有强大的经济和军事实力，同时也是世界第二大经济体。在文化方面，是四'
				.'大文明古国之一，对世界哲学、科技、艺术等领域有着深远的影响。政治上'
				.'，实行的是共产党领导的多党合作和政治协商制度。需要注意的是，对于国'
				.'家的描述可能因不同的视角和价值观而有所不同。\', res=\'是一个拥有'
				.'悠久历史和文化的国家，是世界上人口最多的国家，也是世界上最大的发展'
				.'家之一。它位于亚洲东部，拥有广阔的领土和多样的地理环境，包括高山、'
				.'平原、沙漠、森林和海岸线。是联合国安理会常\' where id=1;"}, "t'
				.'ype": "unknown json request","message":{"path_query": "'
				.'/cli", "body": "can\'t set ooop=\'是一个拥有悠久历史和文化的'
				.'国家，上人口最多的国家，也是世界上最大的发展家之一。它位于亚洲东部'
				.'，拥有广阔的领土和多样的地理环境，包括高山、平原、沙漠、森林和海岸'
				.'线。是联合国安理会常任理事国之一，拥有强大的经济和军事实力，同时也'
				.'是世界第二大经济体 是世界上人口最多的国家 是世界上人口最多的国家，'
				.'也是世界上最大的发展家之一。它位于亚洲东部，拥有广阔的领土和多样的'
				.'地理环境，包括高山、平原、沙漠、森林和海岸线。是联合国安理会常任理'
				.'事国之一，拥有强大的经济和军事实力，同时也是世界第二大经济体。在文'
				.'化方面，是四大文明古国之一，对世界哲学、科技、艺术等领域有着深远的'
				.'影响。政治上，实行的是共产党领导的多党合作和政治协商制度。需要注意'
				.'的是，对于国家的描述可能因不同的视角和价值观而有所不同。是世界上人'
				.'口最多的国家，也是世界上最大的发展家之一。它位于亚洲东部，拥有广阔'
				.'的领土和多样的地理环境，包括高山、平原、沙漠、森林和海岸线。是联合'
				.'国安理会常任理事国之一，拥有强大的经济和军事实力，同时也是世界第二'
				.'大经济体。在文化方面，是四大文明古国之一，对世界哲学、科技、艺术等'
				.'领域有着深远的影响。政治上，实行的是共产党领导的多党合作和政治协商'
				.'制度。需要注意的是，对于国家的描述可能因不同的视角和价值观而有所不'
				.'同。是世界上人口最多的国家，也是世界上最大的发展家之一。它位于亚洲'
				.'东部，拥有广阔的领土和多样的地理环境，包括高山、平原、沙漠、森林和'
				.'海岸线。是联合国安理会常任理事国之一，拥有强大的经济和军事实力，同'
				.'时也是世界第二大经济体。在文化方面，是四大文明古国之一，对世界哲学'
				.'、科技、艺术等领域有着深远的影响。政治上，实行的是共产党领导的多党'
				.'合作和政治协商制度。需要注意的是，对于国家的描述可能因不同的视角和'
				.'价值观而有所不同。\', res=\'是一个拥有悠久历史和文化的国家，是世'
				.'界上人口最多的国家，也是世界上最大的发展家之一。它位于亚洲东部，拥'
				.'有广阔的领土和多样的地理环境，包括高山、平原、沙漠、森林和海岸线。'
				.'是联合国安理会常\' where id=1;"}},"version":1}',
			],
		];
	}


	public function testManticoreQueryValidationFail(): void {
		echo "\nTesting the validation of an incorrect request query from Manticore\n";
		$query = '';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromString', [$query]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals('The payload is missing', $exMsg);

		$query = "Invalid query\nis passed\nagain";
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromString', [$query]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals('Invalid request payload is passed', $exMsg);

		$query = 'Query\nwith unvalid\n\n{"request_body"}';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromString', [$query]);
		$this->assertEquals(InvalidNetworkRequestError::class, $exCls);
		$this->assertEquals('Invalid request payload is passed', $exMsg);
	}
}
