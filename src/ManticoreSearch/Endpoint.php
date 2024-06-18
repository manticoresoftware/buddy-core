<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

enum Endpoint: string {
	case Cli = 'cli';
	case CliJson = 'cli_json';
	case Insert = 'insert';
	case Sql = 'sql?mode=raw';
	case Bulk = 'bulk';
	case Elastic = 'elastic';
	case Search = 'search';
	case Update = 'update';
	case Autocomplete = 'autocomplete';
}
