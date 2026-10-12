<?php
/**
 * Planning: what an import would write, worked out without writing anything.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * The planner.
 *
 * ONE pass produces the plan, and the dry run and the real import both read
 * that same plan. They are not two code paths that agree; they are one code
 * path, run twice. A dry run that reports what a separate importer would do is
 * worth very little — it agrees with the importer until the day it does not,
 * and that is the day somebody trusted it.
 *
 * Nothing here touches the database.
 */
final class Dahu_Product_Import_Planner {

	/**
	 * Builds the plan: per row, a verdict, the differences, and the values.
	 *
	 * Verdicts: `update` (at least one write), `identical`, `rejected`
	 * (nothing at all is written for that row).
	 *
	 * @param string $path CSV path.
	 * @return array
	 * @throws RuntimeException Unreadable file.
	 */
	public function plan( $path ) {

		$csv   = Dahu_Product_Import_CSV::read( $path );
		$items = array();
		$ids    = array();

		// Ids seen on more than one row: the file contradicts itself about a
		// product, and guessing which row wins is not this plugin's business.
		foreach ( $csv['rows'] as $number => $row ) {
			if ( isset( $row[ Dahu_Product_Import_Fields::COLUMN_ID ] ) ) {
				$ids[ trim( $row[ Dahu_Product_Import_Fields::COLUMN_ID ] ) ][] = $number;
			}
		}

		foreach ( $csv['rows'] as $number => $row ) {

			$item = array(
				'line'     => $number,
				'id'       => isset( $row[ Dahu_Product_Import_Fields::COLUMN_ID ] ) ? trim( $row[ Dahu_Product_Import_Fields::COLUMN_ID ] ) : '',
				'slug'     => isset( $row[ Dahu_Product_Import_Fields::COLUMN_SLUG ] ) ? trim( $row[ Dahu_Product_Import_Fields::COLUMN_SLUG ] ) : '',
				'verdict'  => 'rejected',
				'reasons'  => array(),
				'warnings' => array(),
				'changes'  => array(),
				'values'   => array(
					'fields' => array(),
					'meta'   => array(),
					'delete' => array(),
				),
			);

			if ( isset( $row['_error'] ) ) {
				$item['reasons'][] = $row['_error'];
			} elseif ( isset( $ids[ $item['id'] ] ) && count( $ids[ $item['id'] ] ) > 1 ) {
				$item['reasons'][] = sprintf(
					/* translators: %s: comma-separated line numbers. */
					__( 'this product id appears on several lines (%s)', 'dahu-product-import' ),
					implode( ', ', $ids[ $item['id'] ] )
				);
			} else {
				$this->plan_row( $row, $item );
			}

			$items[] = $item;
		}

		$stats = array(
			'rows'      => count( $items ),
			'update'    => 0,
			'identical' => 0,
			'rejected'  => 0,
			'erasures'  => 0,
		);

		foreach ( $items as $item ) {

			++$stats[ $item['verdict'] ];

			foreach ( $item['changes'] as $change ) {
				if ( 'clear' === $change['action'] ) {
					++$stats['erasures'];
				}
			}
		}

		return array(
			'file'      => $path,
			'sha256'    => hash_file( 'sha256', $path ),
			'site'      => home_url(),
			'separator' => $csv['separator'],
			'unknown'   => $csv['unknown'],
			'items'     => $items,
			'stats'     => $stats,
		);
	}

	/**
	 * Plans one row: identity checks, validation, comparison with the site.
	 *
	 * @param array $row  Row, column => cell.
	 * @param array $item Plan item, completed by reference.
	 * @return void
	 */
	private function plan_row( array $row, array &$item ) {

		$post = $this->resolve_product( $item );

		if ( ! $post ) {
			return;
		}

		$id = (int) $item['id'];

		// 1. Wanted values, cleaned. Any blocking error rejects the WHOLE row.
		$wanted = $this->wanted_values( $row, $item, $id );

		if ( $item['reasons'] ) {
			return;
		}

		// 2. Comparison with the site.
		$this->compare( $wanted, $item, $post, $id );

		// 3. The SKU, which has rules of its own.
		if ( ! $this->plan_sku( $row, $item, $id ) ) {
			return;
		}

		// 4. The attributes column.
		$this->plan_attributes( $row, $item, $id );

		/**
		 * Fires once a row has been planned, before any verdict is set.
		 *
		 * This is where a theme adds a write that follows from its own fields
		 * rather than from a column of its own — a marker meaning "this
		 * product now uses the new layout", say. Add to `$item['changes']`
		 * for the report and to `$item['values']` for the write; both shapes
		 * are documented in this class.
		 *
		 * Nothing here may touch the database: this runs during the dry run
		 * too, and that is the whole promise of a dry run.
		 *
		 * @since 1.0.0
		 *
		 * @param array $item   Plan item, by reference.
		 * @param array $row    The row as read.
		 * @param int   $id     Product id.
		 * @param array $wanted Cleaned values, by target.
		 */
		do_action_ref_array( 'dahu_product_import_plan_row', array( &$item, $row, $id, $wanted ) );

		$item['verdict'] = $item['changes'] ? 'update' : 'identical';
	}

	/**
	 * Checks that the row names a product this plugin may write, and returns it.
	 *
	 * @param array $item Plan item, completed by reference.
	 * @return WP_Post|null
	 */
	private function resolve_product( array &$item ) {

		if ( ! ctype_digit( $item['id'] ) || 0 === (int) $item['id'] ) {
			$item['reasons'][] = sprintf(
				/* translators: %s: the cell contents. */
				__( 'invalid product id "%s"', 'dahu-product-import' ),
				$item['id']
			);
			return null;
		}

		$id   = (int) $item['id'];
		$post = get_post( $id );

		if ( ! $post ) {
			$item['reasons'][] = __( 'no such product on this site (this plugin never creates one)', 'dahu-product-import' );
			return null;
		}

		if ( 'product' !== $post->post_type ) {
			$item['reasons'][] = sprintf(
				/* translators: %s: post type. */
				__( 'that id belongs to a "%s", not to a product', 'dahu-product-import' ),
				$post->post_type
			);
			return null;
		}

		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			$item['reasons'][] = sprintf(
				/* translators: %s: post status. */
				__( 'the product is in "%s" status', 'dahu-product-import' ),
				$post->post_status
			);
			return null;
		}

		$excluded = Dahu_Product_Import_Fields::excluded_product_types();

		if ( $excluded ) {

			$types = array_map( 'strval', (array) wp_get_post_terms( $id, 'product_type', array( 'fields' => 'slugs' ) ) );
			$hit   = array_intersect( $excluded, $types );

			if ( $hit ) {
				$item['reasons'][] = sprintf(
					/* translators: %s: product type slug. */
					__( 'products of type "%s" are excluded on this site, so this row is left to whatever manages them', 'dahu-product-import' ),
					reset( $hit )
				);
				return null;
			}
		}

		/*
		 * The slug guard.
		 *
		 * The id matches, but ids get reused when a product is deleted and
		 * another created, and a spreadsheet lives for months. Without this,
		 * a stale file would overwrite whatever product inherited the id —
		 * with content that reads perfectly plausibly.
		 */
		if ( $post->post_name !== $item['slug'] && rawurldecode( $post->post_name ) !== $item['slug'] ) {
			$item['reasons'][] = sprintf(
				/* translators: 1: slug on the site, 2: slug in the file. */
				__( 'the site\'s slug "%1$s" differs from the file\'s "%2$s": the id may no longer mean the same product', 'dahu-product-import' ),
				rawurldecode( $post->post_name ),
				$item['slug']
			);
			return null;
		}

		return $post;
	}

	/**
	 * Cleans and validates every declared field of a row.
	 *
	 * A validation failure appends a reason, which rejects the whole row. That
	 * is deliberate: writing the half of a product's text that happened to
	 * validate leaves a page nobody can make sense of, and no way to tell
	 * which half came from the file.
	 *
	 * @param array $row  Row.
	 * @param array $item Plan item, completed by reference.
	 * @param int   $id   Product id.
	 * @return array<string, array> Wanted values, by target.
	 */
	private function wanted_values( array $row, array &$item, $id ) {

		$wanted = array();

		foreach ( Dahu_Product_Import_Fields::all() as $column => $field ) {

			$raw   = trim( (string) ( isset( $row[ $column ] ) ? $row[ $column ] : '' ) );
			$value = Dahu_Product_Import_Sanitize::clean( $raw, $field['nature'] );

			if ( 'url' === $field['nature'] && '' !== $raw && '' === $value ) {
				$item['reasons'][] = sprintf(
					/* translators: 1: column name, 2: the cell contents. */
					__( '%1$s: invalid URL "%2$s"', 'dahu-product-import' ),
					$column,
					Dahu_Product_Import_Sanitize::preview( $raw )
				);
				continue;
			}

			if ( $field['max_length'] && mb_strlen( $value ) > $field['max_length'] ) {
				$item['reasons'][] = sprintf(
					/* translators: 1: column name, 2: length found, 3: maximum allowed. */
					__( '%1$s: %2$d characters, maximum %3$d (never truncated)', 'dahu-product-import' ),
					$column,
					mb_strlen( $value ),
					$field['max_length']
				);
				continue;
			}

			if ( $field['required'] && '' === $value ) {
				$item['reasons'][] = sprintf(
					/* translators: %s: column name. */
					__( '%s is empty, and that field cannot be cleared', 'dahu-product-import' ),
					$column
				);
				continue;
			}

			/*
			 * A product reference is checked but never blocks the row: a
			 * pointer to a product that has since gone is a stale reference,
			 * not a reason to refuse somebody's text. The field is left as it
			 * is and the report says so.
			 */
			if ( 'product_id' === $field['nature'] && '' !== $raw ) {

				$referenced = '' !== $value ? get_post( (int) $value ) : null;

				if ( ! $referenced || 'product' !== $referenced->post_type ) {
					$item['warnings'][] = sprintf(
						/* translators: 1: column name, 2: the cell contents. */
						__( '%1$s: "%2$s" is not a product on this site; the field is left unchanged', 'dahu-product-import' ),
						$column,
						$raw
					);
					continue;
				}
			}

			$wanted[ $field['target'] ] = array(
				'column' => $column,
				'nature' => $field['nature'],
				'raw'    => (string) ( isset( $row[ $column ] ) ? $row[ $column ] : '' ),
				'value'  => $value,
			);
		}

		return $wanted;
	}

	/**
	 * Compares the wanted values with the site and records the differences.
	 *
	 * @param array   $wanted Wanted values, by target.
	 * @param array   $item   Plan item, completed by reference.
	 * @param WP_Post $post   The product.
	 * @param int     $id     Product id.
	 * @return void
	 */
	private function compare( array $wanted, array &$item, $post, $id ) {

		foreach ( $wanted as $target => $want ) {

			$field = 0 === strpos( $target, 'post:' ) ? substr( $target, 5 ) : '';

			if ( '' !== $field ) {
				$current = (string) $post->$field;
			} else {
				$current = metadata_exists( 'post', $id, $target ) ? (string) get_post_meta( $id, $target, true ) : '';
			}

			if ( Dahu_Product_Import_Sanitize::unchanged( $current, $want['raw'], $want['value'], $want['nature'] ) ) {
				continue;
			}

			$lost = Dahu_Product_Import_Sanitize::losses( $want['raw'], $want['value'], $want['nature'] );

			if ( $lost ) {
				$item['warnings'][] = sprintf(
					/* translators: 1: column name, 2: what the cleaning changes. */
					__( '%1$s: cleaning on write changes the file\'s value (%2$s)', 'dahu-product-import' ),
					$want['column'],
					$lost
				);
			}

			if ( '' === $want['value'] ) {
				$action = 'clear';
			} else {
				$action = '' === trim( $current ) ? 'add' : 'change';
			}

			$item['changes'][] = array(
				'column' => $want['column'],
				'target' => $target,
				'action' => $action,
				'before' => Dahu_Product_Import_Sanitize::preview( $current ),
				'after'  => Dahu_Product_Import_Sanitize::preview( $want['value'] ),
			);

			if ( '' !== $field ) {
				$item['values']['fields'][ $field ] = $want['value'];
			} elseif ( '' === $want['value'] ) {
				// An absent empty meta produces no difference at all, so if we
				// are here the meta exists and deleting it is the change.
				$item['values']['delete'][] = $target;
			} else {
				$item['values']['meta'][ $target ] = $want['value'];
			}
		}
	}

	/**
	 * Plans the SKU.
	 *
	 * Four rules, and not one more:
	 *
	 *  - an empty cell NEVER clears an existing SKU. This is the one column
	 *    that escapes the "empty cell means clear the field" rule, and
	 *    deliberately: a missing SKU in a spreadsheet is almost always an
	 *    omission, while a cleared SKU breaks order history, exports,
	 *    accounting and shipping labels;
	 *  - an identical SKU is not rewritten;
	 *  - uniqueness is checked BEFORE anything is written, and a duplicate
	 *    rejects the whole row rather than writing half a product's text and
	 *    then failing;
	 *  - nothing else is touched: not the weight, the slug, the price or the
	 *    stock.
	 *
	 * @param array $row  Row.
	 * @param array $item Plan item, completed by reference.
	 * @param int   $id   Product id.
	 * @return bool False when the row is rejected.
	 */
	private function plan_sku( array $row, array &$item, $id ) {

		$wanted  = trim( (string) ( isset( $row[ Dahu_Product_Import_Fields::COLUMN_SKU ] ) ? $row[ Dahu_Product_Import_Fields::COLUMN_SKU ] : '' ) );
		$current = trim( (string) get_post_meta( $id, '_sku', true ) );

		if ( '' === $wanted ) {

			if ( '' !== $current ) {
				$item['warnings'][] = sprintf(
					/* translators: %s: the SKU on the site. */
					__( 'sku: the cell is empty, so the site\'s SKU "%s" is KEPT (an empty cell never clears a SKU)', 'dahu-product-import' ),
					$current
				);
			}

			return true;
		}

		if ( $wanted === $current ) {
			return true;
		}

		$holder = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $wanted ) : 0;

		if ( $holder && $holder !== $id ) {

			$other = get_post( $holder );

			$item['reasons'][] = sprintf(
				/* translators: 1: SKU, 2: product id holding it, 3: that product's slug. */
				__( 'sku: "%1$s" is already held by #%2$d "%3$s" on this site — row refused, nothing is written for this product', 'dahu-product-import' ),
				$wanted,
				$holder,
				$other ? $other->post_name : '?'
			);

			return false;
		}

		$item['changes'][] = array(
			'column' => Dahu_Product_Import_Fields::COLUMN_SKU,
			'target' => '_sku',
			'action' => '' === $current ? 'add' : 'change',
			'before' => Dahu_Product_Import_Sanitize::preview( $current ),
			'after'  => Dahu_Product_Import_Sanitize::preview( $wanted ),
		);

		$item['values']['sku'] = $wanted;

		return true;
	}

	/**
	 * Plans the attributes column.
	 *
	 * An empty cell clears the driven attributes, like any other column. The
	 * comparison is made on the two sides rendered to the same text, so the
	 * order of attributes and the spaces around a colon cannot produce a
	 * difference nobody made.
	 *
	 * @param array $row  Row.
	 * @param array $item Plan item, completed by reference.
	 * @param int   $id   Product id.
	 * @return void
	 */
	private function plan_attributes( array $row, array &$item, $id ) {

		$column = Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES;
		$raw    = (string) ( isset( $row[ $column ] ) ? $row[ $column ] : '' );

		if ( ! function_exists( 'wc_get_product' ) ) {

			if ( '' !== trim( $raw ) ) {
				$item['warnings'][] = sprintf(
					/* translators: %s: column name. */
					__( '%s: WooCommerce is not active, so this column is ignored', 'dahu-product-import' ),
					$column
				);
			}

			return;
		}

		$product = wc_get_product( $id );

		if ( ! $product ) {
			$item['warnings'][] = sprintf(
				/* translators: %s: column name. */
				__( '%s: WooCommerce cannot read this product, so this column is ignored', 'dahu-product-import' ),
				$column
			);
			return;
		}

		$current = Dahu_Product_Import_Attributes::pairs( $product );
		$wanted  = Dahu_Product_Import_Attributes::from_cell( $raw, $product );

		foreach ( $wanted['warnings'] as $warning ) {
			$item['warnings'][] = $warning;
		}

		if ( Dahu_Product_Import_Attributes::to_cell( $current ) === Dahu_Product_Import_Attributes::to_cell( $wanted['pairs'] ) ) {
			return;
		}

		if ( ! $wanted['pairs'] ) {
			$action = 'clear';
		} else {
			$action = $current ? 'change' : 'add';
		}

		$item['changes'][] = array(
			'column' => $column,
			'target' => 'local attributes',
			'action' => $action,
			'before' => Dahu_Product_Import_Sanitize::preview( Dahu_Product_Import_Attributes::to_cell( $current ) ),
			'after'  => Dahu_Product_Import_Sanitize::preview( Dahu_Product_Import_Attributes::to_cell( $wanted['pairs'] ) ),
		);

		$item['values']['attributes'] = $wanted['pairs'];
	}
}
