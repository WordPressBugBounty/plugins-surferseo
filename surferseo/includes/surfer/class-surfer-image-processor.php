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
	 * How many images are downloaded in a single run.
	 */
	private const BATCH_SIZE = 3;

	/**
	 * How many times a single image is retried before it is dropped from the queue.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * For how long an image taken by a worker is not picked up again.
	 */
	private const LEASE_TIME = 300;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'surfer_process_image_queue', array( $this, 'process_image_queue' ) );
		add_action( 'admin_init', array( $this, 'maybe_reschedule_queue' ) );
	}

	/**
	 * Makes sure pending images are not left hotlinked to Surfer when the cron event
	 * is lost (missed schedule, failed run, cron disabled and re-enabled later).
	 *
	 * @return void
	 */
	public function maybe_reschedule_queue() {

		if ( wp_next_scheduled( 'surfer_process_image_queue' ) ) {
			return;
		}

		if ( empty( get_option( 'surfer_image_download_queue', array() ) ) ) {
			return;
		}

		wp_schedule_single_event( time() + 5, 'surfer_process_image_queue' );
	}

	/**
	 * Process queued images.
	 *
	 * @return void
	 */
	public function process_image_queue() {

		// Only one run at a time. Two parallel runs would read and write the same post
		// content (or Elementor data), and the slower one would revert the other's
		// replacements - leaving images hotlinked even though attachments exist.
		if ( ! surfer_acquire_lock( 'image_queue_run', self::LEASE_TIME ) ) {
			return;
		}

		try {
			$batch = $this->lease_batch();

			if ( empty( $batch ) ) {
				// Everything left in the queue is leased by a worker that died - come back
				// after the lease expires, so images are not stuck in the queue.
				if ( ! empty( get_option( 'surfer_image_download_queue', array() ) ) ) {
					$this->schedule_next_run( self::LEASE_TIME );
				}

				return;
			}

			$results = array();

			foreach ( $batch as $item ) {
				$results[ $item['url'] ] = $this->download_and_replace_image(
					$item['url'],
					isset( $item['alt'] ) ? $item['alt'] : ''
				);
			}

			$queue = $this->release_batch( $results );

			if ( ! empty( $queue ) ) {
				$this->schedule_next_run( 1 );
			}
		} finally {
			surfer_release_lock( 'image_queue_run' );
		}
	}

	/**
	 * Schedules next queue run, unless one is already waiting.
	 *
	 * @param int $delay - delay in seconds.
	 * @return void
	 */
	private function schedule_next_run( $delay ) {

		if ( wp_next_scheduled( 'surfer_process_image_queue' ) ) {
			return;
		}

		wp_schedule_single_event( time() + $delay, 'surfer_process_image_queue' );
	}

	/**
	 * Takes a batch of images from the queue and marks them as being processed.
	 *
	 * Downloads happen outside of the lock (they can take seconds), so images are leased
	 * instead of being removed - a parallel worker will not download them again, and a
	 * timeout does not lose them permanently.
	 *
	 * @return array
	 */
	private function lease_batch() {

		// Another worker is already picking a batch - it will reschedule the queue.
		if ( ! surfer_acquire_lock( 'image_queue' ) ) {
			return array();
		}

		$queue = get_option( 'surfer_image_download_queue', array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			surfer_release_lock( 'image_queue' );
			return array();
		}

		$batch = array();
		$now   = time();

		foreach ( $queue as $key => $item ) {
			if ( count( $batch ) >= self::BATCH_SIZE ) {
				break;
			}

			if ( empty( $item['url'] ) ) {
				unset( $queue[ $key ] );
				continue;
			}

			$leased_at = isset( $item['processing_at'] ) ? (int) $item['processing_at'] : 0;

			if ( $leased_at > 0 && ( $now - $leased_at ) < self::LEASE_TIME ) {
				continue;
			}

			$queue[ $key ]['processing_at'] = $now;
			$batch[]                        = $queue[ $key ];
		}

		update_option( 'surfer_image_download_queue', array_values( $queue ), false );
		surfer_release_lock( 'image_queue' );

		return $batch;
	}

	/**
	 * Removes downloaded images from the queue and counts attempts for the failed ones.
	 *
	 * @param array $results - map of image URL => was it processed successfully.
	 * @return array - queue left to process.
	 */
	private function release_batch( $results ) {

		$lock_acquired = surfer_acquire_lock( 'image_queue' );

		$queue       = get_option( 'surfer_image_download_queue', array() );
		$new_queue   = array();
		$failed_urls = array();

		foreach ( (array) $queue as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}

			if ( ! array_key_exists( $item['url'], $results ) ) {
				$new_queue[] = $item;
				continue;
			}

			if ( $results[ $item['url'] ] ) {
				continue;
			}

			$item['attempts'] = isset( $item['attempts'] ) ? (int) $item['attempts'] + 1 : 1;
			unset( $item['processing_at'] );

			// Give up eventually, so one broken URL does not keep the queue alive forever.
			if ( $item['attempts'] < self::MAX_ATTEMPTS ) {
				$new_queue[] = $item;
				continue;
			}

			$failed_urls[] = $item['url'];
		}

		update_option( 'surfer_image_download_queue', $new_queue, false );

		if ( $lock_acquired ) {
			surfer_release_lock( 'image_queue' );
		}

		foreach ( $failed_urls as $failed_url ) {
			$this->mark_image_as_failed( $failed_url );
		}

		return $new_queue;
	}

	/**
	 * Replaces an image that could not be imported with the plugin placeholder.
	 *
	 * Leaving the Surfer URL in the content would hide the problem - the post looks fine,
	 * the media library is empty, and the image disappears once Surfer removes the file.
	 *
	 * @param string $image_url - URL of the image that could not be imported.
	 * @return void
	 */
	private function mark_image_as_failed( $image_url ) {

		$failed_image_url = surfer_get_failed_image_url();

		foreach ( $this->find_posts_with_image( $image_url ) as $post_id ) {
			$this->replace_image_in_post_content( $post_id, $image_url, $failed_image_url, 0 );
			$this->replace_image_in_elementor_data( $post_id, $image_url, $failed_image_url, 0 );
		}

		surfer_log_image_import_error(
			$image_url,
			'gave up after ' . self::MAX_ATTEMPTS . ' attempts, image replaced with a placeholder'
		);
	}

	/**
	 * Download image and replace URLs in posts.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $image_alt - Alt text for the image.
	 * @return bool - true when the image is in the media library and posts are updated.
	 */
	private function download_and_replace_image( $image_url, $image_alt ) {

		// The image may have been imported in the meantime (duplicated queue entry,
		// another post using the same image) - do not download it twice.
		$attachment_id = surfer_find_attachment_by_original_url( $image_url );

		if ( ! $attachment_id ) {
			$attachment_id = $this->download_image_to_media_library( $image_url, $image_alt );
		} else {
			surfer_update_image_alt( $attachment_id, $image_alt );
		}

		if ( ! $attachment_id ) {
			return false;
		}

		$new_url = wp_get_attachment_url( $attachment_id );

		if ( ! $new_url ) {
			return false;
		}

		foreach ( $this->find_posts_with_image( $image_url ) as $post_id ) {
			$this->replace_image_in_post_content( $post_id, $image_url, $new_url, $attachment_id );
			$this->replace_image_in_elementor_data( $post_id, $image_url, $new_url, $attachment_id );
		}

		return true;
	}

	/**
	 * Finds posts that still use the Surfer URL, both in content and in Elementor data.
	 *
	 * @param string $image_url - URL of the image.
	 * @return array - list of post IDs.
	 */
	private function find_posts_with_image( $image_url ) {
		global $wpdb;

		// Every status the importer can set, otherwise scheduled and private posts would
		// keep hotlinked images forever.
		$statuses     = array( 'publish', 'draft', 'pending', 'future', 'private' );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$like = '%' . $wpdb->esc_like( $image_url ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_content LIKE %s
				 AND post_status IN ( {$placeholders} )",
				array_merge( array( $like ), $statuses )
			)
		);

		// Elementor keeps its own copy of the content, and it may be the only place with
		// the image when the post was edited in Elementor after the import.
		$elementor_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				 WHERE m.meta_key = '_elementor_data'
				 AND m.meta_value LIKE %s
				 AND p.post_status IN ( {$placeholders} )",
				array_merge( array( $like ), $statuses )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_unique( array_map( 'intval', array_merge( (array) $post_ids, (array) $elementor_post_ids ) ) );
	}

	/**
	 * Replaces Surfer URL with the local one in post content.
	 *
	 * @param int    $post_id - ID of the post.
	 * @param string $image_url - original URL of the image.
	 * @param string $new_url - URL of the image in the media library, or the placeholder.
	 * @param int    $attachment_id - ID of the attachment, 0 when the import failed.
	 * @return void
	 */
	private function replace_image_in_post_content( $post_id, $image_url, $new_url, $attachment_id ) {

		$content = get_post_field( 'post_content', $post_id, 'raw' );

		if ( ! is_string( $content ) || false === strpos( $content, $image_url ) ) {
			return;
		}

		$content = str_replace( $image_url, $new_url, $content );

		if ( $attachment_id > 0 ) {
			$content = $this->link_gutenberg_image_blocks( $content, $new_url, $attachment_id );
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			)
		);
	}

	/**
	 * Connects Gutenberg image blocks with the attachment.
	 *
	 * Blocks imported in the background mode are built before the attachment exists, so
	 * they have no `id` and no `wp-image-*` class - without them WordPress and plugins
	 * cannot fall back to the alt text stored on the attachment.
	 *
	 * @param string $content - post content.
	 * @param string $image_url - URL of the image in the media library.
	 * @param int    $attachment_id - ID of the attachment.
	 * @return string
	 */
	private function link_gutenberg_image_blocks( $content, $image_url, $attachment_id ) {

		if ( false === strpos( $content, '<!-- wp:image' ) ) {
			return $content;
		}

		$updated = false;
		$blocks  = $this->upgrade_image_blocks( parse_blocks( $content ), $image_url, $attachment_id, $updated );

		if ( ! $updated ) {
			return $content;
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Adds attachment data to image blocks using provided URL.
	 *
	 * @param array  $blocks - parsed blocks.
	 * @param string $image_url - URL of the image in the media library.
	 * @param int    $attachment_id - ID of the attachment.
	 * @param bool   $updated - reference set to true when any block was changed.
	 * @return array
	 */
	private function upgrade_image_blocks( $blocks, $image_url, $attachment_id, &$updated ) {

		foreach ( $blocks as $index => $block ) {

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = $this->upgrade_image_blocks(
					$block['innerBlocks'],
					$image_url,
					$attachment_id,
					$updated
				);
			}

			if ( 'core/image' !== $block['blockName'] || ! empty( $block['attrs']['id'] ) ) {
				continue;
			}

			if ( false === strpos( (string) $block['innerHTML'], $image_url ) ) {
				continue;
			}

			$blocks[ $index ]['attrs']['id']       = (int) $attachment_id;
			$blocks[ $index ]['attrs']['sizeSlug'] = 'full';

			$blocks[ $index ]['innerHTML'] = $this->add_attachment_class( $block['innerHTML'], $attachment_id );

			foreach ( (array) $block['innerContent'] as $inner_index => $inner_content ) {
				if ( is_string( $inner_content ) ) {
					$blocks[ $index ]['innerContent'][ $inner_index ] = $this->add_attachment_class( $inner_content, $attachment_id );
				}
			}

			$updated = true;
		}

		return $blocks;
	}

	/**
	 * Adds `wp-image-*` class to the image and full size class to the figure.
	 *
	 * @param string $html - HTML of the image block.
	 * @param int    $attachment_id - ID of the attachment.
	 * @return string
	 */
	private function add_attachment_class( $html, $attachment_id ) {

		$html = str_replace( 'class="wp-block-image"', 'class="wp-block-image size-full"', $html );

		return preg_replace(
			'~<img(?![^>]*\sclass=)~',
			'<img class="wp-image-' . (int) $attachment_id . '"',
			$html,
			1
		);
	}

	/**
	 * Replaces Surfer URL with the local one in Elementor data.
	 *
	 * Elementor renders images (and their alt text) from the attachment pointed by `id`,
	 * so the attachment ID and `library` source have to be set as well.
	 *
	 * @param int    $post_id - ID of the post.
	 * @param string $image_url - original URL of the image.
	 * @param string $new_url - URL of the image in the media library.
	 * @param int    $attachment_id - ID of the attachment.
	 * @return void
	 */
	private function replace_image_in_elementor_data( $post_id, $image_url, $new_url, $attachment_id ) {

		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $elementor_data ) || false === strpos( $elementor_data, $image_url ) ) {
			return;
		}

		$elements = json_decode( $elementor_data, true );

		if ( ! is_array( $elements ) ) {
			surfer_log_image_import_error( $image_url, 'could not decode _elementor_data of post ' . $post_id );
			return;
		}

		$updated  = false;
		$elements = $this->update_elementor_images( $elements, $image_url, $new_url, $attachment_id, $updated );

		if ( ! $updated ) {
			return;
		}

		update_post_meta(
			$post_id,
			'_elementor_data',
			wp_slash( wp_json_encode( $elements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
		);

		// Elementor caches generated CSS per post.
		delete_post_meta( $post_id, '_elementor_css' );
	}

	/**
	 * Points Elementor image widgets to the attachment in the media library.
	 *
	 * @param array  $elements - Elementor elements.
	 * @param string $image_url - original URL of the image.
	 * @param string $new_url - URL of the image in the media library, or the placeholder.
	 * @param int    $attachment_id - ID of the attachment, 0 when the import failed.
	 * @param bool   $updated - reference set to true when any element was changed.
	 * @return array
	 */
	private function update_elementor_images( $elements, $image_url, $new_url, $attachment_id, &$updated ) {

		foreach ( $elements as $index => $element ) {

			if ( isset( $element['settings']['image']['url'] ) && $element['settings']['image']['url'] === $image_url ) {
				$elements[ $index ]['settings']['image']['url']    = $new_url;
				$elements[ $index ]['settings']['image']['id']     = (int) $attachment_id;
				$elements[ $index ]['settings']['image']['source'] = ( $attachment_id > 0 ) ? 'library' : 'url';

				$updated = true;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$elements[ $index ]['elements'] = $this->update_elementor_images(
					$element['elements'],
					$image_url,
					$new_url,
					$attachment_id,
					$updated
				);
			}
		}

		return $elements;
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

		if ( empty( $image_url ) || ! wp_http_validate_url( $image_url ) ) {
			surfer_log_image_import_error( $image_url, 'invalid image URL' );
			return false;
		}

		$tmp_file = download_url( $image_url );

		if ( is_wp_error( $tmp_file ) ) {
			surfer_log_image_import_error( $image_url, $tmp_file->get_error_message() );
			return false;
		}

		$file_name = surfer_resolve_image_file_name( $image_url, $tmp_file );

		// Mime type is intentionally not provided - WordPress detects it from the
		// downloaded file, so images from URLs without an extension are not rejected.
		$attachment_id = media_handle_sideload(
			array(
				'tmp_name' => $tmp_file,
				'name'     => $file_name,
			),
			0
		);

		if ( file_exists( $tmp_file ) ) {
			@unlink( $tmp_file ); // phpcs:ignore
		}

		if ( is_wp_error( $attachment_id ) || empty( $attachment_id ) ) {
			$error_message = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'attachment was not created';
			surfer_log_image_import_error( $image_url, $error_message );
			return false;
		}

		update_post_meta( $attachment_id, 'surfer_file_name', $file_name );
		update_post_meta( $attachment_id, 'surfer_file_original_url', $image_url );
		surfer_forget_attachment_by_original_url( $image_url );
		surfer_update_image_alt( $attachment_id, $image_alt );

		return $attachment_id;
	}
}
