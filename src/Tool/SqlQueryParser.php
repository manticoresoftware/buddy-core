<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Core\Tool;

use Closure;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use PHPSQLParser\PHPSQLCreator;
use PHPSQLParser\PHPSQLParser;
use PHPSQLParser\exceptions\UnsupportedFeatureException;

/**
 * @phpstan-template T of array
 */
final class SqlQueryParser {

	/**
	 * @phpstan-var SqlQueryParser<T>|null
	 */
	protected static self|null $instance = null;

	/**
	 * @phpstan-var T $parsedPayload
	 */
	private static array|null $parsedPayload = null;

	/**
	 * Parser is pretty slow.
	 * We use preprocessor to not allow developer by mistake and run slow query parsing
	 *
	 * @var bool $preprocessorCalled
	 */
	private static bool $preprocessorCalled = false;
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
	 * @return SqlQueryParser<T>
	 */
	public static function getInstance(): self {
		if (self::$instance === null) {
			/** @var SqlQueryParser<T> $self */
			$self = new self();
			self::$instance = $self;
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
	 * @param Closure $callback
	 * @param mixed $args
	 * @return bool
	 */
	public static function checkMatchPreprocessed(Closure $callback, mixed $args): bool {
		$result = (bool)call_user_func($callback, $args);
		static::getInstance()::$preprocessorCalled = $result;
		return $result;
	}


	/**
	 * @phpstan-param string $payload
	 * @phpstan-return T
	 * @param string $payload
	 * return array|null
	 * @throws GenericError
	 */
	public static function parse(string $payload): ?array {

		if (static::getInstance()::$preprocessorCalled === false) {
			throw GenericError::create("You can't run PHP SQL parser without calling preprocessing");
		}

		$parsedPayload = static::getInstance()::getParser()->parse($payload);

		if (empty($parsedPayload)) {
			return null;
		}
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
	 * @return T
	 */
	public static function getParsedPayload(): ?array {
		return static::getInstance()::$parsedPayload;
	}

	/**
	 * @param T $parsedPayload
	 * @return void
	 */
	public static function setParsedPayload(array $parsedPayload): void {
		static::getInstance()::$parsedPayload = $parsedPayload;
	}

	/**
	 * Helper that allows removal of starting and ending quotes from string,
	 * cause parser usually leaves it as is ('manticore', "manticore", `manticore`)
	 *
	 * @param string $var
	 * @return string
	 */
	public static function removeQuotes(string $var): string {
		return trim($var, " \n\r\t\v\x00'`\"");
	}
}
