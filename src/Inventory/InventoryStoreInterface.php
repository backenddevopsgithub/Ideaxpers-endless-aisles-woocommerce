<?php
namespace IdeaXperts\EndlessAisles\Inventory;

defined( 'ABSPATH' ) || exit;

interface InventoryStoreInterface {
	/** Returns an owner token, or '' when another unexpired worker holds the lock. */
	public function acquire_lock( int $ttl_seconds ): string;

	/** Extends the lock only while it is still owned by $token. */
	public function refresh_lock( string $token, int $ttl_seconds ): bool;

	public function release_lock( string $token ): void;

	/** @return array{run_id:int,after_id:int,counts:array<string,int>}|null */
	public function cursor(): ?array;

	/** @param array<string,int> $counts */
	public function save_cursor( int $run_id, int $after_id, array $counts ): void;

	public function clear_cursor(): void;

	/** @return list<array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string}> */
	public function active_mappings( string $scope, string $environment, int $after_id, int $limit ): array;

	public function mark_synced( int $mapping_id ): void;

	public function start_run(): int;

	/** @param array<string,int> $counts Must contain processed, success and failure. */
	public function update_run( int $run_id, string $status, array $counts, string $message ): void;

	public function record_success( string $environment ): void;
}
