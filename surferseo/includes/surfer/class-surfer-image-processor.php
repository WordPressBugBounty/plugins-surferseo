<?php
/**
 * Background image processor for Surfer.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles background image processing.
 */
class Surfer_Image_Processor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'surfer_process_image_queue', array( $this, 'process_image_queue' ) );
	}

	/**
	 * Process queued images.
	 *
	 * @return void
	 */
	public function process_image_queue() {
		$queue = get_option( 'surfer_image_download_queue', array() );

		if ( empty( $queue ) ) {
			return;
		}

		$batch_size = 3;
		$processed  = 0;

		foreach ( $queue as $key => $item ) {
			if ( $processed >= $batch_size ) {
				break;
			}

			$this->download_and_replace_image( $item['url'], $item['alt'] );
			unset( $queue[ $key ] );
			++$processed;
		}

		update_option( 'surfer_image_download_queue', array_values( $queue ) );

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + 1, 'surfer_process_image_queue' );
		}
	}

	/**
	 * Download image and replace URLs in posts.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $image_alt - Alt text for the image.
	 * @return void
	 */
	private function download_and_replace_image( $image_url, $image_alt ) {
		$attachment_id = $this->download_image_to_media_library( $image_url, $image_alt );

		if ( ! $attachment_id ) {
			return;
		}

		$new_url = wp_get_attachment_url( $attachment_id );

		global $wpdb;

		$cache_key = 'image_posts_' . md5( (string) $image_url );
		$posts     = wp_cache_get( $cache_key, 'surferseo_db' );

		if ( false === $posts ) {
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} 
             WHERE post_content LIKE %s 
             AND post_status IN ('publish', 'draft', 'pending')",
					'%' . $image_url . '%'
				)
			);
			wp_cache_set( $cache_key, $posts, 'surferseo_db', MINUTE_IN_SECONDS );
		}

		foreach ( $posts as $post ) {
			$updated_content = str_replace( $image_url, $new_url, $post->post_content );

			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $updated_content,
				)
			);
		}
	}

	/**
	 * Download image to WordPress media library.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $image_alt - Alt text for the image.
	 * @return int|false Attachment ID or false on failure.
	 */
	private function download_image_to_media_library( $image_url, $image_alt ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_name_and_extension = $this->get_file_name_and_extension( $image_url );
		$tmp_directory           = download_url( $image_url );

		$file_array = array(
			'tmp_name' => $tmp_directory,
			'name'     => $file_name_and_extension['file_name'],
			'type'     => 'image/' . $file_name_and_extension['extension'],
		);

		$post_data = array(
			'post_mime_type' => 'image/' . $file_name_and_extension['extension'],
		);

		$attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );
		update_post_meta( $attachment_id, 'surfer_file_name', $file_name_and_extension['file_name'] );
		update_post_meta( $attachment_id, 'surfer_file_original_url', $image_url );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $image_alt );
		@unlink( $tmp_directory ); // phpcs:ignore

		return $attachment_id;
	}

	/**
	 * Get file name from image URL.
	 *
	 * @param string $image_url - URL of the image.
	 * @return string File name.
	 */
	private function get_file_name_and_extension( $image_url ) {
		$file_name = basename( $image_url );

		$extension = pathinfo( $image_url, PATHINFO_EXTENSION );
		if ( empty( $extension ) || '' === $extension ) {
			$headers = get_headers( $image_url );
			foreach ( $headers as $header ) {
				if ( false !== strpos( $header, 'Content-Disposition' ) ) {
					preg_match( '~filename="(.*?)\.(.*?)"~i', $header, $match );
					$extension  = $match[2];
					$file_name .= '.' . $match[2];
					break;
				}
			}
		}

		return array(
			'file_name' => $file_name,
			'extension' => $extension,
		);
	}
}
