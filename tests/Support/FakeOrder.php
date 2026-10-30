<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

/** Minimal WC_Order double: explicit address getters (the builder uses method_exists), status, items, notes. */
final class FakeOrder {
	/** @var list<string> */
	public array $notes = array();
	/** @var array<string,string> */
	public array $shipping_address;
	/** @var array<string,string> */
	public array $billing_address;
	/** @var array<string,string> */
	public array $meta = array();
	public int $saves  = 0;

	public function update_meta_data( string $key, string $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): int {
		++$this->saves;
		return $this->id;
	}

	/** @param list<FakeOrderItem> $items */
	public function __construct( private readonly int $id, public array $items, public string $status = 'processing', public bool $shipping = true ) {
		$this->shipping_address = array(
			'first_name' => 'Test',
			'last_name'  => 'Customer',
			'address_1'  => '123 Anyplace St',
			'address_2'  => 'Unit 3',
			'city'       => 'Hyrule',
			'state'      => 'nc',
			'postcode'   => '27511',
			'country'    => 'US',
		);
		$this->billing_address  = array_merge(
			$this->shipping_address,
			array(
				'first_name' => 'Billing',
				'address_1'  => '9 Billing Rd',
			)
		);
	}

	public function get_id(): int {
		return $this->id;
	}
	public function get_status(): string {
		return $this->status;
	}
	/** @return list<FakeOrderItem> */
	public function get_items( string $type = 'line_item' ): array {
		return $this->items;
	}
	public function has_shipping_address(): bool {
		return $this->shipping;
	}
	public function add_order_note( string $note ): int {
		$this->notes[] = $note;
		return count( $this->notes );
	}
	public function get_billing_email(): string {
		return 'customer@example.test';
	}
	public function get_billing_phone(): string {
		return '5555555555';
	}
	public function get_shipping_first_name(): string {
		return $this->shipping_address['first_name'];
	}
	public function get_shipping_last_name(): string {
		return $this->shipping_address['last_name'];
	}
	public function get_shipping_address_1(): string {
		return $this->shipping_address['address_1'];
	}
	public function get_shipping_address_2(): string {
		return $this->shipping_address['address_2'];
	}
	public function get_shipping_city(): string {
		return $this->shipping_address['city'];
	}
	public function get_shipping_state(): string {
		return $this->shipping_address['state'];
	}
	public function get_shipping_postcode(): string {
		return $this->shipping_address['postcode'];
	}
	public function get_shipping_country(): string {
		return $this->shipping_address['country'];
	}
	public function get_billing_first_name(): string {
		return $this->billing_address['first_name'];
	}
	public function get_billing_last_name(): string {
		return $this->billing_address['last_name'];
	}
	public function get_billing_address_1(): string {
		return $this->billing_address['address_1'];
	}
	public function get_billing_address_2(): string {
		return $this->billing_address['address_2'];
	}
	public function get_billing_city(): string {
		return $this->billing_address['city'];
	}
	public function get_billing_state(): string {
		return $this->billing_address['state'];
	}
	public function get_billing_postcode(): string {
		return $this->billing_address['postcode'];
	}
	public function get_billing_country(): string {
		return $this->billing_address['country'];
	}
}
