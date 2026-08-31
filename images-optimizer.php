<?php
/**
 * Plugin Name: Images Optimizer
 * Description: Converts JPG/PNG uploads to WebP before WordPress builds its size variants, keeps the original file untouched (for feed export), and reports conversion/offload status. Offload to R2 and URL delivery stay with Advanced Media Offloader - this plugin does not touch either.
 * Version: 1.0.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'IMGOPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Self-update from the private GitHub repo (killuakiller/images-optimizer-plugin).
 *
 * IMGOPT_GH_TOKEN is a read-only, repo-scoped GitHub fine-grained token,
 * defined in wp-config.php. Without it, this site just won't see update
 * notices (repo is private) but nothing else breaks.
 */
if ( is_admin() ) {
	require_once IMGOPT_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

	$imgoptUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/killuakiller/images-optimizer-plugin/',
		__FILE__,
		'images-optimizer-plugin'
	);
	$imgoptUpdateChecker->setBranch( 'main' );

	if ( defined( 'IMGOPT_GH_TOKEN' ) && IMGOPT_GH_TOKEN ) {
		$imgoptUpdateChecker->setAuthentication( IMGOPT_GH_TOKEN );
	}
}

require_once IMGOPT_PLUGIN_DIR . 'includes/formats.php';
require_once IMGOPT_PLUGIN_DIR . 'includes/scaled-fixup.php';
require_once IMGOPT_PLUGIN_DIR . 'includes/dashboard.php';

add_action( 'plugins_loaded', 'imgopt_boot' );

function imgopt_boot() {
	imgopt_register_format_filters();
	imgopt_register_scaled_fixup();
	imgopt_register_dashboard();
}
