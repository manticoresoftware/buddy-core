<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

use Ds\Vector;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Strings;

final class Settings {
	// Settings
	public ?string $configurationFile = null;
	public ?int $workerPid = null;
	public bool $searchdAutoSchema = true;
	/** @var ?Vector<string> $searchdListen */
	public ?Vector $searchdListen = null;
	public ?string $searchdLog = null;
	public ?string $searchdQueryLog = null;
	public ?string $searchdPidFile = null;
	public ?string $searchdDataDir = null;
	public ?string $searchdQueryLogFormat = null;
	public ?string $searchdBuddyPath = null;
	public ?string $searchdBinlogPath = null;
	public ?string $commonPluginDir = null;
	public ?string $commonLemmatizerBase = null;
	// Vars
	public ?int $autcommit = null;
	public ?int $autoOptimize = null;
	public ?string $collationConnection = null;
	public ?string $queryLogFormat = null;
	public ?int $sessionReadOnly = null;
	public ?string $logLevel = null;
	public int $maxAllowedPacket = 8388608;
	public ?string $characterSetClient = null;
	public ?string $characterSetConnection = null;
	public ?int $groupingInUtc = null;
	public ?string $lastInsertId = null;
	public ?int $pseudoSharding = null;
	public ?int $secondaryIndexes = null;
	public ?int $accurateAggregation = null;
	public ?string $threadsExEffective = null;
	public ?string $threadsEx = null;

	const CAST = [
		'searchdAutoSchema' => 'boolean',
		'autoOptimize' => 'int',
		'sessionReadOnly' => 'int',
		'maxAllowedPacket' => 'int',
		'groupingInUtc' => 'int',
		'pseudoSharding' => 'int',
		'secondaryIndexes' => 'int',
		'accurateAggregation' => 'int',
	];

	/**
	 * Create Settings structr from the Vector of configurations and variable
	 * @param Vector<array{key:string,value:mixed}> $settings each row has map with key and value
	 * @return static
	 */
	public static function fromVector(Vector $settings): static {
		$self = new static;
		foreach ($settings as ['key' => $key, 'value' => $value]) {
			$property = Strings::camelcaseBySeparator(str_replace('.', '_', $key), '_');
			if (!property_exists(static::class, $property)) {
				continue;
			}

			// Custom case for multiple listen
			if ($key === 'searchd.listen') {
				$self->$property ??= new Vector();
				$self->$property->push($value);
				continue;
			}

			if (isset(static::CAST[$property])) {
				settype($value, static::CAST[$property]);
			}
			$self->$property = $value;
		}

		$settingsJson = json_encode($self);
		Buddy::debug("settings: $settingsJson");
		return $self;
	}

	/**
	 * Detect if manticore runs in RT mode
	 * @return bool
	 */
	public function isRtMode(): bool {
		return isset($this->searchdDataDir);
	}
}
