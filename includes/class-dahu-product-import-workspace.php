<?php
/**
 * The working directory: reports, backups and logs.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * File access, in a directory that must sit outside any web root.
 *
 * The outside-the-web-root rule is enforced rather than recommended. The
 * backup file written before an import holds the previous text of every
 * product touched, and the log names each one; both are ordinary files with
 * guessable names. Served over HTTP they are a free copy of a shop's
 * editorial work, and nobody would ever find out it had been taken.
 */
final class Dahu_Product_Import_Workspace {

	/**
	 * Absolute path, no trailing slash.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Validates and prepares the directory.
	 *
	 * @param string $dir Absolute path; empty means $HOME/dahu-product-import.
	 * @throws RuntimeException Invalid, unreachable or web-exposed directory.
	 */
	public function __construct( $dir ) {

		$dir = trim( (string) $dir );

		if ( '' === $dir ) {

			$home = (string) getenv( 'HOME' );

			if ( '' === $home ) {
				throw new RuntimeException( __( 'The HOME variable is not set: pass --dir=<absolute path>.', 'dahu-product-import' ) );
			}

			$dir = rtrim( $home, '/' ) . '/dahu-product-import';
		}

		if ( 0 !== strpos( $dir, '/' ) || false !== strpos( $dir, '..' ) ) {
			throw new RuntimeException( __( 'The working directory must be an absolute path with no "..".', 'dahu-product-import' ) );
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: directory path. */
			throw new RuntimeException( sprintf( __( 'Cannot create the working directory: %s', 'dahu-product-import' ), $dir ) );
		}

		$real = realpath( $dir );

		if ( false === $real || ! is_writable( $real ) ) {
			/* translators: %s: directory path. */
			throw new RuntimeException( sprintf( __( 'The working directory is not writable: %s', 'dahu-product-import' ), $dir ) );
		}

		foreach ( self::web_roots() as $root ) {
			if ( 0 === strpos( trailingslashit( $real ), trailingslashit( $root ) ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: the web root that contains it. */
						__( 'The working directory is inside a web root (%s): choose one outside it. The backups hold your product text.', 'dahu-product-import' ),
						$root
					)
				);
			}
		}

		@chmod( $real, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort on shared hosting.

		$this->dir = untrailingslashit( $real );
	}

	/**
	 * Known web roots: this site, and any parent directory holding a WordPress.
	 *
	 * The parents are checked too, for the common case of a staging install
	 * nested inside the production one: its own ABSPATH alone would say a
	 * directory is safe while the parent site serves it.
	 *
	 * @return array<int, string>
	 */
	private static function web_roots() {

		$roots = array();
		$path  = realpath( ABSPATH );
		$here  = $path;

		while ( $path && '/' !== $path ) {

			if ( $path === $here || is_file( $path . '/wp-load.php' ) || is_file( $path . '/wp-config.php' ) ) {
				$roots[] = $path;
			}

			$path = dirname( $path );
		}

		return $roots;
	}

	/**
	 * Absolute path of the directory.
	 *
	 * @return string
	 */
	public function dir() {
		return $this->dir;
	}

	/**
	 * Path of a file inside the directory.
	 *
	 * @param string $name File name, no directory part.
	 * @return string
	 */
	public function path( $name ) {
		return $this->dir . '/' . sanitize_file_name( $name );
	}

	/**
	 * The timestamp used in file names.
	 *
	 * UTC, and not translated: these names are sorted, matched and typed back
	 * into a command line.
	 *
	 * @return string
	 */
	public static function stamp() {
		return gmdate( 'Ymd-His' );
	}

	/**
	 * Writes a text file atomically.
	 *
	 * Temporary file then rename, so a run interrupted halfway leaves the
	 * previous file intact rather than a truncated one that looks complete.
	 *
	 * @param string $name    File name.
	 * @param string $content Contents.
	 * @return string Path written.
	 * @throws RuntimeException Cannot write.
	 */
	public function write( $name, $content ) {

		$path = $this->path( $name );
		$tmp  = $path . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI, outside the web root.
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) || ! rename( $tmp, $path ) ) {
			/* translators: %s: file path. */
			throw new RuntimeException( sprintf( __( 'Cannot write: %s', 'dahu-product-import' ), $path ) );
		}

		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $path;
	}

	/**
	 * Resolves a file: an existing absolute path, or a name in this directory.
	 *
	 * @param string $file File.
	 * @return string
	 * @throws RuntimeException Not found.
	 */
	public function resolve( $file ) {

		$file = trim( (string) $file );

		if ( '' === $file ) {
			throw new RuntimeException( __( 'No file name given.', 'dahu-product-import' ) );
		}

		$path = 0 === strpos( $file, '/' ) ? $file : $this->path( $file );

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			/* translators: %s: file path. */
			throw new RuntimeException( sprintf( __( 'File not found: %s', 'dahu-product-import' ), $path ) );
		}

		return $path;
	}

	/**
	 * Reserves a free suffix for one run's files, creating the first file.
	 *
	 * WHY A TIMESTAMP IS NOT ENOUGH. The stamp has one-second resolution, so
	 * two imports started in the same second would get the same file names —
	 * and open_append() would APPEND the second run's records to the first
	 * run's backup. The command then reports that file as the one to roll back
	 * with, and rolling it back undoes BOTH imports, silently, restoring a
	 * state nobody asked for.
	 *
	 * It takes a script, or a quick retry, to hit that. It is not rare enough
	 * to leave in a tool whose whole job is to be reversible.
	 *
	 * The file is created with 'xb', which fails when it already exists, so
	 * the answer comes from the filesystem rather than from a check that could
	 * be overtaken between the looking and the creating.
	 *
	 * @param string $template File name with one %s for the suffix.
	 * @return string The suffix.
	 * @throws RuntimeException No free suffix, or cannot create the file.
	 */
	public function reserve( $template ) {

		$stamp = self::stamp();

		for ( $n = 1; $n <= 1000; $n++ ) {

			$suffix = 1 === $n ? $stamp : $stamp . '-' . $n;
			$path   = $this->path( sprintf( $template, $suffix ) );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an existing file is the expected answer here, not an error.

			if ( $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $suffix;
			}
		}

		throw new RuntimeException(
			sprintf(
				/* translators: %s: file name pattern. */
				__( 'Could not reserve a file name for %s in the working directory.', 'dahu-product-import' ),
				sprintf( $template, self::stamp() )
			)
		);
	}

	/**
	 * Opens a file for appending (the log, the backup).
	 *
	 * @param string $name File name.
	 * @return resource
	 * @throws RuntimeException Cannot open.
	 */
	public function open_append( $name ) {

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $this->path( $name ), 'ab' );

		if ( ! $handle ) {
			/* translators: %s: file path. */
			throw new RuntimeException( sprintf( __( 'Cannot open: %s', 'dahu-product-import' ), $this->path( $name ) ) );
		}

		@chmod( $this->path( $name ), 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $handle;
	}

	/**
	 * Takes an exclusive lock, so two imports cannot run at once.
	 *
	 * Two concurrent imports of different files would interleave their writes
	 * and their backups, and the backups would then restore a state that
	 * never existed.
	 *
	 * @return resource
	 * @throws RuntimeException Already locked.
	 */
	public function lock() {

		$handle = $this->open_append( 'import.lock' );

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			throw new RuntimeException( __( 'An import or a rollback is already running (import.lock).', 'dahu-product-import' ) );
		}

		return $handle;
	}
}
