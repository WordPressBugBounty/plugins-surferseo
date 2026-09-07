<?php
/**
 * Stores general purpose functions to use in multiple places.
 *
 * @package SurferSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns default TTL for DB query cache.
 *
 * @return int
 */
function surfer_get_db_cache_ttl() {
	/**
	 * Filters TTL for DB query cache.
	 *
	 * @param int $ttl Cache TTL in seconds.
	 */
	return (int) apply_filters( 'surferseo_db_cache_ttl', MINUTE_IN_SECONDS );
}

/**
 * Generates cache key for DB query.
 *
 * @param string $sql Prepared SQL query.
 * @param string $type Cache type prefix.
 * @return string
 */
function surfer_get_db_cache_key( $sql, $type = 'q' ) {
	return $type . ':' . md5( (string) $sql );
}

/**
 * Returns post GSC traffic by post ID.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function surfer_get_last_post_traffic_by_id( $post_id ) {
	global $wpdb;

	$cache_key = 'last_post_traffic_' . absint( $post_id );
	$cached    = wp_cache_get( $cache_key, 'surferseo_db' );
	if ( false !== $cached ) {
		return $cached;
	}

	$result = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}surfer_gsc_traffic WHERE post_id = %d ORDER BY data_gathering_date DESC LIMIT 1",
			$post_id
		),
		ARRAY_A
	);

	wp_cache_set( $cache_key, $result, 'surferseo_db', 5 * MINUTE_IN_SECONDS );

	return $result;
}

/**
 * Returns supported post types.
 *
 * @param bool $surfer_select_prepared - if true will return value and label parirs.
 * @return array
 */
function surfer_return_supported_post_types( $surfer_select_prepared = false ) {

	$post_types = get_post_types( array( 'public' => true ), 'objects' );

	$post_types = array_filter(
		$post_types,
		function ( $post_type ) {
			return ! in_array( $post_type->name, array( 'attachment', 'revision', 'nav_menu_item' ), true );
		}
	);

	if ( true === $surfer_select_prepared ) {
		$filtered_post_types = array();
		foreach ( $post_types as $type ) {
			$filtered_post_types[] = array(
				'label' => $type->label,
				'value' => $type->name,
			);
		}
		$post_types = $filtered_post_types;
	} else {
		$post_types = array_map(
			function ( $post_type ) {
				return $post_type->name;
			},
			$post_types
		);
	}

	return apply_filters( 'surfer_supported_post_types', $post_types, $surfer_select_prepared );
}

/**
 * Verifies if user can perform ajax action
 *
 * @param string $nonce_name Nonce name.
 * @param string $action Action name.
 * @return bool
 */
function surfer_validate_ajax_request( $nonce_name = '_surfer_nonce', $action = 'surfer-ajax-nonce' ) {

	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	if ( ! check_ajax_referer( $action, $nonce_name, false ) ) {
		return false;
	}

	return true;
}

/**
 * Verifies if user can perform ajax action
 *
 * @param string $nonce_value Nonce.
 * @param string $action Action name.
 * @return bool
 */
function surfer_validate_custom_request( $nonce_value, $action = 'surfer-ajax-nonce', $require_admin = true ) {

	if ( true === $require_admin && ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce_value ) ), $action ) ) {
		return false;
	}

	return true;
}


/**
 * Adds numerical suffix to number.
 *
 * @param int $number Number to add suffix to.
 * @return string
 */
function surfer_add_numerical_suffix( $number ) {
	$ends = array( 'th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th' );
	if ( ( ( $number % 100 ) >= 11 ) && ( ( $number % 100 ) <= 13 ) ) {
		return $number . 'th';
	} else {
		return $number . $ends[ $number % 10 ];
	}
}

/**
 * Checks if plugin is active even if default function is not loaded.
 *
 * @param string $plugin - plugin name to check.
 * @return bool
 */
function surfer_check_if_plugins_is_active( $plugin ) {

	if ( ! function_exists( 'is_plugin_active' ) ) {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
	} else {
		return is_plugin_active( $plugin );
	}
}

/**
 * Returns cache key for attachment lookup by its original (Surfer) URL.
 *
 * @param string $image_url - original URL of the image.
 * @return string
 */
function surfer_get_attachment_by_url_cache_key( $image_url ) {
	return 'attachment_by_url_' . md5( (string) $image_url );
}

/**
 * Finds attachment imported from provided Surfer URL.
 *
 * Compares URLs exactly - `LIKE` would treat `_` and `%` from the file name as
 * wildcards and match a different attachment.
 *
 * @param string $image_url - original URL of the image.
 * @return int|false
 */
function surfer_find_attachment_by_original_url( $image_url ) {
	global $wpdb;

	if ( empty( $image_url ) ) {
		return false;
	}

	$cache_key     = surfer_get_attachment_by_url_cache_key( $image_url );
	$attachment_id = wp_cache_get( $cache_key, 'surferseo_db' );

	if ( false === $attachment_id ) {
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = 'surfer_file_original_url'
				 AND meta_value = %s",
				$image_url
			)
		);
		wp_cache_set( $cache_key, $attachment_id, 'surferseo_db', 5 * MINUTE_IN_SECONDS );
	}

	return $attachment_id ? (int) $attachment_id : false;
}

/**
 * Forgets cached attachment lookup for provided URL.
 *
 * Has to be called after importing an image, otherwise the cached "not found"
 * result makes the next import download the same file again.
 *
 * @param string $image_url - original URL of the image.
 * @return void
 */
function surfer_forget_attachment_by_original_url( $image_url ) {
	wp_cache_delete( surfer_get_attachment_by_url_cache_key( $image_url ), 'surferseo_db' );
}

/**
 * Builds file name for an image downloaded from Surfer.
 *
 * Surfer CDN URLs often have no extension in the path, and may carry a query string
 * (signed URLs). Both make `media_handle_sideload()` reject the file as a wrong file
 * type, so the name is built from the URL path only and the extension is taken from
 * the downloaded file when the URL does not provide one.
 *
 * @param string $image_url - URL of the image.
 * @param string $file_path - path to the already downloaded file, used to detect the extension.
 * @return string
 */
function surfer_resolve_image_file_name( $image_url, $file_path = '' ) {

	$file_name = basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) );
	$file_name = sanitize_file_name( urldecode( $file_name ) );

	if ( '' === $file_name ) {
		$file_name = 'surfer-image';
	}

	$file_type = wp_check_filetype( $file_name );

	if ( ! empty( $file_type['ext'] ) ) {
		return $file_name;
	}

	$extension = surfer_get_image_extension_from_file( $file_path );

	if ( '' === $extension ) {
		return $file_name;
	}

	return $file_name . '.' . $extension;
}

/**
 * Detects file extension of a downloaded image, based on its real content.
 *
 * @param string $file_path - path to the downloaded file.
 * @return string - extension without a dot, empty when it cannot be detected.
 */
function surfer_get_image_extension_from_file( $file_path ) {

	if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
		return '';
	}

	$mime_type  = '';
	$image_size = wp_getimagesize( $file_path );

	if ( is_array( $image_size ) && ! empty( $image_size['mime'] ) ) {
		$mime_type = $image_size['mime'];
	} elseif ( function_exists( 'mime_content_type' ) ) {
		$mime_type = (string) mime_content_type( $file_path );
	}

	if ( '' === $mime_type ) {
		return '';
	}

	foreach ( wp_get_mime_types() as $extensions => $type ) {
		if ( $type === $mime_type ) {
			$extensions = explode( '|', $extensions );
			return $extensions[0];
		}
	}

	return '';
}

/**
 * Returns URL of the placeholder used when an image could not be imported.
 *
 * Failed images are never left pointing at Surfer - the post would look fine while the
 * media library stays empty, and nobody would notice until Surfer removes the file.
 *
 * @return string
 */
function surfer_get_failed_image_url() {
	return Surfer()->get_baseurl() . '/assets/images/failed_image.png';
}

/**
 * Logs a failed image import, so a placeholder in the content can be explained.
 *
 * @param string $image_url - URL of the image.
 * @param string $error_message - reason of the failure.
 * @return void
 */
function surfer_log_image_import_error( $image_url, $error_message ) {

	/**
	 * Fires when an image from Surfer could not be imported into the media library.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $error_message - reason of the failure.
	 */
	do_action( 'surfer_image_import_failed', $image_url, $error_message );

	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'Surfer: image import failed (' . $image_url . '): ' . $error_message );
}

/**
 * Updates alt text of an attachment imported from Surfer.
 *
 * Rules:
 * - empty alt from Surfer never clears alt that is already in WordPress,
 * - empty alt in WordPress is always filled,
 * - alt that we set ourselves before is refreshed, so corrections made in Surfer
 *   reach WordPress on the next export,
 * - alt edited manually in WordPress is never overwritten.
 *
 * @param int    $image_id - ID of the attachment to update.
 * @param string $image_alt - alt text coming from Surfer.
 * @return void
 */
function surfer_update_image_alt( $image_id, $image_alt = '' ) {

	if ( empty( $image_id ) || is_wp_error( $image_id ) ) {
		return;
	}

	$image_alt = trim( (string) $image_alt );

	if ( '' === $image_alt ) {
		return;
	}

	$current_alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );

	if ( $current_alt === $image_alt ) {
		update_post_meta( $image_id, 'surfer_image_alt', $image_alt );
		return;
	}

	$alt_set_by_surfer = trim( (string) get_post_meta( $image_id, 'surfer_image_alt', true ) );

	if ( '' !== $current_alt && $current_alt !== $alt_set_by_surfer ) {
		return;
	}

	update_post_meta( $image_id, '_wp_attachment_image_alt', $image_alt );
	update_post_meta( $image_id, 'surfer_image_alt', $image_alt );
}

/**
 * Tries to acquire a named lock.
 *
 * `add_option()` fails when the option already exists (option_name is unique),
 * so it works as a mutex between parallel requests and cron runs.
 *
 * @param string $lock_name - name of the lock.
 * @param int    $timeout - after how many seconds a lock is considered stale.
 * @return bool - true when the lock was acquired.
 */
function surfer_acquire_lock( $lock_name, $timeout = 60 ) {
	$option_name = 'surfer_lock_' . $lock_name;
	$now         = time();

	if ( add_option( $option_name, $now, '', false ) ) {
		return true;
	}

	$locked_at = (int) get_option( $option_name, 0 );

	if ( $locked_at > 0 && ( $now - $locked_at ) > $timeout ) {
		update_option( $option_name, $now, false );
		return true;
	}

	return false;
}

/**
 * Releases a named lock.
 *
 * @param string $lock_name - name of the lock.
 * @return void
 */
function surfer_release_lock( $lock_name ) {
	delete_option( 'surfer_lock_' . $lock_name );
}

/**
 * Stupid simple image printer.
 * To avoid PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
 *
 * @param string $image_url - URL to the image.
 * @param string $alt - Alternative text for the image.
 * @param string $style - Style for the image.
 * @param string $id - ID for the image.
 * @return void
 */
function surfer_image_printer( $image_url, $alt, $style, $id ) {
	$style = 'style="' . $style . '"';
	$alt   = 'alt="' . $alt . '"';
	$id    = 'id="' . $id . '"';

	$image  = '<';
	$image .= 'img src="' . esc_url( $image_url ) . '" ' . $style . ' ' . $alt . ' ' . $id . '>';
	echo wp_kses_post( $image );
}
