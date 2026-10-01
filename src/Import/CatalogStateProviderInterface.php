<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

interface CatalogStateProviderInterface {
	/**
	 * Return bounded, read-only state used to authorize an existing-object link.
	 *
	 * @param array<string,mixed> $item Dry-run, manifest, or import item fields.
	 * @return array{fingerprint:string,target:array<string,mixed>|null,upc_owners:list<string>,sku_owners:list<string>,mappings:list<array<string,mixed>>}
	 */
	public function inspect( array $item, string $source_scope, string $environment, bool $force_refresh = false ): array;

	/**
	 * Return a deterministic SHA-256 fingerprint of the current WooCommerce
	 * object, store-wide identifier ownership, and mapping state relevant to an
	 * import item. Implementations must be read-only.
	 *
	 * @param array<string,mixed> $item Dry-run, manifest, or import item fields.
	 */
	public function fingerprint( array $item, string $source_scope, string $environment, bool $force_refresh = false ): string;
}
