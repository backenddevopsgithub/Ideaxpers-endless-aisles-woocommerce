<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\Inventory\InventoryStoreInterface;

final class MemoryInventoryStore implements InventoryStoreInterface {
	public string $lock        = '';
	public bool $refresh_fails = false;
	/** @var array{run_id:int,after_id:int,counts:array<string,int>}|null */
	public ?array $saved_cursor = null;
	/** @var list<array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string}> */
	public array $mappings = array();
	/** @var list<int> */
	public array $synced = array();
	/** @var array<int,array{status:string,counts:array<string,int>,message:string}> */
	public array $runs = array();
	/** @var list<string> */
	public array $successes = array();

	public function acquire_lock( int $ttl_seconds ): string {
		if ( '' !== $this->lock ) {
			return '';
		}
		$this->lock = 'token';
		return 'token';
	}

	public function refresh_lock( string $token, int $ttl_seconds ): bool {
		return ! $this->refresh_fails && $token === $this->lock;
	}

	public function release_lock( string $token ): void {
		if ( $token === $this->lock ) {
			$this->lock = '';
		}
	}

	public function cursor(): ?array {
		return $this->saved_cursor;
	}

	public function save_cursor( int $run_id, int $after_id, array $counts ): void {
		$this->saved_cursor = array(
			'run_id'   => $run_id,
			'after_id' => $after_id,
			'counts'   => $counts,
		);
	}

	public function clear_cursor(): void {
		$this->saved_cursor = null;
	}

	public function active_mappings( string $scope, string $environment, int $after_id, int $limit ): array {
		$rows = array_values( array_filter( $this->mappings, static fn( array $row ): bool => $row['id'] > $after_id ) );
		return array_slice( $rows, 0, $limit );
	}

	public function mappings_for_products( string $scope, string $environment, array $product_ids ): array {
		$result = array();
		foreach ( $this->mappings as $mapping ) {
			if ( in_array( $mapping['wc_product_id'], $product_ids, true ) ) {
				$result[ $mapping['wc_product_id'] ] = $mapping;
			}
		}
		return $result;
	}

	public function mark_synced( int $mapping_id ): void {
		$this->synced[] = $mapping_id;
	}

	public function start_run(): int {
		$id                = count( $this->runs ) + 1;
		$this->runs[ $id ] = array(
			'status'  => 'running',
			'counts'  => array(),
			'message' => '',
		);
		return $id;
	}

	public function update_run( int $run_id, string $status, array $counts, string $message ): void {
		$this->runs[ $run_id ] = compact( 'status', 'counts', 'message' );
	}

	public function record_success( string $environment ): void {
		$this->successes[] = $environment;
	}
}
