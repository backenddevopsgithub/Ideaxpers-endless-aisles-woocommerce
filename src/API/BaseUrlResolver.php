<?php
namespace IdeaXperts\EndlessAisles\API;

defined( 'ABSPATH' ) || exit;

final class BaseUrlResolver {
	public const QA_URL         = 'https://app-qa.endlessaisles.io/';
	public const PRODUCTION_URL = 'https://app.endlessaisles.io/';

	public function resolve( string $environment = 'qa', bool $production_confirmed = false ): string {
		$environment = 'production' === $environment ? 'production' : 'qa';
		if ( 'production' === $environment && ! $production_confirmed ) {
			return '';
		}
		return untrailingslashit( 'production' === $environment ? self::PRODUCTION_URL : self::QA_URL );
	}
}
