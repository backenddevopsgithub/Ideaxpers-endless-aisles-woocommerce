<?php
namespace IdeaXperts\EndlessAisles\API;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class ApiException extends RuntimeException {
	public const BODY_EXCERPT_BYTES = 1000;

	/**
	 * @param array<string,mixed> $context Loggable classification only.
	 * @param string              $body    Bounded vendor error body; kept out of context so it is never logged implicitly.
	 */
	public function __construct( string $message, private readonly array $context = array(), int $code = 0, ?Throwable $previous = null, private readonly string $body = '' ) {
		parent::__construct( $message, $code, $previous );
	}

	/** @return array<string,mixed> */
	public function context(): array {
		return $this->context;
	}

	public function body(): string {
		return $this->body;
	}
}
