<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\Inventory\StockWriterInterface;

final class RecordingStockWriter implements StockWriterInterface {
	/** @var array<string,int> */
	public array $applied = array();
	/** @var array<string,string> */
	public array $outcomes     = array();
	public ?\Closure $on_apply = null;

	public function apply( array $mapping, string $environment, int $quantity, string $observed_at ): string {
		$outcome = $this->outcomes[ $mapping['ea_option_id'] ] ?? self::UPDATED;
		if ( 'throw' === $outcome ) {
			throw new \RuntimeException( 'save failed' );
		}
		if ( self::UPDATED === $outcome ) {
			$this->applied[ $mapping['ea_option_id'] ] = $quantity;
		}
		if ( $this->on_apply ) {
			( $this->on_apply )();
		}
		return $outcome;
	}
}
