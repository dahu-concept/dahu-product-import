<?php
/**
 * Which spreadsheet column writes which product field.
 *
 * This file is the whole configuration surface of the plugin. Everything else
 * — reading the file, planning, reporting, writing, rolling back — works from
 * what is declared here and knows nothing about any particular shop.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * The field registry.
 *
 * A field is a column of the spreadsheet plus where its value goes and how it
 * is cleaned on the way. Three fields are built in — the title, the
 * description and the short description — because those exist in every
 * WooCommerce shop and the plugin should do something useful before anybody
 * configures anything.
 *
 * Everything else is a theme's or a plugin's own meta, and a shop declares it:
 *
 *     add_filter( 'dahu_product_import_fields', function ( $fields ) {
 *         $fields['subtitle'] = array(
 *             'target' => '_my_theme_subtitle',
 *             'nature' => 'text',
 *         );
 *         return $fields;
 *     } );
 *
 * The column names are what appears in the spreadsheet header, so they are
 * what the person editing the file reads. They belong to the shop, not to this
 * plugin, which is why they are plain keys rather than anything translated:
 * a column name that changed with the admin language would break every file
 * saved before the change.
 */
final class Dahu_Product_Import_Fields {

	/**
	 * Column holding the product id, which is how a row is matched.
	 *
	 * An id and not a SKU. A SKU is editable, often empty on a new catalogue,
	 * and duplicated more often than shops expect; the id is the only handle
	 * that cannot quietly come to mean a different product. The slug is then
	 * checked against the site as a guard — see the planner.
	 */
	const COLUMN_ID = 'product_id';

	/** Column holding the slug, used only to confirm the id still means this product. */
	const COLUMN_SLUG = 'slug';

	/** Column holding the SKU, which has rules of its own (see the planner). */
	const COLUMN_SKU = 'sku';

	/** Column driving the product's local attributes. */
	const COLUMN_ATTRIBUTES = 'attributes';

	/**
	 * Columns written out for information and never read back.
	 *
	 * They exist because a spreadsheet of nothing but ids is unreadable: the
	 * person editing it needs to see which product each row is. Writing them
	 * back would mean this plugin moved a product between categories or
	 * changed its type, which is not what it is for.
	 */
	const COLUMN_TYPE     = 'type';
	const COLUMN_CATEGORY = 'category';

	/**
	 * Length of the values shown in the report.
	 */
	const PREVIEW = 70;

	/**
	 * Cached registry for this request.
	 *
	 * @var array|null
	 */
	private static $fields = null;

	/**
	 * Every declared field, as column => declaration.
	 *
	 * A declaration holds:
	 *
	 *  - `target`     (required) `post:<field>` for a column of the posts
	 *                 table, or a meta key.
	 *  - `nature`     (required) how the value is cleaned, and what counts as
	 *                 valid. See Dahu_Product_Import_Sanitize::NATURES.
	 *  - `max_length` (optional) in characters. A longer value REJECTS the
	 *                 whole row rather than being truncated.
	 *  - `required`   (optional) true to reject a row whose cell is empty.
	 *
	 * @return array<string, array>
	 */
	public static function all() {

		if ( null !== self::$fields ) {
			return self::$fields;
		}

		$fields = array(

			/*
			 * The title is required, and that is not a stylistic choice: an
			 * empty cell everywhere else means "clear this field", and a
			 * product that lost its title is a product nobody can find in the
			 * admin list, with nothing to say what it was.
			 */
			'title'             => array(
				'target'   => 'post:post_title',
				'nature'   => 'title',
				'required' => true,
			),

			'description'       => array(
				'target' => 'post:post_content',
				'nature' => 'html',
			),

			'short_description' => array(
				'target' => 'post:post_excerpt',
				'nature' => 'excerpt',
			),
		);

		/**
		 * Filters the fields a spreadsheet row can write.
		 *
		 * The key is the column name as it appears in the header. See this
		 * class's docblock for the shape of a declaration.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array> $fields Column => declaration.
		 */
		$fields = (array) apply_filters( 'dahu_product_import_fields', $fields );

		self::$fields = self::validate( $fields );

		return self::$fields;
	}

	/**
	 * Keeps the declarations that are usable and drops the rest.
	 *
	 * A broken declaration is dropped rather than tolerated. The alternative
	 * is a column that looks configured, appears in the header, and silently
	 * writes nowhere — which is indistinguishable, from the outside, from a
	 * column whose content nobody filled in.
	 *
	 * A declaration naming one of the reserved columns is also dropped: those
	 * columns already mean something, and letting a filter redefine the id
	 * column would make a row match a product nobody intended.
	 *
	 * @param array $fields Declarations as filtered.
	 * @return array<string, array>
	 */
	private static function validate( $fields ) {

		$reserved = self::reserved_columns();
		$natures  = Dahu_Product_Import_Sanitize::NATURES;
		$valid    = array();
		$rejected = array();

		foreach ( $fields as $column => $field ) {

			$column = is_string( $column ) ? strtolower( trim( $column ) ) : '';

			if ( '' === $column || ! is_array( $field ) ) {
				$rejected[] = (string) $column;
				continue;
			}

			if ( in_array( $column, $reserved, true ) ) {
				$rejected[] = $column . ' (reserved column)';
				continue;
			}

			$target = isset( $field['target'] ) ? (string) $field['target'] : '';
			$nature = isset( $field['nature'] ) ? (string) $field['nature'] : '';

			if ( '' === $target || ! in_array( $nature, $natures, true ) ) {
				$rejected[] = $column;
				continue;
			}

			$valid[ $column ] = array(
				'target'     => $target,
				'nature'     => $nature,
				'max_length' => isset( $field['max_length'] ) ? absint( $field['max_length'] ) : 0,
				'required'   => ! empty( $field['required'] ),
			);
		}

		if ( $rejected && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log(
				'[Dahu Product Import] these field declarations were dropped as unusable: '
				. implode( ', ', $rejected )
			);
		}

		return $valid;
	}

	/**
	 * Columns this plugin owns, which a declaration may not redefine.
	 *
	 * @return array<int, string>
	 */
	public static function reserved_columns() {
		return array(
			self::COLUMN_ID,
			self::COLUMN_SLUG,
			self::COLUMN_SKU,
			self::COLUMN_TYPE,
			self::COLUMN_CATEGORY,
			self::COLUMN_ATTRIBUTES,
		);
	}

	/**
	 * Every column of the file, in the order the export writes them.
	 *
	 * @return array<int, string>
	 */
	public static function columns() {
		return array_merge(
			array(
				self::COLUMN_ID,
				self::COLUMN_SKU,
				self::COLUMN_SLUG,
				self::COLUMN_TYPE,
				self::COLUMN_CATEGORY,
			),
			array_keys( self::all() ),
			array( self::COLUMN_ATTRIBUTES )
		);
	}

	/**
	 * Product types this plugin leaves alone.
	 *
	 * Empty by default. A shop whose product text for some type is managed by
	 * another tool lists that type here, so one object is never written from
	 * two places — the rows are reported as rejected, with the reason, rather
	 * than quietly skipped.
	 *
	 * @return array<int, string>
	 */
	public static function excluded_product_types() {

		/**
		 * Filters the product types this plugin refuses to write.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $types Product type slugs.
		 */
		return array_map( 'strval', (array) apply_filters( 'dahu_product_import_excluded_product_types', array() ) );
	}

	/**
	 * Forgets the cached registry.
	 *
	 * For tests, and for a long-running process that declares fields after the
	 * registry was first read.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$fields = null;
	}
}
