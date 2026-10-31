<?php
/**
 * Cleaning a cell, and deciding whether it differs from the site.
 *
 * @package DahuProductImport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleaning, comparison and preview.
 *
 * Every value written to the database passes through here. The comparison
 * functions matter as much as the cleaning ones: deciding that a cell is
 * UNCHANGED is what stops an import from rewriting a field with a normalised
 * version of what was already there, which is a loss dressed up as a no-op.
 */
final class Dahu_Product_Import_Sanitize {

	/**
	 * The kinds of cleaning a field can ask for.
	 *
	 * - `text`        plain text, one line. Newlines collapse to spaces.
	 * - `textarea`    plain text, newlines kept.
	 * - `title`       plain text through sanitize_text_field(), because a
	 *                 title is usually rendered unescaped by a theme.
	 * - `html`        rich text through wp_kses_post().
	 * - `excerpt`     plain multi-line text, or HTML kept as typed when the
	 *                 cell contains any.
	 * - `url`         an http or https URL with a host, or nothing.
	 * - `product_id`  the id of a product on this site.
	 */
	const NATURES = array( 'text', 'textarea', 'title', 'html', 'excerpt', 'url', 'product_id' );

	/**
	 * Cleans plain text that is stored as typed and escaped only on output.
	 *
	 * NOT sanitize_text_field() or sanitize_textarea_field(), and the reason is
	 * concrete: those turn a lone "<" into "&lt;" through
	 * wp_pre_kses_less_than(), which corrupts a legitimate technical value
	 * such as "<50dB" — and when a ">" appears later on the same line they
	 * remove the whole fragment between them.
	 *
	 * Escaping belongs to output. A theme renders these values with
	 * esc_html(), so storing them escaped would double-escape them.
	 *
	 * @param string $text          Text.
	 * @param bool   $keep_newlines Keep newlines.
	 * @return string
	 */
	public static function text( $text, $keep_newlines = false ) {

		$text = wp_check_invalid_utf8( (string) $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		$text = (string) preg_replace( '#<!--.*?-->#s', '', $text );
		$text = (string) preg_replace( '#</?[a-z][a-z0-9-]*(?:\s[^<>]*)?/?>#i', '', $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		$text = $keep_newlines
			? (string) preg_replace( '/[^\S\n]+/u', ' ', $text )
			: (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Cleans a cell according to its field's nature.
	 *
	 * @param string $raw    Cell, newlines already normalised to LF and edges trimmed.
	 * @param string $nature One of NATURES.
	 * @return string Value to store; '' means an empty field.
	 */
	public static function clean( $raw, $nature ) {

		if ( '' === $raw ) {
			return '';
		}

		switch ( $nature ) {

			case 'html':
				return trim( wp_kses_post( $raw ) );

			case 'textarea':
				return self::text( $raw, true );

			case 'title':
				return sanitize_text_field( $raw );

			case 'url':
				return self::url( $raw );

			case 'product_id':
				return ctype_digit( $raw ) && absint( $raw ) ? (string) absint( $raw ) : '';

			case 'excerpt':
				return self::excerpt( $raw );

			default:
				return self::text( $raw );
		}
	}

	/**
	 * Cleans a URL, or returns nothing.
	 *
	 * A HOST CHECK IS NOT ENOUGH ON ITS OWN, which is the subtle part.
	 * esc_url_raw() does not merely sanitise: given a bare string it PREPENDS a
	 * scheme. "not a url" comes back as "http://not%20a%20url", whose host is
	 * "not%20a%20url" — perfectly truthy. So a cell holding a sentence passed
	 * a host check, was stored as a URL, and became a broken link on the
	 * product page, while the dry run reported it as an ordinary value change.
	 *
	 * The test is therefore on the host's CHARACTERS: a percent sign or a
	 * space cannot appear in a host, and prose always contains one or the
	 * other. Non-ASCII is allowed through, because an internationalised domain
	 * is a real address and refusing it would be worse than the problem.
	 *
	 * Deliberately NOT filter_var( FILTER_VALIDATE_URL ): it rejects
	 * internationalised domains outright.
	 *
	 * And deliberately no requirement of a dot in the host. A single-label
	 * host is unusual in a shop's spreadsheet and usually a typo — but
	 * "localhost" and intranet names are real, and an invalid URL REJECTS THE
	 * WHOLE ROW, so none of that product's text would import. A broken link is
	 * visible and cheap to fix; a row refused for a surprising reason costs
	 * somebody an afternoon.
	 *
	 * @param string $raw Cell.
	 * @return string The URL, or '' when the cell is not one.
	 */
	public static function url( $raw ) {

		$url = esc_url_raw( $raw, array( 'http', 'https' ) );

		if ( '' === $url ) {
			return '';
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return '';
		}

		// Letters, digits, hyphen, dot, underscore, tilde, and anything
		// non-ASCII. No percent sign, no whitespace, no colon.
		if ( ! preg_match( '/^[A-Za-z0-9\-._~\x{0080}-\x{10FFFF}]+$/u', $host ) ) {
			return '';
		}

		// A host of nothing but punctuation is not a host.
		if ( ! preg_match( '/[A-Za-z0-9\x{0080}-\x{10FFFF}]/u', $host ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Cleans a short description.
	 *
	 * A cell already containing HTML is kept as it is, after wp_kses_post().
	 * That is what makes an export-then-import round trip neutral on
	 * descriptions written in HTML. A cell of plain text keeps its newlines,
	 * which WooCommerce renders through wpautop() as line breaks.
	 *
	 * @param string $raw Cell.
	 * @return string
	 */
	public static function excerpt( $raw ) {

		if ( self::looks_like_html( $raw ) ) {
			return trim( wp_kses_post( $raw ) );
		}

		return self::text( $raw, true );
	}

	/**
	 * Does this cell contain markup?
	 *
	 * @param string $raw Cell.
	 * @return bool
	 */
	private static function looks_like_html( $raw ) {
		return (bool) preg_match( '#<\s*/?\s*[a-z!]#i', (string) $raw );
	}

	/**
	 * Are two values equivalent, ignoring line endings and edge whitespace?
	 *
	 * The admin's own forms save CRLF. Without this, every field somebody
	 * edited in the admin would come out of the comparison as changed.
	 *
	 * @param string $a One value.
	 * @param string $b The other.
	 * @return bool
	 */
	public static function same( $a, $b ) {

		$normalise = static function ( $value ) {
			return trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $value ) );
		};

		return $normalise( $a ) === $normalise( $b );
	}

	/**
	 * Does this cell leave the field unchanged?
	 *
	 * When it does, NOTHING is written and the site's value stays in the
	 * database byte for byte, including whatever the cleaning would have
	 * normalised. Two ways that happens:
	 *
	 *  1. the raw cell equals the site's value — an export-then-import round
	 *     trip with no edit;
	 *  2. cleaning the SITE's value gives exactly the cleaned cell, so the
	 *     difference is only what cleaning normalises: a `style="…;"`, an
	 *     `<hr/>`, a disallowed attribute. Writing would replace the original
	 *     content with its laundered version and gain nothing — which is
	 *     precisely the loss worth avoiding.
	 *
	 * A genuinely different value fails both tests and is written cleaned.
	 *
	 * @param string $current Site value.
	 * @param string $raw     Raw cell.
	 * @param string $clean   Cleaned cell.
	 * @param string $nature  Field nature.
	 * @return bool
	 */
	public static function unchanged( $current, $raw, $clean, $nature ) {

		if ( self::same( $current, $raw ) || self::same( $current, $clean ) ) {
			return true;
		}

		$current_clean = self::clean(
			trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $current ) ),
			$nature
		);

		return self::same( $current_clean, $clean );
	}

	/**
	 * What the cleaning takes out of a cell that is about to be written.
	 *
	 * Reported before anything is written, so somebody can see that the file
	 * says one thing and the database will hold another.
	 *
	 * @param string $raw    Raw cell.
	 * @param string $clean  Cleaned cell.
	 * @param string $nature Field nature.
	 * @return string Empty when cleaning changes nothing.
	 */
	public static function losses( $raw, $clean, $nature ) {

		if ( ! in_array( $nature, array( 'html', 'excerpt' ), true ) || ! self::looks_like_html( $raw ) ) {
			return '';
		}

		$raw = trim( $raw );

		if ( $raw === $clean ) {
			return '';
		}

		$attributes = static function ( $html ) {
			preg_match_all( '#<[a-z][a-z0-9]*\b[^>]*>#i', $html, $tags );
			preg_match_all( '#\s([a-z][a-z0-9_:.-]*)\s*=#i', implode( ' ', $tags[0] ), $names );
			return array_count_values( array_map( 'strtolower', $names[1] ) );
		};

		$before = $attributes( $raw );
		$after  = $attributes( $clean );
		$parts  = array();

		foreach ( $before as $name => $count ) {

			$gone = $count - ( isset( $after[ $name ] ) ? $after[ $name ] : 0 );

			if ( $gone > 0 ) {
				$parts[] = sprintf(
					/* translators: 1: HTML attribute name, 2: how many were removed. */
					__( 'attribute %1$s removed x%2$d', 'dahu-product-import' ),
					$name,
					$gone
				);
			}
		}

		if ( strlen( wp_strip_all_tags( $raw ) ) !== strlen( wp_strip_all_tags( $clean ) ) ) {
			$parts[] = __( 'text changed', 'dahu-product-import' );
		}

		return $parts ? implode( ', ', $parts ) : __( 'HTML normalised', 'dahu-product-import' );
	}

	/**
	 * A one-line, truncated preview of a value, for the report.
	 *
	 * Invisible characters are made visible. Without that the preview shows
	 * two words run together where the file holds a zero-width space, or an
	 * ordinary space where it holds a non-breaking one — and somebody spends
	 * an afternoon on a difference the report declined to show them. This is
	 * display only; the value written to the database is untouched.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function preview( $value ) {

		$value = (string) preg_replace( '/\s*\R\s*/u', ' ⏎ ', (string) $value );

		$value = strtr(
			$value,
			array(
				"\xC2\xA0"     => '␣', // Non-breaking space.
				"\xE2\x80\xAF" => '␣', // Narrow non-breaking space.
				"\xE2\x80\x89" => '␣', // Thin space.
				"\xE2\x80\x8B" => '·', // Zero-width space.
				"\xE2\x80\x8C" => '·', // Zero-width non-joiner.
				"\xE2\x80\x8D" => '·', // Zero-width joiner.
				"\xEF\xBB\xBF" => '·', // A BOM in mid-text.
				"\xC2\xAD"     => '·', // Soft hyphen.
				"\t"           => '→',
			)
		);

		$value = trim( $value );
		$limit = Dahu_Product_Import_Fields::PREVIEW;

		return mb_strlen( $value ) > $limit ? mb_substr( $value, 0, $limit ) . '…' : $value;
	}

	/**
	 * A readable window around one byte: escaped text, then hexadecimal.
	 *
	 * @param string $value  Value.
	 * @param int    $offset Byte at the centre.
	 * @return string
	 */
	public static function around( $value, $offset ) {

		$start = max( 0, $offset - 20 );
		$slice = substr( (string) $value, $start, 40 );
		$text  = addcslashes( $slice, "\0..\37\177..\377" );

		return '« ' . $text . ' »  hex ' . trim( chunk_split( bin2hex( $slice ), 2, ' ' ) );
	}
}
