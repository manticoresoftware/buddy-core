<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Core\Tool;

use PHPSQLParser\PHPSQLCreator;
use PHPSQLParser\PHPSQLParser;

class SqlQueryParser
{

	/**
	 * @var SqlQueryParser|null
	 */
	protected static self|null $instance = null;
	/**
	 * @var array<string, array<string, string>>|null
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
	 * @return array<string, array<string, string>>|null
	 */
	public static function parse(string $payload): ?array {
		$parsedPayload = static::getInstance()::getParser()->parse($payload);
		static::setParsedPayload($parsedPayload);
		return $parsedPayload;
	}

	/**
	 * @return string
	 * @throws \PHPSQLParser\exceptions\UnsupportedFeatureException
	 */
	public static function getCompletedPayload(): string {
		return static::getInstance()::getCreator()->create(static::getParsedPayload());
	}

	/**
	 * @return array<string, array<string, string>>|null
	 */
	public static function getParsedPayload(): ?array {
		return static::getInstance()::$parsedPayload;
	}

	/**
	 * @param array<string, array<string, string>> $parsedPayload
	 * @return void
	 */
	public static function setParsedPayload(array $parsedPayload): void {
		static::getInstance()::$parsedPayload = $parsedPayload;
	}
}
