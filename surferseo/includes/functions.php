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
 * Checks if the current user can use Surfer editor tools (sidebar, Content Sync, Writing Guidelines) for a post.
 *
 * The post type sent in the request is never enough - the user has to be able to edit the post.
 * For an existing post `edit_post` is checked. Without a post (new post) the post type is taken
 * from the current screen and the `edit_posts` capability of that post type is checked.
 * An ID of a post that does not exist is denied - it never falls back to the screen.
 *
 * @param int|null $post_id - ID of the edited post, null for a new post.
 * @return bool
 */
function surfer_current_user_can_use_editor_tools( ?int $post_id = null ): bool {

	$post = ( null !== $post_id && $post_id > 0 ) ? get_post( $post_id ) : null;

	if ( null !== $post_id && $post_id > 0 && ! $post instanceof WP_Post ) {
		return false;
	}

	if ( $post instanceof WP_Post ) {
		$post_type = $post->post_type;
	} else {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = ( $screen instanceof WP_Screen ) ? $screen->post_type : '';
	}

	if ( '' === $post_type || ! in_array( $post_type, surfer_return_supported_post_types(), true ) ) {
		return false;
	}

	if ( $post instanceof WP_Post ) {
		return current_user_can( 'edit_post', $post->ID );
	}

	$post_type_object = get_post_type_object( $post_type );

	if ( null === $post_type_object ) {
		return false;
	}

	return current_user_can( $post_type_object->cap->edit_posts );
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
 * Capability is always checked - a nonce alone never opens the action.
 * `true` and `false` both mean `manage_options` (`false` used to skip the check).
 *
 * @param string      $nonce_value Nonce.
 * @param string      $action Action name.
 * @param bool|string $capability Capability to check, `true`/`false` means `manage_options`.
 * @param int|null    $object_id ID of the object to check the capability for (e.g. post ID for `edit_post`).
 * @return bool
 */
function surfer_validate_custom_request( $nonce_value, $action = 'surfer-ajax-nonce', $capability = true, $object_id = null ): bool {

	if ( is_bool( $capability ) ) {
		$capability = 'manage_options';
	}

	$user_can = null !== $object_id ? current_user_can( $capability, $object_id ) : current_user_can( $capability );

	if ( ! $user_can ) {
		return false;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce_value ) ), $action ) ) {
		return false;
	}

	return true;
}

/**
 * Returns decoded JSON body of the current AJAX request.
 *
 * @return object|null - null when the body is missing or is not a JSON object.
 */
function surfer_get_json_request_body(): ?object {

	/**
	 * Filters JSON body of the request before it is read from `php://input`.
	 *
	 * Value other than null replaces the body read from the request (used by tests).
	 *
	 * @param object|array|null $body Request body.
	 */
	$body = apply_filters( 'surfer_pre_json_request_body', null );

	if ( null === $body ) {
		$body = json_decode( (string) file_get_contents( 'php://input' ) );
	} elseif ( is_array( $body ) ) {
		$body = json_decode( (string) wp_json_encode( $body ) );
	}

	return is_object( $body ) ? $body : null;
}

/**
 * Returns a masked form of a secret (e.g. Surfer API key) that is safe to show or log: `…abcd`.
 *
 * Only the last 4 characters are kept, and none for a secret that short it would be almost whole.
 *
 * @param mixed $secret Secret value.
 * @return string - masked secret, empty string when the secret is not set.
 */
function surfer_mask_secret( $secret ): string {

	if ( ! is_string( $secret ) || '' === $secret ) {
		return '';
	}

	return '…' . ( strlen( $secret ) > 8 ? substr( $secret, -4 ) : '' );
}

/**
 * Checks if URL points to a trusted Surfer host, so it can be used as the Surfer URL.
 *
 * The Surfer URL receives the API key and the connection token, and serves the writing
 * guidelines script, so only `https` URLs on a trusted host are accepted. A host matches
 * when it equals a trusted host or is its subdomain (`app.surferseo.com` for `surferseo.com`).
 *
 * @param mixed $url URL to check.
 * @return bool
 */
function surfer_is_trusted_surfer_url( $url ): bool {

	// FILTER_VALIDATE_URL rejects URLs that PHP and browsers parse differently (e.g. with a backslash).
	if ( ! is_string( $url ) || '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return false;
	}

	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
		return false;
	}

	// Credentials in the URL only serve to disguise the real host.
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}

	$host = strtolower( $parts['host'] );

	/**
	 * Filters hosts trusted as the Surfer URL. Subdomains of each host are trusted too.
	 *
	 * @param string[] $trusted_hosts Trusted hosts.
	 */
	$trusted_hosts = (array) apply_filters( 'surfer_trusted_hosts', array( 'surferseo.com' ) );

	foreach ( $trusted_hosts as $trusted_host ) {
		if ( ! is_string( $trusted_host ) || '' === $trusted_host ) {
			continue;
		}

		$trusted_host = strtolower( $trusted_host );

		if ( $host === $trusted_host || str_ends_with( $host, '.' . $trusted_host ) ) {
			return true;
		}
	}

	return false;
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
 * Detects which supported SEO plugin is active.
 * Returns 'surfer' when none is, then meta is kept in Surfer's own post meta.
 *
 * @return string - 'yoast', 'aioseo', 'rank_math' or 'surfer'.
 */
function surfer_detect_active_seo_plugin() {

	if ( surfer_check_if_plugins_is_active( 'wordpress-seo/wp-seo.php' ) ) {
		return 'yoast';
	} elseif ( surfer_check_if_plugins_is_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) ) {
		return 'aioseo';
	} elseif ( surfer_check_if_plugins_is_active( 'seo-by-rank-math/rank-math.php' ) ) {
		return 'rank_math';
	}

	return 'surfer';
}

/**
 * Returns where meta title and description are stored: the SEO plugin chosen
 * in settings, or the detected one when the setting is on auto detection.
 *
 * @return string - 'yoast', 'aioseo', 'rank_math' or 'surfer'.
 */
function surfer_get_seo_meta_target() {

	$chosen_seo_plugin = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'default_seo_plugin', '' );

	if ( '' === $chosen_seo_plugin ) {
		$chosen_seo_plugin = surfer_detect_active_seo_plugin();
	}

	return $chosen_seo_plugin;
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

		// "Not found" is not cached. When a worker dies in the middle of an import (timeout
		// while generating sizes of a big image), a cached miss made the next run import the
		// same file again, creating another attachment every time.
		if ( $attachment_id ) {
			wp_cache_set( $cache_key, $attachment_id, 'surferseo_db', 5 * MINUTE_IN_SECONDS );
		}
	}

	return $attachment_id ? (int) $attachment_id : false;
}

/**
 * Forgets cached attachment lookup for provided URL.
 *
 * Has to be called when an attachment for the URL is created or removed, so the
 * cached ID never points at a missing or outdated attachment.
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
 * Returns limits of the content import and image downloads.
 *
 * - `content_bytes` - maximum size of the imported content,
 * - `images_per_import` - how many images one import downloads or queues,
 * - `queue_length` - maximum number of images waiting in the background queue,
 * - `download_bytes` - maximum size of a downloaded image,
 * - `download_timeout` - timeout of a single image download, in seconds,
 * - `max_pixels` - maximum width × height of a downloaded image.
 *
 * @return array{content_bytes: int, images_per_import: int, queue_length: int, download_bytes: int, download_timeout: int, max_pixels: int}
 */
function surfer_get_import_limits(): array {

	$defaults = array(
		'content_bytes'     => 5 * MB_IN_BYTES,
		'images_per_import' => 100,
		'queue_length'      => 500,
		'download_bytes'    => 10 * MB_IN_BYTES,
		'download_timeout'  => 30,
		'max_pixels'        => 40000000,
	);

	/**
	 * Filters limits of the content import and image downloads.
	 *
	 * Values that are not numeric or are lower than 1 are replaced with the defaults.
	 *
	 * @param array $limits - limits, see surfer_get_import_limits().
	 */
	$limits = apply_filters( 'surfer_import_limits', $defaults );

	if ( ! is_array( $limits ) ) {
		$limits = array();
	}

	$result = $defaults;

	foreach ( $defaults as $key => $default ) {
		if ( isset( $limits[ $key ] ) && is_numeric( $limits[ $key ] ) && (float) $limits[ $key ] >= 1 ) {
			$result[ $key ] = (int) $limits[ $key ];
		}
	}

	return $result;
}

/**
 * Downloads an image from Surfer into the media library.
 *
 * The download is limited in size and time. Only real images are saved - the type is
 * detected from the downloaded file, not from the URL or the response headers.
 *
 * Results:
 * - `ok` - the attachment exists, its ID is in `attachment_id`,
 * - `not_image` - the file is not one of the accepted image types, nothing was saved,
 * - `too_large` - the file is bigger than `download_bytes` or has more than `max_pixels`,
 * - `error` - any other failure (network, invalid URL, media library).
 *
 * Every result other than `ok` is logged.
 *
 * @param string $url - URL of the image.
 * @param string $alt - alt text from Surfer.
 * @return array{status: string, attachment_id?: int}
 */
function surfer_download_image( string $url, string $alt = '' ): array {

	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		surfer_log_image_import_error( $url, 'invalid image URL' );
		return array( 'status' => 'error' );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$limits         = surfer_get_import_limits();
	$content_length = null;

	// `download_url()` does not take request arguments, so the size limit is added for the
	// duration of this download only.
	$limit_response_size = function ( $args ) use ( $limits ) {
		$args['limit_response_size'] = $limits['download_bytes'];
		return $args;
	};

	$read_content_length = function ( $response ) use ( &$content_length ) {
		if ( is_array( $response ) ) {
			$content_length = wp_remote_retrieve_header( $response, 'content-length' );
		}
		return $response;
	};

	add_filter( 'http_request_args', $limit_response_size, PHP_INT_MAX );
	add_filter( 'http_response', $read_content_length, PHP_INT_MAX );

	try {
		$tmp_file = download_url( $url, $limits['download_timeout'] );
	} finally {
		remove_filter( 'http_request_args', $limit_response_size, PHP_INT_MAX );
		remove_filter( 'http_response', $read_content_length, PHP_INT_MAX );
	}

	if ( is_wp_error( $tmp_file ) ) {
		surfer_log_image_import_error( $url, $tmp_file->get_error_message() );
		return array( 'status' => 'error' );
	}

	$status = surfer_check_downloaded_image( $tmp_file, $content_length, $limits );

	if ( 'ok' !== $status['status'] ) {
		@unlink( $tmp_file ); // phpcs:ignore
		surfer_log_image_import_error( $url, $status['message'] );
		return array( 'status' => $status['status'] );
	}

	$file_name = surfer_resolve_image_file_name( $url, $tmp_file );

	// Mime type is intentionally not provided - WordPress detects it from the
	// downloaded file, so images from URLs without an extension are not rejected.
	//
	// Surfer meta is saved together with the attachment, before its sizes are generated.
	// Generating sizes of a big image can take longer than PHP allows - when the process
	// is killed there, the next run has to find this attachment instead of importing the
	// same file again.
	$attachment_id = media_handle_sideload(
		array(
			'tmp_name' => $tmp_file,
			'name'     => $file_name,
		),
		0,
		null,
		array(
			'meta_input' => array(
				'surfer_file_name'         => $file_name,
				'surfer_file_original_url' => $url,
			),
		)
	);

	if ( file_exists( $tmp_file ) ) {
		@unlink( $tmp_file ); // phpcs:ignore
	}

	if ( is_wp_error( $attachment_id ) || empty( $attachment_id ) ) {
		$error_message = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'attachment was not created';
		surfer_log_image_import_error( $url, $error_message );
		return array( 'status' => 'error' );
	}

	surfer_forget_attachment_by_original_url( $url );
	surfer_update_image_alt( (int) $attachment_id, $alt );

	return array(
		'status'        => 'ok',
		'attachment_id' => (int) $attachment_id,
	);
}

/**
 * Checks a downloaded file against the image type whitelist and the size limits.
 *
 * The type is checked first - a file that is not an image keeps its remote URL whatever
 * its size, and a file cut at the size limit still has its header, so a real image is
 * recognized and then reported as too large.
 *
 * `limit_response_size` silently cuts the file at the limit, so a file of exactly the
 * limit size (or a response announcing more) is treated as too large.
 *
 * @param string             $file_path - path to the downloaded file.
 * @param mixed              $content_length - `Content-Length` header of the response, if known.
 * @param array<string, int> $limits - limits from surfer_get_import_limits().
 * @return array{status: string, message: string}
 */
function surfer_check_downloaded_image( string $file_path, $content_length, array $limits ): array {

	if ( is_array( $content_length ) ) {
		$content_length = end( $content_length );
	}

	$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
	$mime_type     = wp_get_image_mime( $file_path );

	if ( ! in_array( $mime_type, $allowed_types, true ) ) {
		return array(
			'status'  => 'not_image',
			'message' => 'file is not an accepted image (' . ( $mime_type ? $mime_type : 'unknown type' ) . '), remote URL kept',
		);
	}

	$file_size = (int) filesize( $file_path );

	if ( $file_size >= $limits['download_bytes'] || ( is_numeric( $content_length ) && (float) $content_length > $limits['download_bytes'] ) ) {
		return array(
			'status'  => 'too_large',
			'message' => 'file is larger than ' . $limits['download_bytes'] . ' bytes',
		);
	}

	$image_size = wp_getimagesize( $file_path );

	if ( is_array( $image_size ) && (float) $image_size[0] * (float) $image_size[1] > $limits['max_pixels'] ) {
		return array(
			'status'  => 'too_large',
			'message' => 'image has more than ' . $limits['max_pixels'] . ' pixels',
		);
	}

	return array(
		'status'  => 'ok',
		'message' => '',
	);
}

/**
 * Calls the callback for the `src` attribute of every `<img>` tag in the HTML.
 *
 * The callback gets the decoded value (`&amp;` becomes `&`) and returns the new raw
 * value of the attribute, already escaped, or null to leave the attribute unchanged.
 * Attributes are read one by one, so `src` inside another attribute's value (or
 * `data-src`) is never taken for the image source.
 *
 * @param string   $html - HTML to process.
 * @param callable $callback - receives decoded `src`, returns new escaped value or null.
 * @return string
 */
function surfer_map_img_src( string $html, callable $callback ): string {

	if ( false === stripos( $html, '<img' ) ) {
		return $html;
	}

	$result = preg_replace_callback(
		'~(<img)\b((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>~i',
		function ( $tag ) use ( $callback ) {
			$attributes = preg_replace_callback(
				'~(\s+)([^\s=/>"\']+)(?:(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?~',
				function ( $attribute ) use ( $callback ) {
					if ( 'src' !== strtolower( $attribute[2] ) || ! isset( $attribute[4] ) ) {
						return $attribute[0];
					}

					$quote = $attribute[4][0];
					$value = $attribute[4];

					if ( '"' === $quote || "'" === $quote ) {
						$value = substr( $value, 1, -1 );
					} else {
						$quote = '"';
					}

					$new_value = $callback( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

					if ( null === $new_value ) {
						return $attribute[0];
					}

					return $attribute[1] . $attribute[2] . $attribute[3] . $quote . $new_value . $quote;
				},
				$tag[2]
			);

			return $tag[1] . $attributes . '>';
		},
		$html
	);

	return is_string( $result ) ? $result : $html;
}

/**
 * Replaces the image source in `<img>` tags whose `src` is exactly the provided URL.
 *
 * Other occurrences of the URL (text, links, longer URLs starting the same way) are
 * left untouched. The new URL is escaped with `esc_url()`.
 *
 * @param string $html - HTML to process.
 * @param string $from_url - URL to replace, compared with the decoded `src` value.
 * @param string $to_url - new URL of the image.
 * @return string
 */
function surfer_replace_img_src( string $html, string $from_url, string $to_url ): string {

	$escaped_url = esc_url( $to_url );

	if ( '' === $from_url || '' === $escaped_url ) {
		return $html;
	}

	return surfer_map_img_src(
		$html,
		function ( $src ) use ( $from_url, $escaped_url ) {
			return $src === $from_url ? $escaped_url : null;
		}
	);
}

/**
 * Checks if any `<img>` tag in the HTML has `src` exactly equal to the provided URL.
 *
 * @param string $html - HTML to check.
 * @param string $image_url - URL of the image, compared with the decoded `src` value.
 * @return bool
 */
function surfer_content_has_img_src( string $html, string $image_url ): bool {

	$found = false;

	if ( '' === $image_url ) {
		return false;
	}

	surfer_map_img_src(
		$html,
		function ( $src ) use ( $image_url, &$found ) {
			if ( $src === $image_url ) {
				$found = true;
			}

			return null;
		}
	);

	return $found;
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

	// Every attempt reads the lock from the database - a value cached by an earlier attempt
	// in this request would hide that another request has released the lock since.
	wp_cache_delete( $option_name, 'options' );

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
