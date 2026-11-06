<?php
/**
 * Plugin Name:       Ubac Product Import
 * Description:       Edit WooCommerce product text in a spreadsheet and import it back: one row per product, a dry run that shows every change before anything is written, and a rollback.
 * Version:           1.1.2
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-import
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package UbacImport
 */

defined( 'ABSPATH' ) || exit;

define( 'UBAC_IMPORT_VERSION', '1.1.2' );
define( 'UBAC_IMPORT_FILE', __FILE__ );
define( 'UBAC_IMPORT_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_IMPORT_URL', plugin_dir_url( __FILE__ ) );
define( 'UBAC_IMPORT_BASENAME', plugin_basename( __FILE__ ) );
define( 'UBAC_IMPORT_SLUG', 'ubac-import' );

/**
 * Autoloader: Ubac_Import_Foo_Bar => includes/class-ubac-import-foo-bar.php.
 */
spl_autoload_register(
	static function ( $class ) {

		if ( 0 !== strpos( $class, 'Ubac_Import' ) ) {
			return;
		}

		$file = UBAC_IMPORT_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

/**
 * HPOS compatibility: this plugin never reads or writes an order.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				UBAC_IMPORT_FILE,
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
	WP_CLI::add_command( 'product-import', 'Ubac_Import_CLI' );
}

if ( is_admin() ) {
	Ubac_Import_Admin::init();
}

add_filter(
	'plugin_action_links_' . UBAC_IMPORT_BASENAME,
	static function ( $links ) {
		$page = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=' . UBAC_IMPORT_SLUG ) ),
			esc_html__( 'How to use', 'ubac-import' )
		);
		array_unshift( $links, $page );
		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( UBAC_IMPORT_BASENAME !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( 'https://dahu-concept.fr' ),
			esc_html__( 'Support', 'ubac-import' )
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
require_once UBAC_IMPORT_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'ubac-import' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'ubac-import',
				'name'       => __( 'Product import', 'ubac-import' ),
				'tagline'    => __( 'Brings products in from a file, and says what it changed.', 'ubac-import' ),
				'file'       => UBAC_IMPORT_FILE,
				'version'    => UBAC_IMPORT_VERSION,
				'textdomain' => 'ubac-import',
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
					'next'   => __( 'Next', 'ubac-import' ),
					'done'   => __( 'Done', 'ubac-import' ),
					'skip'   => __( 'Skip', 'ubac-import' ),
					'of'     => __( 'of', 'ubac-import' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-import' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Dry run first', 'ubac-import' ),
					'text'  => __( 'See what would happen before anything is written.', 'ubac-import' ),
				),
				array(
					'title' => __( 'Line by line', 'ubac-import' ),
					'text'  => __( 'Each row reports created, updated or skipped, with a reason.', 'ubac-import' ),
				),
				array(
					'title' => __( 'Stops on nonsense', 'ubac-import' ),
					'text'  => __( 'A malformed file is refused, not half-imported.', 'ubac-import' ),
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
				'tour_screen' => 'tools_page_ubac-import',
				'tour'        => array(
					array(
						'selector' => '.ubac-ui-head__title',
						'title'    => __( 'Runs from WP-CLI', 'ubac-import' ),
						'text'     => __( 'This screen explains; the work is done from the command line.', 'ubac-import' ),
					),
					array(
						'selector' => '.ubac-pi-steps',
						'title'    => __( 'The sequence', 'ubac-import' ),
						'text'     => __( 'Export, dry run, import. Start with the dry run.', 'ubac-import' ),
					),
					array(
						'selector' => '.ubac-pi-columns',
						'title'    => __( 'Your file\'s columns', 'ubac-import' ),
						'text'     => __( 'The columns this site reads and writes.', 'ubac-import' ),
					),
				),
			)
		);
	}
);
