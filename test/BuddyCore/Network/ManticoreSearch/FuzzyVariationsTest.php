<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use PHPUnit\Framework\TestCase;

class FuzzyVariationsTest extends TestCase {

	/**
	 * @return array<string,array{string,array<array{qpos:string,tokenized:string,normalized:string}>,array<array{original:string,keywords:array<string>}>,array<string>}>
	 */
	public static function keywordsProvider(): array {
		return [
			'exact form of one word' => [
				'keybord',
				[
					['qpos' => '1', 'tokenized' => 'keybord', 'normalized' => 'keybord'],
					['qpos' => '1', 'tokenized' => 'keybord', 'normalized' => '=keybord'],
				],
				[['original' => 'keybord', 'keywords' => ['keyboard']]],
				['keybord'],
			],
			'exact forms of two words' => [
				'wireless keybord',
				[
					['qpos' => '1', 'tokenized' => 'wireless', 'normalized' => 'wireless'],
					['qpos' => '1', 'tokenized' => 'wireless', 'normalized' => '=wireless'],
					['qpos' => '2', 'tokenized' => 'keybord', 'normalized' => 'keybord'],
					['qpos' => '2', 'tokenized' => 'keybord', 'normalized' => '=keybord'],
				],
				[
					['original' => 'wireless', 'keywords' => ['wireless']],
					['original' => 'keybord', 'keywords' => ['keyboard']],
				],
				['wireless', 'keybord'],
			],
			'ordinary token without exact form' => [
				'keybord',
				[['qpos' => '1', 'tokenized' => 'keybord', 'normalized' => 'keybord']],
				[['original' => 'keybord', 'keywords' => ['keyboard']]],
				['keybord'],
			],
		];
	}

	/**
	 * @dataProvider keywordsProvider
	 * @param string $query
	 * @param array<array{qpos:string,tokenized:string,normalized:string}> $keywords
	 * @param array<array{original:string,keywords:array<string>}> $expectedVariations
	 * @param array<string> $expectedSuggestWords
	 */
	public function testFetchFuzzyVariationsIgnoresExactForms(
		string $query,
		array $keywords,
		array $expectedVariations,
		array $expectedSuggestWords
	): void {
		$suggestWords = [];
		$client = $this->getMockBuilder(Client::class)
			->disableOriginalConstructor()
			->onlyMethods(['sendRequest'])
			->getMock();
		$client->method('sendRequest')->willReturnCallback(
			static function (string $sql) use ($keywords, &$suggestWords): Response {
				if (str_starts_with($sql, 'CALL KEYWORDS(')) {
					$data = $keywords;
				} elseif (preg_match("/CALL SUGGEST\(\s*'([^']+)'/", $sql, $matches)) {
					$word = $matches[1];
					$suggestWords[] = $word;
					$suggestions = [
						'wireless' => [['suggest' => 'wireless', 'distance' => 0, 'docs' => 2]],
						'keybord' => [['suggest' => 'keyboard', 'distance' => 1, 'docs' => 1]],
					];
					if (!isset($suggestions[$word])) {
						throw new RuntimeException("Unexpected suggestion request for '$word'");
					}
					$data = $suggestions[$word];
				} else {
					throw new RuntimeException("Unexpected Manticore request: $sql");
				}

				return Response::fromBody(json_encode([[
					'total' => count($data),
					'error' => '',
					'warning' => '',
					'columns' => [],
					'data' => $data,
				]], JSON_THROW_ON_ERROR));
			}
		);

		[$variations] = $client->fetchFuzzyVariations($query, 'fz_all');
		$this->assertSame($expectedVariations, $variations);
		$this->assertSame($expectedSuggestWords, $suggestWords);
	}
}
