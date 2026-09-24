<?php
namespace IdeaXperts\EndlessAisles\Core;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Container {
	/** @var array<string,callable> */
	private array $factories = array();
	/** @var array<string,object> */
	private array $instances = array();

	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
	}

	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException( 'Unknown service: ' . esc_html( $id ) );
		}
		$this->instances[ $id ] = ( $this->factories[ $id ] )();
		return $this->instances[ $id ];
	}
}
