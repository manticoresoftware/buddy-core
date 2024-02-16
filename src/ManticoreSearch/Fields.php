<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

class Fields {
	const TYPE_INT = 'uint';
	const TYPE_BIGINT = 'bigint';
	const TYPE_TIMESTAMP = 'timestamp';
	const TYPE_BOOL = 'bool';
	const TYPE_FLOAT = 'float';
	const TYPE_TEXT = 'text';
	const TYPE_STRING = 'string';
	const TYPE_JSON = 'json';
	const TYPE_MVA = 'mva';
	const TYPE_MVA64 = 'multi64';
	const TYPE_FLOAT_VECTOR = 'float_vector';
}
