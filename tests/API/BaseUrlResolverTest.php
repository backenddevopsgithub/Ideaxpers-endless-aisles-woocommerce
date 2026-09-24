<?php
namespace IdeaXperts\EndlessAisles\Tests\API;

use IdeaXperts\EndlessAisles\API\BaseUrlResolver;
use PHPUnit\Framework\TestCase;

final class BaseUrlResolverTest extends TestCase {
	public function test_qa_is_the_safe_default(): void {
		self::assertSame( 'https://app-qa.endlessaisles.io', ( new BaseUrlResolver() )->resolve() );
		self::assertSame( 'https://app-qa.endlessaisles.io', ( new BaseUrlResolver() )->resolve( 'invalid' ) );
	}

	public function test_documented_production_url_requires_confirmation(): void {
		$resolver = new BaseUrlResolver();
		self::assertSame( '', $resolver->resolve( 'production' ) );
		self::assertSame( 'https://app.endlessaisles.io', $resolver->resolve( 'production', true ) );
	}
}
