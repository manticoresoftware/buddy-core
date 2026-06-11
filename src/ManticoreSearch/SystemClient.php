<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Core\ManticoreSearch;

use LogicException;

/**
 * Client permanently bound to the trusted system.buddy identity.
 *
 * Use it for internal plugin operations (system.* tables, background
 * machinery) that must not depend on the requesting user's permissions.
 * The identity is pinned at construction and cannot be re-delegated, so a
 * SystemClient in a signature or property marks a privilege boundary that
 * the type system enforces. Obtain it via Client::getSystemClient().
 */
class SystemClient extends Client {
	/**
	 * @param ?string $url
	 * @param ?string $authToken
	 * @return void
	 */
	public function __construct(?string $url = null, ?string $authToken = null) {
		parent::__construct($url, $authToken);
		parent::setDelegatedUser(self::SYSTEM_USER);
	}

	/**
	 * The system identity is immutable: re-delegating a privileged client is
	 * a programming error, so it fails loudly. clearDelegatedUser() routes
	 * through here as well.
	 *
	 * @param ?string $user
	 * @return static
	 * @throws LogicException always
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, SlevomatCodingStandard.Functions.UnusedParameter
	public function setDelegatedUser(?string $user): static {
		throw new LogicException('SystemClient always runs as ' . self::SYSTEM_USER);
	}

	/**
	 * Already the system client: reuse this instance.
	 * @return SystemClient
	 */
	public function getSystemClient(): SystemClient {
		return $this;
	}
}
