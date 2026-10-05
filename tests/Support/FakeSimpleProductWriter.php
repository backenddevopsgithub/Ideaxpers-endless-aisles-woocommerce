<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\Import\SimpleProductWriterInterface;

/** External store survives custom-table transaction rollback. */
final class FakeSimpleProductWriter implements SimpleProductWriterInterface {
	public int $saves = 0;
	public string $mode = 'success';
	public array $objects = array();
	public $after_persistence = null;
	public $before_persistence = null;
	public $before_lookup = null;
	public bool $lookup_failure = false;
	public int $lookups = 0;

	public function __construct( private readonly FixedCatalogStateProvider $state ) {}

	public function create_draft( array $item, array $projection ): int {
		++$this->saves;
		if ( 'before' === $this->mode ) {
			throw new \RuntimeException( 'Before persistence' );
		}
		if ( is_callable( $this->before_persistence ) ) {
			( $this->before_persistence )();
		}
		$this->objects[501] = array( 'item' => $item, 'projection' => $projection, 'type' => 'simple', 'status' => 'draft' );
		$this->state->upc_owners = array( '501:0' );
		$this->state->target = array( 'wc_product_id' => 501, 'wc_variation_id' => 0, 'product_type' => 'simple', 'product_status' => 'draft', 'upcs' => array( (string) $item['normalized_upc'] ) );
		if ( is_callable( $this->after_persistence ) ) {
			( $this->after_persistence )();
		}
		if ( 'ambiguous' === $this->mode ) {
			throw new \RuntimeException( 'Persisted before ID returned' );
		}
		return 'mismatch' === $this->mode ? 999 : 501;
	}

	public function correlated_drafts( array $item ): array {
		++$this->lookups;
		if ( is_callable( $this->before_lookup ) ) { ( $this->before_lookup )(); }
		if ( $this->lookup_failure ) { throw new \RuntimeException( 'correlation_read_failed' ); }
		$ids = array();
		foreach ( $this->objects as $id => $object ) {
			if ( $object['item']['operation_uuid'] !== $item['operation_uuid'] ) {
				continue;
			}
			foreach ( array( 'source_scope', 'environment', 'ea_product_id', 'ea_option_id', 'id', 'normalized_upc' ) as $field ) {
				if ( $object['item'][ $field ] !== $item[ $field ] ) {
					throw new \RuntimeException( 'Wrong correlation' );
				}
			}
			if ( 'simple' !== $object['type'] || 'draft' !== $object['status'] ) {
				throw new \RuntimeException( 'Wrong object' );
			}
			$ids[] = $id;
		}
		return array_slice( $ids, 0, 2 );
	}
}
