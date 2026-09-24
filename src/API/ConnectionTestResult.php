<?php
namespace IdeaXperts\EndlessAisles\API;

defined( 'ABSPATH' ) || exit;

final class ConnectionTestResult {
	public function __construct(
		public readonly bool $successful,
		public readonly string $status,
		public readonly string $message
	) {}
}
