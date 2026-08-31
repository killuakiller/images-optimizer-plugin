<?php
/**
 * Core-native single-track WebP pipeline: resize threshold, output format, quality.
 *
 * Every filter here is a WordPress core hook (image_editor_output_format,
 * wp_editor_set_quality, big_image_size_threshold) - no custom resize/encode
 * code. wp_create_image_subsizes() re-applies image_editor_output_format on
 * every single file it saves (full size and each registered sub-size), so
 * registering the mime map once here converts all of them.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Source mime types this plugin converts to WebP.
 *
 * GIF is deliberately excluded - converting an animated GIF would collapse
 * it to a single frame.
 *
 * @return array<string, string> Source mime type => target mime type.
 */
function imgopt_convertible_mime_map() {
	return array(
		'image/jpeg' => 'image/webp',
		'image/png'  => 'image/webp',
	);
}

function imgopt_register_format_filters() {
	add_filter( 'big_image_size_threshold', 'imgopt_big_image_threshold' );
	add_filter( 'image_editor_output_format', 'imgopt_output_format_map' );
	add_filter( 'wp_editor_set_quality', 'imgopt_webp_quality', 10, 2 );
}

function imgopt_big_image_threshold( $threshold ) {
	return 1500;
}

/**
 * @param array<string, string> $formats Existing mime map (core defaults to HEIC/HEIF -> JPEG).
 * @return array<string, string>
 */
function imgopt_output_format_map( $formats ) {
	if ( ! is_array( $formats ) ) {
		$formats = array();
	}
	return array_merge( $formats, imgopt_convertible_mime_map() );
}

function imgopt_webp_quality( $quality, $mime_type ) {
	if ( 'image/webp' === $mime_type ) {
		return 80;
	}
	return $quality;
}
