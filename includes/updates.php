<?php
/**
 * Self-updating from GitHub releases.
 *
 * Every site running this plugin sees new releases in Plugins > Updates, the
 * way a wordpress.org plugin does, through Plugin Update Checker (MIT, vendored
 * in vendor/). The repo is private, so the site needs a read-only GitHub token
 * in wp-config: define( 'OMENS_PLUGIN_UPDATE_TOKEN', '...' ). One token covers
 * every omens-wp-plugin-* repo. Without it the plugin still works; it just does
 * not know about new versions.
 *
 * Publishing: tools/release.sh <version> bumps, tags and uploads the zip.
 *
 * @package OmensMedia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			$loader = OMENS_MEDIA_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
			if ( ! file_exists( $loader ) ) {
				return;
			}
			require_once $loader;
		}
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/omens-agency/omens-wp-plugin-media/',
			OMENS_MEDIA_FILE,
			'omens-wp-plugin-media'
		);
		// The release asset (the zip release.sh uploads), not GitHub's source tarball,
		// so the folder inside is the plugin slug and vendor/ is included.
		$checker->getVcsApi()->enableReleaseAssets();
		if ( defined( 'OMENS_PLUGIN_UPDATE_TOKEN' ) && OMENS_PLUGIN_UPDATE_TOKEN ) {
			$checker->setAuthentication( OMENS_PLUGIN_UPDATE_TOKEN );
		}
	}
);
