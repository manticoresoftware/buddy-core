<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Doctum\Doctum;
use Symfony\Component\Finder\Finder;

$project_dir = realpath(__DIR__ . '/../');
$iterator = Finder::create()
	->files()
	->name('*.php')
	->exclude('test')
	->in($project_dir . '/src');

return new Doctum($iterator, [
	'title' => 'Manticore Buddy Core Docs',
	'build_dir' => $project_dir . '/docs',
	'cache_dir' => $project_dir . '/cache',
]);
