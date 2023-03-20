<?php

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
