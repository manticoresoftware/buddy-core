<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Core\Plugin;

use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings as ManticoreSettings;
use Manticoresearch\Buddy\Core\Network\Request as NetworkRequest;

abstract class Request {
	protected ManticoreSettings $manticoreSettings;

	/**
	 * Set current settings to use in request
	 *
	 * @param ManticoreSettings $manticoreSettings
	 * @return static
	 */
	public function setManticoreSettings(ManticoreSettings $manticoreSettings): static {
		$this->manticoreSettings = $manticoreSettings;
		return $this;
	}

	/**
	 * Get current settings
	 * @return ManticoreSettings
	 */
	public function getManticoreSettings(): ManticoreSettings {
		return $this->manticoreSettings;
	}

	/**
	 * Redirect all /cli requests to /sql endpoint
	 *
	 * @param NetworkRequest $request
	 * @return array{0:string,1:boolean}
	 */
	public static function getEndpointInfo(NetworkRequest $request): array {
		return ($request->endpointBundle === ManticoreEndpoint::Cli)
			? [ManticoreEndpoint::Sql->value, true] : [$request->path, false];
	}

	/**
	 * @param NetworkRequest $request
	 * @return static
	 */
	abstract public static function fromNetworkRequest(NetworkRequest $request): static;

	/**
	 * This method validates if this request suites for our plugin or not
	 * @param NetworkRequest $request
	 * @return bool
	 */
	abstract public static function hasMatch(NetworkRequest $request): bool;
}
