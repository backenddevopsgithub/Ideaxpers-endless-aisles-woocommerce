<?php
namespace IdeaXperts\EndlessAisles\API;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class ApiException extends RuntimeException {
	/** @param array<string,mixed> $context */
	public function __construct( string $message, private readonly array $context = array(), int $code = 0, ?Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
	}

	/** @return array<string,mixed> */
	public function context(): array {
		return $this->context;
	}
}
