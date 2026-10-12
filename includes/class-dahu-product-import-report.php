<?php
/**
 * The report.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a plan into something a person reads before deciding.
 *
 * The report is the product, as much as the import is. An import of this kind
 * is only safe if somebody can see, beforehand, exactly what it will change —
 * so the dry run's output is written to be read by whoever edited the
 * spreadsheet, not by whoever wrote this plugin.
 *
 * Three sections exist specifically to catch the mistakes that otherwise pass:
 * unrecognised columns (a misspelt header imports nothing, silently, file
 * after file), erasures grouped by column (a column accidentally emptied in
 * the spreadsheet shows up as two hundred erasures in one line), and rejected
 * rows with their reasons.
 */
final class Dahu_Product_Import_Report {

	/**
	 * Builds the text report.
	 *
	 * @param array $plan    The plan.
	 * @param bool  $execute True for a real import, false for a dry run.
	 * @return string
	 */
	public static function build( array $plan, $execute ) {

		$symbols = array(
			'add'    => '+',
			'change' => '~',
			'clear'  => '-',
		);

		$out = array();

		$out[] = '=== ' . ( $execute
			? __( 'Product text import — REAL IMPORT', 'dahu-product-import' )
			: __( 'Product text import — DRY RUN (nothing is written)', 'dahu-product-import' ) ) . ' ===';

		$out[] = sprintf( '%-10s %s', __( 'Site', 'dahu-product-import' ), $plan['site'] );
		$out[] = sprintf( '%-10s %s', __( 'File', 'dahu-product-import' ), $plan['file'] );
		$out[] = sprintf( '%-10s %s', 'SHA-256', $plan['sha256'] );
		$out[] = sprintf(
			'%-10s "%s"',
			__( 'Separator', 'dahu-product-import' ),
			"\t" === $plan['separator'] ? __( 'tab', 'dahu-product-import' ) : $plan['separator']
		);
		$out[] = sprintf(
			'%-10s %s',
			__( 'Key', 'dahu-product-import' ),
			__( '+ added   ~ changed   - CLEARED (the cell is empty in the file)', 'dahu-product-import' )
		);
		$out[] = sprintf(
			'%-10s %s',
			__( 'Previews', 'dahu-product-import' ),
			__( '⏎ line break   ␣ non-breaking space   · invisible character   → tab', 'dahu-product-import' )
		);

		$out[] = '';
		$out[] = '--- ' . __( 'Unrecognised columns (their contents are NOT imported)', 'dahu-product-import' );

		if ( ! $plan['unknown'] ) {
			$out[] = '  ' . __( 'none — every column in the header matches a declared field', 'dahu-product-import' );
		}

		foreach ( $plan['unknown'] as $column ) {
			$out[] = '  ' . $column;
		}

		$identical = array();
		$rejected  = array();
		$erasures  = array();

		foreach ( $plan['items'] as $item ) {

			if ( 'identical' === $item['verdict'] ) {
				$identical[] = '#' . $item['id'];
			}

			if ( 'rejected' === $item['verdict'] ) {
				$rejected[] = $item;
				continue;
			}

			if ( 'update' !== $item['verdict'] && ! $item['warnings'] ) {
				continue;
			}

			$counts = array_count_values( wp_list_pluck( $item['changes'], 'action' ) );

			$out[] = '';
			$out[] = sprintf(
				/* translators: 1: product id, 2: slug, 3: line number, 4: additions, 5: changes, 6: erasures. */
				__( '--- Product #%1$s "%2$s" (line %3$d): %4$d added, %5$d changed, %6$d cleared', 'dahu-product-import' ),
				$item['id'],
				$item['slug'],
				$item['line'],
				isset( $counts['add'] ) ? $counts['add'] : 0,
				isset( $counts['change'] ) ? $counts['change'] : 0,
				isset( $counts['clear'] ) ? $counts['clear'] : 0
			);

			foreach ( $item['changes'] as $change ) {

				$out[] = sprintf(
					'  %s %-24s "%s" → %s',
					isset( $symbols[ $change['action'] ] ) ? $symbols[ $change['action'] ] : '?',
					$change['column'],
					$change['before'],
					'clear' === $change['action']
						? '(' . __( 'CLEARED', 'dahu-product-import' ) . ')'
						: '"' . $change['after'] . '"'
				);

				if ( 'clear' === $change['action'] ) {
					$erasures[ $change['column'] ][] = '#' . $item['id'];
				}
			}

			foreach ( $item['warnings'] as $warning ) {
				$out[] = '  ! ' . $warning;
			}
		}

		$out[] = '';
		$out[] = '--- ' . __( 'Erasures by column (a column emptied by mistake shows up here)', 'dahu-product-import' );

		if ( ! $erasures ) {
			$out[] = '  ' . __( 'none', 'dahu-product-import' );
		}

		foreach ( $erasures as $column => $products ) {
			$out[] = sprintf(
				'  %-24s %d %s: %s',
				$column,
				count( $products ),
				_n( 'product', 'products', count( $products ), 'dahu-product-import' ),
				implode( ' ', $products )
			);
		}

		$out[] = '';
		$out[] = '--- ' . __( 'Rejected rows (nothing at all is written for these)', 'dahu-product-import' );

		if ( ! $rejected ) {
			$out[] = '  ' . __( 'none', 'dahu-product-import' );
		}

		foreach ( $rejected as $item ) {
			$out[] = sprintf(
				/* translators: 1: line number, 2: product id, 3: slug, 4: the reasons. */
				__( '  line %1$d — #%2$s "%3$s": %4$s', 'dahu-product-import' ),
				$item['line'],
				$item['id'],
				$item['slug'],
				implode( ' ; ', $item['reasons'] )
			);
		}

		$out[] = '';
		$out[] = '--- ' . sprintf(
			/* translators: %d: how many rows. */
			__( 'Identical, nothing written: %d', 'dahu-product-import' ),
			count( $identical )
		) . ( $identical ? ' — ' . implode( ' ', $identical ) : '' );

		$out[] = '';
		$out[] = sprintf(
			/* translators: 1: rows, 2: products to update, 3: identical, 4: rejected, 5: field erasures. */
			__( '=== Summary: %1$d row(s); %2$d product(s) to update; %3$d identical; %4$d rejected; %5$d field erasure(s)', 'dahu-product-import' ),
			$plan['stats']['rows'],
			$plan['stats']['update'],
			$plan['stats']['identical'],
			$plan['stats']['rejected'],
			$plan['stats']['erasures']
		);

		return implode( "\n", $out );
	}
}
