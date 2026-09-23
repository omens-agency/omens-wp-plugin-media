<?php
/**
 * Filename formatting.
 *
 * Pure string work, no WordPress state. Kept separate from attachment.php so it
 * can be reasoned about and tested on its own.
 *
 * @package OmensMedia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filenames that carry no human meaning.
 *
 * Camera and phone defaults, screenshots, stock-library hashes, and the generic
 * names browsers and chat apps produce. A filename matching one of these must
 * never become alt text: "Img 4523" read aloud by a screen reader is worse than
 * silence, because it sounds like content and is not. WCAG 2.2 AA is a launch
 * gate here (CONVENTIONS.md), so these are reported for a human instead.
 *
 * @return string[] Regular expressions, matched case-insensitively against the
 *                  extension-stripped filename.
 */
function omens_media_meaningless_patterns() {
	$patterns = array(
		// Most cameras, plus Canon/Nikon Adobe RGB's leading underscore (_MG_1234).
		// The trailing group catches WhatsApp and timestamped variants
		// (IMG-20240101-WA0001), which a digits-anchored pattern lets through.
		'/^_?(img|dsc|dscn|dscf|mg|pict|mov|vid)[-_]?\d+([-_].*)?$/i',
		'/^_?[a-z0-9]{2,4}\d{4,}$/i',                      // Body-prefixed bursts: R5A1234, 5D4A1234, P1010101.
		'/^pxl_\d{8}_\d+$/i',                              // Google Pixel.
		'/^\d{8}[-_]\d{6}/',                               // Android and Samsung timestamps.
		'/^(screen[- _]?shot|screenshot|screen[- _]?recording)/i',
		'/^(pasted|paste)[- _]?(image|graphic)?/i',
		'/^(unnamed|untitled|image|photo|picture|download|file|copy|final|new|temp|tmp|asset)[-_ ]?\d*$/i',
		'/^photo-\d{10,}/i',                               // Unsplash.
		'/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}/i',       // UUIDs and hashes.
		'/^[a-f0-9]{16,}$/i',
		'/^\d+$/',                                         // Pure numbers.
		'/^[a-z]$/i',                                      // Single letters.
	);

	/**
	 * Filter the meaningless-filename patterns.
	 *
	 * @param string[] $patterns Regular expressions.
	 */
	return apply_filters( 'omens_media/meaningless_patterns', $patterns );
}

/**
 * Does this filename carry any human meaning?
 *
 * @param string $filename Filename, with or without extension.
 * @return bool True when the name is a camera default, hash, or generic label.
 */
function omens_media_is_meaningless( $filename ) {
	$stem = omens_media_stem( $filename );

	if ( '' === $stem ) {
		return true;
	}

	foreach ( omens_media_meaningless_patterns() as $pattern ) {
		if ( preg_match( $pattern, $stem ) ) {
			return true;
		}
	}

	// A name with no letters at all cannot describe anything.
	if ( ! preg_match( '/[a-z]/i', $stem ) ) {
		return true;
	}

	return false;
}

/**
 * Reduce a filename to its stem: no path, no extension, no machine suffixes.
 *
 * Strips the suffixes WordPress and editing tools append, which describe the
 * file rather than the picture: size crops (-1024x768), -scaled, -rotated,
 * -edited, and retina @2x.
 *
 * Deliberately does NOT strip a trailing -1 / -2 counter. Core appends those on
 * a filename collision, but photographers number their frames the same way, and
 * the two are indistinguishable from the string alone. Tested against the
 * Westbourne library: stripping turns hero-2.jpg into "Hero" and morton-95.jpg
 * into "Morton". Leaving a deduped logo-1.png as "Logo 1" is the cheaper error.
 *
 * @param string $filename Filename or path.
 * @return string Stem, still in its original separator form.
 */
function omens_media_stem( $filename ) {
	$stem = pathinfo( (string) $filename, PATHINFO_FILENAME );
	$stem = preg_replace( '/-\d{2,5}x\d{2,5}$/', '', $stem );
	$stem = preg_replace( '/[-_](scaled|rotated|edited|copy)$/i', '', $stem );
	$stem = preg_replace( '/@[2-4]x$/i', '', $stem );

	return trim( (string) $stem );
}

/**
 * Words kept lowercase inside a title, and words forced to a fixed casing.
 *
 * The acronym map is where a project teaches the formatter its own vocabulary,
 * and it is meant to be added to per site:
 *
 *     add_filter( 'omens_media/vocabulary', function ( $v ) {
 *         $v['acronyms']['rsf'] = 'RSF';   // Rancho Santa Fe
 *         return $v;
 *     } );
 *
 * The defaults are conservative on purpose. Only unambiguous acronyms belong
 * here: two-letter entries in particular collide with ordinary words. "la" was
 * tried and removed, because it turned the Westbourne library's La Jolla shoot
 * into "LA Jolla". A wrong expansion is worse than a missing one.
 *
 * @return array{small: string[], acronyms: array<string, string>}
 */
function omens_media_vocabulary() {
	$vocabulary = array(
		'small'    => array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'nor', 'of', 'on', 'or', 'the', 'to', 'up', 'via', 'with' ),
		'acronyms' => array(
			'adu' => 'ADU',
			'cta' => 'CTA',
			'diy' => 'DIY',
			'faq' => 'FAQ',
			'nyc' => 'NYC',
			'pdf' => 'PDF',
			'usa' => 'USA',
		),
	);

	/**
	 * Filter the small-word list and acronym map.
	 *
	 * @param array $vocabulary Keys: small, acronyms.
	 */
	return apply_filters( 'omens_media/vocabulary', $vocabulary );
}

/**
 * Turn a filename into a human-readable phrase.
 *
 * hero_image-01.jpg              -> Hero Image 01
 * about-the-studio-1024x768.png  -> About the Studio
 * heroImageDark.png              -> Hero Image Dark
 * a-day-in-the-life.jpg          -> A Day in the Life
 * kitchen_remodel@2x.jpg         -> Kitchen Remodel
 * IMG_4523.jpg                   -> ''  (see omens_media_is_meaningless)
 *
 * @param string $filename Filename or path.
 * @param string $case     title|sentence|lower|none. Default title.
 * @return string Readable phrase, or an empty string when the name means nothing.
 */
function omens_media_humanize( $filename, $case = 'title' ) {
	if ( omens_media_is_meaningless( $filename ) ) {
		return '';
	}

	$text = omens_media_stem( $filename );

	// Percent-encoding first, so %20 becomes a separator and not literal text.
	$text = rawurldecode( $text );

	// camelCase and PascalCase to spaced words, before separators are collapsed.
	$text = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', $text );

	// Every common separator becomes a space.
	$text = preg_replace( '/[-_.+]+/', ' ', $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	$text = trim( (string) $text );

	if ( '' === $text ) {
		return '';
	}

	$vocabulary = omens_media_vocabulary();
	$words      = explode( ' ', $text );

	foreach ( $words as $i => $word ) {
		$lower = strtolower( $word );

		if ( isset( $vocabulary['acronyms'][ $lower ] ) ) {
			$words[ $i ] = $vocabulary['acronyms'][ $lower ];
			continue;
		}

		switch ( $case ) {
			case 'none':
				break;

			case 'lower':
				$words[ $i ] = $lower;
				break;

			case 'sentence':
				$words[ $i ] = 0 === $i ? omens_media_ucfirst( $lower ) : $lower;
				break;

			case 'title':
			default:
				$is_small    = in_array( $lower, $vocabulary['small'], true );
				$words[ $i ] = ( $is_small && 0 !== $i && count( $words ) - 1 !== $i )
					? $lower
					: omens_media_ucfirst( $lower );
				break;
		}
	}

	$text = implode( ' ', $words );

	/**
	 * Filter the finished phrase.
	 *
	 * @param string $text     Formatted phrase.
	 * @param string $filename Original filename.
	 * @param string $case     Casing mode applied.
	 */
	return apply_filters( 'omens_media/humanize', $text, $filename, $case );
}

/**
 * Multibyte-safe ucfirst.
 *
 * @param string $text Text to capitalize.
 * @return string
 */
function omens_media_ucfirst( $text ) {
	if ( '' === $text ) {
		return '';
	}

	if ( function_exists( 'mb_strtoupper' ) ) {
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	return ucfirst( $text );
}
