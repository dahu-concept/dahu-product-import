<?php
/**
 * Tracing a value through the whole chain, read-only.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds the step that changes a value.
 *
 * WHY THIS EXISTS. The usual complaint about a tool like this is "it keeps
 * rewriting a field I never touched". There are six places that can be
 * responsible — the site's own value, the export, this server's CSV encoding,
 * the spreadsheet application that saved the file, the trim, the cleaning —
 * and from the outside they are indistinguishable. Guessing wastes a day.
 * Each one is walked here and the first that alters the value is named, byte
 * offset and hex included.
 *
 * Nothing is written.
 *
 * THE OUTPUT OF THIS COMMAND IS ENGLISH AND NOT TRANSLATABLE, deliberately,
 * and the same rule the log lines follow. It is byte offsets, hex dumps, a PHP
 * version and a locale, read while something is wrong and usually pasted to
 * somebody else — a developer, a host's support desk. Half of it cannot be
 * translated at all, and a report where the headings follow the admin language
 * while the measurements do not is harder to read than one in plain English.
 */
final class Dahu_Product_Import_Diagnostics {

	/**
	 * Traces the fields of the given products.
	 *
	 * @param array<int, int> $ids       Product ids.
	 * @param string|null     $file      An exported CSV to compare against, or null.
	 * @param string          $separator Separator for the in-memory step.
	 * @return string The report.
	 * @throws RuntimeException Unreadable file.
	 */
	public static function trace( array $ids, $file, $separator ) {

		$rows = array();

		if ( $file ) {
			foreach ( Dahu_Product_Import_CSV::read( $file )['rows'] as $row ) {
				if ( isset( $row[ Dahu_Product_Import_Fields::COLUMN_ID ] ) ) {
					$rows[ (int) trim( $row[ Dahu_Product_Import_Fields::COLUMN_ID ] ) ] = $row;
				}
			}
		}

		$out   = array();
		$out[] = '=== ' . 'Product text round trip — read only' . ' ===';
		$out[] = sprintf(
			'PHP %s ; LC_CTYPE=%s ; LC_ALL=%s ; separator "%s" ; file: %s',
			PHP_VERSION,
			(string) setlocale( LC_CTYPE, 0 ),
			(string) setlocale( LC_ALL, 0 ),
			"\t" === $separator ? 'tab' : $separator,
			$file ? $file : '(none)'
		);

		foreach ( $ids as $id ) {

			$post  = get_post( $id );
			$out[] = '';

			if ( ! $post || 'product' !== $post->post_type ) {
				$out[] = sprintf( '--- #%d: no such product', $id );
				continue;
			}

			$out[] = sprintf(
				'--- %s #%d "%s"%s',
				'Product',
				$id,
				$post->post_name,
				$file && ! isset( $rows[ $id ] ) ? ' (' . 'absent from the file' . ')' : ''
			);

			$clean = 0;

			foreach ( Dahu_Product_Import_Fields::all() as $column => $field ) {
				$clean += self::trace_field( $out, $post, $id, $column, $field, $rows, $separator );
			}

			$clean += self::trace_attributes( $out, $id, $rows, $separator );

			$out[] = sprintf(
				'  %d column(s) with no difference',
				$clean
			);
		}

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Traces one declared field.
	 *
	 * @param array   $out       Report lines, by reference.
	 * @param WP_Post $post      The product.
	 * @param int     $id        Product id.
	 * @param string  $column    Column name.
	 * @param array   $field     Field declaration.
	 * @param array   $rows      Rows read from the file, by product id.
	 * @param string  $separator Separator.
	 * @return int 1 when the column shows no difference at all.
	 */
	private static function trace_field( array &$out, $post, $id, $column, array $field, array $rows, $separator ) {

		$site = 0 === strpos( $field['target'], 'post:' )
			? (string) $post->{substr( $field['target'], 5 )}
			: (string) get_post_meta( $id, $field['target'], true );

		$steps = array( '1 site' => $site );

		$steps['2 export'] = Dahu_Product_Import_Exporter::cell( $post, $field );
		$steps['3 csv']    = Dahu_Product_Import_CSV::round_trip( $steps['2 export'], $separator );

		if ( isset( $rows[ $id ][ $column ] ) ) {
			$steps['4 file'] = (string) $rows[ $id ][ $column ];
		}

		$last             = end( $steps );
		$steps['5 trim']  = trim( $last );
		$steps['6 clean'] = Dahu_Product_Import_Sanitize::clean( $steps['5 trim'], $field['nature'] );

		$writes = ! Dahu_Product_Import_Sanitize::unchanged( $site, $last, $steps['6 clean'], $field['nature'] );
		$broken = '' !== $site && ! Dahu_Product_Import_Sanitize::same( $site, $steps['6 clean'] );

		if ( ! $writes && ! $broken ) {
			return 1;
		}

		$out[] = sprintf(
			'  [%s] %s → %s: %s',
			$column,
			$field['target'],
			'verdict',
			$writes ? 'WOULD WRITE' : 'identical'
		);

		self::show_steps( $out, $steps );

		return 0;
	}

	/**
	 * Traces the attributes column.
	 *
	 * Its last step is not a cleaning but a parse-and-re-serialise, so it has
	 * a trace of its own rather than being forced into the field one.
	 *
	 * @param array  $out       Report lines, by reference.
	 * @param int    $id        Product id.
	 * @param array  $rows      Rows read from the file, by product id.
	 * @param string $separator Separator.
	 * @return int 1 when the column shows no difference.
	 */
	private static function trace_attributes( array &$out, $id, array $rows, $separator ) {

		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product = wc_get_product( $id );

		if ( ! $product ) {
			return 0;
		}

		$column = Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES;
		$site   = Dahu_Product_Import_Attributes::to_cell( Dahu_Product_Import_Attributes::pairs( $product ) );

		$steps             = array( '1 site' => $site );
		$steps['2 export'] = $site;
		$steps['3 csv']    = Dahu_Product_Import_CSV::round_trip( $steps['2 export'], $separator );

		if ( isset( $rows[ $id ][ $column ] ) ) {
			$steps['4 file'] = (string) $rows[ $id ][ $column ];
		}

		$last             = end( $steps );
		$steps['5 trim']  = trim( $last );
		$steps['6 pairs'] = Dahu_Product_Import_Attributes::to_cell(
			Dahu_Product_Import_Attributes::from_cell( $steps['5 trim'], $product )['pairs']
		);

		if ( $steps['6 pairs'] === $site ) {
			return 1;
		}

		$out[] = sprintf(
			'  [%s] %s → %s: %s',
			$column,
			'local attributes',
			'verdict',
			'WOULD WRITE'
		);

		self::show_steps( $out, $steps );

		return 0;
	}

	/**
	 * Prints each step and what it changed.
	 *
	 * The byte offset of the first difference, with hex, is the point of the
	 * whole exercise: that is what tells a non-breaking space apart from a
	 * space, and a BOM from nothing at all.
	 *
	 * @param array $out   Report lines, by reference.
	 * @param array $steps Ordered steps, label => value.
	 * @return void
	 */
	private static function show_steps( array &$out, array $steps ) {

		$labels = array_keys( $steps );

		for ( $i = 1, $n = count( $labels ); $i < $n; $i++ ) {

			$a = $steps[ $labels[ $i - 1 ] ];
			$b = $steps[ $labels[ $i ] ];

			if ( $a === $b ) {
				$out[] = sprintf(
					'    %-10s → %-10s identical (%d bytes)',
					$labels[ $i - 1 ],
					$labels[ $i ],
					strlen( $b )
				);
				continue;
			}

			$at = strspn( $a ^ $b, "\0" );

			$out[] = sprintf(
				'    %-10s → %-10s CHANGED: %d → %d bytes, first difference at byte %d%s',
				$labels[ $i - 1 ],
				$labels[ $i ],
				strlen( $a ),
				strlen( $b ),
				$at,
				Dahu_Product_Import_Sanitize::same( $a, $b ) ? ' (line endings or edges only)' : ''
			);

			$out[] = '      before: ' . Dahu_Product_Import_Sanitize::around( $a, $at );
			$out[] = '      after : ' . Dahu_Product_Import_Sanitize::around( $b, $at );
		}
	}
}
