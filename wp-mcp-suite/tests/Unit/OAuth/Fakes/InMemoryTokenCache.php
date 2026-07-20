<?php
/**
 * @package MCPSuite\Tests\Unit\OAuth\Fakes
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\OAuth\Fakes;

use MCPSuite\Core\OAuth\CachedToken;
use MCPSuite\Core\OAuth\TokenCacheInterface;

final class InMemoryTokenCache implements TokenCacheInterface {

	private ?CachedToken $token = null;

	public int $set_call_count = 0;

	public function get(): ?CachedToken {
		return $this->token;
	}

	public function set( CachedToken $token ): void {
		$this->token = $token;
		$this->set_call_count++;
	}

	public function clear(): void {
		$this->token = null;
	}
}
