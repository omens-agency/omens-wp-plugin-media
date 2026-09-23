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
 * @return array{filled: string[], skipped: string[], text: string} What changed, or would have.
 */
function omens_media_fill( $attachment_id, $dry_run = false ) {
	$result = array(
		'filled'  => array(),
		'skipped' => array(),
		'text'    => '',
	);

	$attachment = get_post( $attachment_id );

	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		$result['skipped'][] = 'not-an-attachment';
		return $result;
	}

	$settings = omens_media_settings();

	if ( $settings['images_only'] && ! wp_attachment_is_image( $attachment_id ) ) {
		$result['skipped'][] = 'not-an-image';
		return $result;
	}

	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$file = $file ? $file : $attachment->post_title;
	$text = omens_media_humanize( $file, $settings['case'] );

	if ( '' === $text ) {
		// A camera default or a hash. Reported, never guessed at.
		$result['skipped'][] = 'meaningless-filename';
		return $result;
	}

	$result['text'] = $text;

	// Alt text lives in meta, not the post row.
	if ( $settings['alt'] ) {
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( '' === trim( (string) $existing ) ) {
			if ( ! $dry_run ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $text );
			}
			$result['filled'][] = 'alt';
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

		// A title equal to the raw filename is WordPress's placeholder, not an edit.
		$current     = (string) $attachment->$column;
		$is_untouched = '' === trim( $current )
			|| ( 'title' === $field && $current === pathinfo( (string) $file, PATHINFO_FILENAME ) );

		if ( $is_untouched ) {
			$update[ $column ]  = $text;
			$result['filled'][] = $field;
		} else {
			$result['skipped'][] = $field . '-already-set';
		}
	}

	if ( $update && ! $dry_run ) {
		$update['ID'] = $attachment_id;

		// Unhook first: wp_update_post fires save_post, which would re-enter this.
		remove_action( 'add_attachment', 'omens_media_on_upload' );
		wp_update_post( $update );
		add_action( 'add_attachment', 'omens_media_on_upload' );
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

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
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
