<?php
/**
 * Plugin Name:       Omens Alt Text
 * Plugin URI:        https://github.com/omens-agency/omens-wp-plugin-media
 * Update URI:        https://github.com/omens-agency/omens-wp-plugin-media
 * Description:       Turns descriptive filenames into alt text and titles as you upload, and flags the images it can't describe. It never overwrites what you've written.
 * Version:           1.0.5
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Omens Agency
 * Author URI:        https://omens.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omens-media
 *
 * @package OmensMedia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OMENS_MEDIA_VERSION', '1.0.5' );
define( 'OMENS_MEDIA_FILE', __FILE__ );
define( 'OMENS_MEDIA_DIR', plugin_dir_path( __FILE__ ) );

require_once OMENS_MEDIA_DIR . 'includes/format.php';
require_once OMENS_MEDIA_DIR . 'includes/attachment.php';
require_once OMENS_MEDIA_DIR . 'includes/updates.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OMENS_MEDIA_DIR . 'includes/cli.php';
}
