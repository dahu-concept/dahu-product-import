<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the Plugins screen, never on
 * deactivation.
 *
 * There is nothing to delete, and that is worth writing down rather than
 * leaving somebody to wonder. This plugin stores no option, no transient and
 * no meta of its own: it writes to fields and meta that belong to the theme or
 * to WooCommerce, and those are the shop's content, not this plugin's data.
 *
 * The reports, backups and logs stay where they are. They live in a directory
 * outside any web root, chosen by whoever ran the command, and a backup is the
 * only way to undo an import: deleting them from here would mean uninstalling
 * the plugin silently threw away the ability to reverse what it had done.
 * Removing them is a `rm` on a directory somebody chose, which belongs to them
 * and not to an uninstall routine.
 *
 * @package DahuProductImport
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
