<?php
namespace IdeaXperts\EndlessAisles\Catalog;

defined( 'ABSPATH' ) || exit;

/** Presentation only: never schedules work or changes a run. */
final class DryRunProgress {
	/** @param array<string,mixed> $run
	 *  @return array<string,mixed>
	 */
	public static function summarize( array $run ): array {
		$status    = (string) ( $run['status'] ?? 'pending' );
		$phases    = array(
			'pending'          => 'Queued',
			'scanning_store'   => 'Inspecting local catalog',
			'fetching_catalog' => 'Fetching catalog and classifying options',
			'cancelling'       => 'Cancelling safely',
			'recovering'       => 'Recovering safely',
			'completed'        => 'Completed',
			'failed'           => 'Failed',
			'cancelled'        => 'Cancelled',
		);
		$matches   = self::counts( $run['match_counters'] ?? null, MatchClassifier::CLASSIFICATIONS );
		$flags     = self::counts( $run['warning_counters'] ?? null, MatchClassifier::REVIEW_FLAGS );
		$total     = isset( $run['catalog_total_products'] ) ? max( 0, (int) $run['catalog_total_products'] ) : null;
		$processed = isset( $run['catalog_products_processed'] ) ? max( 0, (int) $run['catalog_products_processed'] ) : null;
		$percent   = null;
		if ( 'local' === ( $run['environment'] ?? '' ) && isset( $run['store_products_scanned'] ) && (int) ( $run['store_total_products'] ?? 0 ) > 0 ) {
			$percent = min( 100, (int) floor( 100 * max( 0, (int) $run['store_products_scanned'] ) / (int) $run['store_total_products'] ) );
		} elseif ( 'local' === ( $run['environment'] ?? '' ) && (int) ( $run['store_total_pages'] ?? 0 ) > 0 ) {
			$percent = min( 100, (int) floor( 100 * max( 0, (int) ( $run['current_store_page'] ?? 0 ) ) / (int) $run['store_total_pages'] ) );
		} elseif ( 'local' !== ( $run['environment'] ?? 'qa' ) && null !== $processed && null !== $total && $total > 0 ) {
			// This is scan coverage; completion is always determined by the persisted status.
			$percent = min( 100, (int) floor( 100 * $processed / $total ) );
		} elseif ( 'local' !== ( $run['environment'] ?? 'qa' ) && (int) ( $run['catalog_total_pages'] ?? 0 ) > 0 ) {
			$percent = min( 100, (int) floor( 100 * max( 0, (int) ( $run['current_api_page'] ?? 0 ) ) / (int) $run['catalog_total_pages'] ) );
		}
		return array(
			'phase'       => $phases[ $status ] ?? 'Unknown state',
			'state'       => 'pending' === $status ? 'Queued' : ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ? $phases[ $status ] : 'Running' ),
			'environment' => 'production' === ( $run['environment'] ?? '' ) ? 'Production Preview' : ( 'local' === ( $run['environment'] ?? '' ) ? 'Local — no API requests' : 'QA' ),
			'active'      => in_array( $status, array( 'pending', 'scanning_store', 'fetching_catalog', 'cancelling', 'recovering' ), true ),
			'matches'     => $matches,
			'flags'       => $flags,
			'options'     => array_sum( $matches ),
			'processed'   => $processed,
			'total'       => $total,
			'percent'     => $percent,
			'error'       => self::safe_error( (string) ( $run['error_summary'] ?? '' ) ),
			'error_count' => empty( $run['error_summary'] ) ? 0 : 1,
		);
	}

	/** @param list<string> $allowed
	 *  @return array<string,int>
	 */
	private static function counts( mixed $raw, array $allowed ): array {
		$data   = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$result = array();
		foreach ( $allowed as $key ) {
			$result[ $key ] = is_array( $data ) && is_numeric( $data[ $key ] ?? null ) ? max( 0, (int) $data[ $key ] ) : 0;
		}
		return $result;
	}

	private static function safe_error( string $message ): string {
		if ( '' === $message ) {
			return '';
		}
		$allowed = array(
			'Endless Aisles request timed out.',
			'Endless Aisles request failed.',
			'Endless Aisles returned an unsuccessful response.',
			'Endless Aisles returned invalid JSON.',
			'Endless Aisles returned an invalid pagination structure.',
			'Catalog dry-run processing failed.',
		);
		return in_array( $message, $allowed, true ) ? $message : 'The operation failed. Check the redacted logs and retry only when the cause is resolved.';
	}
}
