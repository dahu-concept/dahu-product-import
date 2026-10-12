<?php
/**
 * The attributes column.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * One column drives a product's local attributes.
 *
 * Format: one per line, `Name : value`, with several values separated by a
 * pipe. That is readable in a spreadsheet cell and survives a round trip
 * through one, which a serialised format would not.
 *
 * WHAT THIS COLUMN WILL NOT TOUCH, and why it matters more than what it will:
 *
 *  - global attributes (the `pa_*` taxonomies) — they are shared across the
 *    catalogue, so a value written from one product's row would change every
 *    product using the term;
 *  - attributes used for variations — rewriting one breaks the variations
 *    built on it, and their SKUs and prices with them;
 *  - hidden local attributes — a shop hides an attribute deliberately, often
 *    because another plugin reads it.
 *
 * A row naming one of those is REPORTED and skipped, never applied. Reporting
 * it is the point: silently ignoring the line would leave somebody editing the
 * same cell again next week, wondering why it never takes.
 */
final class Dahu_Product_Import_Attributes {

	/** Separator between several values of one attribute. */
	const VALUE_SEPARATOR = ' | ';

	/**
	 * Parses `Name : value`, one pair per line.
	 *
	 * @param string $text Cell contents.
	 * @return array{pairs: array<int, array>, ignored: array<int, string>}
	 */
	public static function parse( $text ) {

		$parsed = array(
			'pairs'   => array(),
			'ignored' => array(),
		);

		foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {

			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$colon = strpos( $line, ':' );
			$name  = false === $colon ? '' : trim( substr( $line, 0, $colon ) );

			if ( '' === $name ) {
				$parsed['ignored'][] = $line;
				continue;
			}

			$parsed['pairs'][] = array(
				'name'  => $name,
				'value' => trim( substr( $line, $colon + 1 ) ),
			);
		}

		return $parsed;
	}

	/**
	 * Splits a product's attributes into the ones this column drives and the
	 * ones it must leave alone.
	 *
	 * @param WC_Product $product Product.
	 * @return array{driven: array, kept: array}
	 */
	public static function split( $product ) {

		$driven = array();
		$kept   = array();

		foreach ( $product->get_attributes() as $key => $attribute ) {

			if ( ! $attribute instanceof WC_Product_Attribute ) {
				$kept[ $key ] = $attribute;
				continue;
			}

			if ( $attribute->is_taxonomy() || $attribute->get_variation() || ! $attribute->get_visible() ) {
				$kept[ $key ] = $attribute;
				continue;
			}

			$driven[ $key ] = $attribute;
		}

		return array(
			'driven' => $driven,
			'kept'   => $kept,
		);
	}

	/**
	 * The driven attributes as name/value pairs, in the product's own order.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, array>
	 */
	public static function pairs( $product ) {

		$pairs = array();

		foreach ( self::split( $product )['driven'] as $attribute ) {
			$pairs[] = array(
				'name'  => (string) $attribute->get_name(),
				'value' => implode( self::VALUE_SEPARATOR, array_map( 'strval', (array) $attribute->get_options() ) ),
			);
		}

		return $pairs;
	}

	/**
	 * Pairs rendered as a cell: one per line.
	 *
	 * Also the canonical form used for COMPARISON, on both sides. Comparing
	 * the rendered strings rather than the arrays is what stops the order of
	 * attributes, or the spaces around a colon, from being reported as a
	 * change nobody made.
	 *
	 * @param array<int, array> $pairs Pairs.
	 * @return string
	 */
	public static function to_cell( array $pairs ) {

		$lines = array();

		foreach ( $pairs as $pair ) {
			$lines[] = $pair['name'] . ' : ' . $pair['value'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * A comparable form of a label: no accents, no case, spaces collapsed.
	 *
	 * Used to notice that a line in the file names an attribute that is
	 * already a global or a variation one, whatever way it was typed.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private static function key( $label ) {

		$label = remove_accents( wp_strip_all_tags( (string) $label ) );
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label );

		return trim( (string) preg_replace( '/\s+/u', ' ', $label ) );
	}

	/**
	 * The pairs a cell asks for, plus anything worth reporting.
	 *
	 * @param string     $raw     Raw cell.
	 * @param WC_Product $product Product.
	 * @return array{pairs: array<int, array>, warnings: array<int, string>}
	 */
	public static function from_cell( $raw, $product ) {

		$parsed   = self::parse( $raw );
		$warnings = array();
		$pairs    = array();
		$seen     = array();

		// Attributes this column must never touch: any line naming one is refused.
		$protected = array();

		foreach ( self::split( $product )['kept'] as $attribute ) {

			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			$label = $attribute->is_taxonomy()
				? wc_attribute_label( $attribute->get_name(), $product )
				: $attribute->get_name();

			if ( $attribute->is_taxonomy() ) {
				/* translators: %s: attribute taxonomy name. */
				$reason = sprintf( __( 'global attribute (taxonomy %s)', 'dahu-product-import' ), $attribute->get_name() );
			} elseif ( $attribute->get_variation() ) {
				$reason = __( 'attribute used for variations', 'dahu-product-import' );
			} else {
				$reason = __( 'hidden local attribute', 'dahu-product-import' );
			}

			$protected[ self::key( $label ) ] = $reason;
		}

		foreach ( $parsed['ignored'] as $line ) {
			$warnings[] = sprintf(
				/* translators: 1: column name, 2: the line as typed. */
				__( '%1$s: line without a ":" ignored — "%2$s"', 'dahu-product-import' ),
				Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES,
				Dahu_Product_Import_Sanitize::preview( $line )
			);
		}

		foreach ( $parsed['pairs'] as $pair ) {

			$name = Dahu_Product_Import_Sanitize::text( $pair['name'] );
			$key  = self::key( $name );

			if ( '' === $name ) {
				continue;
			}

			if ( isset( $protected[ $key ] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: column name, 2: attribute name, 3: why it is protected. */
					__( '%1$s: "%2$s" is a %3$s — line ignored, the existing attribute is left untouched.', 'dahu-product-import' ),
					Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES,
					$name,
					$protected[ $key ]
				);
				continue;
			}

			/*
			 * WooCommerce indexes local attributes by sanitize_title(). Two
			 * names giving the same key would overwrite one another in the
			 * database, silently, and the second would simply never appear.
			 */
			$slug = sanitize_title( $name );

			if ( isset( $seen[ $key ] ) || isset( $seen[ $slug ] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: column name, 2: attribute name. */
					__( '%1$s: "%2$s" names the same attribute as an earlier line; only the first is kept.', 'dahu-product-import' ),
					Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES,
					$name
				);
				continue;
			}

			$seen[ $key ]  = true;
			$seen[ $slug ] = true;

			// Plain text: "<50dB" and ">89%" are legitimate values, stored as typed.
			$values = array_values(
				array_filter(
					array_map(
						array( 'Dahu_Product_Import_Sanitize', 'text' ),
						explode( '|', $pair['value'] )
					),
					static function ( $value ) {
						return '' !== $value;
					}
				)
			);

			$pairs[] = array(
				'name'  => $name,
				'value' => implode( self::VALUE_SEPARATOR, $values ),
			);
		}

		return array(
			'pairs'    => $pairs,
			'warnings' => $warnings,
		);
	}

	/**
	 * Replaces a product's driven attributes, keeping the others as they are.
	 *
	 * Written through the WooCommerce API — WC_Product_Attribute, then
	 * set_attributes() and save() — never in SQL. Prices, stock and variations
	 * are not touched: only the attributes change.
	 *
	 * @param WC_Product        $product Product.
	 * @param array<int, array> $pairs   Wanted pairs, in display order.
	 * @return bool True when the product was saved.
	 */
	public static function apply( $product, array $pairs ) {

		$split      = self::split( $product );
		$attributes = array();
		$position   = 0;

		foreach ( $split['kept'] as $key => $attribute ) {

			if ( $attribute instanceof WC_Product_Attribute ) {
				$attribute->set_position( $position++ );
			}

			$attributes[ $key ] = $attribute;
		}

		foreach ( $pairs as $pair ) {

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $pair['name'] );
			$attribute->set_options( explode( self::VALUE_SEPARATOR, $pair['value'] ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attribute->set_position( $position++ );

			$attributes[ sanitize_title( $pair['name'] ) ] = $attribute;
		}

		$product->set_attributes( $attributes );

		return (bool) $product->save();
	}
}
