<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Plugin;

use Manticoresearch\Buddy\Core\Task\Task;
use parallel\Runtime;

abstract class BaseHandler {
	/** @return Task */
	abstract public function run(Runtime $runtime): Task;

	/** @return array<string> */
	abstract public function getProps(): array;
}
