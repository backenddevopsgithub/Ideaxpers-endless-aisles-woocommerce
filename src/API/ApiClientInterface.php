<?php
namespace IdeaXperts\EndlessAisles\API;

defined( 'ABSPATH' ) || exit;

interface ApiClientInterface {
	/** @return array<string,mixed> */
	public function request( string $method, string $path ): array;
}
