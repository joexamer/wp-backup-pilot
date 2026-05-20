<?php
/**
 * Optional S3-compatible remote storage.
 *
 * @package WPBackupPilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPBP_Remote_Storage {
	/**
	 * Upload a package if remote storage is enabled.
	 *
	 * @param string $path Local file.
	 * @return true|WP_Error
	 */
	public function maybe_upload( $path ) {
		$settings = WPBP_Settings::get();
		if ( empty( $settings['remote_enabled'] ) ) {
			return true;
		}

		foreach ( array( 'remote_endpoint', 'remote_region', 'remote_bucket', 'remote_access_key', 'remote_secret_key' ) as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				return new WP_Error( 'wpbp_remote_incomplete', __( 'Remote storage is enabled but S3 settings are incomplete.', 'backup-pilot' ) );
			}
		}

		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'wpbp_remote_file_missing', __( 'Backup package is not readable for remote upload.', 'backup-pilot' ) );
		}

		$key    = trim( $settings['remote_prefix'], '/' ) . '/' . basename( $path );
		$result = $this->put_object( $settings, $key, file_get_contents( $path ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $settings['remote_delete_local'] ) ) {
			WPBP_Filesystem::delete_file( $path );
		}

		return true;
	}

	/**
	 * Test remote storage by uploading a small probe object.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$settings = WPBP_Settings::get();
		if ( empty( $settings['remote_enabled'] ) ) {
			return new WP_Error( 'wpbp_remote_disabled', __( 'Remote storage is not enabled.', 'backup-pilot' ) );
		}

		foreach ( array( 'remote_endpoint', 'remote_region', 'remote_bucket', 'remote_access_key', 'remote_secret_key' ) as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				return new WP_Error( 'wpbp_remote_incomplete', __( 'Remote storage settings are incomplete.', 'backup-pilot' ) );
			}
		}

		$key = trim( $settings['remote_prefix'], '/' ) . '/connection-test-' . gmdate( 'Ymd-His' ) . '.txt';
		return $this->put_object( $settings, $key, 'Backup Pilot connection test ' . gmdate( 'c' ) );
	}

	/**
	 * PUT object using AWS Signature V4.
	 *
	 * @param array  $settings Settings.
	 * @param string $key Object key.
	 * @param string $body Body.
	 * @return true|WP_Error
	 */
	private function put_object( array $settings, $key, $body ) {
		$endpoint = untrailingslashit( $settings['remote_endpoint'] );
		$bucket   = $settings['remote_bucket'];
		$region   = $settings['remote_region'];
		$host     = wp_parse_url( $endpoint, PHP_URL_HOST );
		$scheme   = wp_parse_url( $endpoint, PHP_URL_SCHEME ) ?: 'https';
		$encoded  = str_replace( '%2F', '/', rawurlencode( $key ) );

		if ( ! empty( $settings['remote_path_style'] ) ) {
			$url = $endpoint . '/' . rawurlencode( $bucket ) . '/' . $encoded;
			$uri = '/' . rawurlencode( $bucket ) . '/' . $encoded;
		} else {
			$host = $bucket . '.' . $host;
			$url  = $scheme . '://' . $host . '/' . $encoded;
			$uri  = '/' . $encoded;
		}

		$payload_hash = hash( 'sha256', $body );
		$amz_date     = gmdate( 'Ymd\THis\Z' );
		$date         = gmdate( 'Ymd' );
		$scope        = $date . '/' . $region . '/s3/aws4_request';
		$headers      = array(
			'host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $amz_date,
		);

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( $value ) . "\n";
		}

		$canonical_request        = "PUT\n" . $uri . "\n\n" . $canonical_headers . "\n" . implode( ';', array_keys( $headers ) ) . "\n" . $payload_hash;
		$string_to_sign           = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
		$signature                = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $settings['remote_secret_key'], $date, $region ) );
		$headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $settings['remote_access_key'] . '/' . $scope . ', SignedHeaders=' . implode( ';', array( 'host', 'x-amz-content-sha256', 'x-amz-date' ) ) . ', Signature=' . $signature;

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		/* translators: %d: HTTP response status code. */
		return $code >= 200 && $code < 300 ? true : new WP_Error( 'wpbp_remote_upload_failed', sprintf( __( 'Remote upload failed with HTTP %d.', 'backup-pilot' ), $code ) );
	}

	/**
	 * Build signing key.
	 *
	 * @param string $secret Secret.
	 * @param string $date Date.
	 * @param string $region Region.
	 * @return string
	 */
	private function signing_key( $secret, $date, $region ) {
		$key = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$key = hash_hmac( 'sha256', $region, $key, true );
		$key = hash_hmac( 'sha256', 's3', $key, true );
		return hash_hmac( 'sha256', 'aws4_request', $key, true );
	}
}
