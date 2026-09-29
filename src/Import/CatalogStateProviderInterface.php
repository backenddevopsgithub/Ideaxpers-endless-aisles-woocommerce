<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

interface CatalogStateProviderInterface {
	/**
	 * Return a deterministic SHA-256 fingerprint of the current WooCommerce
	 * object, store-wide identifier ownership, and mapping state relevant to an
	 * import item. Implementations must be read-only.
	 *
	 * @param array<string,mixed> $item Dry-run, manifest, or import item fields.
	 */
	public function fingerprint( array $item, string $source_scope, string $environment, bool $force_refresh = false ): string;
}
