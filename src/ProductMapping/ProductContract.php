<?php
namespace IdeaXperts\EndlessAisles\ProductMapping;

defined( 'ABSPATH' ) || exit;

final class ProductContract {
	public const PRODUCT_FIELDS = array(
		'id',
		'title',
		'brand',
		'description',
		'ingredients',
		'analysis',
		'image_url',
		'additional_image_urls',
		'metadata',
		'sizes',
	);

	public const METADATA_FIELDS = array(
		'species',
		'category',
		'life_stage',
		'product_group',
		'main_ingredient',
		'breed_size',
		'special_condition',
		'subcategory',
		'type',
		'country_of_origin',
	);

	public const SIZE_FIELDS = array(
		'id',
		'upc',
		'description',
		'price',
		'wholesale',
		'purchasability',
		'discontinued',
		'minimum_advertised_price',
		'wholesale_source',
		'msrp',
		'shipping_weight_lbs',
		'phillips_item_number',
	);

	/** @param array<string,mixed> $size
	 *  @return array<string,mixed>
	 */
	public static function normalize_size( array $size ): array {
		return array_intersect_key( $size, array_flip( self::SIZE_FIELDS ) );
	}
}
