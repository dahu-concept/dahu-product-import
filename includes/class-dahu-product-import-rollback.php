<?php
/**
 * Putting back what an import replaced.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a backup file and restores the values it holds.
 *
 * The important decision here is what the rollback does when a product has
 * been EDITED SINCE the import. It refuses, per field, and says so.
 *
 * The reason is that an import is often discovered to be wrong a few days
 * later, by which time somebody has fixed three products by hand. A rollback
 * that restored everything would silently undo that work, and the person who
 * did it would have no way of knowing. So each field's current value is
 * checked against the fingerprint the import recorded: unchanged since the
 * import, it is restored; changed, it is left alone and reported. `--force`
 * overrides that, for somebody who has read the report and means it.
 *
 * A dry run is the default here too.
 */
final class Dahu_Product_Import_Rollback {

	/**
	 * Working directory.
	 *
	 * @var Dahu_Product_Import_Workspace
	 */
	private $workspace;

	/**
	 * Output callback.
	 *
	 * @var callable
	 */
	private $say;

	/**
	 * Constructor.
	 *
	 * @param Dahu_Product_Import_Workspace $workspace Working directory.
	 * @param callable                      $say       Output callback.
	 */
	public function __construct( Dahu_Product_Import_Workspace $workspace, $say ) {
		$this->workspace = $workspace;
		$this->say       = $say;
	}

	/**
	 * Runs the rollback.
	 *
	 * @param string $file    Backup file.
	 * @param bool   $execute Write, rather than only report.
	 * @param bool   $force   Restore even what changed since the import.
	 * @return array Counters.
	 * @throws RuntimeException Unreadable file, or already locked.
	 */
	public function run( $file, $execute, $force ) {

		$path = $this->workspace->resolve( $file );
		$lock = $this->workspace->lock();

		$stats = array(
			'restored' => 0,
			'skipped'  => 0,
			'errors'   => 0,
		);

		$prefix = $execute ? '' : '[' . __( 'dry run', 'dahu-product-import' ) . '] ';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local file, CLI.
		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			flock( $lock, LOCK_UN );
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot read: %s', 'dahu-product-import' ), $path ) ) );
		}

		/*
		 * Read in reverse so products are restored in the opposite order to
		 * the one they were written in. It makes no difference to the result
		 * here, and it makes the log read like an undo.
		 */
		$records = array();

		try {
			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition

				$record = json_decode( trim( $line ), true );

				if ( is_array( $record ) && isset( $record['op'] ) && 'update_product' === $record['op'] ) {
					$records[] = $record;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		try {
			foreach ( array_reverse( $records ) as $record ) {
				$this->restore( $record, $execute, $force, $prefix, $stats );
			}
		} finally {
			flock( $lock, LOCK_UN );
		}

		return $stats;
	}

	/**
	 * Restores one product.
	 *
	 * @param array  $record  Backup record.
	 * @param bool   $execute Write.
	 * @param bool   $force   Ignore the fingerprints.
	 * @param string $prefix  Dry-run prefix for the messages.
	 * @param array  $stats   Counters, by reference.
	 * @return void
	 */
	private function restore( array $record, $execute, $force, $prefix, array &$stats ) {

		$id    = (int) $record['id'];
		$label = sprintf( 'product #%d "%s"', $id, isset( $record['slug'] ) ? $record['slug'] : '' );
		$post  = get_post( $id );

		if ( ! $post ) {
			$this->out( $label . ': ' . __( 'not found', 'dahu-product-import' ), $stats, 'errors' );
			return;
		}

		$before = isset( $record['before'] ) ? (array) $record['before'] : array();
		$after  = isset( $record['after'] ) ? (array) $record['after'] : array();

		$fields = array();

		foreach ( ( isset( $before['fields'] ) ? (array) $before['fields'] : array() ) as $field => $value ) {

			$expected = isset( $after['fields'][ $field ] ) ? $after['fields'][ $field ] : null;

			if ( ! $force && md5( (string) $post->$field ) !== $expected ) {
				$this->out(
					sprintf(
						/* translators: 1: dry-run prefix, 2: product label, 3: field name. */
						__( '%1$s%2$s: %3$s has changed since the import, not restored (--force to overwrite)', 'dahu-product-import' ),
						$prefix,
						$label,
						$field
					),
					$stats,
					'skipped'
				);
				continue;
			}

			$fields[ $field ] = (string) $value;
		}

		$meta = array();

		foreach ( ( isset( $before['meta'] ) ? (array) $before['meta'] : array() ) as $key => $was ) {

			$expected = isset( $after['meta'][ $key ] ) ? $after['meta'][ $key ] : null;
			$exists   = metadata_exists( 'post', $id, $key );
			$current  = $exists ? md5( (string) get_post_meta( $id, $key, true ) ) : null;

			if ( ! $force && $current !== $expected ) {
				$this->out(
					sprintf(
						/* translators: 1: dry-run prefix, 2: product label, 3: meta key. */
						__( '%1$s%2$s: %3$s has changed since the import, not restored (--force to overwrite)', 'dahu-product-import' ),
						$prefix,
						$label,
						$key
					),
					$stats,
					'skipped'
				);
				continue;
			}

			$meta[ $key ] = (array) $was;
		}

		$sku        = $this->plan_sku_restore( $record, $id, $force, $prefix, $stats );
		$attributes = $this->plan_attributes_restore( $record, $id, $force, $prefix, $stats );

		if ( ! $fields && ! $meta && ! $sku && ! $attributes ) {
			return;
		}

		if ( $execute ) {

			if ( $fields ) {

				$result = wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $fields ) ), true );

				if ( is_wp_error( $result ) ) {
					$this->out( $label . ': ' . $result->get_error_message(), $stats, 'errors' );
					return;
				}
			}

			foreach ( $meta as $key => $was ) {
				if ( ! empty( $was['exists'] ) ) {
					update_post_meta( $id, $key, wp_slash( (string) $was['value'] ) );
				} else {
					delete_post_meta( $id, $key );
				}
			}

			if ( $sku ) {
				$sku[0]->set_sku( $sku[1] );
				$sku[0]->save();
			}

			if ( $attributes ) {
				Dahu_Product_Import_Attributes::apply( $attributes[0], $attributes[1] );
			}

			clean_post_cache( $id );

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
		}

		$restored = array_merge( array_keys( $fields ), array_keys( $meta ) );

		if ( $sku ) {
			$restored[] = 'sku';
		}

		if ( $attributes ) {
			$restored[] = Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES;
		}

		$this->out(
			sprintf( '%s%s restored: %s', $prefix, $label, implode( ', ', $restored ) ),
			$stats,
			'restored'
		);
	}

	/**
	 * Decides whether the SKU can be put back.
	 *
	 * Two extra conditions beyond the fingerprint: the product has to be
	 * readable by WooCommerce, and the OLD SKU has to still be free. Without
	 * the second, restoring would hand a SKU to a second product that now
	 * also holds it — WooCommerce would refuse the save, and the rollback
	 * would stop halfway through a product.
	 *
	 * @param array  $record Backup record.
	 * @param int    $id     Product id.
	 * @param bool   $force  Ignore the fingerprint.
	 * @param string $prefix Dry-run prefix.
	 * @param array  $stats  Counters, by reference.
	 * @return array|null array( WC_Product, sku ), or null.
	 */
	private function plan_sku_restore( array $record, $id, $force, $prefix, array &$stats ) {

		$before = isset( $record['before'] ) ? (array) $record['before'] : array();

		if ( ! array_key_exists( 'sku', $before ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$label   = sprintf( 'product #%d', $id );
		$product = wc_get_product( $id );

		if ( ! $product ) {
			$this->out( $label . ': ' . __( 'WooCommerce cannot read it, SKU not restored', 'dahu-product-import' ), $stats, 'errors' );
			return null;
		}

		$expected = isset( $record['after']['sku'] ) ? $record['after']['sku'] : null;

		if ( ! $force && md5( (string) $product->get_sku() ) !== $expected ) {
			$this->out(
				sprintf(
					/* translators: 1: dry-run prefix, 2: product label. */
					__( '%1$s%2$s: the SKU has changed since the import, not restored (--force to overwrite)', 'dahu-product-import' ),
					$prefix,
					$label
				),
				$stats,
				'skipped'
			);
			return null;
		}

		$holder = function_exists( 'wc_get_product_id_by_sku' )
			? (int) wc_get_product_id_by_sku( (string) $before['sku'] )
			: 0;

		if ( $holder && $holder !== $id ) {
			$this->out(
				sprintf(
					/* translators: 1: dry-run prefix, 2: product label, 3: the product id now holding it. */
					__( '%1$s%2$s: the pre-import SKU is now held by #%3$d, not restored', 'dahu-product-import' ),
					$prefix,
					$label,
					$holder
				),
				$stats,
				'skipped'
			);
			return null;
		}

		return array( $product, (string) $before['sku'] );
	}

	/**
	 * Decides whether the attributes can be put back.
	 *
	 * @param array  $record Backup record.
	 * @param int    $id     Product id.
	 * @param bool   $force  Ignore the fingerprint.
	 * @param string $prefix Dry-run prefix.
	 * @param array  $stats  Counters, by reference.
	 * @return array|null array( WC_Product, pairs ), or null.
	 */
	private function plan_attributes_restore( array $record, $id, $force, $prefix, array &$stats ) {

		$before = isset( $record['before'] ) ? (array) $record['before'] : array();

		if ( ! isset( $before['attributes'] ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$label   = sprintf( 'product #%d', $id );
		$product = wc_get_product( $id );

		if ( ! $product ) {
			$this->out( $label . ': ' . __( 'WooCommerce cannot read it, attributes not restored', 'dahu-product-import' ), $stats, 'errors' );
			return null;
		}

		$expected = isset( $record['after']['attributes'] ) ? $record['after']['attributes'] : null;
		$current  = md5( (string) wp_json_encode( Dahu_Product_Import_Attributes::pairs( $product ) ) );

		if ( ! $force && $current !== $expected ) {
			$this->out(
				sprintf(
					/* translators: 1: dry-run prefix, 2: product label. */
					__( '%1$s%2$s: the attributes have changed since the import, not restored (--force to overwrite)', 'dahu-product-import' ),
					$prefix,
					$label
				),
				$stats,
				'skipped'
			);
			return null;
		}

		return array( $product, (array) $before['attributes'] );
	}

	/**
	 * Says something and counts it.
	 *
	 * @param string $message Message.
	 * @param array  $stats   Counters, by reference.
	 * @param string $counter Which counter.
	 * @return void
	 */
	private function out( $message, array &$stats, $counter ) {
		++$stats[ $counter ];
		call_user_func( $this->say, $message );
	}
}
