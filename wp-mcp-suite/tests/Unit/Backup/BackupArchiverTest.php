<?php
/**
 * @package MCPSuite\Tests\Unit\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Backup;

use MCPSuite\Modules\Backup\BackupArchiver;
use MCPSuite\Modules\Backup\BackupException;
use PHPUnit\Framework\TestCase;

final class BackupArchiverTest extends TestCase {

	private BackupArchiver $archiver;

	protected function setUp(): void {
		parent::setUp();
		$this->archiver = new BackupArchiver();
	}

	public function test_compress_then_decompress_round_trips_to_the_original(): void {
		$sql = "CREATE TABLE wp_posts (...);\nINSERT INTO wp_posts VALUES (1, 'test');\n";

		$compressed = $this->archiver->compress( $sql );
		$decompressed = $this->archiver->decompress( $compressed );

		$this->assertSame( $sql, $decompressed );
	}

	public function test_compressed_output_is_smaller_than_input_for_repetitive_sql(): void {
		$sql = str_repeat( "INSERT INTO wp_postmeta VALUES (1, 'meta_key', 'meta_value');\n", 500 );

		$compressed = $this->archiver->compress( $sql );

		$this->assertLessThan( strlen( $sql ), strlen( $compressed ) );
	}

	public function test_compressed_output_starts_with_the_gzip_magic_bytes(): void {
		$compressed = $this->archiver->compress( 'test data' );
		$this->assertSame( "\x1f\x8b", substr( $compressed, 0, 2 ) );
	}

	public function test_decompressing_corrupt_data_throws_rather_than_returning_garbage(): void {
		$this->expectException( BackupException::class );
		$this->archiver->decompress( 'this is not gzip data at all' );
	}

	public function test_empty_string_round_trips_correctly(): void {
		$compressed = $this->archiver->compress( '' );
		$this->assertSame( '', $this->archiver->decompress( $compressed ) );
	}

	public function test_persian_utf8_content_round_trips_correctly(): void {
		// Real payload will include Persian post content from the actual
		// database dump, so this is a genuine correctness requirement.
		$sql = "INSERT INTO wp_posts (post_title) VALUES ('راهنمای ریکاوری بعد از رینوپلاستی');\n";

		$compressed = $this->archiver->compress( $sql );
		$this->assertSame( $sql, $this->archiver->decompress( $compressed ) );
	}
}
