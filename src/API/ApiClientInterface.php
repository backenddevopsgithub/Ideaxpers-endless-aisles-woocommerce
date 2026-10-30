<?php
namespace IdeaXperts\EndlessAisles\API;

defined( 'ABSPATH' ) || exit;

interface ApiClientInterface {
	/**
	 * @param array<string,mixed>|null $body JSON request body.
	 * @return array<string,mixed>
	 */
	public function request( string $method, string $path, ?array $body = null ): array;
}
