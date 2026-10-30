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

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array;

	/**
	 * Rows a sweep should look at: pending/unknown whose retry time has come, and
	 * submitting rows whose lease expired (a worker died mid-request).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function due( int $limit ): array;

	/**
	 * Compare-and-swap into `submitting`, committed before any HTTP request.
	 * Returns the new owner token, or '' if another worker or state change won.
	 */
	public function claim( int $id, string $from_status, int $lease_seconds ): string;

	/**
	 * Settle an owned `submitting` row; fails if the lease owner changed.
	 *
	 * @param array<string,string|int|null> $fields
	 */
	public function settle( int $id, string $token, string $to_status, array $fields ): bool;

	/**
	 * Unowned compare-and-swap for rows no worker holds (blocking before a claim,
	 * stale intents, expired leases). `$expected` adds equality conditions.
	 *
	 * @param array<string,string|int> $expected
	 * @param array<string,string|int|null> $fields
	 */
	public function transition( int $id, string $from_status, array $expected, string $to_status, array $fields ): bool;
}
