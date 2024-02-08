<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Core\Tool;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use PHPSQLParser\PHPSQLCreator;
use PHPSQLParser\PHPSQLParser;
use PHPSQLParser\exceptions\UnsupportedFeatureException;

final class SqlQueryParser
{

	/**
	 * @var SqlQueryParser|null
	 */
	protected static self|null $instance = null;
	/**
	 * @var array<string, array<int, array{table:string, expr_type:string, base_expr:string,
	 *   sub_tree:array<int, array<string, string|bool>>}>>|null
	 */
	private static array|null $parsedPayload = null;
	/**
	 * @var PHPSQLParser
	 */
	private static PHPSQLParser $parser;
	/**
	 * @var PHPSQLCreator
	 */
	private static PHPSQLCreator $creator;


	final private function __construct() {
	}

	final protected function __clone() {
	}

	final public function __wakeup() {
		throw new \Exception('Cannot unserialize a singleton.');
	}

	/**
	 * @return self
	 */
	public static function getInstance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
			self::$parser = new PHPSQLParser();
			self::$creator = new PHPSQLCreator();
		}

		return self::$instance;
	}

	/**
	 * @return PHPSQLParser
	 */
	private static function getParser(): PHPSQLParser {
		return self::$parser;
	}

	/**
	 * @return PHPSQLCreator
	 */
	private static function getCreator(): PHPSQLCreator {
		return self::$creator;
	}

	/**
	 * @param string $payload
	 * @return array<string, array<int,
	 *   array{table:string, expr_type:string, base_expr:string,
	 * sub_tree: array<int, array<string, string|bool>>}>>|null
	 */
	public static function parse(string $payload): ?array {
		$parsedPayload = static::getInstance()::getParser()->parse($payload);
		static::setParsedPayload($parsedPayload);
		return $parsedPayload;
	}

	/**
	 * @return string
	 * @throws QueryParseError
	 */
	public static function getCompletedPayload(): string {
		try {
			return static::getInstance()::getCreator()->create(static::getParsedPayload());
		} catch (UnsupportedFeatureException $exception) {
			throw new QueryParseError($exception->getMessage());
		}
	}

	/**
	 * @return array<string, array<int,
	 *   array{table:string, expr_type:string, base_expr:string,
	 * sub_tree: array<int, array<string, string|bool>>}>>|null
	 */
	public static function getParsedPayload(): ?array {
		return static::getInstance()::$parsedPayload;
	}

	/**
	 * @param array<string, array<int,
	 *   array{table: string, expr_type:string, base_expr:string,
	 * sub_tree: array<int, array<string, string|bool>>}>> $parsedPayload
	 * @return void
	 */
	public static function setParsedPayload(array $parsedPayload): void {
		static::getInstance()::$parsedPayload = $parsedPayload;
	}
}
