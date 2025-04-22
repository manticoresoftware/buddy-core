<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Error;

use Throwable;

class ProcessorException extends \Exception
{
	public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
	}
}
