<?php
namespace IdeaXperts\EndlessAisles\API;

defined( 'ABSPATH' ) || exit;

final class EndpointRegistry {
	/** @return array<string,array{method:string,path:string}> */
	public function all(): array {
		return array(
			'products'        => array(
				'method' => 'GET',
				'path'   => '/api/products',
			),
			'product'         => array(
				'method' => 'GET',
				'path'   => '/api/products/{id}',
			),
			'product_by_size' => array(
				'method' => 'GET',
				'path'   => '/api/products/bysize/{id}',
			),
			'product_option'  => array(
				'method' => 'GET',
				'path'   => '/api/products/{productId}/options/{optionId}',
			),
			'stock'           => array(
				'method' => 'GET',
				'path'   => '/api/stock',
			),
			'stock_by_upc'    => array(
				'method' => 'GET',
				'path'   => '/api/stock/byUpc',
			),
			'orders'          => array(
				'method' => 'GET',
				'path'   => '/api/orders',
			),
			'order'           => array(
				'method' => 'GET',
				'path'   => '/api/orders/{id}',
			),
			'create_order'    => array(
				'method' => 'PUT',
				'path'   => '/api/orders',
			),
		);
	}

	public function connection_test_path(): string {
		return '/api/products?page=1&per_page=1';
	}
}
