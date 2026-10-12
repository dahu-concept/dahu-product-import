<?php
/**
 * The WP-CLI commands.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit WooCommerce product text in a spreadsheet and import it back.
 *
 * The usual sequence is: export, edit the file, import once to see the report,
 * then import with --execute.
 */
final class Dahu_Product_Import_CLI {

	/**
	 * Exports every product's text to a spreadsheet.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<name>]
	 * : File name inside the working directory. Defaults to a timestamped name.
	 *
	 * [--separator=<char>]
	 * : Separator to write: "," (default), ";" or "tab".
	 *
	 * [--dir=<path>]
	 * : Working directory. Absolute, and outside any web root. Defaults to
	 * $HOME/dahu-product-import.
	 *
	 * ## EXAMPLES
	 *
	 *     wp product-import export
	 *     wp product-import export --file=catalogue.csv --separator=";"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {

		$workspace = $this->workspace( $assoc_args );
		$name      = isset( $assoc_args['file'] ) && '' !== $assoc_args['file']
			? (string) $assoc_args['file']
			: 'products-' . Dahu_Product_Import_Workspace::stamp() . '.csv';

		$result = ( new Dahu_Product_Import_Exporter( $workspace ) )->run( $name, $this->separator( $assoc_args ) );

		/* translators: %d: how many products. */
		WP_CLI::log( sprintf( __( 'Products written: %d', 'dahu-product-import' ), $result['products'] ) );
		/* translators: %s: file path. */
		WP_CLI::log( sprintf( __( 'File: %s', 'dahu-product-import' ), $result['path'] ) );

		$this->report_out_of_scope( $result['out_of_scope'] );

		WP_CLI::success( __( 'Export finished. Nothing was written to the database.', 'dahu-product-import' ) );
	}

	/**
	 * Imports a spreadsheet. A dry run unless --execute is given.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : The CSV file. An absolute path, or a name inside the working directory.
	 *
	 * [--execute]
	 * : Actually write. Without it, nothing is written and only the report is
	 * produced.
	 *
	 * [--dir=<path>]
	 * : Working directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp product-import import --file=catalogue.csv
	 *     wp product-import import --file=catalogue.csv --execute
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {

		$workspace = $this->workspace( $assoc_args );
		$execute    = ! empty( $assoc_args['execute'] );

		if ( empty( $assoc_args['file'] ) ) {
			WP_CLI::error( __( 'Pass --file=<path>.', 'dahu-product-import' ) );
		}

		$path = $workspace->resolve( (string) $assoc_args['file'] );
		$plan = ( new Dahu_Product_Import_Planner() )->plan( $path );

		$report = Dahu_Product_Import_Report::build( $plan, $execute );
		$name   = 'report-' . Dahu_Product_Import_Workspace::stamp() . '.txt';
		$saved  = $workspace->write( $name, $report . "\n" );

		WP_CLI::log( $report );
		WP_CLI::log( '' );
		/* translators: %s: file path. */
		WP_CLI::log( sprintf( __( 'Report: %s', 'dahu-product-import' ), $saved ) );

		if ( ! $execute ) {
			WP_CLI::success( __( 'Dry run finished. Nothing was written. Add --execute to apply it.', 'dahu-product-import' ) );
			return;
		}

		if ( 0 === $plan['stats']['update'] ) {
			WP_CLI::success( __( 'Nothing to write: every row is identical or rejected.', 'dahu-product-import' ) );
			return;
		}

		$result = ( new Dahu_Product_Import_Importer( $workspace, array( 'WP_CLI', 'log' ) ) )->run( $plan );

		WP_CLI::log( '' );
		/* translators: %s: file path. */
		WP_CLI::log( sprintf( __( 'Backup: %s', 'dahu-product-import' ), $result['backup'] ) );
		/* translators: %s: file path. */
		WP_CLI::log( sprintf( __( 'Log: %s', 'dahu-product-import' ), $result['log'] ) );
		/* translators: %s: backup file name. */
		WP_CLI::log( sprintf( __( 'Roll back with: wp product-import rollback --file=%s', 'dahu-product-import' ), basename( $result['backup'] ) ) );

		if ( $result['stats']['errors'] ) {
			WP_CLI::warning(
				sprintf(
					/* translators: 1: products updated, 2: warnings, 3: errors. */
					__( '%1$d product(s) updated, %2$d warning(s), %3$d error(s). Read the log.', 'dahu-product-import' ),
					$result['stats']['updated'],
					$result['stats']['warnings'],
					$result['stats']['errors']
				)
			);
			return;
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: products updated, 2: warnings. */
				__( '%1$d product(s) updated, %2$d warning(s).', 'dahu-product-import' ),
				$result['stats']['updated'],
				$result['stats']['warnings']
			)
		);
	}

	/**
	 * Puts back what an import replaced. A dry run unless --execute is given.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : The backup file an import wrote.
	 *
	 * [--execute]
	 * : Actually write.
	 *
	 * [--force]
	 * : Restore even the fields somebody has edited since the import. Without
	 * it, those are left alone and reported — which is almost always what you
	 * want, because a hand fix made after a bad import is work you would be
	 * throwing away.
	 *
	 * [--dir=<path>]
	 * : Working directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp product-import rollback --file=backup-20260101-120000.jsonl
	 *     wp product-import rollback --file=backup-20260101-120000.jsonl --execute
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function rollback( $args, $assoc_args ) {

		$workspace = $this->workspace( $assoc_args );

		if ( empty( $assoc_args['file'] ) ) {
			WP_CLI::error( __( 'Pass --file=<path>.', 'dahu-product-import' ) );
		}

		$execute = ! empty( $assoc_args['execute'] );

		$stats = ( new Dahu_Product_Import_Rollback( $workspace, array( 'WP_CLI', 'log' ) ) )->run(
			(string) $assoc_args['file'],
			$execute,
			! empty( $assoc_args['force'] )
		);

		$summary = sprintf(
			/* translators: 1: fields restored, 2: fields left alone, 3: errors. */
			__( '%1$d restored, %2$d left alone, %3$d error(s).', 'dahu-product-import' ),
			$stats['restored'],
			$stats['skipped'],
			$stats['errors']
		);

		if ( ! $execute ) {
			WP_CLI::success( __( 'Dry run finished. Nothing was written.', 'dahu-product-import' ) . ' ' . $summary );
			return;
		}

		if ( $stats['errors'] ) {
			WP_CLI::warning( $summary );
			return;
		}

		WP_CLI::success( $summary );
	}

	/**
	 * Traces a field through every step and names the first that changes it.
	 *
	 * Read-only. Use it when a field is rewritten on every import although
	 * nobody edited it.
	 *
	 * ## OPTIONS
	 *
	 * --ids=<ids>
	 * : Comma-separated product ids.
	 *
	 * [--file=<path>]
	 * : An exported CSV to compare against. Without it, the trace stops at
	 * this server's own CSV encoding, which still catches most cases.
	 *
	 * [--separator=<char>]
	 * : Separator for the in-memory step: "," (default), ";" or "tab".
	 *
	 * [--dir=<path>]
	 * : Working directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp product-import trace --ids=12,34
	 *     wp product-import trace --ids=12 --file=catalogue.csv --separator=";"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function trace( $args, $assoc_args ) {

		$workspace = $this->workspace( $assoc_args );

		if ( empty( $assoc_args['ids'] ) ) {
			WP_CLI::error( __( 'Pass --ids=<comma-separated product ids>.', 'dahu-product-import' ) );
		}

		$ids = array_values(
			array_filter(
				array_map( 'absint', explode( ',', (string) $assoc_args['ids'] ) )
			)
		);

		if ( ! $ids ) {
			WP_CLI::error( __( 'No usable product id in --ids.', 'dahu-product-import' ) );
		}

		$file = ! empty( $assoc_args['file'] ) ? $workspace->resolve( (string) $assoc_args['file'] ) : null;

		WP_CLI::log(
			Dahu_Product_Import_Diagnostics::trace( $ids, $file, $this->separator( $assoc_args ) )
		);

		WP_CLI::success( __( 'Nothing was written.', 'dahu-product-import' ) );
	}

	/**
	 * Shows what this plugin is configured to read and write.
	 *
	 * The first command to run on a new site: a column the shop expected and
	 * this plugin does not know about is the single most common reason an
	 * import "does nothing" to a field.
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<path>]
	 * : Working directory.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function check( $args, $assoc_args ) {

		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::warning( __( 'WooCommerce is not active, so the attributes column will be ignored.', 'dahu-product-import' ) );
		}

		$rows = array();

		foreach ( Dahu_Product_Import_Fields::columns() as $column ) {

			$fields = Dahu_Product_Import_Fields::all();

			if ( isset( $fields[ $column ] ) ) {
				$rows[] = array(
					'column' => $column,
					'writes' => $fields[ $column ]['target'],
					'nature' => $fields[ $column ]['nature'],
					'rules'  => trim(
						( $fields[ $column ]['required'] ? 'required ' : '' )
						. ( $fields[ $column ]['max_length'] ? 'max ' . $fields[ $column ]['max_length'] : '' )
					),
				);
				continue;
			}

			$fixed = array(
				Dahu_Product_Import_Fields::COLUMN_ID         => array( __( 'matches the product', 'dahu-product-import' ), '' ),
				Dahu_Product_Import_Fields::COLUMN_SLUG       => array( __( 'confirms the id', 'dahu-product-import' ), '' ),
				Dahu_Product_Import_Fields::COLUMN_SKU        => array( '_sku', __( 'never cleared by an empty cell', 'dahu-product-import' ) ),
				Dahu_Product_Import_Fields::COLUMN_TYPE       => array( __( 'never written', 'dahu-product-import' ), __( 'for information', 'dahu-product-import' ) ),
				Dahu_Product_Import_Fields::COLUMN_CATEGORY   => array( __( 'never written', 'dahu-product-import' ), __( 'for information', 'dahu-product-import' ) ),
				Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES => array( __( 'local attributes', 'dahu-product-import' ), __( 'visible, non-variation only', 'dahu-product-import' ) ),
			);

			$rows[] = array(
				'column' => $column,
				'writes' => isset( $fixed[ $column ] ) ? $fixed[ $column ][0] : '',
				'nature' => '',
				'rules'  => isset( $fixed[ $column ] ) ? $fixed[ $column ][1] : '',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'column', 'writes', 'nature', 'rules' ) );

		$excluded = Dahu_Product_Import_Fields::excluded_product_types();

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				/* translators: %s: comma-separated product type slugs, or "none". */
				__( 'Excluded product types: %s', 'dahu-product-import' ),
				$excluded ? implode( ', ', $excluded ) : __( 'none', 'dahu-product-import' )
			)
		);

		try {
			$workspace = $this->workspace( $assoc_args );
			/* translators: %s: directory path. */
			WP_CLI::log( sprintf( __( 'Working directory: %s', 'dahu-product-import' ), $workspace->dir() ) );
		} catch ( RuntimeException $e ) {
			/* translators: %s: the reason the directory cannot be used. */
			WP_CLI::warning( sprintf( __( 'Working directory: %s', 'dahu-product-import' ), $e->getMessage() ) );
		}

		WP_CLI::success( __( 'Command loaded.', 'dahu-product-import' ) );
	}

	/**
	 * Builds the working directory, turning a failure into a CLI error.
	 *
	 * @param array $assoc_args Flags.
	 * @return Dahu_Product_Import_Workspace
	 */
	private function workspace( array $assoc_args ) {

		try {
			return new Dahu_Product_Import_Workspace( isset( $assoc_args['dir'] ) ? (string) $assoc_args['dir'] : '' );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Reads the separator flag.
	 *
	 * "tab" is spelled out because a literal tab cannot survive being typed
	 * into a shell argument.
	 *
	 * @param array $assoc_args Flags.
	 * @return string
	 */
	private function separator( array $assoc_args ) {

		$given = isset( $assoc_args['separator'] ) ? (string) $assoc_args['separator'] : ',';

		if ( 'tab' === $given || "\t" === $given ) {
			return "\t";
		}

		return ';' === $given ? ';' : ',';
	}

	/**
	 * Says how many products carry attributes the spreadsheet does not hold.
	 *
	 * @param array $out_of_scope Ids by kind.
	 * @return void
	 */
	private function report_out_of_scope( array $out_of_scope ) {

		$labels = array(
			'global'    => __( 'global attributes (pa_*)', 'dahu-product-import' ),
			'variation' => __( 'attributes used for variations', 'dahu-product-import' ),
			'hidden'    => __( 'hidden local attributes', 'dahu-product-import' ),
		);

		foreach ( $labels as $key => $label ) {

			$ids = isset( $out_of_scope[ $key ] ) ? $out_of_scope[ $key ] : array();

			if ( ! $ids ) {
				continue;
			}

			WP_CLI::log(
				sprintf(
					/* translators: 1: how many products, 2: the kind of attribute. */
					__( '%1$d product(s) carry %2$s: not in the file, and never written by an import.', 'dahu-product-import' ),
					count( $ids ),
					$label
				)
			);
		}
	}
}
