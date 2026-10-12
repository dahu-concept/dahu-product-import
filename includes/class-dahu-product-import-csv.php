<?php
/**
 * Reading and writing the spreadsheet.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * The CSV file.
 *
 * Everything here is about one thing: a spreadsheet saved by a person on a
 * laptop is not a well-formed data file, and every way it can be malformed
 * has to produce a message naming the problem rather than a silent misread.
 * A column read one position to the left writes a description into a subtitle
 * on four hundred products, and nothing fails.
 */
final class Dahu_Product_Import_CSV {

	/**
	 * Reads the file: header, plus records indexed by spreadsheet line number.
	 *
	 * The line number is kept because that is the number the person editing
	 * the file can see. A row index of our own would be one more thing to
	 * translate between the report and the spreadsheet.
	 *
	 * fgetcsv() handles quoted cells containing separators, doubled quotes and
	 * newlines. The non-standard escape character (backslash) is switched off,
	 * per RFC 4180: left on, a Windows path in a cell swallows the quote after
	 * it. A UTF-8 BOM is skipped, cell line endings are brought to LF, and any
	 * non-UTF-8 byte stops the read rather than being converted — a silent
	 * encoding conversion is how accented text turns to mojibake in four
	 * hundred products at once.
	 *
	 * @param string $path File path.
	 * @return array{separator: string, header: array, rows: array, unknown: array}
	 * @throws RuntimeException Unreadable, not UTF-8, empty, or missing columns.
	 */
	public static function read( $path ) {

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local file, CLI.
		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			/* translators: %s: file path. */
			throw new RuntimeException( sprintf( __( 'Cannot read: %s', 'dahu-product-import' ), $path ) );
		}

		try {
			if ( "\xEF\xBB\xBF" !== fread( $handle, 3 ) ) {
				rewind( $handle );
			}

			$start     = ftell( $handle );
			$first     = (string) fgets( $handle );
			$separator = self::detect_separator( $first );
			fseek( $handle, $start );

			$header  = null;
			$records = array();
			$number  = 0;

			while ( false !== ( $row = fgetcsv( $handle, 0, $separator, '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$number;

				if ( array( null ) === $row ) {
					continue; // An empty line.
				}

				foreach ( $row as $i => $cell ) {

					$cell = (string) $cell;

					if ( ! mb_check_encoding( $cell, 'UTF-8' ) ) {
						throw new RuntimeException(
							sprintf(
								/* translators: %d: spreadsheet line number. */
								__( 'Line %d: the text is not UTF-8. Save the spreadsheet as "CSV UTF-8", not as plain "CSV".', 'dahu-product-import' ),
								$number
							)
						);
					}

					$row[ $i ] = str_replace( array( "\r\n", "\r" ), "\n", $cell );
				}

				if ( null === $header ) {
					$header = array_map(
						static function ( $name ) {
							return strtolower( trim( $name ) );
						},
						$row
					);
					continue;
				}

				$records[ $number ] = $row;
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		if ( null === $header ) {
			/* translators: %s: file path. */
			throw new RuntimeException( sprintf( __( 'The file is empty: %s', 'dahu-product-import' ), $path ) );
		}

		$expected = Dahu_Product_Import_Fields::columns();
		$missing  = array_diff( $expected, $header );

		if ( $missing ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: comma-separated column names, 2: the detected separator. */
					__( 'Columns missing from the header: %1$s (separator detected: "%2$s").', 'dahu-product-import' ),
					implode( ', ', $missing ),
					"\t" === $separator ? 'tab' : $separator
				)
			);
		}

		$duplicates = array_diff_assoc( $header, array_unique( $header ) );

		if ( $duplicates ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: comma-separated column names. */
					__( 'Duplicate columns in the header: %s', 'dahu-product-import' ),
					implode( ', ', array_unique( $duplicates ) )
				)
			);
		}

		$rows = array();

		foreach ( $records as $number => $row ) {

			if ( count( $row ) !== count( $header ) ) {
				/*
				 * Recorded as an error on the row rather than thrown. One row
				 * with an unclosed quote must not stop the other three hundred
				 * from being reported, and the report is what somebody uses to
				 * fix the file.
				 */
				$rows[ $number ] = array(
					'_error' => sprintf(
						/* translators: 1: cells found, 2: cells expected. */
						__( '%1$d cell(s) instead of %2$d (an unclosed quote, or a separator inside an unquoted cell?)', 'dahu-product-import' ),
						count( $row ),
						count( $header )
					),
				);
				continue;
			}

			$rows[ $number ] = array_combine( $header, $row );
		}

		return array(
			'separator' => $separator,
			'header'    => $header,
			'rows'      => $rows,
			'unknown'   => array_values( array_diff( $header, $expected ) ),
		);
	}

	/**
	 * The separator most frequent in the header line.
	 *
	 * Detected rather than configured, because the person saving the file
	 * usually does not choose it: a spreadsheet in a French or German locale
	 * writes semicolons from the same "Save as CSV" menu item that writes
	 * commas in an English one.
	 *
	 * @param string $line First line of the file.
	 * @return string
	 */
	public static function detect_separator( $line ) {

		$best  = ',';
		$count = substr_count( $line, ',' );

		foreach ( array( ';', "\t" ) as $candidate ) {

			$found = substr_count( $line, $candidate );

			if ( $found > $count ) {
				$best  = $candidate;
				$count = $found;
			}
		}

		return $best;
	}

	/**
	 * Writes rows to a CSV string, with a UTF-8 BOM.
	 *
	 * The BOM is there for one reason: without it a spreadsheet opening the
	 * file on Windows reads it as the system codepage, and every accented
	 * character is wrong before anybody has edited anything.
	 *
	 * @param array<int, array> $rows      Rows, each an ordered list of cells.
	 * @param string            $separator Separator.
	 * @return string
	 */
	public static function write( $rows, $separator ) {

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory buffer.
		$buffer = fopen( 'php://temp', 'w+b' );

		foreach ( $rows as $row ) {
			fputcsv( $buffer, $row, $separator, '"', '' );
		}

		rewind( $buffer );
		$csv = (string) stream_get_contents( $buffer );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Sends one cell through fputcsv() and straight back through fgetcsv().
	 *
	 * Used by the diagnostic command to show what this server's own CSV
	 * encoding does to a value, which is the step people least expect to be
	 * the culprit.
	 *
	 * @param string $cell      Cell value.
	 * @param string $separator Separator.
	 * @return string
	 */
	public static function round_trip( $cell, $separator ) {

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory buffer.
		$buffer = fopen( 'php://temp', 'w+b' );
		fputcsv( $buffer, array( 'a', $cell, 'b' ), $separator, '"', '' );
		rewind( $buffer );
		$row = fgetcsv( $buffer, 0, $separator, '"', '' );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return is_array( $row ) && isset( $row[1] )
			? str_replace( array( "\r\n", "\r" ), "\n", (string) $row[1] )
			: __( '(unreadable)', 'dahu-product-import' );
	}
}
