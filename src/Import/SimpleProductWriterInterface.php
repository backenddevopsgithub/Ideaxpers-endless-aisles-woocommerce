<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

interface SimpleProductWriterInterface {
	/**
	 * @param array<string,mixed> $item
	 * @param array<string,string> $projection
	 */
	public function create_draft( array $item, array $projection ): int;

	/**
	 * Return at most two exact operation matches. Throw on failed read or invalid correlation.
	 * @param array<string,mixed> $item
	 * @return list<int>
	 */
	public function correlated_drafts( array $item ): array;
}
