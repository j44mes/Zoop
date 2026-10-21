<?php
/**
 * Build the production plugin directory and zip from an allowlist.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

/**
 * Paths that may be copied into the plugin package.
 *
 * @return string[]
 */
function seostudio_package_roots(): array {
	return array(
		'seo-studio.php',
		'seo-studio-build.php',
		'uninstall.php',
		'readme.txt',
		'src',
		'vendor',
		'assets/build',
		'languages',
		'vendor-js/yoastseo/LICENSE',
	);
}

/**
 * A relative path is rejected when it is outside the allowlist or is a development file.
 */
function seostudio_package_rejected( string $relative ): bool {
	$relative = str_replace( '\\', '/', $relative );
	$relative = ltrim( $relative, '/' );
	if ( '' === $relative || str_contains( $relative, '../' ) ) {
		return true;
	}

	$allowed = false;
	foreach ( seostudio_package_roots() as $root ) {
		if ( $relative === $root || str_starts_with( $relative, $root . '/' ) ) {
			$allowed = true;
			break;
		}
	}
	if ( ! $allowed ) {
		return true;
	}

	foreach ( explode( '/', $relative ) as $part ) {
		if ( in_array( $part, array( '.git', '.github', 'node_modules', 'tests', 'dist', 'coverage', 'docs', '.phpunit.cache' ), true ) ) {
			return true;
		}
		if ( str_starts_with( $part, '.env' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Development packages recorded by Composer. Packaging must use --no-dev.
 *
 * @return string[]
 */
function seostudio_package_dev_packages( string $root ): array {
	$path = $root . '/vendor/composer/installed.php';
	if ( ! is_readable( $path ) ) {
		return array( 'vendor/composer/installed.php' );
	}

	$installed = include $path;
	if ( ! is_array( $installed ) || ! isset( $installed['versions'] ) || ! is_array( $installed['versions'] ) ) {
		return array( 'vendor/composer/installed.php' );
	}

	$dev = array();
	foreach ( $installed['versions'] as $name => $package ) {
		if ( ! is_array( $package ) || empty( $package['dev_requirement'] ) ) {
			continue;
		}
		$dev[] = (string) $name;
	}

	return $dev;
}

/**
 * Copy the allowlist into the destination directory.
 *
 * @return string[] Problems found while copying.
 */
function seostudio_package_copy( string $root, string $destination ): array {
	$problems = seostudio_package_dev_packages( $root );
	if ( array() !== $problems ) {
		return $problems;
	}

	if ( ! is_dir( $destination ) && ! mkdir( $destination, 0777, true ) && ! is_dir( $destination ) ) {
		return array( 'Could not create ' . $destination );
	}

	foreach ( seostudio_package_roots() as $root_path ) {
		$source = $root . '/' . $root_path;
		if ( ! file_exists( $source ) ) {
			continue;
		}
		$copied = seostudio_package_copy_path( $root, $source, $destination );
		if ( array() !== $copied ) {
			return $copied;
		}
	}

	return seostudio_package_verify( $destination );
}

/**
 * Copy one allowlisted file or directory.
 *
 * @return string[]
 */
function seostudio_package_copy_path( string $root, string $source, string $destination ): array {
	if ( is_file( $source ) ) {
		$relative = substr( $source, strlen( $root ) + 1 );
		if ( ! is_string( $relative ) || seostudio_package_rejected( $relative ) ) {
			return array();
		}
		$target = $destination . '/' . $relative;
		$parent = dirname( $target );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0777, true ) && ! is_dir( $parent ) ) {
			return array( 'Could not create ' . $parent );
		}
		if ( ! copy( $source, $target ) ) {
			return array( 'Could not copy ' . $relative );
		}
		return array();
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		if ( ! is_string( $relative ) || seostudio_package_rejected( $relative ) ) {
			continue;
		}
		$target = $destination . '/' . $relative;
		$parent = dirname( $target );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0777, true ) && ! is_dir( $parent ) ) {
			return array( 'Could not create ' . $parent );
		}
		if ( ! copy( $file->getPathname(), $target ) ) {
			return array( 'Could not copy ' . $relative );
		}
	}

	return array();
}

/**
 * Required runtime files and forbidden paths in a built package.
 *
 * @return string[]
 */
function seostudio_package_verify( string $destination ): array {
	$problems = array();
	$required = array(
		'seo-studio.php',
		'uninstall.php',
		'readme.txt',
		'vendor/autoload.php',
		'vendor/woocommerce/action-scheduler/action-scheduler.php',
		'assets/build/dashboard.js',
		'assets/build/editor.js',
		'assets/build/metabox.js',
		'assets/build/worker.js',
		'src/Core/Plugin.php',
		'vendor-js/yoastseo/LICENSE',
	);
	foreach ( $required as $path ) {
		if ( ! is_readable( $destination . '/' . $path ) ) {
			$problems[] = 'Missing ' . $path;
		}
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $destination, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $destination ) + 1 );
		if ( is_string( $relative ) && seostudio_package_rejected( $relative ) ) {
			$problems[] = 'Unexpected ' . $relative;
		}
	}

	return $problems;
}

/**
 * Package this checkout. Fails when development Composer packages are installed.
 */
function seostudio_package_main(): int {
	require_once __DIR__ . '/validate-version.php';
	$root        = dirname( __DIR__ );
	$stage       = $root . '/dist/seo-studio';
	$plugin      = (string) file_get_contents( $root . '/seo-studio.php' );
	$version     = '';
	if ( 1 === preg_match( "/define\\(\\s*'SEOSTUDIO_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $plugin, $found ) ) {
		$version = $found[1];
	}
	if ( ! seostudio_version_valid( $version ) ) {
		fwrite( STDERR, "Refusing to pack version '{$version}'.\n" );
		return 1;
	}

	if ( is_dir( $stage ) ) {
		seostudio_package_remove( $stage );
	}

	$problems = seostudio_package_copy( $root, $stage );
	if ( array() !== $problems ) {
		fwrite( STDERR, "Package check failed:\n" . implode( "\n", $problems ) . "\n" );
		return 1;
	}

	$zip_path = $root . '/dist/seo-studio-' . $version . '.zip';
	if ( is_file( $zip_path ) ) {
		unlink( $zip_path );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
		fwrite( STDERR, "Could not create {$zip_path}.\n" );
		return 1;
	}
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS )
	);
	$packed = array();
	foreach ( $files as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $stage ) + 1 );
		if ( ! is_string( $relative ) ) {
			continue;
		}
		$packed[] = str_replace( '\\', '/', $relative );
	}
	sort( $packed );
	// A fixed timestamp keeps two builds of the same tree on the same checksum.
	$mtime = 1735689600;
	foreach ( $packed as $relative ) {
		$path = $stage . '/' . $relative;
		touch( $path, $mtime );
		$zip->addFile( $path, 'seo-studio/' . $relative );
	}
	$zip->close();

	fwrite( STDOUT, "Packed {$zip_path}.\n" );
	return 0;
}

/**
 * Remove a previous package directory.
 */
function seostudio_package_remove( string $directory ): void {
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $files as $file ) {
		if ( ! $file instanceof SplFileInfo ) {
			continue;
		}
		if ( $file->isDir() ) {
			rmdir( $file->getPathname() );
		} else {
			unlink( $file->getPathname() );
		}
	}
	rmdir( $directory );
}

if ( isset( $argv[0] ) && 'package-plugin.php' === basename( (string) $argv[0] ) ) {
	exit( seostudio_package_main() );
}
