<?php
/**
 * WP-CLI commands.
 *
 * Dry run is the default everywhere, matching the house habit set by
 * accessibe-batch: show the change, then ask for it.
 *
 * @package OmensMedia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fill media metadata from filenames.
 */
class Omens_Media_CLI {

	/**
	 * Fill empty metadata on existing attachments.
	 *
	 * Reports by default and writes nothing. Pass --apply to write.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the changes. Without it, this only reports what would change.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated: alt, title, caption, description. Defaults to the
	 *   site's omens_media/fields setting (alt and title).
	 *
	 * [--case=<case>]
	 * : title, sentence, lower or none. Default: title.
	 *
	 * [--post_type=<type>]
	 * : Limit to attachments of a MIME type, e.g. image, image/jpeg.
	 *
	 * [--limit=<n>]
	 * : Stop after n attachments. Useful for a first look at a large library.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what would change.
	 *     wp omens-media backfill
	 *
	 *     # Fill alt text only, for images, for real.
	 *     wp omens-media backfill --fields=alt --post_type=image --apply
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function backfill( $args, $assoc_args ) {
		$apply     = isset( $assoc_args['apply'] );
		$limit     = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : -1;
		$mime      = isset( $assoc_args['post_type'] ) ? $assoc_args['post_type'] : '';
		$case      = isset( $assoc_args['case'] ) ? $assoc_args['case'] : '';
		$fields    = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : array();
		$overrides = array();

		if ( $fields ) {
			$known   = array( 'alt', 'title', 'caption', 'description' );
			$unknown = array_diff( $fields, $known );
			if ( $unknown ) {
				// Silently turning every field off (what an unknown name used to do)
				// looked like a run that found nothing to fill.
				WP_CLI::error( sprintf( 'Unknown field(s): %s. Use any of: %s.', implode( ', ', $unknown ), implode( ', ', $known ) ) );
			}
			foreach ( $known as $field ) {
				$overrides[ $field ] = in_array( $field, $fields, true );
			}
		}

		if ( $case ) {
			$overrides['case'] = $case;
		}

		if ( $overrides ) {
			add_filter(
				'omens_media/fields',
				function ( $settings ) use ( $overrides ) {
					return array_merge( $settings, $overrides );
				}
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => $mime ? $mime : null,
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$ids = $query->posts;

		if ( ! $ids ) {
			WP_CLI::success( 'No attachments found.' );
			return;
		}

		$filled       = 0;
		$written      = 0;
		$failed       = array();
		$untouched    = 0;
		$meaningless  = array();
		$progress     = WP_CLI\Utils\make_progress_bar(
			$apply ? 'Filling metadata' : 'Checking metadata',
			count( $ids )
		);

		foreach ( $ids as $id ) {
			$result = omens_media_fill( $id, ! $apply );

			if ( in_array( 'meaningless-filename', $result['skipped'], true ) ) {
				$meaningless[] = $id;
			} elseif ( $result['filled'] ) {
				++$filled;

				if ( $apply ) {
					if ( $result['written'] ) {
						++$written;
					} else {
						$failed[] = $id;
					}
				} else {
					WP_CLI::log(
						sprintf(
							'  #%d  %s  ->  %s',
							$id,
							implode( ', ', $result['filled'] ),
							$result['text']
						)
					);
				}
			} else {
				++$untouched;
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Attachments scanned:   %d', count( $ids ) ) );
		WP_CLI::log( sprintf( 'Would fill / filled:   %d', $filled ) );
		WP_CLI::log( sprintf( 'Already had metadata:  %d', $untouched ) );
		WP_CLI::log( sprintf( 'Filename means nothing: %d', count( $meaningless ) ) );

		if ( $meaningless ) {
			WP_CLI::log( '' );
			WP_CLI::warning(
				sprintf(
					'%d files are camera defaults, screenshots or hashes (IMG_4523, DSC01234, a UUID). No alt text was invented for them: a made-up description is worse for a screen reader than none. These need a human, or renaming before re-import.',
					count( $meaningless )
				)
			);
			WP_CLI::log( 'IDs: ' . implode( ', ', array_slice( $meaningless, 0, 50 ) ) . ( count( $meaningless ) > 50 ? ' ...' : '' ) );
		}

		if ( ! $apply ) {
			WP_CLI::log( '' );
			WP_CLI::success( 'Dry run. Nothing was written. Re-run with --apply to make these changes.' );
			return;
		}

		if ( $failed ) {
			WP_CLI::warning( sprintf( '%d attachments could not be written: %s', count( $failed ), implode( ', ', array_slice( $failed, 0, 50 ) ) ) );
		}
		WP_CLI::success( sprintf( '%d attachments updated.', $written ) );
	}

	/**
	 * Show what a filename would become, without touching the library.
	 *
	 * ## OPTIONS
	 *
	 * <filename>...
	 * : One or more filenames to test.
	 *
	 * [--case=<case>]
	 * : title, sentence, lower or none. Default: title.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omens-media test hero_image-01.jpg IMG_4523.jpg about-the-studio-1024x768.png
	 *
	 * @param array $args       Filenames.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		$case = isset( $assoc_args['case'] ) ? $assoc_args['case'] : 'title';
		$rows = array();

		foreach ( $args as $filename ) {
			$text   = omens_media_humanize( $filename, $case );
			$rows[] = array(
				'filename' => $filename,
				'result'   => '' === $text ? '(skipped: no human meaning)' : $text,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'filename', 'result' ) );
	}
}

WP_CLI::add_command( 'omens-media', 'Omens_Media_CLI' );
