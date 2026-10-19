<?php
/**
 * Is Trusted Surfer URL Validator.
 *
 * @package SurferSEO
 */

namespace SurferSEO\Forms\Validators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validator to check if field points to a trusted Surfer host.
 */
class Validator_Is_Trusted_Surfer_Url implements Surfer_Validator_Interface {

	/**
	 * Validate value.
	 *
	 * Empty value is left to Validator_Is_Required.
	 *
	 * @param mixed $value - value to validate.
	 * @return bool
	 */
	public function validate( $value ) {
		if ( ! isset( $value ) || '' === $value ) {
			return true;
		}

		// The same rule is applied when the saved URL is read, see Surfer::resolve_api_url().
		return surfer_is_trusted_surfer_url( $value );
	}

	/**
	 * Returns error message in case of validation fail.
	 *
	 * @return string
	 */
	public function get_error() {
		return __( 'Surfer URL has to use https and point to surferseo.com or its subdomain.', 'surferseo' );
	}
}
