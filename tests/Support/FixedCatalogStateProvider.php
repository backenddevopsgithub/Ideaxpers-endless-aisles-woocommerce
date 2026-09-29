<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\Import\ApprovalManifest;
use IdeaXperts\EndlessAisles\Import\CatalogStateProviderInterface;

final class FixedCatalogStateProvider implements CatalogStateProviderInterface {
	public string $version = 'initial';

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
