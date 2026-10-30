<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

/** API spy for the real narrow Woo writer, not a custom-table fake. */
class WritableSimpleProduct {
	public array $fields = array();
	public array $meta = array();
	public bool $manage_stock = false;
	public ?int $stock_quantity = null;
	public string $backorders = 'no';
	public function set_status( string $value ): void { $this->fields['status'] = $value; }
	public function set_name( string $value ): void { $this->fields['name'] = $value; }
	public function set_description( string $value ): void { $this->fields['description'] = $value; }
	public function set_regular_price( string $value ): void { $this->fields['regular_price'] = $value; }
	public function set_global_unique_id( string $value ): void { $this->fields['global_unique_id'] = $value; }
	public function update_meta_data( string $key, string $value ): void { $this->meta[ $key ] = $value; }
	public function save(): int {
		$GLOBALS['ea_wc_writes'][] = $this;
		$GLOBALS['ea_wc_product_map'][601] = clone $this;
		return 601;
	}
	public function get_type(): string { return 'simple'; }
	public function read_meta_data( bool $force_read = false ): void {}
	public function get_name(): string { return $this->fields['name']; }
	public function get_description(): string { return $this->fields['description']; }
	public function get_regular_price(): string { return $this->fields['regular_price']; }
	public function get_status(): string { return $this->fields['status']; }
	public function get_global_unique_id(): string { return $this->fields['global_unique_id']; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function get_manage_stock(): bool { return $this->manage_stock; }
	public function get_stock_quantity(): ?int { return $this->stock_quantity; }
	public function get_backorders(): string { return $this->backorders; }
	public function set_manage_stock( bool $value ): void { $this->manage_stock = $value; }
	public function set_stock_quantity( ?int $value ): void { $this->stock_quantity = $value; }
	public function set_backorders( string $value ): void { $this->backorders = $value; }
}
