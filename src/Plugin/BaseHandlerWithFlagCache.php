<?php declare(strict_types=1);

/*
 Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\Plugin;

use Manticoresearch\Buddy\Core\Cache\Flag;

/**
 * This is the parent class to format Manticore client responses as tables
 */
abstract class BaseHandlerWithFlagCache extends BaseHandlerWithClient {
	/** @var Flag $flagCache */
	protected Flag $flagCache;

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return ['manticoreClient', 'flagCache'];
	}

	/**
	 * @param Flag $flagCache
	 * $return Flag
	 */
	public function setFlagCache(Flag $flagCache): Flag {
		$this->flagCache = $flagCache;
		return $this->flagCache;
	}
}
