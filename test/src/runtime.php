<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

// Initialize runtime environment for unit tests to run Task in threads
include_once __DIR__ . DIRECTORY_SEPARATOR
  . '..' . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
  . '..' . DIRECTORY_SEPARATOR
  . '..' . DIRECTORY_SEPARATOR
	. 'vendor' . DIRECTORY_SEPARATOR
	. 'autoload.php'
;

const MOCK_BUDDY_VERSION = '1.0.0';

Manticoresearch\Buddy\Core\Tool\Buddy::setVersionFile(MOCK_BUDDY_VERSION);
