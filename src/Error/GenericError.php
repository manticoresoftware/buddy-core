<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Error;

use Exception;
use Throwable;

class GenericError extends Exception {
	/** @var string $responseError */
	protected string $responseError = '';

	/** @var bool */
	protected bool $proxyOriginalError = false;

	/**
	 *
	 * @param string $message
	 * @param int $code
	 * @param ?Throwable $previous
	 * @return void
	 */
	final public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
	}

	/**
	 * Little helper to simplify error creation
	 *
	 * @param string $responseError
	 * @return static
	 */
	public static function create(string $responseError, bool $proxyOriginalError = false): static {
		$self = new static();
		$self->setResponseError($responseError);
		$self->setProxyOriginalError($proxyOriginalError);
		return $self;
	}

	/**
	 * Little proxy to little helper to not just create but throw also
	 *
	 * @param string $responseError
	 * @return static
	 */
	public static function throw(string $responseError, bool $proxyOriginalError = false): static {
		throw static::create($responseError, $proxyOriginalError);
	}

	/**
	 * Set response error that we will return to client
	 *
	 * @param string $responseError
	 * @return static
	 */
	public function setResponseError(string $responseError): static {
		$this->responseError = $responseError;

		return $this;
	}

	/**
	 * Client error message, that we return to the manticore to return to client
	 *
	 * @param string $default
	 * @return string
	 */
	public function getResponseError(string $default = 'Something went wrong'): string {
		return $this->hasResponseError() ? $this->responseError : $default;
	}

	/**
	 * Check if response error is set
	 * @return bool
	 */
	public function hasResponseError(): bool {
		return !!$this->responseError;
	}

	/**
	 * Set if we should proxy original error or not
	 *
	 * @param bool $proxyOriginalError
	 * @return static
	 */
	public function setProxyOriginalError(bool $proxyOriginalError): static {
		$this->proxyOriginalError = $proxyOriginalError;

		return $this;
	}

	/**
	 *
	 * @return bool
	 */
	public function getProxyOriginalError(): bool {
		return $this->proxyOriginalError;
	}
}
