<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\ApiClientInterface;
use IdeaXperts\EndlessAisles\API\ApiException;

final class SequenceClient implements ApiClientInterface {
	/** @var list<array{string,string}> */
	public array $requests = array();
	/** @param list<array<string,mixed>|ApiException> $responses */
	public function __construct( private array $responses ) {}

	public function request( string $method, string $path ): array {
		$this->requests[] = array( $method, $path );
		$response         = array_shift( $this->responses );
		if ( $response instanceof ApiException ) {
			throw $response;
		}
		return is_array( $response ) ? $response : array();
	}
}
