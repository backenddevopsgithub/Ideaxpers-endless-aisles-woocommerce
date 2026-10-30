<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

interface OrderSubmissionStoreInterface {
	/**
	 * Insert the one submission row for (order, environment), or return the existing one.
	 *
	 * @param array{wc_order_id:int,environment:string,custom_order_id:string,status:string,payload_hash:string,line_snapshot:string,last_error_code:string} $intent
	 * @return array{id:int,created:bool}|null Null only when neither insert nor lookup succeeded.
	 */
	public function record_intent( array $intent ): ?array;

	/** @return array<string,mixed>|null */
	public function find_for_order( int $wc_order_id, string $environment ): ?array;

	/** @return list<array<string,mixed>> */
	public function recent( int $limit ): array;
}
