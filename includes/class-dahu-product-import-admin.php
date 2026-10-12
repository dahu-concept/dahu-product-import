<?php
/**
 * The Tools screen: what to run, and what the file holds.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * An information screen. No operation runs from the browser.
 *
 * It exists because a plugin with no screen at all looks broken: somebody
 * installs it, finds nothing anywhere, and uninstalls it. This page says what
 * to type and, more usefully, shows the columns THIS site's file will have —
 * which depends on what the theme declared, so no documentation can state it.
 */
final class Dahu_Product_Import_Admin {

	/**
	 * Hooks the menu.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * Loads this screen's stylesheet, on this screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_style( $hook ) {

		if ( 'tools_page_' . DAHU_PRODUCT_IMPORT_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'dahu-product-import-admin',
			DAHU_PRODUCT_IMPORT_URL . 'assets/css/admin.css',
			array(),
			DAHU_PRODUCT_IMPORT_VERSION
		);
	}

	/**
	 * Adds the page under Tools.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page(
			__( 'Product Import', 'dahu-product-import' ),
			__( 'Product Import', 'dahu-product-import' ),
			'manage_woocommerce',
			DAHU_PRODUCT_IMPORT_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dahu-product-import' ) );
		}

		$steps = array(
			__( 'See which columns this site reads and writes.', 'dahu-product-import' )      => 'wp product-import check',
			__( 'Write the spreadsheet from the site.', 'dahu-product-import' )               => 'wp product-import export',
			__( 'Dry run: the report, and not one write.', 'dahu-product-import' )            => 'wp product-import import --file=<file>',
			__( 'Import, once the report says what you expected.', 'dahu-product-import' )    => 'wp product-import import --file=<file> --execute',
			__( 'Put it back.', 'dahu-product-import' )                                       => 'wp product-import rollback --file=<backup> --execute',
			__( 'Find out why a field keeps being rewritten.', 'dahu-product-import' )         => 'wp product-import trace --ids=<ids>',
		);

		$fields   = Dahu_Product_Import_Fields::all();
		$reserved = Dahu_Product_Import_Fields::reserved_columns();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product import', 'dahu-product-import' ); ?></h1>

			<p class="dahu-product-import__intro">
				<?php esc_html_e( 'Dahu Product Import lets you edit your product text in a spreadsheet and import it back. Everything runs from WP-CLI: an import writes to hundreds of products at once, so it needs a dry run you can read, a report you can keep, and no browser timing out halfway through.', 'dahu-product-import' ); ?>
			</p>
			<hr class="wp-header-end">

			<p>
				<?php
				printf(
					/* translators: %s: site URL. */
					esc_html__( 'This site: %s', 'dahu-product-import' ),
					'<code>' . esc_html( home_url() ) . '</code>'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'The sequence', 'dahu-product-import' ); ?></h2>

			<ol class="dahu-pi-steps">
				<?php foreach ( $steps as $label => $command ) : ?>
					<li>
						<?php echo esc_html( $label ); ?><br>
						<code><?php echo esc_html( $command ); ?></code>
					</li>
				<?php endforeach; ?>
			</ol>

			<h2><?php esc_html_e( 'The columns of your file', 'dahu-product-import' ); ?></h2>

			<p class="dahu-pi-note">
				<?php esc_html_e( 'These depend on what your theme or plugins have declared, so this list is the only accurate one for this site.', 'dahu-product-import' ); ?>
			</p>

			<table class="widefat striped dahu-pi-columns">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Column', 'dahu-product-import' ); ?></th>
						<th><?php esc_html_e( 'Writes', 'dahu-product-import' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'dahu-product-import' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( Dahu_Product_Import_Fields::columns() as $column ) : ?>
					<tr>
						<td><code><?php echo esc_html( $column ); ?></code></td>
						<td>
							<?php
							if ( isset( $fields[ $column ] ) ) {
								echo '<code>' . esc_html( $fields[ $column ]['target'] ) . '</code>';
							} elseif ( Dahu_Product_Import_Fields::COLUMN_SKU === $column ) {
								echo '<code>_sku</code>';
							} elseif ( Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES === $column ) {
								esc_html_e( 'local attributes', 'dahu-product-import' );
							} else {
								esc_html_e( 'nothing', 'dahu-product-import' );
							}
							?>
						</td>
						<td>
							<?php
							if ( isset( $fields[ $column ] ) ) {

								$notes = array( $fields[ $column ]['nature'] );

								if ( $fields[ $column ]['required'] ) {
									$notes[] = __( 'required', 'dahu-product-import' );
								}

								if ( $fields[ $column ]['max_length'] ) {
									/* translators: %d: maximum number of characters. */
									$notes[] = sprintf( __( 'at most %d characters', 'dahu-product-import' ), $fields[ $column ]['max_length'] );
								}

								echo esc_html( implode( ', ', $notes ) );

							} elseif ( Dahu_Product_Import_Fields::COLUMN_ID === $column ) {
								esc_html_e( 'matches the row to a product', 'dahu-product-import' );
							} elseif ( Dahu_Product_Import_Fields::COLUMN_SLUG === $column ) {
								esc_html_e( 'confirms the id still means that product', 'dahu-product-import' );
							} elseif ( Dahu_Product_Import_Fields::COLUMN_SKU === $column ) {
								esc_html_e( 'an empty cell never clears a SKU', 'dahu-product-import' );
							} elseif ( Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES === $column ) {
								esc_html_e( 'visible, non-variation attributes only', 'dahu-product-import' );
							} else {
								esc_html_e( 'for information, never written', 'dahu-product-import' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( count( Dahu_Product_Import_Fields::columns() ) === count( $reserved ) + count( $fields ) && ! array_diff( array_keys( $fields ), array( 'title', 'description', 'short_description' ) ) ) : ?>
				<p class="dahu-pi-note">
					<?php
					printf(
						/* translators: %s: the filter name, in code tags. */
						esc_html__( 'Only the three built-in fields are declared. To carry your theme\'s own product fields, add them with the %s filter — the plugin\'s README has an example.', 'dahu-product-import' ),
						'<code>dahu_product_import_fields</code>'
					);
					?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'What an import never touches', 'dahu-product-import' ); ?></h2>

			<p class="dahu-product-import__intro">
				<?php esc_html_e( 'Prices, stock, weights, dimensions, categories, tags, variations, global attributes, orders and customers. It never creates or deletes a product either: a row naming an id this site does not have is reported and skipped.', 'dahu-product-import' ); ?>
			</p>
		</div>
		<?php
	}
}
