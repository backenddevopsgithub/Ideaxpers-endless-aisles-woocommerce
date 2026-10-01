<?php
namespace IdeaXperts\EndlessAisles\Tests\Support {
	/** Test-only overrides for immutable PHP constants, with normal delegation by default. */
	final class SigningKeyConstants {
		/** @var array<string,mixed>|null */
		public static ?array $values = null;
	}
}

namespace IdeaXperts\EndlessAisles\Database {
	function defined( string $name ): bool {
		$values = \IdeaXperts\EndlessAisles\Tests\Support\SigningKeyConstants::$values;
		if ( null !== $values && in_array( $name, array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ), true ) ) {
			return array_key_exists( $name, $values );
		}
		return \defined( $name );
	}

	function constant( string $name ): mixed {
		$values = \IdeaXperts\EndlessAisles\Tests\Support\SigningKeyConstants::$values;
		if ( null !== $values && in_array( $name, array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ), true ) ) {
			return $values[ $name ];
		}
		return \constant( $name );
	}
}
