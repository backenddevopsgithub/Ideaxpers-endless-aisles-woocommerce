<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

/** One deterministic byte budget for every import audit event. */
final class ImportAuditPayload {
	public const MAX_BYTES    = 4000;
	private const VALUE_BYTES = 128;
	private const FIELDS      = array( 'environment', 'source_scope', 'match_type', 'ownership_mode', 'preview_only', 'mapping_created', 'mapping_adopted', 'catalog_identity_id', 'previous_mapping_hash', 'previous_mapping_count', 'final_mapping', 'preview_target', 'action_id', 'dispatch_generation', 'approved_by', 'approved_at', 'approval_generation', 'before_live_hash', 'final_live_hash', 'manifest_hash', 'item_count', 'mapping_count', 'failure_code', 'reason', 'action_type', 'before_status', 'after_status' );
	private const CORE_FIELDS = array( 'environment', 'source_scope', 'match_type', 'ownership_mode', 'preview_only', 'mapping_created', 'mapping_adopted', 'catalog_identity_id', 'action_id', 'approved_by', 'approved_at', 'approval_generation', 'before_live_hash', 'final_live_hash', 'failure_code' );

	/**
	 * Large context is hashed, never truncated. Event columns and the immutable
	 * manifest retain complete EA/target/operation identities and approval state.
	 *
	 * @param array<string,mixed> $data Internal allowlisted audit context.
	 */
	public static function encode( array $data ): ?string {
		$payload = array_intersect_key( $data, array_flip( self::FIELDS ) );
		foreach ( $payload as $field => $value ) {
			$encoded = self::json( $value );
			if ( null === $encoded ) {
				return null;
			}
			if ( strlen( $encoded ) > self::VALUE_BYTES ) {
				$payload[ $field ] = array(
					'sha256' => ApprovalManifest::hash( $value ),
					'bytes'  => strlen( $encoded ),
				);
			}
		}
		$json = self::json( $payload );
		if ( null !== $json && strlen( $json ) > self::MAX_BYTES ) {
			$payload = array_merge( array_intersect_key( $payload, array_flip( self::CORE_FIELDS ) ), array( 'compacted_payload_hash' => ApprovalManifest::hash( $payload ) ) );
			$json    = self::json( $payload );
		}
		return null !== $json && strlen( $json ) <= self::MAX_BYTES ? $json : null;
	}

	private static function json( mixed $value ): ?string {
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : null;
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonicalize( $child );
		}
		return $value;
	}
}
