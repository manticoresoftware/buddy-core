<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Core\Process;

use Generator;
use RuntimeException;
use Swoole\Process as SwooleProcess;

/** @package  */
final class ProcessReader {
	/**
	 * Serialize and pack message
	 * @param array<mixed> $message
	 * @return string
	 */
	public static function packMessage(array $message): string {
		$serialized = serialize($message);
		return pack('N', strlen($serialized)) . $serialized;
	}

	/**
	 * Unpack the message and returns message to unserialize and rest of it in case have we have incomplete
	 * @param string $message
	 * @return array{0:string,1:string}
	 * @throws RuntimeException
	 */
	public static function unpackMessage(string $message): array {
		$length = static::getMessageLen($message);
		$chunk = substr($message, 4, $length);
		return [$chunk, substr($message, $length + 4)];
	}

	/**
	 * Validate if the message is complete or not
	 * cuz sometimes Swoole may send it in the different packages
	 * @param string $message
	 * @return bool
	 */
	public static function isMessageComplete(string $message): bool {
		$length = static::getMessageLen($message);
		return strlen($message) >= $length + 4;
	}

	/**
	 * @param string $message
	 * @return int
	 * @throws RuntimeException
	 */
	protected static function getMessageLen(string $message): int {
		$unpacked = unpack('N', $message);
		if (!$unpacked) {
			throw new RuntimeException('Failed to unpack message');
		}
		return $unpacked[1];
	}

	/**
	 * Wrapper to read chunked message from the worker
	 * @param SwooleProcess $worker
	 * @return Generator<string>
	 */
	public static function read(SwooleProcess $worker): Generator {
		$buffer = '';
		$chunk = $worker->read();

		if ($chunk) {
			$buffer .= $chunk;

			while ($buffer) {
				if (!static::isMessageComplete($buffer)) {
					break;
				}

				[$current, $remaining] = static::unpackMessage($buffer);
				yield $current;

				$buffer = $remaining;
			}
		}

		return $buffer;
	}
}
