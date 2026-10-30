<?php
namespace IdeaXperts\EndlessAisles\Tests\OrderIntegration;

use IdeaXperts\EndlessAisles\OrderIntegration\CustomOrderId;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderPayloadBuilder;
use IdeaXperts\EndlessAisles\OrderIntegration\OrderPayloadException;
use IdeaXperts\EndlessAisles\Tests\Support\FakeOrder;
use IdeaXperts\EndlessAisles\Tests\Support\FakeOrderItem;
use PHPUnit\Framework\TestCase;

final class OrderPayloadBuilderTest extends TestCase {
	/** @var array<int,array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string}> */
	private array $mappings = array(
		701 => array(
			'id'            => 1,
			'wc_product_id' => 701,
			'ea_product_id' => '44095',
			'ea_option_id'  => '50770',
		),
		702 => array(
			'id'            => 2,
			'wc_product_id' => 702,
			'ea_product_id' => '37661',
			'ea_option_id'  => '57015',
		),
	);

	public function test_mixed_cart_sends_only_vendor_lines_with_documented_fields(): void {
		$order = $this->order( array( $this->line( 701, 2, 73.98 ), new FakeOrderItem( 999, 1, 10.0 ) ) );

		$payload = ( new OrderPayloadBuilder() )->build( $order, 'qa', $this->mappings );

		self::assertNotNull( $payload );
		self::assertSame(
			array(
				array(
					'id'       => 50770,
					'quantity' => 2,
					'price'    => 36.99,
				),
			),
			$payload->payload['line_items']
		);
		self::assertSame( CustomOrderId::for_order( 5001, 'qa' ), $payload->payload['custom_order_id'] );
		self::assertSame(
			array(
				'first_name'     => 'Test',
				'last_name'      => 'Customer',
				'address_line_1' => '123 Anyplace St',
				'city'           => 'Hyrule',
				'state'          => 'NC',
				'zip'            => '27511',
				'address_line_2' => 'Unit 3',
				'country'        => 'US',
			),
			$payload->payload['shipping']
		);
		self::assertSame( '36.99', $payload->lines[0]['price'] );
	}

	public function test_no_personal_contact_data_is_sent_or_snapshotted(): void {
		$payload = ( new OrderPayloadBuilder() )->build( $this->order( array( $this->line( 701, 1, 10.0 ) ) ), 'qa', $this->mappings );

		$encoded = (string) wp_json_encode( $payload?->payload ) . (string) wp_json_encode( $payload?->lines );
		self::assertStringNotContainsString( 'customer@example.test', $encoded );
		self::assertStringNotContainsString( '5555555555', $encoded );
		self::assertStringNotContainsString( 'Anyplace', (string) wp_json_encode( $payload?->lines ) );
	}

	public function test_order_without_vendor_lines_produces_no_payload(): void {
		self::assertNull( ( new OrderPayloadBuilder() )->build( $this->order( array( new FakeOrderItem( 999, 1, 10.0 ) ) ), 'qa', $this->mappings ) );
	}

	public function test_hash_is_stable_across_line_order_and_rebuilds_and_changes_with_content(): void {
		$builder                             = new OrderPayloadBuilder();
		$a                                   = $builder->build( $this->order( array( $this->line( 701, 1, 10.0 ), $this->line( 702, 3, 30.0 ) ) ), 'qa', $this->mappings );
		$b                                   = $builder->build( $this->order( array( $this->line( 702, 3, 30.0 ), $this->line( 701, 1, 10.0 ) ) ), 'qa', $this->mappings );
		$c                                   = $builder->build( $this->order( array( $this->line( 702, 2, 20.0 ), $this->line( 701, 1, 10.0 ) ) ), 'qa', $this->mappings );
		$moved                               = $this->order( array( $this->line( 701, 1, 10.0 ), $this->line( 702, 3, 30.0 ) ) );
		$moved->shipping_address['postcode'] = '27512';

		self::assertSame( $a?->hash, $b?->hash );
		self::assertNotSame( $a?->hash, $c?->hash );
		self::assertNotSame( $a?->hash, $builder->build( $moved, 'qa', $this->mappings )?->hash );
		self::assertSame( 64, strlen( (string) $a?->hash ) );
	}

	public function test_custom_order_id_is_deterministic_and_environment_specific(): void {
		self::assertSame( CustomOrderId::for_order( 77, 'qa' ), CustomOrderId::for_order( 77, 'qa' ) );
		self::assertNotSame( CustomOrderId::for_order( 77, 'qa' ), CustomOrderId::for_order( 77, 'production' ) );
		self::assertMatchesRegularExpression( '/\Aea[a-f0-9]{8}-qa-77\z/', CustomOrderId::for_order( 77, 'qa' ) );
	}

	public function test_billing_address_is_used_when_order_has_no_shipping_address(): void {
		$order           = $this->order( array( $this->line( 701, 1, 10.0 ) ) );
		$order->shipping = false;

		self::assertSame( 'Billing', ( new OrderPayloadBuilder() )->build( $order, 'qa', $this->mappings )?->payload['shipping']['first_name'] );
	}

	public function test_unsendable_vendor_lines_fail_with_stable_codes(): void {
		$cases = array(
			'shipping_country_unsupported' => fn( FakeOrder $order ) => $order->shipping_address['country'] = 'CA',
			'shipping_state_invalid'       => fn( FakeOrder $order ) => $order->shipping_address['state'] = 'Ontario',
			'shipping_address_incomplete'  => fn( FakeOrder $order ) => $order->shipping_address['city'] = ' ',
		);
		foreach ( $cases as $code => $mutate ) {
			$order = $this->order( array( $this->line( 701, 1, 10.0 ) ) );
			$mutate( $order );
			$this->assertFailure( $code, $order, $this->mappings );
		}

		$this->assertFailure( 'line_not_vendor_owned', $this->order( array( new FakeOrderItem( 701, 1, 10.0, new \WC_Product_Simple() ) ) ), $this->mappings );
		$this->assertFailure( 'line_not_vendor_owned', $this->order( array( new FakeOrderItem( 701, 1, 10.0, FakeOrderItem::owned_product( '44095', '50770', 'production' ) ) ) ), $this->mappings );
		$this->assertFailure( 'line_variation_unsupported', $this->order( array( new FakeOrderItem( 701, 1, 10.0, FakeOrderItem::owned_product( '44095', '50770' ), 9 ) ) ), $this->mappings );
		$this->assertFailure( 'line_price_invalid', $this->order( array( $this->line( 701, 1, 0.0 ) ) ), $this->mappings );

		$bad_id                      = $this->mappings;
		$bad_id[701]['ea_option_id'] = 'opt-1';
		$this->assertFailure( 'line_identifier_invalid', $this->order( array( new FakeOrderItem( 701, 1, 10.0, FakeOrderItem::owned_product( '44095', 'opt-1' ) ) ) ), $bad_id );
	}

	/** @param array<int,array{id:int,wc_product_id:int,ea_product_id:string,ea_option_id:string}> $mappings */
	private function assertFailure( string $code, FakeOrder $order, array $mappings ): void {
		try {
			( new OrderPayloadBuilder() )->build( $order, 'qa', $mappings );
			self::fail( $code . ' was not raised.' );
		} catch ( OrderPayloadException $exception ) {
			self::assertSame( $code, $exception->getMessage() );
		}
	}

	private function line( int $product_id, int $quantity, float $subtotal ): FakeOrderItem {
		$mapping = $this->mappings[ $product_id ];
		return new FakeOrderItem( $product_id, $quantity, $subtotal, FakeOrderItem::owned_product( $mapping['ea_product_id'], $mapping['ea_option_id'] ) );
	}

	/** @param list<FakeOrderItem> $items */
	private function order( array $items ): FakeOrder {
		return new FakeOrder( 5001, $items );
	}
}
