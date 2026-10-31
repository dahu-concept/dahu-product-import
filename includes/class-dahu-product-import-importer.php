<?php
/**
 * Applying a plan.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only class in this plugin that writes to the database.
 *
 * It writes exactly what the plan announced and nothing else, and before each
 * product it appends the product's PREVIOUS values to a backup file. The
 * backup is what makes the whole thing acceptable: an import that touches four
 * hundred products without one is a decision nobody can take back.
 *
 * Every write is read back afterwards. A plugin filtering post content on save
 * will quietly store something other than what was asked for, and the only way
 * to know is to look.
 */
final class Dahu_Product_Import_Importer {

	/**
	 * Working directory.
	 *
	 * @var Dahu_Product_Import_Workspace
	 */
	private $workspace;

	/**
	 * Output callback, one line at a time.
	 *
	 * @var callable
	 */
	private $say;

	/**
	 * Backup file, open during the import.
	 *
	 * @var resource|null
	 */
	private $backup;

	/**
	 * Log file, open during the import.
	 *
	 * @var resource|null
	 */
	private $log;

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
	 * Applies the plan.
	 *
	 * Running the same file again writes nothing: every product comes back as
	 * identical. That is not a nicety — it is what lets somebody re-run an
	 * import that was interrupted without having to work out where it stopped.
	 *
	 * @param array $plan A plan built in this same process.
	 * @return array{stats: array, backup: string, log: string}
	 * @throws RuntimeException Already locked.
	 */
	public function run( array $plan ) {

		$lock = $this->workspace->lock();

		/*
		 * One suffix, reserved from the filesystem, shared by this run's two
		 * files — so they are obviously a pair, and so a second import started
		 * in the same second cannot append to this one's backup. See
		 * Workspace::reserve().
		 */
		$suffix = $this->workspace->reserve( 'backup-%s.jsonl' );

		$backup_name = 'backup-' . $suffix . '.jsonl';
		$log_name    = 'import-' . $suffix . '.log';

		$stats = array(
			'updated'  => 0,
			'warnings' => 0,
			'errors'   => 0,
		);

		$this->backup = $this->workspace->open_append( $backup_name );
		$this->log    = $this->workspace->open_append( $log_name );

		$this->write_log( sprintf( 'import started: %s (SHA-256 %s)', $plan['file'], $plan['sha256'] ) );
		$this->record(
			array(
				'op'     => 'start',
				'file'   => $plan['file'],
				'sha256' => $plan['sha256'],
				'site'   => home_url(),
			)
		);

		/*
		 * Values are already cleaned explicitly, field by field, according to
		 * each one's declared nature. A second pass of kses here would filter
		 * them again according to the ROLE of whoever is running the command,
		 * so the same file would import differently for an administrator and
		 * for an editor — and the read-back check below would report
		 * differences that are really this filtering.
		 */
		kses_remove_filters();

		try {
			foreach ( $plan['items'] as $item ) {

				if ( 'rejected' === $item['verdict'] ) {
					$this->write_log(
						sprintf(
							'line %d — #%s skipped: %s',
							$item['line'],
							$item['id'],
							implode( ' ; ', $item['reasons'] )
						),
						'WARN'
					);
					++$stats['warnings'];
					continue;
				}

				if ( 'update' === $item['verdict'] ) {
					$this->update( $item, $stats );
				}
			}
		} finally {
			kses_init_filters();
			$this->write_log( 'import finished: ' . wp_json_encode( $stats ) );
			$this->record(
				array(
					'op'    => 'end',
					'stats' => $stats,
				)
			);
			fclose( $this->backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			flock( $lock, LOCK_UN );
		}

		return array(
			'stats'  => $stats,
			'backup' => $this->workspace->path( $backup_name ),
			'log'    => $this->workspace->path( $log_name ),
		);
	}

	/**
	 * Writes one product, after backing up what it held.
	 *
	 * @param array $item  Plan item.
	 * @param array $stats Counters, by reference.
	 * @return void
	 */
	private function update( array $item, array &$stats ) {

		$id     = (int) $item['id'];
		$values = $item['values'];
		$post   = get_post( $id );

		if ( ! $post || 'product' !== $post->post_type ) {
			++$stats['errors'];
			$this->write_log( sprintf( 'product #%d not found at write time', $id ), 'ERROR' );
			return;
		}

		$before = $this->capture_before( $id, $post, $values );
		$after  = $this->capture_after( $values );

		$this->record(
			array(
				'op'     => 'update_product',
				'line'   => $item['line'],
				'id'     => $id,
				'slug'   => $post->post_name,
				'before' => $before['record'],
				'after'  => $after,
			)
		);

		if ( $values['fields'] ) {

			$result = wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $values['fields'] ) ), true );

			if ( is_wp_error( $result ) ) {
				++$stats['errors'];
				$this->write_log(
					sprintf( 'product #%d: %s (its meta was not written)', $id, $result->get_error_message() ),
					'ERROR'
				);
				return;
			}
		}

		foreach ( $values['meta'] as $key => $value ) {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}

		foreach ( $values['delete'] as $key ) {
			delete_post_meta( $id, $key );
		}

		$sku_written = $this->write_sku( $id, $values, $stats );

		if ( isset( $values['attributes'] ) && $before['product'] ) {
			Dahu_Product_Import_Attributes::apply( $before['product'], $values['attributes'] );
		}

		clean_post_cache( $id );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $id );
		}

		$this->read_back( $id, $values, $sku_written, $stats );

		++$stats['updated'];
		$this->write_log(
			sprintf(
				'product #%d "%s" updated: %s',
				$id,
				$post->post_name,
				implode( ', ', $this->describe_written( $values, $sku_written ) )
			)
		);
	}

	/**
	 * The product's previous values, for the backup.
	 *
	 * @param int     $id     Product id.
	 * @param WP_Post $post   The product.
	 * @param array   $values Values about to be written.
	 * @return array{record: array, product: WC_Product|null}
	 */
	private function capture_before( $id, $post, array $values ) {

		$record = array(
			'fields' => array(),
			'meta'   => array(),
		);

		foreach ( array_keys( $values['fields'] ) as $field ) {
			$record['fields'][ $field ] = (string) $post->$field;
		}

		foreach ( array_merge( array_keys( $values['meta'] ), $values['delete'] ) as $key ) {

			$exists = metadata_exists( 'post', $id, $key );

			$record['meta'][ $key ] = array(
				'exists' => $exists,
				'value'  => $exists ? (string) get_post_meta( $id, $key, true ) : null,
			);
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		if ( $product && isset( $values['sku'] ) ) {
			$record['sku'] = (string) $product->get_sku();
		}

		if ( $product && isset( $values['attributes'] ) ) {
			$record['attributes'] = Dahu_Product_Import_Attributes::pairs( $product );
		}

		return array(
			'record'  => $record,
			'product' => $product,
		);
	}

	/**
	 * Fingerprints of what is about to be written.
	 *
	 * Fingerprints and not the values themselves: the rollback uses them to
	 * check that the product still holds what the import left, and storing
	 * them twice would double the size of a backup whose only job is to hold
	 * the PREVIOUS values.
	 *
	 * @param array $values Values about to be written.
	 * @return array
	 */
	private function capture_after( array $values ) {

		$after = array(
			'fields' => array_map( 'md5', $values['fields'] ),
			'meta'   => array_map( 'md5', $values['meta'] ),
		);

		foreach ( $values['delete'] as $key ) {
			$after['meta'][ $key ] = null;
		}

		if ( isset( $values['sku'] ) ) {
			$after['sku'] = md5( (string) $values['sku'] );
		}

		if ( isset( $values['attributes'] ) ) {
			$after['attributes'] = md5( (string) wp_json_encode( $values['attributes'] ) );
		}

		return $after;
	}

	/**
	 * Writes the SKU through the WooCommerce API.
	 *
	 * Never as a direct meta write: WooCommerce keeps its own SKU lookup
	 * table, and a meta written behind its back leaves that table on the old
	 * value — so the SKU shows correctly on the product and finds nothing when
	 * searched for.
	 *
	 * @param int   $id     Product id.
	 * @param array $values Planned values.
	 * @param array $stats  Counters, by reference.
	 * @return bool True when a SKU was written.
	 */
	private function write_sku( $id, array $values, array &$stats ) {

		if ( ! isset( $values['sku'] ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		/*
		 * Reloaded AFTER the fields and meta were written: the object read
		 * before them no longer reflects the product.
		 */
		$product = wc_get_product( $id );

		if ( ! $product ) {
			++$stats['errors'];
			$this->write_log( sprintf( 'product #%d: WooCommerce cannot read it, SKU not written', $id ), 'ERROR' );
			return false;
		}

		try {
			// set_sku() throws on a duplicate. Uniqueness was checked during
			// planning, but the catalogue can have moved since.
			$product->set_sku( $values['sku'] );
			$product->save();
		} catch ( Throwable $e ) {
			// Throwable, and not Exception. save() runs every third-party
			// hook on a product write, and a plugin that calls a method on
			// null there raises an Error. An Error escaping this clause
			// leaves the run at the product whose text has just been
			// written, with the remaining lines neither written nor
			// reported — a half-done import whose backup covers only the
			// products it reached. One refused SKU is a counted error and
			// the next line, which is what the clause was for.
			++$stats['errors'];
			$this->write_log(
				sprintf( 'product #%d: SKU "%s" refused by WooCommerce (%s)', $id, $values['sku'], $e->getMessage() ),
				'ERROR'
			);
			return false;
		}

		return true;
	}

	/**
	 * Reads every written value back and reports anything that differs.
	 *
	 * A plugin filtering content on save stores something other than what was
	 * asked for. Noticing it here matters twice over: the import says so, and
	 * the rollback will later require --force rather than refusing silently,
	 * because its fingerprints will not match either.
	 *
	 * @param int   $id          Product id.
	 * @param array $values      Written values.
	 * @param bool  $sku_written Whether a SKU was written.
	 * @param array $stats       Counters, by reference.
	 * @return void
	 */
	private function read_back( $id, array $values, $sku_written, array &$stats ) {

		$post = get_post( $id );

		foreach ( $values['fields'] as $field => $value ) {
			if ( (string) $post->$field !== (string) $value ) {
				++$stats['warnings'];
				$this->write_log(
					sprintf( 'product #%d: %s reads back different from what was written (a plugin filter?)', $id, $field ),
					'WARN'
				);
			}
		}

		foreach ( $values['meta'] as $key => $value ) {
			if ( (string) get_post_meta( $id, $key, true ) !== (string) $value ) {
				++$stats['warnings'];
				$this->write_log(
					sprintf( 'product #%d: %s reads back different from what was written', $id, $key ),
					'WARN'
				);
			}
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		if ( isset( $values['attributes'] ) ) {

			$product = wc_get_product( $id );
			$read    = $product ? Dahu_Product_Import_Attributes::pairs( $product ) : array();

			if ( Dahu_Product_Import_Attributes::to_cell( $read ) !== Dahu_Product_Import_Attributes::to_cell( $values['attributes'] ) ) {
				++$stats['warnings'];
				$this->write_log(
					sprintf( 'product #%d: attributes read back different from what was written', $id ),
					'WARN'
				);
			}
		}

		if ( $sku_written ) {

			$product = wc_get_product( $id );

			if ( $product && (string) $product->get_sku() !== (string) $values['sku'] ) {
				++$stats['warnings'];
				$this->write_log( sprintf( 'product #%d: SKU reads back different from what was written', $id ), 'WARN' );
			}
		}
	}

	/**
	 * What was written, for the log line.
	 *
	 * The attributes get a description of their own: they are neither a field
	 * nor a meta, so without this their write would appear nowhere in the log.
	 *
	 * @param array $values      Written values.
	 * @param bool  $sku_written Whether a SKU was written.
	 * @return array<int, string>
	 */
	private function describe_written( array $values, $sku_written ) {

		$written = array_merge(
			array_keys( $values['fields'] ),
			array_keys( $values['meta'] ),
			array_map(
				static function ( $key ) {
					return '-' . $key;
				},
				$values['delete']
			)
		);

		if ( $sku_written ) {
			$written[] = 'sku';
		}

		if ( isset( $values['attributes'] ) ) {

			$names = wp_list_pluck( $values['attributes'], 'name' );

			$written[] = sprintf(
				'%s [%d attribute(s)%s]',
				Dahu_Product_Import_Fields::COLUMN_ATTRIBUTES,
				count( $names ),
				$names ? ': ' . implode( ', ', $names ) : ' — all removed'
			);
		}

		return $written;
	}

	/**
	 * Appends one record to the JSONL backup and forces it to disk.
	 *
	 * Flushed on every line on purpose. A backup buffered in memory is lost
	 * exactly when it is needed: when the run died halfway through.
	 *
	 * @param array $record Record.
	 * @return void
	 */
	private function record( array $record ) {

		$record['date'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $this->backup, wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		fflush( $this->backup );
	}

	/**
	 * Appends one line to the log and shows it.
	 *
	 * Log lines are English and deliberately NOT translatable: a technical log
	 * is read while something is wrong, often by somebody other than the
	 * person who ran the import, and it is more useful when its wording does
	 * not depend on whichever locale happened to be active.
	 *
	 * @param string $message Message.
	 * @param string $level   INFO|WARN|ERROR.
	 * @return void
	 */
	private function write_log( $message, $level = 'INFO' ) {

		$line = gmdate( 'Y-m-d H:i:s' ) . ' [' . $level . '] ' . $message;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $this->log, $line . "\n" );
		fflush( $this->log );

		call_user_func( $this->say, $line );
	}
}
