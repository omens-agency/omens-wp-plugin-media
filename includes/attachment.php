<?php
/**
 * Attachment metadata.
 *
 * Fills alt text and title on upload; caption and description only when a
 * project switches them on. Nothing here ever overwrites a value a human wrote.
 *
 * @package OmensMedia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which fields get filled, and how.
 *
 * Defaults are the house baseline: alt and title on, caption and description
 * off. A project turns the others on in its theme:
 *
 *     add_filter( 'omens_media/fields', function ( $fields ) {
 *         $fields['caption'] = true;
 *         return $fields;
 *     } );
 *
 * @return array{alt: bool, title: bool, caption: bool, description: bool, case: string, images_only: bool}
 */
function omens_media_settings() {
	$settings = array(
		'alt'         => true,
		'title'       => true,
		'caption'     => false,
		'description' => false,
		'case'        => 'title',
		'images_only' => false,
	);

	/**
	 * Filter which metadata fields are filled on upload.
	 *
	 * @param array $settings Field switches, casing mode, and the images-only flag.
	 */
	return apply_filters( 'omens_media/fields', $settings );
}

/**
 * Fill an attachment's metadata from its filename.
 *
 * Safe to call more than once: every field is written only when it is currently
 * empty, so a re-run tops up what is missing and leaves everything else alone.
 *
 * @param int  $attachment_id Attachment post ID.
 * @param bool $dry_run       When true, work out the changes and write nothing.
 * @return array{filled: string[], written: string[], skipped: string[], text: string}
 *         filled: fields that are empty or placeholders and get the text (on a dry
 *         run, would get it); written: the subset that was actually saved this call
 *         (always empty on a dry run); skipped: why the others were left alone.
 */
function omens_media_fill( $attachment_id, $dry_run = false ) {
	$result = array(
		'filled'  => array(),
		'written' => array(),
		'skipped' => array(),
		'text'    => '',
	);

	$attachment = get_post( $attachment_id );

	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		$result['skipped'][] = 'not-an-attachment';
		return $result;
	}

	$settings = omens_media_settings();

	if ( $settings['images_only'] && ! omens_media_takes_alt( $attachment_id ) ) {
		$result['skipped'][] = 'not-an-image';
		return $result;
	}

	// The filename is the only source. An attachment with no file on record
	// (offloaded media, a broken import) is skipped: feeding its title back in
	// as if it were a filename made a human-written title look like a
	// placeholder and re-cased it, which is the one thing this plugin must
	// never do. The GUID is tried first because core sets it to the file URL.
	$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( '' === $file ) {
		$guid = wp_basename( (string) $attachment->guid );
		$file = ( '' !== $guid && false !== strpos( $guid, '.' ) ) ? $guid : '';
	}
	if ( '' === $file ) {
		$result['skipped'][] = 'no-file';
		return $result;
	}

	// omens_media_stem() drops core's -scaled and -WxH suffixes before humanizing.
	$text = omens_media_humanize( wp_basename( $file ), $settings['case'] );

	if ( '' === $text ) {
		// A camera default or a hash. Reported, never guessed at.
		$result['skipped'][] = 'meaningless-filename';
		return $result;
	}

	// Core sanitizes alt and title the same way on every write of its own;
	// a filename set by an importer or offload plugin bypasses the upload
	// sanitizer, so do it here before the text reaches the database.
	$text = sanitize_text_field( $text );
	if ( '' === $text ) {
		$result['skipped'][] = 'meaningless-filename';
		return $result;
	}

	$result['text'] = $text;

	// Alt text lives in meta, not the post row, and only images (SVG included)
	// take it; writing it to a PDF or a video would just inflate the count.
	if ( $settings['alt'] && omens_media_takes_alt( $attachment_id ) ) {
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( '' === trim( (string) $existing ) ) {
			$result['filled'][] = 'alt';
			if ( ! $dry_run && false !== update_post_meta( $attachment_id, '_wp_attachment_image_alt', $text ) ) {
				$result['written'][] = 'alt';
			}
		} else {
			$result['skipped'][] = 'alt-already-set';
		}
	}

	// Title, caption (post_excerpt) and description (post_content) are post columns.
	$columns = array(
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	);
	$update  = array();

	foreach ( $columns as $field => $column ) {
		if ( ! $settings[ $field ] ) {
			continue;
		}

		$current = (string) $attachment->$column;

		// Already what we would write (a one-word filename whose only capital
		// is the first letter, an acronym, a site on case => none). Not a
		// fill: counting it as one made the backfill report the same
		// attachments as "would fill" on every run, with nothing to write.
		if ( $current === $text ) {
			$result['skipped'][] = $field . '-already-set';
			continue;
		}

		$is_untouched = '' === trim( $current )
			|| ( 'title' === $field && omens_media_is_placeholder_title( $current, $file ) );

		if ( $is_untouched ) {
			$update[ $column ]  = $text;
			$result['filled'][] = $field;
		} else {
			$result['skipped'][] = $field . '-already-set';
		}
	}

	if ( $update && ! $dry_run ) {
		$columns_written = array_keys( $update );
		$update['ID']    = $attachment_id;

		// wp_update_post on an attachment fires edit_attachment, not
		// add_attachment, so there is no re-entry to guard against.
		$saved = wp_update_post( $update, true );
		if ( $saved && ! is_wp_error( $saved ) ) {
			foreach ( $columns as $field => $column ) {
				if ( in_array( $column, $columns_written, true ) ) {
					$result['written'][] = $field;
				}
			}
		} else {
			$result['skipped'][] = 'write-failed';
		}
	}

	/**
	 * Fires after an attachment's metadata has been filled.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Fields filled and skipped, and the text used.
	 * @param bool  $dry_run       Whether anything was actually written.
	 */
	do_action( 'omens_media/filled', $attachment_id, $result, $dry_run );

	return $result;
}

/**
 * Whether an attachment is the kind that carries alt text.
 *
 * wp_attachment_is_image() knows jpg, gif, png, webp, avif and heic. SVG
 * logos, the images most likely to need alt text, are not on that list, so
 * they are added here. Used for the alt write, the images_only switch and
 * the media-library column, so all three agree.
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool
 */
function omens_media_takes_alt( $attachment_id ) {
	return wp_attachment_is_image( $attachment_id ) || 'image/svg+xml' === get_post_mime_type( $attachment_id );
}

/**
 * Whether a title is WordPress's own placeholder for the file, not an edit.
 *
 * Core sets post_title to the filename stem at upload, then may rename the
 * file: to <stem>-scaled for a large image, to <stem>-1 on a name collision.
 * So the placeholder matches the current filename, or the filename with
 * those suffixes removed, exactly or after both are slugified (the admin
 * uploader turns spaces into dashes in the file but not in the title).
 *
 * @param string $title Current post_title.
 * @param string $file  _wp_attached_file (any path; only the basename is used).
 * @return bool
 */
function omens_media_is_placeholder_title( $title, $file ) {
	$stem = pathinfo( wp_basename( (string) $file ), PATHINFO_FILENAME );
	$base = preg_replace( '/-scaled$/', '', $stem );
	$base = preg_replace( '/-\d+$/', '', (string) $base );

	foreach ( array_unique( array( $stem, (string) $base ) ) as $candidate ) {
		if ( '' === $candidate ) {
			continue;
		}
		if ( $title === $candidate || sanitize_title( $title ) === sanitize_title( $candidate ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Fill metadata as each file is uploaded.
 *
 * @param int $attachment_id Attachment post ID.
 * @return void
 */
function omens_media_on_upload( $attachment_id ) {
	omens_media_fill( $attachment_id );
}
add_action( 'add_attachment', 'omens_media_on_upload' );

/**
 * Flag images with no alt text in the media library list.
 *
 * The point of the plugin is that most images no longer need a human. This
 * column is how the ones that still do become visible, instead of shipping
 * silently. Turn it off with:
 * add_filter( 'omens_media/alt_column', '__return_false' ).
 *
 * @param string[] $columns Existing columns.
 * @return string[]
 */
function omens_media_alt_column( $columns ) {
	if ( ! apply_filters( 'omens_media/alt_column', true ) ) {
		return $columns;
	}

	$columns['omens_alt'] = __( 'Alt text', 'omens-media' );

	return $columns;
}
add_filter( 'manage_media_columns', 'omens_media_alt_column' );

/**
 * Render the alt-text column.
 *
 * @param string $column        Column key.
 * @param int    $attachment_id Attachment post ID.
 * @return void
 */
function omens_media_alt_column_content( $column, $attachment_id ) {
	if ( 'omens_alt' !== $column ) {
		return;
	}

	if ( ! omens_media_takes_alt( $attachment_id ) ) {
		echo '<span aria-hidden="true">&mdash;</span>';
		return;
	}

	$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

	if ( '' !== $alt ) {
		echo esc_html( $alt );
		return;
	}

	printf(
		'<strong style="color:#b32d2e;">%s</strong>',
		esc_html__( 'Needs alt text', 'omens-media' )
	);
}
add_action( 'manage_media_custom_column', 'omens_media_alt_column_content', 10, 2 );
