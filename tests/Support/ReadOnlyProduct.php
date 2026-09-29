<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

final class ReadOnlyProduct {
	public function __construct(
		private readonly int $id,
		private readonly string $type,
		private readonly string $status,
		private readonly string $name,
		private readonly string $gtin,
		private readonly string $sku,
		/** @var list<int> */
		private readonly array $children = array(),
		/** @var array<string,string> */
		private readonly array $meta = array(),
		private readonly int $parent_id = 0
	) {}

	public function get_id(): int {
		return $this->id;
	}
	public function get_type(): string {
		return $this->type;
	}
	public function get_status(): string {
		return $this->status;
	}
	public function get_name(): string {
		return $this->name;
	}
	public function get_global_unique_id(): string {
		return $this->gtin;
	}
	public function get_sku(): string {
		return $this->sku;
	}
	public function get_meta( string $key, bool $single = true ): string {
		return $this->meta[ $key ] ?? '';
	}
	/** @return list<int> */
	public function get_children(): array {
		return $this->children;
	}
	public function get_parent_id(): int {
		return $this->parent_id;
	}
	/** @param array<int,mixed> $arguments */
	public function __call( string $name, array $arguments ): mixed {
		$GLOBALS['ea_wc_writes'][] = $name;
		throw new \RuntimeException( $name );
	}
}
