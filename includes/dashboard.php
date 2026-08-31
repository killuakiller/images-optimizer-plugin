<?php
/**
 * Read-only status page.
 *
 * Reports conversion status from this plugin's own _imgopt_converted marker,
 * and offload status by reading Advanced Media Offloader's own postmeta
 * (advmo_offloaded / advmo_path) directly - this plugin never tracks or
 * decides offload state itself, only displays what ADVMO already recorded.
 */

defined( 'ABSPATH' ) || exit;

function imgopt_register_dashboard() {
	add_action( 'admin_menu', 'imgopt_add_admin_page' );
}

function imgopt_add_admin_page() {
	add_media_page(
		__( 'Images Optimizer', 'images-optimizer' ),
		__( 'Images Optimizer', 'images-optimizer' ),
		'manage_options',
		'images-optimizer',
		'imgopt_render_admin_page'
	);
}

/**
 * @return array{total:int, converted:int, not_convertible:int, not_converted:int, offloaded:int, not_offloaded:int}
 */
function imgopt_gather_stats() {
	global $wpdb;

	$total = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
	);

	$converted = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_imgopt_converted'
		 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
	);

	$not_convertible = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_type = 'attachment'
		 AND post_mime_type IN ('image/gif', 'image/svg+xml')"
	);

	$offloaded = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'advmo_offloaded'
		 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND pm.meta_value = '1'"
	);

	return array(
		'total'            => $total,
		'converted'        => $converted,
		'not_convertible'  => $not_convertible,
		'not_converted'    => max( 0, $total - $converted - $not_convertible ),
		'offloaded'        => $offloaded,
		'not_offloaded'    => max( 0, $total - $offloaded ),
	);
}

function imgopt_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stats            = imgopt_gather_stats();
	$advmo_active     = function_exists( 'is_plugin_active' )
		? is_plugin_active( 'advanced-media-offloader/advanced-media-offloader.php' )
		: defined( 'ADVMO_VERSION' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Images Optimizer', 'images-optimizer' ); ?></h1>
		<p>
			<?php esc_html_e( 'This plugin only converts uploads to WebP before WordPress builds its size variants and keeps the original file. Offload to R2 and URL delivery are handled entirely by Advanced Media Offloader.', 'images-optimizer' ); ?>
		</p>

		<?php if ( ! $advmo_active ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Advanced Media Offloader does not appear to be active - offload counts below will read as 0.', 'images-optimizer' ); ?></p>
			</div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width: 640px;">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Total image attachments', 'images-optimizer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Converted to WebP', 'images-optimizer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stats['converted'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Not yet converted', 'images-optimizer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stats['not_converted'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Not convertible (GIF / SVG)', 'images-optimizer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stats['not_convertible'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Offloaded to R2 (per Advanced Media Offloader)', 'images-optimizer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stats['offloaded'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Not yet offloaded', 'images-optimizer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $stats['not_offloaded'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php
}
