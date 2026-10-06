<?php
namespace IdeaXperts\EndlessAisles\Import;

defined( 'ABSPATH' ) || exit;

/** Read-only: has no writer, import repository, reservation, or scheduler dependency. */
final class CreationPreview {
	public function __construct( private readonly ApprovalManifest $manifests, private readonly ImportPolicy $policy, private readonly CatalogStateProviderInterface $catalog ) {}

	/**
	 * @param array<string,mixed> $run Persisted completed run.
	 * @param array<string,mixed> $item Persisted candidate.
	 * @param array<string,mixed> $settings
	 * @return array{eligible:bool,reason:string,binding:array<string,mixed>,mapping_state:string}
	 */
	public function inspect( array $run, array $item, array $settings ): array {
		$result   = array(
			'eligible'      => false,
			'reason'        => 'creation_ineligible',
			'binding'       => array(),
			'mapping_state' => 'not inspected',
		);
		$decision = $this->policy->evaluate( $item );
		if ( ! $decision['eligible'] || 'create' !== $decision['action'] ) {
			$result['reason'] = $decision['reason'];
			return $result;
		}
		$environment = (string) ( $run['environment'] ?? '' );
		if ( ! in_array( $environment, array( 'qa', 'production' ), true ) || ( $item['environment'] ?? '' ) !== $environment || ( $item['source_scope'] ?? '' ) !== 'endless-aisles:' . $environment ) {
			$result['reason'] = 'preview_environment_mismatch';
			return $result;
		}
		try {
			$live                    = $this->catalog->inspect( $item, 'endless-aisles:' . $environment, $environment );
			$result['mapping_state'] = $live['mappings'] ? 'existing mapping' : 'no mapping';
			if ( null !== $live['target'] || $live['upc_owners'] || $live['sku_owners'] || $live['mappings'] ) {
				$result['reason'] = 'creation_existing_product';
				return $result;
			}
			$built              = $this->manifests->build( $run, array( $item ), array(), 1, $settings );
			$result['binding']  = $built['manifest']['items'][0]['creation_binding'];
			$result['reason']   = (string) $result['binding']['failure_code'];
			$result['eligible'] = '' === $result['reason'];
		} catch ( CatalogInspectionConflict ) {
			$result['reason'] = 'creation_existing_product';
		} catch ( \Throwable $error ) {
			$result['reason'] = in_array( $error->getMessage(), array( 'creation_ineligible', 'creation_invalid_upc', 'creation_title_missing', 'creation_invalid_content', 'creation_invalid_price', 'creation_policy_invalid' ), true ) ? $error->getMessage() : 'preview_state_unavailable';
		}
		return $result;
	}
}
