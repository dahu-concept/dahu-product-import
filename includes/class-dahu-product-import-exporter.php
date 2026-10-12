<?php
/**
 * Writing the spreadsheet from the site.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * The reverse direction: site to file.
 *
 * This is what makes the plugin usable rather than merely possible. Nobody
 * types four hundred product ids and slugs by hand. The export produces the
 * file the import reads, so the workflow is: export, edit in a spreadsheet,
 * dry run, import.
 *
 * Which makes the round trip the property that matters most: exporting and
 * importing without editing anything must write NOTHING. Every comparison
 * rule in the sanitizer exists to keep that true.
 */
final class Dahu_Product_Import_Exporter {

	/**
	 * Working directory.
	 *
	 * @var Dahu_Product_Import_Workspace
	 */
	private $workspace;

	/**
	 * Constructor.
	 *
	 * @param Dahu_Product_Import_Workspace $workspace Working directory.
	 */
	public function __construct( Dahu_Product_Import_Workspace $workspace ) {
		$this->workspace = $workspace;
	}

	/**
	 * The cell for one column of one product.
	 *
	 * The site's RAW value, with no normalisation at all. Normalising on the
	 * way out would make the round trip report changes on every product the
	 * first time, and hide real ones among them.
	 *
	 * @param WP_Post $post  The product.
	 * @param array   $field Field declaration.
	 * @return string
	 */
	public static function cell( WP_Post $post, array $field ) {

		if ( 0 === strpos( $field['target'], 'post:' ) ) {
			return (string) $post->{substr( $field['target'], 5 )};
		}

		return (string) get_post_meta( $post->ID, $field['target'], true );
	}

	/**
	 * Writes the spreadsheet for every product that is not in the trash.
	 *
	 * Nothing is written to the database.
	 *
	 * @param string $name      File name in the working directory.
	 * @param string $separator Separator.
	 * @return array{path: string, products: int, out_of_scope: array}
	 * @throws RuntimeException Cannot write.
	 */
	public function run( $name, $separator ) {

		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$columns = Dahu_Product_Import_Fields::columns();
		$fields  = Dahu_Product_Import_Fields::all();
		$rows    = array( $columns );

		/*
		 * Attributes the spreadsheet does not carry, counted so the command
		 * can say how many products have some. Without that line somebody
		 * edits the attributes column, sees their global attributes
		 * untouched, and concludes the import is broken.
		 */
		$out_of_scope = array(
			'global'    => array(),
			'variation' => array(),
			'hidden'    => array(),
		);

		foreach ( $ids as $id ) {

			$post    = get_post( $id );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			$terms   = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );

			$row = array(
				Dahu_Product_Import_Fields::COLUMN_ID       => (string) $id,
				Dahu_Product_Import_Fields::COLUMN_SKU      => $product ? (string) $product->get_sku() : '',
				Dahu_Product_Import_Fields::COLUMN_SLUG     => rawurldecode( $post->post_name ),
				Dahu_Product_Import_Fields::COLUMN_TYPE     => $product ? $product->get_type() : '',
				Dahu_Product_Import_Fields::COLUMN_CATEGORY => is_wp_error( $terms ) ? '' : implode( ' | ', $terms ),
			);

			foreach ( $fields as $column => $field ) {
				$row[ $column ] = self::cell( $post, $field );
			}

			$row[ Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES ] = $product
				? Dahu_Product_Import_Attributes::to_cell( Dahu_Product_Import_Attributes::pairs( $product ) )
				: '';

			foreach ( $product ? Dahu_Product_Import_Attributes::split( $product )['kept'] : array() as $attribute ) {

				if ( ! $attribute instanceof WC_Product_Attribute ) {
					continue;
				}

				if ( $attribute->is_taxonomy() ) {
					$out_of_scope['global'][ $id ] = true;
				} elseif ( $attribute->get_variation() ) {
					$out_of_scope['variation'][ $id ] = true;
				} else {
					$out_of_scope['hidden'][ $id ] = true;
				}
			}

			$ordered = array();

			foreach ( $columns as $column ) {
				$ordered[] = isset( $row[ $column ] ) ? $row[ $column ] : '';
			}

			$rows[] = $ordered;
		}

		return array(
			'path'         => $this->workspace->write( $name, Dahu_Product_Import_CSV::write( $rows, $separator ) ),
			'products'     => count( $ids ),
			'out_of_scope' => array_map( 'array_keys', $out_of_scope ),
		);
	}
}
