<?php
/**
 * Cross-platform PHP syntax lint runner.
 *
 * @package IdeaXperts\EndlessAisles
 */

declare(strict_types=1);

/**
 * Determine whether a file name represents generated PHP output.
 */
function ideaxperts_ea_lint_is_generated_file( string $file_name ): bool {
	$lower_name = strtolower( $file_name );

	return str_ends_with( $lower_name, '.generated.php' )
		|| str_ends_with( $lower_name, '.generated.inc.php' );
}

/**
 * Collect all PHP files that form part of the lint target.
 *
 * @return list<string>
 */
function ideaxperts_ea_lint_collect_files( string $root ): array {
	$files      = array();
	$entry_file = $root . DIRECTORY_SEPARATOR . 'ideaxperts-endless-aisles.php';
	$scan_paths = array( 'src', 'tests', 'tools' );
	$excluded   = array( '.cache', '.git', '.phpunit.cache', 'build', 'cache', 'caches', 'generated', 'vendor' );

	if ( ! is_file( $entry_file ) ) {
		throw new RuntimeException( 'Required lint target is missing: ideaxperts-endless-aisles.php' );
	}

	$entry_realpath = realpath( $entry_file );
	if ( false === $entry_realpath ) {
		throw new RuntimeException( 'Could not resolve lint target: ideaxperts-endless-aisles.php' );
	}
	$files[ str_replace( '\\', '/', $entry_realpath ) ] = $entry_realpath;

	foreach ( $scan_paths as $scan_path ) {
		$directory_path = $root . DIRECTORY_SEPARATOR . $scan_path;
		if ( ! is_dir( $directory_path ) ) {
			throw new RuntimeException( 'Required lint directory is missing: ' . $scan_path );
		}

		$directory = new RecursiveDirectoryIterator( $directory_path, FilesystemIterator::SKIP_DOTS );
		$filter    = new RecursiveCallbackFilterIterator(
			$directory,
			static function ( SplFileInfo $item ) use ( $excluded ): bool {
				if ( $item->isLink() ) {
					return false;
				}

				if ( $item->isDir() ) {
					return ! in_array( strtolower( $item->getFilename() ), $excluded, true );
				}

				return $item->isFile()
					&& 'php' === strtolower( $item->getExtension() )
					&& ! ideaxperts_ea_lint_is_generated_file( $item->getFilename() );
			}
		);
		$iterator  = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::LEAVES_ONLY );

		foreach ( $iterator as $file ) {
			$realpath = $file->getRealPath();
			if ( false === $realpath ) {
				throw new RuntimeException( 'Could not resolve lint target: ' . $file->getPathname() );
			}

			// Keying by the normalized real path prevents duplicate lint runs, including this file.
			$files[ str_replace( '\\', '/', $realpath ) ] = $realpath;
		}
	}

	ksort( $files, SORT_STRING );

	return array_values( $files );
}

/**
 * Lint one PHP file with the current PHP executable, without invoking a shell.
 */
function ideaxperts_ea_lint_file( string $file, string $root ): int {
	$relative_path = substr( $file, strlen( rtrim( $root, '/\\' ) ) + 1 );
	fwrite( STDOUT, 'Linting ' . str_replace( '\\', '/', $relative_path ) . PHP_EOL );

	$descriptor_spec = array(
		0 => STDIN,
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$process         = proc_open(
		array( PHP_BINARY, '-l', $file ),
		$descriptor_spec,
		$pipes,
		$root,
		null,
		array( 'bypass_shell' => true )
	);

	if ( ! is_resource( $process ) ) {
		fwrite( STDERR, 'Unable to start PHP lint for ' . $relative_path . PHP_EOL );

		return 1;
	}

	$standard_output = stream_get_contents( $pipes[1] );
	$error_output    = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit_code = proc_close( $process );

	if ( false !== $standard_output && '' !== $standard_output ) {
		fwrite( STDOUT, $standard_output );
	}
	if ( false !== $error_output && '' !== $error_output ) {
		fwrite( STDERR, $error_output );
	}

	return $exit_code;
}

/**
 * Run syntax linting for every project PHP file.
 */
function ideaxperts_ea_lint_run( string $root ): int {
	try {
		$files = ideaxperts_ea_lint_collect_files( $root );
	} catch ( Throwable $exception ) {
		fwrite( STDERR, 'Lint setup failed: ' . $exception->getMessage() . PHP_EOL );

		return 1;
	}

	$failed = false;
	foreach ( $files as $file ) {
		if ( 0 !== ideaxperts_ea_lint_file( $file, $root ) ) {
			$failed = true;
		}
	}

	if ( $failed ) {
		fwrite( STDERR, 'PHP syntax lint failed.' . PHP_EOL );

		return 1;
	}

	fwrite( STDOUT, 'PHP syntax lint passed for ' . count( $files ) . ' files.' . PHP_EOL );

	return 0;
}

$ideaxperts_ea_script_file = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CLI-provided script path.
if ( false !== $ideaxperts_ea_script_file && __FILE__ === $ideaxperts_ea_script_file ) {
	exit( ideaxperts_ea_lint_run( dirname( __DIR__ ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI exit status, not output.
}
