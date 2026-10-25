<?php
declare( strict_types=1 );

namespace Slonik\Sql;

use RuntimeException;

final class TranslationException extends RuntimeException {

	/** @var string The construct without a PostgreSQL equivalent ('' when unknown). */
	private $construct = '';

	/**
	 * A construct without a PostgreSQL equivalent (fail closed).
	 *
	 * The construct id is the one in Slonik\Compat\Unsupported; it lets
	 * the compatibility scan report *which* construct was rejected.
	 *
	 * @param string $construct Construct id.
	 * @param string $message   The reason.
	 * @return self
	 */
	public static function unsupported( $construct, $message ): self {
		$e               = new self( (string) $message );
		$e->construct    = (string) $construct;
		return $e;
	}

	/**
	 * @return string The construct id, or '' when unknown.
	 */
	public function construct(): string {
		return $this->construct;
	}

	/**
	 * @return bool True when the statement failed because of a construct
	 *              without a PostgreSQL equivalent.
	 */
	public function isUnsupported(): bool {
		return '' !== $this->construct;
	}
}
