<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\CatalogStateProviderInterface;

final class FixedCatalogStateProvider implements CatalogStateProviderInterface {
	public string $version = 'initial';
	/** @var array<string,mixed>|null */
	public ?array $target = null;
	/** @var list<string> */
	public array $upc_owners = array();
	/** @var list<string> */
	public array $sku_owners = array();
	/** @var list<array<string,mixed>> */
	public array $mappings = array();
	/** @var callable|null */
	public $on_inspect = null;

	/**
	 * @param array<string,mixed> $item
	 * @return array{fingerprint:string,target:array<string,mixed>|null,upc_owners:list<string>,sku_owners:list<string>,mappings:list<array<string,mixed>>}
	 */
	public function inspect( array $item, string $source_scope, string $environment, bool $force_refresh = false ): array {
		if ( is_callable( $this->on_inspect ) ) {
			( $this->on_inspect )();
		}
		return array(
			'fingerprint' => $this->fingerprint( $item, $source_scope, $environment, $force_refresh ),
			'target'      => $this->target,
			'upc_owners'  => $this->upc_owners,
			'sku_owners'  => $this->sku_owners,
			'mappings'    => $this->mappings,
		);
	}

	/** @param array<string,mixed> $item */
	public function fingerprint( array $item, string $source_scope, string $environment, bool $force_refresh = false ): string {
		return ApprovalManifest::hash(
			array(
				'version'      => $this->version,
				'source_scope' => $source_scope,
				'environment'  => $environment,
				'product'      => (string) ( $item['ea_product_id'] ?? '' ),
				'option'       => (string) ( $item['ea_option_id'] ?? '' ),
				'target'       => array( (int) ( $item['target_wc_product_id'] ?? $item['wc_product_id'] ?? 0 ), (int) ( $item['target_wc_variation_id'] ?? $item['wc_variation_id'] ?? 0 ) ),
				'upc'          => (string) ( $item['normalized_upc'] ?? '' ),
			)
		);
	}
}
