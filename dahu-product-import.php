<?php
/**
 * Plugin Name:       Dahu Product Import
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Edit WooCommerce product text in a spreadsheet and import it back: one row per product, a dry run that shows every change before anything is written, and a rollback.
 * Version:           1.0.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-product-import
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_PRODUCT_IMPORT_VERSION', '1.0.0' );
define( 'DAHU_PRODUCT_IMPORT_FILE', __FILE__ );
define( 'DAHU_PRODUCT_IMPORT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_PRODUCT_IMPORT_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_PRODUCT_IMPORT_BASENAME', plugin_basename( __FILE__ ) );
define( 'DAHU_PRODUCT_IMPORT_SLUG', 'dahu-product-import' );

/**
 * Autoloader: Dahu_Product_Import_Foo_Bar => includes/class-dahu-product-import-foo-bar.php.
 */
spl_autoload_register(
	static function ( $class ) {

		if ( 0 !== strpos( $class, 'Dahu_Product_Import' ) ) {
			return;
		}

		$file = DAHU_PRODUCT_IMPORT_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'dahu-product-import',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/**
 * HPOS compatibility: this plugin never reads or writes an order.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				DAHU_PRODUCT_IMPORT_FILE,
				true
			);
		}
	}
);

/**
 * Every operation runs from WP-CLI.
 *
 * Deliberately not from the admin, and this is worth stating because it is the
 * kind of decision somebody will want to revisit. An import of this sort writes
 * to hundreds of products in one pass; it has to be preceded by a dry run, it
 * has to be interruptible, and its report has to be readable afterwards and
 * kept. A browser request gives none of that: it times out halfway through, it
 * loses the report on the next page load, and it invites being clicked twice.
 *
 * The admin screen exists, and it explains what to run.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'product-import', 'Dahu_Product_Import_CLI' );
}

if ( is_admin() ) {
	Dahu_Product_Import_Admin::init();
}

add_filter(
	'plugin_action_links_' . DAHU_PRODUCT_IMPORT_BASENAME,
	static function ( $links ) {
		$page = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=' . DAHU_PRODUCT_IMPORT_SLUG ) ),
			esc_html__( 'How to use', 'dahu-product-import' )
		);
		array_unshift( $links, $page );
		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( DAHU_PRODUCT_IMPORT_BASENAME !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( 'https://dahu-concept.fr' ),
			esc_html__( 'Support', 'dahu-product-import' )
		);

		return $links;
	},
	10,
	2
);
