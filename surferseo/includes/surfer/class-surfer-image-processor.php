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
	 * How many times queueing images tries to get the queue lock.
	 */
	private const QUEUE_LOCK_ATTEMPTS = 5;

	/**
	 * Pause between attempts to get the queue lock, in microseconds.
	 */
	private const QUEUE_LOCK_RETRY_DELAY = 100000;

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
	 * Queues images of a saved post for the background download.
	 *
	 * Called by the importer only after the post is saved, so the worker can change this
	 * post only - never another post that happens to use the same URL. When the image is
	 * already queued (another post uses it), the post is added to that entry and the alt
	 * text of the first entry is kept. A new image that does not fit in the queue
	 * (`queue_length` limit) keeps its Surfer URL.
	 *
	 * @param array<int, array{url: string, alt: string}> $images - images left for the background download.
	 * @param int                                          $post_id - ID of the saved post.
	 * @return void
	 */
	public function queue_images( $images, $post_id ) {

		$post_id = (int) $post_id;

		if ( empty( $images ) || $post_id < 1 ) {
			return;
		}

		if ( ! $this->acquire_queue_lock_with_retries() ) {
			// The post keeps the Surfer URL - better than losing an entry of another import
			// by writing the queue without the lock.
			foreach ( $images as $image ) {
				surfer_log_image_import_error( $image['url'], 'image queue is locked, image of post ' . $post_id . ' was not queued' );
			}

			return;
		}

		$queue = get_option( 'surfer_image_download_queue', array() );

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$queue        = array_values( $queue );
		$keys_by_urls = array();
		$queue_length = surfer_get_import_limits()['queue_length'];
		$not_queued   = array();

		foreach ( $queue as $key => $item ) {
			if ( isset( $item['url'] ) && ! isset( $keys_by_urls[ $item['url'] ] ) ) {
				$keys_by_urls[ $item['url'] ] = $key;
			}
		}

		foreach ( $images as $image ) {
			if ( empty( $image['url'] ) ) {
				continue;
			}

			if ( isset( $keys_by_urls[ $image['url'] ] ) ) {
				$key      = $keys_by_urls[ $image['url'] ];
				$post_ids = $this->get_item_post_ids( $queue[ $key ] );

				if ( ! in_array( $post_id, $post_ids, true ) ) {
					$post_ids[] = $post_id;
				}

				$queue[ $key ]['post_ids'] = $post_ids;
				continue;
			}

			if ( count( $queue ) >= $queue_length ) {
				$not_queued[] = $image['url'];
				continue;
			}

			$queue[] = array(
				'url'       => $image['url'],
				'alt'       => isset( $image['alt'] ) ? (string) $image['alt'] : '',
				'timestamp' => time(),
				'post_ids'  => array( $post_id ),
			);

			$keys_by_urls[ $image['url'] ] = count( $queue ) - 1;
		}

		update_option( 'surfer_image_download_queue', $queue, false );
		surfer_release_lock( 'image_queue' );

		foreach ( $not_queued as $image_url ) {
			surfer_log_image_import_error( $image_url, 'image queue is full (' . $queue_length . ' images), image of post ' . $post_id . ' keeps its remote URL' );
		}

		$this->schedule_next_run( 5 );
	}

	/**
	 * Tries to get the queue lock a few times, waiting a moment between attempts.
	 *
	 * @return bool - true when the lock was acquired.
	 */
	private function acquire_queue_lock_with_retries() {

		for ( $attempt = 1; $attempt <= self::QUEUE_LOCK_ATTEMPTS; $attempt++ ) {
			if ( surfer_acquire_lock( 'image_queue' ) ) {
				return true;
			}

			if ( $attempt < self::QUEUE_LOCK_ATTEMPTS ) {
				usleep( self::QUEUE_LOCK_RETRY_DELAY );
			}
		}

		return false;
	}

	/**
	 * Returns IDs of posts the queue entry was created for.
	 *
	 * @param array $item - queue entry.
	 * @return int[] - empty for entries without post IDs (older plugin versions).
	 */
	private function get_item_post_ids( $item ) {

		if ( ! isset( $item['post_ids'] ) || ! is_array( $item['post_ids'] ) ) {
			return array();
		}

		$post_ids = array_filter(
			array_map( 'intval', $item['post_ids'] ),
			function ( $post_id ) {
				return $post_id > 0;
			}
		);

		return array_values( array_unique( $post_ids ) );
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

			$results   = array();
			$processed = array();

			foreach ( $batch as $item ) {
				$post_ids = $this->get_item_post_ids( $item );

				$this->count_attempt( $item['url'] );

				$results[ $item['url'] ]   = $this->download_and_replace_image(
					$item['url'],
					isset( $item['alt'] ) ? $item['alt'] : '',
					$post_ids
				);
				$processed[ $item['url'] ] = $post_ids;
			}

			$queue = $this->release_batch( $results, $processed );

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
	 * How many images to download in the current run.
	 *
	 * @return int
	 */
	private function get_batch_size() {

		/**
		 * Filters how many images are downloaded in one background run.
		 *
		 * Values below 1 are ignored and the default batch size is used. Values are capped,
		 * so a run fits in the run lock and image leases (`LEASE_TIME`, 300 seconds): at most
		 * `floor( 300 / ( download_timeout + 30 ) )` images, where `download_timeout` comes
		 * from the `surfer_import_limits` filter and 30 seconds are left for saving the file
		 * and generating its sizes - 5 images with the default 30 seconds timeout.
		 *
		 * @param int $batch_size - number of images per run.
		 */
		$batch_size = (int) apply_filters( 'surfer_image_batch_size', self::BATCH_SIZE );

		if ( $batch_size < 1 ) {
			$batch_size = self::BATCH_SIZE;
		}

		$download_timeout = surfer_get_import_limits()['download_timeout'];
		$max_batch_size   = max( 1, (int) floor( self::LEASE_TIME / ( $download_timeout + 30 ) ) );

		return min( $batch_size, $max_batch_size );
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

		$batch      = array();
		$given_up   = array();
		$dropped    = array();
		$now        = time();
		$batch_size = $this->get_batch_size();

		foreach ( $queue as $key => $item ) {
			if ( count( $batch ) >= $batch_size ) {
				break;
			}

			if ( empty( $item['url'] ) ) {
				unset( $queue[ $key ] );
				continue;
			}

			// Entries queued by older plugin versions do not say which posts use the image,
			// and changing every post with that URL could touch posts of other authors.
			if ( empty( $this->get_item_post_ids( $item ) ) ) {
				$dropped[] = $item['url'];
				unset( $queue[ $key ] );
				continue;
			}

			$leased_at = isset( $item['processing_at'] ) ? (int) $item['processing_at'] : 0;

			if ( $leased_at > 0 && ( $now - $leased_at ) < self::LEASE_TIME ) {
				continue;
			}

			// Workers died on this image every time (PHP timeout, out of memory) - they never
			// reached `release_batch()`, so without this check the image would be retried
			// after every lease forever.
			$attempts = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				$given_up[] = $item;
				unset( $queue[ $key ] );
				continue;
			}

			$queue[ $key ]['processing_at'] = $now;
			$batch[]                        = $queue[ $key ];
		}

		update_option( 'surfer_image_download_queue', array_values( $queue ), false );
		surfer_release_lock( 'image_queue' );

		foreach ( $dropped as $image_url ) {
			surfer_log_image_import_error( $image_url, 'queue entry without post IDs dropped, no post was changed' );
		}

		foreach ( $given_up as $item ) {
			$this->give_up_on_image( $item['url'], $this->get_item_post_ids( $item ) );
		}

		return $batch;
	}

	/**
	 * Counts an attempt to import the image, before the import starts.
	 *
	 * A worker killed during the import (big image, slow server) never gets to
	 * `release_batch()`, so counting there would let such an image be retried forever.
	 *
	 * @param string $image_url - URL of the image.
	 * @return void
	 */
	private function count_attempt( $image_url ) {

		// Writing without the lock could drop entries queued in the meantime. The attempt
		// is not counted then - the image is still leased, so it is not retried right away.
		if ( ! surfer_acquire_lock( 'image_queue' ) ) {
			return;
		}

		$queue = get_option( 'surfer_image_download_queue', array() );

		foreach ( (array) $queue as $key => $item ) {
			if ( isset( $item['url'] ) && $item['url'] === $image_url ) {
				$queue[ $key ]['attempts'] = isset( $item['attempts'] ) ? (int) $item['attempts'] + 1 : 1;
			}
		}

		update_option( 'surfer_image_download_queue', $queue, false );
		surfer_release_lock( 'image_queue' );
	}

	/**
	 * Removes downloaded images from the queue, and the failed ones out of attempts.
	 *
	 * Posts imported while the image was being downloaded were added to its entry after
	 * the batch was leased - the entry stays for them, and the next run only points them
	 * to the attachment that already exists.
	 *
	 * A file that is not an image or is too large will not change on the next attempt,
	 * so it is not retried: a non-image is dropped and posts keep its URL, a too large
	 * image is replaced with the placeholder right away.
	 *
	 * @param array $results - map of image URL => result: `ok`, `not_image`, `too_large` or `error`.
	 * @param array $processed - map of image URL => IDs of posts handled in this run.
	 * @return array - queue left to process.
	 */
	private function release_batch( $results, $processed ) {

		// Writing without the lock could drop entries queued in the meantime. The queue is
		// left as it is instead - leases expire, and the next run finds the attachments
		// that already exist instead of downloading the images again.
		if ( ! surfer_acquire_lock( 'image_queue' ) ) {
			return (array) get_option( 'surfer_image_download_queue', array() );
		}

		$queue     = get_option( 'surfer_image_download_queue', array() );
		$new_queue = array();
		$failed    = array();
		$too_large = array();

		foreach ( (array) $queue as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}

			if ( ! array_key_exists( $item['url'], $results ) ) {
				$new_queue[] = $item;
				continue;
			}

			unset( $item['processing_at'] );

			if ( 'not_image' === $results[ $item['url'] ] ) {
				continue;
			}

			if ( 'too_large' === $results[ $item['url'] ] ) {
				$too_large[] = $item;
				continue;
			}

			if ( 'ok' === $results[ $item['url'] ] ) {
				$handled_post_ids = isset( $processed[ $item['url'] ] ) ? $processed[ $item['url'] ] : array();
				$new_post_ids     = array_values( array_diff( $this->get_item_post_ids( $item ), $handled_post_ids ) );

				if ( ! empty( $new_post_ids ) ) {
					$item['post_ids'] = $new_post_ids;
					$item['attempts'] = 0;
					$new_queue[]      = $item;
				}

				continue;
			}

			// Give up eventually, so one broken URL does not keep the queue alive forever.
			// No `attempts` means none was counted (the lock was busy in `count_attempt()`),
			// not that the attempts are used up.
			if ( (int) ( isset( $item['attempts'] ) ? $item['attempts'] : 0 ) < self::MAX_ATTEMPTS ) {
				$new_queue[] = $item;
				continue;
			}

			$failed[] = $item;
		}

		update_option( 'surfer_image_download_queue', $new_queue, false );
		surfer_release_lock( 'image_queue' );

		foreach ( $failed as $item ) {
			$this->mark_image_as_failed( $item['url'], $this->get_item_post_ids( $item ) );
		}

		foreach ( $too_large as $item ) {
			$this->mark_image_as_failed( $item['url'], $this->get_item_post_ids( $item ), 'image is too large, replaced with a placeholder' );
		}

		return $new_queue;
	}

	/**
	 * Stops processing an image that killed the worker too many times.
	 *
	 * The attachment may already be in the media library (the worker died after creating
	 * it, e.g. while generating image sizes) - then posts are pointed to it, and only
	 * otherwise the image is replaced with the placeholder. Nothing is downloaded here,
	 * so this cannot kill the worker again.
	 *
	 * @param string $image_url - URL of the image.
	 * @param int[]  $post_ids - IDs of posts the image was queued for.
	 * @return void
	 */
	private function give_up_on_image( $image_url, $post_ids ) {

		$attachment_id = surfer_find_attachment_by_original_url( $image_url );
		$new_url       = $attachment_id ? wp_get_attachment_url( $attachment_id ) : false;

		if ( ! $new_url ) {
			$this->mark_image_as_failed( $image_url, $post_ids );
			return;
		}

		foreach ( $this->find_target_posts( $image_url, $post_ids ) as $post_id ) {
			$this->replace_image_in_post_content( $post_id, $image_url, $new_url, $attachment_id );
			$this->replace_image_in_elementor_data( $post_id, $image_url, $new_url, $attachment_id );
		}

		surfer_log_image_import_error(
			$image_url,
			'import did not finish after ' . self::MAX_ATTEMPTS . ' attempts, posts point to attachment ' . $attachment_id
		);
	}

	/**
	 * Replaces an image that could not be imported with the plugin placeholder.
	 *
	 * Leaving the Surfer URL in the content would hide the problem - the post looks fine,
	 * the media library is empty, and the image disappears once Surfer removes the file.
	 *
	 * @param string      $image_url - URL of the image that could not be imported.
	 * @param int[]       $post_ids - IDs of posts the image was queued for.
	 * @param string|null $reason - message to log, null for the attempts limit.
	 * @return void
	 */
	private function mark_image_as_failed( $image_url, $post_ids, $reason = null ) {

		$failed_image_url = surfer_get_failed_image_url();

		foreach ( $this->find_target_posts( $image_url, $post_ids ) as $post_id ) {
			$this->replace_image_in_post_content( $post_id, $image_url, $failed_image_url, 0 );
			$this->replace_image_in_elementor_data( $post_id, $image_url, $failed_image_url, 0 );
		}

		surfer_log_image_import_error(
			$image_url,
			null !== $reason ? $reason : 'gave up after ' . self::MAX_ATTEMPTS . ' attempts, image replaced with a placeholder'
		);
	}

	/**
	 * Download image and replace URLs in posts.
	 *
	 * @param string $image_url - URL of the image.
	 * @param string $image_alt - Alt text for the image.
	 * @param int[]  $post_ids - IDs of posts the image was queued for.
	 * @return string - `ok` when the image is in the media library and posts are updated,
	 *                  or when no post uses the image anymore; otherwise `not_image`,
	 *                  `too_large` or `error` - see surfer_download_image().
	 */
	private function download_and_replace_image( $image_url, $image_alt, $post_ids ) {

		$target_post_ids = $this->find_target_posts( $image_url, $post_ids );

		// The image was removed from the posts (or the posts were deleted) before the
		// queue got to it - there is nothing to download it for.
		if ( empty( $target_post_ids ) ) {
			return 'ok';
		}

		// The image may have been imported in the meantime (duplicated queue entry,
		// another post using the same image) - do not download it twice.
		$attachment_id = surfer_find_attachment_by_original_url( $image_url );

		if ( ! $attachment_id ) {
			$result = $this->download_image_to_media_library( $image_url, $image_alt );

			if ( 'ok' !== $result['status'] || empty( $result['attachment_id'] ) ) {
				return 'ok' === $result['status'] ? 'error' : $result['status'];
			}

			$attachment_id = $result['attachment_id'];
		} else {
			surfer_update_image_alt( $attachment_id, $image_alt );
		}

		$new_url = wp_get_attachment_url( $attachment_id );

		if ( ! $new_url ) {
			return 'error';
		}

		foreach ( $target_post_ids as $post_id ) {
			$this->replace_image_in_post_content( $post_id, $image_url, $new_url, $attachment_id );
			$this->replace_image_in_elementor_data( $post_id, $image_url, $new_url, $attachment_id );
		}

		return 'ok';
	}

	/**
	 * Picks the posts the image was queued for that still use it.
	 *
	 * Only `src` of `<img>` tags in the content and image widgets in Elementor data count -
	 * the same URL in a link or in the text is not an image to import.
	 *
	 * @param string $image_url - URL of the image.
	 * @param int[]  $post_ids - IDs of posts the image was queued for.
	 * @return int[] - IDs of posts to update.
	 */
	private function find_target_posts( $image_url, $post_ids ) {

		$target_post_ids = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
				continue;
			}

			if ( surfer_content_has_img_src( (string) $post->post_content, $image_url ) ) {
				$target_post_ids[] = (int) $post->ID;
				continue;
			}

			$elements = $this->get_elementor_elements( (int) $post->ID );

			if ( is_array( $elements ) && $this->elementor_data_has_image( $elements, $image_url ) ) {
				$target_post_ids[] = (int) $post->ID;
			}
		}

		return $target_post_ids;
	}

	/**
	 * Returns decoded Elementor data of the post.
	 *
	 * Elementor stores JSON with escaped slashes (`https:\/\/`), so the URL can be compared
	 * only after decoding.
	 *
	 * @param int $post_id - ID of the post.
	 * @return array|null - null when the post has no Elementor data or it cannot be decoded.
	 */
	private function get_elementor_elements( $post_id ) {

		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $elementor_data ) || '' === $elementor_data ) {
			return null;
		}

		$elements = json_decode( $elementor_data, true );

		return is_array( $elements ) ? $elements : null;
	}

	/**
	 * Checks if any Elementor image widget uses the image.
	 *
	 * @param array  $elements - Elementor elements.
	 * @param string $image_url - URL of the image.
	 * @return bool
	 */
	private function elementor_data_has_image( $elements, $image_url ) {

		foreach ( $elements as $element ) {
			if ( isset( $element['settings']['image']['url'] ) && $element['settings']['image']['url'] === $image_url ) {
				return true;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->elementor_data_has_image( $element['elements'], $image_url ) ) {
				return true;
			}
		}

		return false;
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

		if ( ! is_string( $content ) ) {
			return;
		}

		$updated_content = surfer_replace_img_src( $content, $image_url, $new_url );

		if ( $updated_content === $content ) {
			return;
		}

		$content = $updated_content;

		if ( $attachment_id > 0 ) {
			// `src` holds the URL escaped the same way.
			$content = $this->link_gutenberg_image_blocks( $content, esc_url( $new_url ), $attachment_id );
		}

		// The worker runs in WP-Cron without a logged-in user, so kses would strip markup the
		// author is allowed to use (Custom HTML block with an iframe or a script). The content
		// comes from the database and only `src` changed (escaped with `esc_url()`), so it is
		// saved without kses.
		$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		if ( $had_kses ) {
			kses_remove_filters();
		}

		try {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $content ),
				)
			);
		} finally {
			if ( $had_kses ) {
				kses_init_filters();
			}
		}
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

		if ( ! is_string( $elementor_data ) || '' === $elementor_data ) {
			return;
		}

		$elements = $this->get_elementor_elements( $post_id );

		if ( null === $elements ) {
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
	 * @return array{status: string, attachment_id?: int} - see surfer_download_image().
	 */
	private function download_image_to_media_library( $image_url, $image_alt ) {
		return surfer_download_image( (string) $image_url, (string) $image_alt );
	}
}
