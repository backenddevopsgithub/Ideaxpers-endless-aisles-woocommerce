<?php
namespace IdeaXperts\EndlessAisles\Tests\OrderIntegration;

use IdeaXperts\EndlessAisles\OrderIntegration\OrderSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class OrderSubmissionRepositoryTest extends TestCase {
	private OrderSubmissionWpdb $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new OrderSubmissionWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_one_row_per_order_and_environment_even_when_line_items_change(): void {
		$repository = new OrderSubmissionRepository();

		self::assertSame( array( 'id' => 1, 'created' => true ), $repository->record_intent( $this->intent( 42, 'qa', 'hash-a' ) ) );
		self::assertSame( array( 'id' => 1, 'created' => false ), $repository->record_intent( $this->intent( 42, 'qa', 'hash-b' ) ) );
		self::assertSame( array( 'id' => 2, 'created' => true ), $repository->record_intent( $this->intent( 42, 'production', 'hash-a' ) ) );
		self::assertCount( 2, $this->wpdb->rows );
		self::assertSame( hash( 'sha256', 'eaX-qa-42' ), $this->wpdb->rows[1]['idempotency_key'] );
		self::assertSame( 'endless-aisles:qa', $this->wpdb->rows[1]['source_scope'] );
	}

	public function test_concurrent_insert_loss_returns_the_winning_row(): void {
		$this->wpdb->race_winner = $this->intent( 42, 'qa', 'hash-a' );

		self::assertSame( array( 'id' => 1, 'created' => false ), ( new OrderSubmissionRepository() )->record_intent( $this->intent( 42, 'qa', 'hash-b' ) ) );
	}

	public function test_blocked_rows_are_not_scheduled(): void {
		$intent           = $this->intent( 43, 'qa', '' );
		$intent['status'] = 'blocked';

		( new OrderSubmissionRepository() )->record_intent( $intent );

		self::assertNull( $this->wpdb->rows[1]['next_attempt_at'] );
	}

	/** @return array{wc_order_id:int,environment:string,custom_order_id:string,status:string,payload_hash:string,line_snapshot:string,last_error_code:string} */
	private function intent( int $order, string $environment, string $hash ): array {
		return array(
			'wc_order_id'     => $order,
			'environment'     => $environment,
			'custom_order_id' => 'eaX-' . $environment . '-' . $order,
			'status'          => 'pending',
			'payload_hash'    => $hash,
			'line_snapshot'   => '[]',
			'last_error_code' => '',
		);
	}
}

/** Enforces the table's unique (wc_order_id, environment) key; can simulate a concurrent winner. */
final class OrderSubmissionWpdb {
	public string $prefix  = 'wp_';
	public int $insert_id  = 0;
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	/** @var array<string,mixed>|null */
	public ?array $race_winner = null;
	/** @var list<mixed> */
	private array $args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->args = $args;
		return $query;
	}

	/** @return array<string,mixed>|null */
	public function get_row( string $query, mixed $output = null ): ?array {
		foreach ( $this->rows as $row ) {
			if ( $row['wc_order_id'] === $this->args[0] && $row['environment'] === $this->args[1] ) {
				return $row;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $data */
	public function insert( string $table, array $data ): int|false {
		if ( null !== $this->race_winner ) {
			$this->rows[1]     = array_merge( $this->race_winner, array( 'id' => 1 ) );
			$this->race_winner = null;
		}
		foreach ( $this->rows as $row ) {
			if ( $row['wc_order_id'] === $data['wc_order_id'] && $row['environment'] === $data['environment'] ) {
				return false;
			}
		}
		$this->insert_id                = count( $this->rows ) + 1;
		$this->rows[ $this->insert_id ] = array_merge( $data, array( 'id' => $this->insert_id ) );
		return 1;
	}
}
