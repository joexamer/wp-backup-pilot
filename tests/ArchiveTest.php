<?php
/**
 * Archive validation tests.
 *
 * @package WPBackupPilot
 */

require_once dirname( __DIR__ ) . '/includes/class-wpbp-archive.php';

class WPBP_ArchiveTest extends PHPUnit\Framework\TestCase {
	private $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/wpbp-test-' . uniqid( '', true );
		mkdir( $this->temp_dir, 0777, true );
	}

	protected function tearDown(): void {
		$this->remove_directory( $this->temp_dir );
		parent::tearDown();
	}

	public function test_inspect_rejects_package_without_manifest() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$zip_path = $this->temp_dir . '/missing-manifest.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'database.sql', '-- sql' );
		$zip->close();

		$result = ( new WPBP_Archive() )->inspect( $zip_path );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpbp_zip_missing_entry', $result->get_error_code() );
	}

	public function test_inspect_accepts_valid_minimal_package() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$zip_path = $this->temp_dir . '/valid.zip';
		$manifest = array(
			'source'   => array(
				'home_url' => 'https://source.test',
			),
			'includes' => array(
				'database' => true,
				'files'    => true,
			),
		);

		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'manifest.json', json_encode( $manifest ) );
		$zip->addFromString( 'database.sql', '-- sql' );
		$zip->addFromString( 'files/wp-content/uploads/example.txt', 'demo' );
		$zip->close();

		$result = ( new WPBP_Archive() )->inspect( $zip_path );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://source.test', $result['manifest']['source']['home_url'] );
		$this->assertSame( $zip_path, $result['path'] );
	}

	private function remove_directory( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $path );
	}
}
