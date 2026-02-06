<?php
/**
 * Simple XML logger for Surfer import/export operations.
 *
 * @package SurferSEO
 * @link https://surferseo.com
 */

namespace SurferSEO\Surfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger class for Surfer operations.
 */
class Surfer_Logger {

	/**
	 * Maximum number of logs to keep.
	 */
	const MAX_LOGS = 5;

	/**
	 * Maximum content length to log.
	 */
	const MAX_CONTENT_LENGTH = 50000;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private $log_dir;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir    = wp_upload_dir();
		$this->log_dir = $upload_dir['basedir'] . '/surfer-logs/';

		if ( ! file_exists( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}
	}

	/**
	 * Log import operation.
	 *
	 * @param string $original_content Original content received.
	 * @param string $parsed_content Content after parsing (empty if parsing failed).
	 * @param mixed  $result Result of save operation (true on success, WP_Error on failure).
	 * @param string $error_message Error message if request failed before parsing.
	 * @return void
	 */
	public function log_import( $original_content, $parsed_content = '', $result = null, $error_message = '' ) {
		$this->write_log( 'import', $original_content, $parsed_content, $result, $error_message );
	}

	/**
	 * Log export operation.
	 *
	 * @param string $original_content Original content from WordPress.
	 * @param string $parsed_content Content after parsing for Surfer.
	 * @param mixed  $result Result of export operation (true on success, error message on failure).
	 * @param string $error_message Error message if request failed before parsing.
	 * @return void
	 */
	public function log_export( $original_content, $parsed_content = '', $result = null, $error_message = '' ) {
		$this->write_log( 'export', $original_content, $parsed_content, $result, $error_message );
	}

	/**
	 * Write log entry to XML file.
	 *
	 * @param string $operation_type Type of operation (import/export).
	 * @param string $original_content Original content.
	 * @param string $parsed_content Parsed content.
	 * @param mixed  $result Operation result.
	 * @param string $error_message Error message.
	 * @return void
	 */
	private function write_log( $operation_type, $original_content, $parsed_content, $result, $error_message ) {
		$log_file = $this->log_dir . 'surfer-' . $operation_type . '.xml';

		if ( file_exists( $log_file ) ) {
			$xml = simplexml_load_file( $log_file );
		} else {
			$xml = new \SimpleXMLElement( '<logs></logs>' );
		}

		$entry = $xml->addChild( 'entry' );
		$entry->addChild( 'timestamp', current_time( 'Y-m-d H:i:s' ) );
		$entry->addChild( 'original_content', $this->escape_xml_content( $original_content ) );
		$entry->addChild( 'parsed_content', $this->escape_xml_content( $parsed_content ) );

		if ( ! empty( $error_message ) ) {
			$entry->addChild( 'result', 'error' );
			$entry->addChild( 'error_message', $this->escape_xml_content( $error_message ) );
		} elseif ( is_wp_error( $result ) ) {
			$entry->addChild( 'result', 'error' );
			$entry->addChild( 'error_message', $this->escape_xml_content( $result->get_error_message() ) );
		} elseif ( true === $result ) {
			$entry->addChild( 'result', 'success' );
			$entry->addChild( 'error_message', '' );
		} else {
			$entry->addChild( 'result', 'unknown' );
			$entry->addChild( 'error_message', $this->escape_xml_content( (string) $result ) );
		}

		$this->cleanup_old_entries( $xml );

		$dom               = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.MemberNotSnakeCase
		$dom->loadXML( $xml->asXML() );
		$dom->save( $log_file );
	}

	/**
	 * Remove old entries to keep only MAX_LOGS entries.
	 *
	 * @param \SimpleXMLElement $xml XML object.
	 * @return void
	 */
	private function cleanup_old_entries( $xml ) {
		$entries = $xml->xpath( '//entry' );
		$count   = count( $entries );

		if ( $count > self::MAX_LOGS ) {
			$to_remove = $count - self::MAX_LOGS;
			for ( $i = 0; $i < $to_remove; $i++ ) {
				$dom = dom_import_simplexml( $entries[ $i ] );
				$dom->parentNode->removeChild( $dom ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.MemberNotSnakeCase
			}
		}
	}

	/**
	 * Escape content for XML.
	 *
	 * @param string $content Content to escape.
	 * @return string
	 */
	private function escape_xml_content( $content ) {

		if ( strlen( $content ) > self::MAX_CONTENT_LENGTH ) {
			$content = substr( $content, 0, self::MAX_CONTENT_LENGTH ) . '... [TRUNCATED]';
		}

		return htmlspecialchars( $content, ENT_XML1, 'UTF-8' );
	}

	/**
	 * Get log file path for given operation type.
	 *
	 * @param string $operation_type Operation type (import/export).
	 * @return string
	 */
	public function get_log_file_path( $operation_type ) {
		return $this->log_dir . 'surfer-' . $operation_type . '.xml';
	}
}
