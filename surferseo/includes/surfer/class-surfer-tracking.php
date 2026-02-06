<?php
/**
 *  Object that manage tracking functions
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SurferSEO\Surferseo;

/**
 * Object responsible for handling tracking functions
 */
class Surfer_Tracking {


	/**
	 * Object construct.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Init function.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'notify_tracking_question' ) );
		add_action( 'admin_init', array( $this, 'save_dismissal_of_surfer_notification' ) );
		add_action( 'admin_init', array( $this, 'save_permission_allowed' ) );

		add_action( 'wp_ajax_surfer_track_keyword_research_usage', array( $this, 'track_keyword_research_usage' ) );
		add_action( 'wp_ajax_surfer_track_event', array( $this, 'track_custom_event' ) );
		add_action( 'wp_ajax_nopriv_surfer_track_event', array( $this, 'track_custom_event' ) );

		$this->track_utm_events();
	}

	/**
	 * Checks if tracking is enabled.
	 *
	 * @return boolean
	 */
	public function is_tracking_allowed() {

		$tracking_enabled = Surfer()->get_surfer_settings()->get_option( 'content-importer', 'surfer_tracking_enabled', false );
		if ( isset( $tracking_enabled ) && 1 === intval( $tracking_enabled ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Displays notification asking for tracking permission.
	 *
	 * @return void
	 */
	public function notify_tracking_question() {
		$connected = Surfer()->get_surfer()->is_surfer_connected();
		if ( ! $connected ) {
			return;
		}

		if ( $this->is_tracking_allowed() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'surfer-dismiss-and-save', 'tracking_question' ),
			'surfer_dismiss_notification'
		);

		$permission_allowed_url = wp_nonce_url(
			add_query_arg( 'surfer_enable_tracking', '1' ),
			'surfer_dismiss_notification'
		);

		$dismissals = (array) get_option( 'surfer_notification_dismissals' );

		?>
		<?php if ( ! in_array( 'tracking_question', $dismissals, true ) ) : ?>
		<div class="notice surfer-notice surfer-layout is-dismissible">
			<h3><?php esc_html_e( 'Help us make Surfer’s WordPress plugin better', 'surferseo' ); ?></h3>
			<span class="surfer-notice_multiline-paragraphs">
				<p><?php esc_html_e( 'Help us improve! We\'d like to analyze how you use the tool to see which features are most helpful. Don\'t worry, it\'s completely anonymous (and no, we can\'t see your Amazon wishlist 😉). We\'re mostly interested in things like what version of PHP or WordPress you\'re using. This helps us make decisions for future plugin updates.', 'surferseo' ); ?></p>
				<p><?php esc_html_e( 'What do you say?', 'surferseo' ); ?></p>
				<?php /* translators: %s: Surfer settings URL */ ?>
				<p><?php printf( wp_kses_post( __( 'Don\'t worry! You can turn off this feature at any time in <a href="%s">Surfer’s WordPress plugin settings</a>.', 'surferseo' ) ), esc_url( admin_url( 'admin.php?page=surfer' ) ) ); ?></p>
			</span>
			<span class="surfer-notice_action_buttons">
				<a href="<?php echo esc_url( $permission_allowed_url ); ?>" class="surfer-button surfer-button--primary surfer-button--small surfer-button--icon-left surfer-analytics" data-event-name="banner_enable_tracking" data-event-data="tracking_enabled" data-tracking-enabling="true" >
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="21" viewBox="0 0 20 21" fill="currentColor">
						<path fill-rule="evenodd" clip-rule="evenodd" d="M16.7045 4.43777C17.034 4.6888 17.0976 5.1594 16.8466 5.48887L8.84657 15.9889C8.71541 16.161 8.51627 16.2681 8.30033 16.2827C8.08439 16.2972 7.87271 16.2177 7.71967 16.0647L3.21967 11.5647C2.92678 11.2718 2.92678 10.7969 3.21967 10.504C3.51256 10.2111 3.98744 10.2111 4.28033 10.504L8.17351 14.3972L15.6534 4.57981C15.9045 4.25033 16.3751 4.18674 16.7045 4.43777Z" fill="white"/>
					</svg>

					<?php esc_html_e( 'Allow us to analyze usage data ', 'surferseo' ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="surfer-button surfer-button--secondary surfer-button--small surfer-button--icon-left">
					<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>

					<?php esc_html_e( 'Don\'t allow', 'surferseo' ); ?>
				</a>
			</span>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the dismissal of the tracking question.
	 *
	 * @return void
	 */
	public function save_dismissal_of_surfer_notification() {

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'surfer_dismiss_notification' ) ) {
			return;
		}

		if ( ! isset( $_GET['surfer-dismiss-and-save'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dismissals = get_option( 'surfer_notification_dismissals' );

		if ( ! is_array( $dismissals ) ) {
			$dismissals = array();
		}

		$dismissals[] = sanitize_text_field( wp_unslash( $_GET['surfer-dismiss-and-save'] ) );

		update_option( 'surfer_notification_dismissals', $dismissals );

		$redirect_url = remove_query_arg( array( 'surfer-dismiss-and-save', '_wpnonce' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Enable tracking from GET param and redirect to Surfer config.
	 *
	 * @return void
	 */
	public function save_permission_allowed() {

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'surfer_dismiss_notification' ) ) {
			return;
		}

		if ( ! isset( $_GET['surfer_enable_tracking'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 1 !== (int) $_GET['surfer_enable_tracking'] ) {
			return;
		}

		Surfer()->get_surfer_settings()->save_option( 'content-importer', 'surfer_tracking_enabled', true );
		wp_safe_redirect( admin_url( 'admin.php?page=surfer#header_tracking' ) );
		exit;
	}

	/**
	 * Function to track user environment.
	 *
	 * @return string | boolean
	 */
	public function track_wp_environment() {
		if ( ! $this->is_tracking_allowed() ) {
			echo 'Tracking disabled';
			return false;
		}

		$tracking_data = $this->get_general_tracking_data();

		$api_url = Surfer()->get_surfer()->get_api_url() . '/track_environment';
		$token   = get_option( 'wpsurfer_api_access_key', false );

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
				'api-key'      => $token,
			),
			'body'    => wp_json_encode( $tracking_data ),
		);

		$result = wp_remote_request( $api_url, $args );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return $result['body'];
	}


	/**
	 * Retrieves the data to send in the usage tracking.
	 *
	 * @return array An array of data to send.
	 */
	protected function get_general_tracking_data() {
		$theme_data = wp_get_theme();

		return array(
			// Generic data (environment).
			'url'                       => home_url(),
			'php_version'               => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'wp_version'                => get_bloginfo( 'version' ),
			'server_version'            => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'is_ssl'                    => is_ssl(),
			'is_multisite'              => is_multisite(),
			'sites_count'               => function_exists( 'get_blog_count' ) ? (int) get_blog_count() : 1,
			'active_plugins'            => $this->get_active_plugins(),
			'theme_name'                => $theme_data->name,
			'theme_version'             => $theme_data->version,
			'user_count'                => function_exists( 'get_user_count' ) ? get_user_count() : null,
			'locale'                    => get_locale(),
			'email'                     => get_bloginfo( 'admin_email' ),
			// Surfer specific data.
			'surfer_version'            => SURFER_VERSION,
			'surfer_gsc_is_hidden'      => Surfer()->get_surfer()->get_gsc()->check_if_admin_hide_gsc_column(),
			'surfer_email_notification' => Surfer()->get_surfer()->get_gsc()->performance_report_email_notification_enabled(),
		);
	}

	/**
	 * Return a list of active plugins.
	 *
	 * @return array An array of active plugin data.
	 */
	public function get_active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			include ABSPATH . '/wp-admin/includes/plugin.php';
		}
		$active  = get_option( 'active_plugins', array() );
		$plugins = array_intersect_key( get_plugins(), array_flip( $active ) );

		return array_map(
			static function ( $plugin ) {
				if ( isset( $plugin['Version'] ) ) {
					return $plugin['Name'] . ' - ' . $plugin['Version'];
				}

				return $plugin['Name'] . ' - Not Set';
			},
			$plugins
		);
	}

	/**
	 * Function to track event.
	 *
	 * @param string $event_name Event type.
	 * @param string $event_type Event name.
	 * @param bool   $force_push Force push.
	 *
	 * @return string | boolean
	 */
	public function track_wp_event( $event_name, $event_type, $force_push = false ) {

		if ( ! $force_push && ! $this->is_tracking_allowed() ) {
			return 'Tracking Disabled';
		}

		$tracking_data = array(
			'event_type' => $event_type,
			'event_name' => $event_name,
			'url'        => home_url(),
		);

		$api_url = Surfer()->get_surfer()->get_api_url() . '/track_event';
		$token   = get_option( 'wpsurfer_api_access_key', false );

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
				'api-key'      => $token,
			),
			'body'    => wp_json_encode( $tracking_data ),
		);

		return wp_remote_request( $api_url, $args );
	}

	/**
	 * Function to track keyword research usage.
	 *
	 * @return void
	 */
	public function track_keyword_research_usage() {

		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$keyword  = $data->keyword;
		$location = $data->location;

		$result = $this->track_wp_event( 'keyword_research_usage', 'search for: ' . $keyword . ' in ' . $location );

		echo wp_json_encode( $result );
		wp_die();
	}

	/**
	 * Allows to track custom event from React.
	 *
	 * @return void
	 */
	public function track_custom_event() {

		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json );

		if ( ! surfer_validate_custom_request( $data->_surfer_nonce ) ) {
			echo wp_json_encode( array( 'message' => 'Security check failed.' ) );
			wp_die();
		}

		$event_name = $data->event_name;
		$event_data = $data->event_data;

		$result = $this->track_wp_event( $event_name, $event_data, $data->force_push );

		echo wp_json_encode( $result );
		wp_die();
	}

	/**
	 * Function to track UTM events.
	 *
	 * @return void
	 */
	private function track_utm_events() {

		if ( Surfer()->get_surfer_tracking()->is_tracking_allowed() ) {

			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'surfer_utm_events' ) ) {
				return;
			}

			if ( isset( $_GET['utm_surfer'] ) ) {
				$utm_surfer = sanitize_text_field( wp_unslash( $_GET['utm_surfer'] ) );

				$this->track_wp_event( 'utm_surfer', $utm_surfer );
			}
		}
	}
}
