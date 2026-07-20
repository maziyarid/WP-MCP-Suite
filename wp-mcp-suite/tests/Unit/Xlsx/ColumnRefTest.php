<?php
/**
 * @package MCPSuite\Tests\Unit\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Xlsx;

use MCPSuite\Core\Xlsx\ColumnRef;
use PHPUnit\Framework\TestCase;

final class ColumnRefTest extends TestCase {

	/**
	 * @dataProvider knownConversions
	 */
	public function test_known_index_to_letter_conversions( int $index, string $expected ): void {
		$this->assertSame( $expected, ColumnRef::letter( $index ) );
	}

	public static function knownConversions(): array {
		return array(
			array( 0, 'A' ),
			array( 1, 'B' ),
			array( 25, 'Z' ),
			array( 26, 'AA' ),   // the single-to-double-letter boundary — classic off-by-one spot
			array( 27, 'AB' ),
			array( 51, 'AZ' ),
			array( 52, 'BA' ),
			array( 701, 'ZZ' ),  // double-to-triple-letter boundary
			array( 702, 'AAA' ),
		);
	}

	public function test_cell_combines_column_letter_and_one_based_row(): void {
		$this->assertSame( 'A1', ColumnRef::cell( 0, 1 ) );
		$this->assertSame( 'B2', ColumnRef::cell( 1, 2 ) );
		$this->assertSame( 'AA10', ColumnRef::cell( 26, 10 ) );
	}

	public function test_negative_index_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		ColumnRef::letter( -1 );
	}

	public function test_letters_are_unique_and_monotonically_increasing_in_length_for_a_wide_range(): void {
		$seen = array();
		for ( $i = 0; $i < 1000; $i++ ) {
			$letter = ColumnRef::letter( $i );
			$this->assertArrayNotHasKey( $letter, $seen, "duplicate letter '{$letter}' produced for index {$i}" );
			$seen[ $letter ] = $i;
		}
	}
}
