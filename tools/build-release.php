<?php
/**
 * Build an allowlisted production plugin ZIP.
 *
 * @package IdeaXperts\EndlessAisles
 */

declare(strict_types=1);

const IDEAXPERTS_EA_RELEASE_SLUG    = 'ideaxperts-endless-aisles';
const IDEAXPERTS_EA_RELEASE_VERSION = '0.1.0';

/** Remove a build path without following symbolic links. */
function ideaxperts_ea_remove_path( string $path, string $allowed_root ): void {
	$normalized_path = str_replace( '\\', '/', $path );
	$normalized_root = rtrim( str_replace( '\\', '/', $allowed_root ), '/' ) . '/';
	if ( ! str_starts_with( $normalized_path . '/', $normalized_root ) ) {
		throw new RuntimeException( 'Refusing to remove a path outside the build directory.' );
	}
	if ( is_link( $path ) || is_file( $path ) ) {
		if ( ! unlink( $path ) ) {
			throw new RuntimeException( 'Could not remove an existing build file.' );
		}
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS );
	foreach ( $items as $item ) {
		ideaxperts_ea_remove_path( $item->getPathname(), $allowed_root );
	}
	if ( ! rmdir( $path ) ) {
		throw new RuntimeException( 'Could not remove an existing build directory.' );
	}
}

/** Copy an allowlisted file or directory without following symbolic links. */
function ideaxperts_ea_copy_path( string $source, string $destination ): void {
	if ( is_link( $source ) ) {
		throw new RuntimeException( 'Symbolic links are not permitted in the release package.' );
	}
	if ( is_file( $source ) ) {
		$parent = dirname( $destination );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
			throw new RuntimeException( 'Could not create a release directory.' );
		}
		if ( ! copy( $source, $destination ) ) {
			throw new RuntimeException( 'Could not copy an allowlisted release file.' );
		}
		return;
	}
	if ( ! is_dir( $source ) ) {
		throw new RuntimeException( 'An allowlisted release path is missing: ' . basename( $source ) );
	}
	$iterator = new FilesystemIterator( $source, FilesystemIterator::SKIP_DOTS );
	foreach ( $iterator as $item ) {
		ideaxperts_ea_copy_path( $item->getPathname(), $destination . DIRECTORY_SEPARATOR . $item->getFilename() );
	}
}

/** Build the production ZIP from an explicit source allowlist. */
function ideaxperts_ea_build_release(): void {
	$root       = dirname( __DIR__ );
	$build_root = $root . DIRECTORY_SEPARATOR . 'build';
	$stage      = $build_root . DIRECTORY_SEPARATOR . IDEAXPERTS_EA_RELEASE_SLUG;
	$zip_path   = $build_root . DIRECTORY_SEPARATOR . IDEAXPERTS_EA_RELEASE_SLUG . '-' . IDEAXPERTS_EA_RELEASE_VERSION . '.zip';

	try {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'The PHP zip extension is required to build a release.' );
		}
		if ( is_dir( $build_root ) ) {
			ideaxperts_ea_remove_path( $build_root, $build_root );
		}
		if ( ! mkdir( $stage, 0755, true ) && ! is_dir( $stage ) ) {
			throw new RuntimeException( 'Could not create the release staging directory.' );
		}

		$runtime_paths = array(
			'ideaxperts-endless-aisles.php',
			'src',
			'README.md',
			'CHANGELOG.md',
			'docs',
		);
		foreach ( $runtime_paths as $relative_path ) {
			ideaxperts_ea_copy_path( $root . DIRECTORY_SEPARATOR . $relative_path, $stage . DIRECTORY_SEPARATOR . $relative_path );
		}

		// Composer manifests are build inputs only and are removed before packaging.
		ideaxperts_ea_copy_path( $root . DIRECTORY_SEPARATOR . 'composer.json', $stage . DIRECTORY_SEPARATOR . 'composer.json' );
		ideaxperts_ea_copy_path( $root . DIRECTORY_SEPARATOR . 'composer.lock', $stage . DIRECTORY_SEPARATOR . 'composer.lock' );
		$command = 'composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --working-dir=' . escapeshellarg( $stage );
		passthru( $command, $composer_exit );
		if ( 0 !== $composer_exit || ! is_file( $stage . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' ) ) {
			throw new RuntimeException( 'Production Composer installation failed.' );
		}
		unlink( $stage . DIRECTORY_SEPARATOR . 'composer.json' );
		unlink( $stage . DIRECTORY_SEPARATOR . 'composer.lock' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create the release ZIP.' );
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $files as $file ) {
			if ( $file->isLink() || ! $file->isFile() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $stage ) + 1 );
			$zip->addFile( $file->getPathname(), IDEAXPERTS_EA_RELEASE_SLUG . '/' . str_replace( '\\', '/', $relative ) );
		}
		$zip->close();
		fwrite( STDOUT, "Release created: {$zip_path}" . PHP_EOL );
	} catch ( Throwable $exception ) {
		fwrite( STDERR, 'Release build failed: ' . $exception->getMessage() . PHP_EOL );
		exit( 1 );
	}
}

ideaxperts_ea_build_release();
