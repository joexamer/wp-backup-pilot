<?php
/**
 * PHPUnit bootstrap.
 *
 * Uses the WordPress test suite when WP_TESTS_DIR is available. Otherwise it
 * defines a small set of WordPress-compatible shims for isolated unit tests.
 *
 * @package WPBackupPilot
 */

if ( getenv( 'WP_TESTS_DIR' ) ) {
	require getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data ) {
		if ( ! is_string( $data ) ) {
			return false;
		}

		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}

		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}

		$token = $data[0];
		if ( ! in_array( $token, array( 's', 'a', 'O', 'i', 'b', 'd' ), true ) ) {
			return false;
		}

		return false !== @unserialize( $data );
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		return is_serialized( $data ) ? @unserialize( trim( $data ) ) : $data;
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}

		if ( is_serialized( $data ) ) {
			return serialize( $data );
		}

		return $data;
	}
}
