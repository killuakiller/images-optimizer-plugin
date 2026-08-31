<?php
/**
 * Full-size filename normalization.
 *
 * Correction: an earlier version of this file assumed WordPress core skips
 * WebP conversion for the "full" size whenever big_image_size_threshold
 * scales an upload down (since wp-admin/includes/image.php's top-level
 * $scale_down/$convert flags are mutually exclusive). Live testing proved
 * that assumption wrong: WP_Image_Editor::save() always re-derives the
 * output format via get_output_format() -> image_editor_output_format on
 * every single file it writes (confirmed in class-wp-image-editor.php:344-407
 * and class-wp-image-editor-gd.php:524), independent of which top-level
 * branch called it. Core's own "-scaled" save already comes out as WebP with
 * no help needed here.
 *
 * The real, remaining problem is naming, not conversion: core builds the
 * scaled/rotated full-size filename by appending "-scaled" or "-rotated" to
 * the original basename BEFORE substituting the extension, so the result is
 * "photo-scaled.webp", not "photo.webp" - while metadata['original_image']
 * (the true original, untouched) stays "photo.jpg". A feed-export bulk
 * ".webp" -> ".jpg" replace on such a file's URL would look for
 * "photo-scaled.jpg", which does not exist.
 *
 * This file renames the full-size file (no re-encode - core already wrote
 * the right bytes and format) to drop that suffix, so the full size is
 * always named after metadata['original_image']'s own basename.
 */

defined( 'ABSPATH' ) || exit;

function imgopt_register_scaled_fixup() {
	// Priority 20: wp_create_image_subsizes() has already fully run by the
	// time any wp_generate_attachment_metadata filter fires. Running before
	// Advanced Media Offloader's own hook (priority 99) ensures ADVMO
	// offloads the final, correctly-named file.
	add_filter( 'wp_generate_attachment_metadata', 'imgopt_normalize_full_size_filename', 20, 2 );
}

/**
 * @param array<string, mixed> $metadata
 * @param int                  $attachment_id
 * @return array<string, mixed>
 */
function imgopt_normalize_full_size_filename( $metadata, $attachment_id ) {
	if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
		return $metadata;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return $metadata;
	}

	$filetype  = wp_check_filetype( $file );
	$mime_type = $filetype['type'];

	if ( empty( $mime_type ) ) {
		return $metadata;
	}

	// Still one of our source mimes at this point: something upstream
	// (missing image editor, unsupported mime for this server) prevented
	// conversion. Leave it as-is, don't mark it converted.
	if ( isset( imgopt_convertible_mime_map()[ $mime_type ] ) ) {
		return $metadata;
	}

	// Not WebP and not one of our source mimes (GIF, SVG, ...): not
	// applicable to this plugin at all, leave alone, don't mark converted.
	if ( 'image/webp' !== $mime_type ) {
		return $metadata;
	}

	imgopt_sync_post_mime_type( $attachment_id, 'image/webp' );

	// No original_image means core never renamed the full size away from the
	// raw upload's own name (no scale, no rotation, no conversion needed) -
	// current filename is already correct as-is.
	if ( empty( $metadata['original_image'] ) ) {
		update_post_meta( $attachment_id, '_imgopt_converted', time() );
		return $metadata;
	}

	$dir                   = pathinfo( $file, PATHINFO_DIRNAME );
	$current_name_no_ext   = pathinfo( $file, PATHINFO_FILENAME );
	$original_name_no_ext  = pathinfo( $metadata['original_image'], PATHINFO_FILENAME );

	// Core's own non-scaled convert path already names the result after the
	// original with no suffix - nothing to rename.
	if ( $current_name_no_ext === $original_name_no_ext ) {
		update_post_meta( $attachment_id, '_imgopt_converted', time() );
		return $metadata;
	}

	$current_ext  = pathinfo( $file, PATHINFO_EXTENSION );
	$desired_name = $original_name_no_ext . '.' . $current_ext;

	// Collision guard: an unrelated attachment could genuinely already own
	// "<original-basename>.<ext>" in this directory. Never overwrite it
	// silently. Deliberately NOT wp_unique_filename() here - live-tested and
	// confirmed it also treats this attachment's OWN already-generated
	// sub-size files (e.g. "large-300x200.webp", sitting in this exact
	// directory by the time this hook runs) as a reason to bump the name,
	// since its dimension-pattern collision check (for issue #42437) can't
	// tell "this attachment's own sibling" from "a genuinely different
	// attachment" - a false positive on every single scaled/rotated upload.
	// A plain exact-path existence check has no such false positive and
	// still catches every real collision, since it checks the literal target
	// path, not a pattern.
	$safe_name = imgopt_unique_filename( $dir, $desired_name );
	$target    = trailingslashit( $dir ) . $safe_name;

	if ( ! @rename( $file, $target ) ) {
		error_log( sprintf( 'Images Optimizer: could not rename %s to %s for attachment %d', $file, $target, $attachment_id ) );
		return $metadata;
	}

	update_attached_file( $attachment_id, $target );
	$metadata['file'] = _wp_relative_upload_path( $target );

	update_post_meta( $attachment_id, '_imgopt_converted', time() );

	return $metadata;
}

/**
 * Exact-path collision guard.
 *
 * Deliberately simpler than wp_unique_filename(): only checks whether the
 * literal candidate path already exists, incrementing "-1", "-2"... until it
 * doesn't. No dimension-pattern heuristics - see the call site for why those
 * produce false positives for this specific rename.
 *
 * @param string $dir      Absolute directory path (no trailing slash required).
 * @param string $filename Desired filename, e.g. "photo.webp".
 * @return string A filename guaranteed not to exist in $dir yet.
 */
function imgopt_unique_filename( $dir, $filename ) {
	$dir  = trailingslashit( $dir );
	$ext  = pathinfo( $filename, PATHINFO_EXTENSION );
	$name = pathinfo( $filename, PATHINFO_FILENAME );

	$candidate = $filename;
	$number    = 0;
	while ( file_exists( $dir . $candidate ) ) {
		++$number;
		$candidate = $ext ? "{$name}-{$number}.{$ext}" : "{$name}-{$number}";
	}

	return $candidate;
}

/**
 * WordPress does not update post_mime_type when an attachment's underlying
 * file changes format after upload - confirmed live: core's own
 * image_editor_output_format convert path leaves wp_posts.post_mime_type at
 * the original value (image/jpeg or image/png) even after the real file on
 * disk is WebP. Left unsynced, the Media Library's type filter/icon and
 * anything else reading post_mime_type (not get_attached_file()) would
 * misreport the file's real format.
 *
 * @param int    $attachment_id
 * @param string $mime_type
 */
function imgopt_sync_post_mime_type( $attachment_id, $mime_type ) {
	if ( get_post_mime_type( $attachment_id ) === $mime_type ) {
		return;
	}

	wp_update_post(
		array(
			'ID'             => $attachment_id,
			'post_mime_type' => $mime_type,
		)
	);
}
