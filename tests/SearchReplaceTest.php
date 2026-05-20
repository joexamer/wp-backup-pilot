<?php
/**
 * Search/replace tests.
 *
 * @package WPBackupPilot
 */

// phpcs:ignoreFile -- PHPUnit tests run outside the WordPress admin runtime.

require_once dirname( __DIR__ ) . '/includes/class-wpbp-search-replace.php';

class WPBP_SearchReplaceTest extends PHPUnit\Framework\TestCase {
	public function test_replaces_plain_string_value() {
		$replace = new WPBP_Search_Replace();
		$method  = new ReflectionMethod( $replace, 'replace_value' );
		$method->setAccessible( true );

		$this->assertSame(
			'https://new.test/page',
			$method->invoke( $replace, 'https://old.test/page', 'https://old.test', 'https://new.test' )
		);
	}

	public function test_replaces_serialized_array_without_breaking_lengths() {
		$replace = new WPBP_Search_Replace();
		$method  = new ReflectionMethod( $replace, 'replace_value' );
		$method->setAccessible( true );

		$original = serialize(
			array(
				'home'   => 'https://old.test',
				'nested' => array(
					'asset' => 'https://old.test/uploads/image.jpg',
				),
			)
		);

		$result = $method->invoke( $replace, $original, 'https://old.test', 'https://new-example.test' );
		$value  = unserialize( $result );

		$this->assertSame( 'https://new-example.test', $value['home'] );
		$this->assertSame( 'https://new-example.test/uploads/image.jpg', $value['nested']['asset'] );
	}
}
