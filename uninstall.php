<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the Plugins screen, never on
 * deactivation.
 *
 * Almost nothing to delete, and that is worth writing down rather than
 * leaving somebody to wonder. This plugin stores no setting, no transient and
 * no meta of its own: it writes to fields and meta that belong to the theme or
 * to WooCommerce, and those are the shop's content, not this plugin's data.
 *
 * The three exceptions are the welcome screen's own flags, written by
 * `includes/ubac-welcome.php` — the file this family copies into every
 * plugin. They are interface state rather than data, and they are the reason
 * this file is not empty: left behind, a reinstall would never greet anybody
 * again, the screen and the guided tour staying silent for good with nothing
 * on screen to explain why.
 *
 * The reports, backups and logs stay where they are. They live in a directory
 * outside any web root, chosen by whoever ran the command, and a backup is the
 * only way to undo an import: deleting them from here would mean uninstalling
 * the plugin silently threw away the ability to reverse what it had done.
 * Removing them is a `rm` on a directory somebody chose, which belongs to them
 * and not to an uninstall routine.
 *
 * @package UbacImport
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clears the welcome screen's flags for one site.
 *
 * @return void
 */
function ubac_import_uninstall_site() {

	delete_option( 'ubac_welcome_seen_ubac-import' );
	// Les memes clefs sous le nouveau slug : elle est construite au runtime
	// a partir du slug, donc elle a change avec lui. Sans ces trois lignes,
	// une mise a jour depuis la version Ubac laisse des orphelines que plus
	// rien ne nomme.
	delete_option( 'ubac_welcome_seen_dahuconcept-product-import' );
	delete_option( 'ubac_welcome_pending_ubac-import' );
	delete_option( 'ubac_welcome_pending_dahuconcept-product-import' );
	delete_option( 'ubac_welcome_tour_ubac-import' );
	delete_option( 'ubac_welcome_tour_dahuconcept-product-import' );
}

if ( is_multisite() ) {

	// `number => 0`: without it WP_Site_Query stops at the first hundred sites
	// and the flags stay behind on every other one.
	$ubac_import_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_import_sites as $ubac_import_site ) {
		switch_to_blog( (int) $ubac_import_site );
		ubac_import_uninstall_site();
		restore_current_blog();
	}
} else {
	ubac_import_uninstall_site();
}
