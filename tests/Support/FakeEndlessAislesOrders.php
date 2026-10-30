<?php
namespace IdeaXperts\EndlessAisles\Tests\Support;

use IdeaXperts\EndlessAisles\API\ApiClientInterface;
use IdeaXperts\EndlessAisles\API\ApiException;

/**
 * A vendor with its own order book that enforces custom_order_id uniqueness,
 * so tests can count real shipments rather than requests.
 *
 * Each queued behaviour applies to one request:
 * - 'ok'               create and return 200
 * - 'accept_timeout'   create the order, then lose the response (timeout)
 * - 'timeout'          lose the request before the vendor sees it
 * - 'server_error'     500 without creating
 * - 'accept_500'       create, then answer 500
 * - 'validation'       400 validation failure
 * - 'unauthorized'     401
 * - 'malformed'        create, answer 200 without an ID
 */
final class FakeEndlessAislesOrders implements ApiClientInterface {
	/** @var array<string,array{id:int,payload:array<string,mixed>}> Keyed by custom_order_id. */
	public array $orders = array();
	/** @var list<array{string,string,array<string,mixed>|null}> */
	public array $requests = array();
	/** @var list<string> */
	public array $behaviours = array();
	private int $next_id     = 10030;

	public function request( string $method, string $path, ?array $body = null ): array {
		$this->requests[] = array( $method, $path, $body );
		$behaviour        = array_shift( $this->behaviours ) ?? 'ok';
		if ( 'timeout' === $behaviour ) {
			throw new ApiException( 'Endless Aisles request timed out.', array( 'error_code' => 'http_request_failed' ) );
		}
		if ( 'unauthorized' === $behaviour ) {
			throw new ApiException( 'Endless Aisles returned an unsuccessful response.', array( 'status' => 401 ), 401, null, '{"error":"Unauthenticated."}' );
		}
		if ( 'server_error' === $behaviour ) {
			throw new ApiException( 'Endless Aisles returned an unsuccessful response.', array( 'status' => 500 ), 500, null, '{"error":"Server Error"}' );
		}
		if ( 'validation' === $behaviour ) {
			throw new ApiException( 'Endless Aisles returned an unsuccessful response.', array( 'status' => 400 ), 400, null, '{"error":" [\"The shipping.zip field is required.\"]"}' );
		}
		$reference = (string) ( $body['custom_order_id'] ?? '' );
		if ( isset( $this->orders[ $reference ] ) ) {
			throw new ApiException( 'Endless Aisles returned an unsuccessful response.', array( 'status' => 400 ), 400, null, '{"error":" [\"The custom_order_id has already been taken.\"]"}' );
		}
		$id                         = $this->next_id++;
		$this->orders[ $reference ] = array(
			'id'      => $id,
			'payload' => (array) $body,
		);
		if ( 'accept_timeout' === $behaviour ) {
			throw new ApiException( 'Endless Aisles request timed out.', array( 'error_code' => 'http_request_failed' ) );
		}
		if ( 'accept_500' === $behaviour ) {
			throw new ApiException( 'Endless Aisles returned an unsuccessful response.', array( 'status' => 500 ), 500, null, '{"error":"Server Error"}' );
		}
		if ( 'malformed' === $behaviour ) {
			return array( 'data' => (object) array( 'status' => 'Scheduled' ) );
		}
		return array(
			'data' => (object) array(
				'id'     => $id,
				'status' => 'Scheduled',
			),
		);
	}
}
