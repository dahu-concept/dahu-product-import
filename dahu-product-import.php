<?php
/**
 * Plugin Name:       Dahu Product Import
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Edit WooCommerce product text in a spreadsheet and import it back: one row per product, a dry run that shows every change before anything is written, and a rollback.
 * Version:           1.1.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
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

define( 'DAHU_PRODUCT_IMPORT_VERSION', '1.1.0' );
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

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_PRODUCT_IMPORT_PATH . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-product-import' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-product-import',
				'name'       => __( 'Product import', 'dahu-product-import' ),
				'tagline'    => __( 'Brings products in from a file, and says what it changed.', 'dahu-product-import' ),
				'file'       => DAHU_PRODUCT_IMPORT_FILE,
				'version'    => DAHU_PRODUCT_IMPORT_VERSION,
				'textdomain' => 'dahu-product-import',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M10 76 L10 100 Q10 106 16 106 L96 106 Q102 106 102 100 L102 76',
						'M10 76 L30 62 M102 76 L82 62 M10 76 L102 76',
						'M56 10 L56 52 M42 40 L56 54 L70 40',
						'M34.0 2.0 H78.0 A4.0 4.0 0 0 1 82.0 6.0 V12.0 A4.0 4.0 0 0 1 78.0 16.0 H34.0 A4.0 4.0 0 0 1 30.0 12.0 V6.0 A4.0 4.0 0 0 1 34.0 2.0 Z',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'dahu-product-import' ),
					'done'   => __( 'Done', 'dahu-product-import' ),
					'skip'   => __( 'Skip', 'dahu-product-import' ),
					'of'     => __( 'of', 'dahu-product-import' ),
					'denied' => __( 'You are not allowed to view this page.', 'dahu-product-import' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Dry run first', 'dahu-product-import' ),
					'text'  => __( 'See what would happen before anything is written.', 'dahu-product-import' ),
				),
				array(
					'title' => __( 'Line by line', 'dahu-product-import' ),
					'text'  => __( 'Each row reports created, updated or skipped, with a reason.', 'dahu-product-import' ),
				),
				array(
					'title' => __( 'Stops on nonsense', 'dahu-product-import' ),
					'text'  => __( 'A malformed file is refused, not half-imported.', 'dahu-product-import' ),
				),
				),

				/*
				 * The one-time tour.
				 *
				 * Every selector below points at something the Tools screen
				 * always renders, whatever the site holds: the page title, the
				 * ordered list of commands (a fixed array) and the columns
				 * table (its head is static). A step whose target is missing
				 * is skipped without a sound, so nothing here depends on data.
				 */
				'tour_screen' => 'tools_page_dahu-product-import',
				'tour'        => array(
					array(
						'selector' => '.dahu-ui-head__title',
						'title'    => __( 'Runs from WP-CLI', 'dahu-product-import' ),
						'text'     => __( 'This screen explains; the work is done from the command line.', 'dahu-product-import' ),
					),
					array(
						'selector' => '.dahu-pi-steps',
						'title'    => __( 'The sequence', 'dahu-product-import' ),
						'text'     => __( 'Export, dry run, import. Start with the dry run.', 'dahu-product-import' ),
					),
					array(
						'selector' => '.dahu-pi-columns',
						'title'    => __( 'Your file\'s columns', 'dahu-product-import' ),
						'text'     => __( 'The columns this site reads and writes.', 'dahu-product-import' ),
					),
				),
			)
		);
	}
);
