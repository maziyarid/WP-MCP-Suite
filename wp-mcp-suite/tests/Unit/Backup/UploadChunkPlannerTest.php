<?php
/**
 * @package MCPSuite\Tests\Unit\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Backup;

use MCPSuite\Modules\Backup\UploadChunkPlanner;
use PHPUnit\Framework\TestCase;

final class UploadChunkPlannerTest extends TestCase {

	private UploadChunkPlanner $planner;

	protected function setUp(): void {
		parent::setUp();
		$this->planner = new UploadChunkPlanner();
	}

	private const ALIGNED_CHUNK = UploadChunkPlanner::GRAPH_CHUNK_ALIGNMENT * 10; // 3,276,800 bytes

	public function test_file_smaller_than_one_chunk_produces_a_single_chunk_covering_the_whole_file(): void {
		$chunks = $this->planner->plan( 1000, self::ALIGNED_CHUNK );

		$this->assertCount( 1, $chunks );
		$this->assertSame( 0, $chunks[0]->start );
		$this->assertSame( 999, $chunks[0]->end );
		$this->assertSame( 1000, $chunks[0]->length );
		$this->assertTrue( $chunks[0]->is_last() );
	}

	public function test_file_exactly_one_chunk_size_produces_a_single_chunk(): void {
		$chunks = $this->planner->plan( self::ALIGNED_CHUNK, self::ALIGNED_CHUNK );

		$this->assertCount( 1, $chunks );
		$this->assertSame( 0, $chunks[0]->start );
		$this->assertSame( self::ALIGNED_CHUNK - 1, $chunks[0]->end );
	}

	public function test_file_one_byte_larger_than_one_chunk_produces_two_chunks(): void {
		$total  = self::ALIGNED_CHUNK + 1;
		$chunks = $this->planner->plan( $total, self::ALIGNED_CHUNK );

		$this->assertCount( 2, $chunks );
		$this->assertSame( self::ALIGNED_CHUNK, $chunks[0]->length );
		$this->assertSame( 1, $chunks[1]->length );
		$this->assertTrue( $chunks[1]->is_last() );
		$this->assertFalse( $chunks[0]->is_last() );
	}

	public function test_chunks_have_no_gaps_and_no_overlaps_across_many_sizes(): void {
		foreach ( array( 1, 100, self::ALIGNED_CHUNK - 1, self::ALIGNED_CHUNK, self::ALIGNED_CHUNK + 1, self::ALIGNED_CHUNK * 3 + 12345 ) as $total_size ) {
			$chunks = $this->planner->plan( $total_size, self::ALIGNED_CHUNK );

			$expected_next_start = 0;
			foreach ( $chunks as $chunk ) {
				$this->assertSame( $expected_next_start, $chunk->start, "gap or overlap at total_size={$total_size}" );
				$this->assertSame( $chunk->start + $chunk->length - 1, $chunk->end );
				$expected_next_start = $chunk->end + 1;
			}

			$this->assertSame( $total_size, $expected_next_start, "chunks did not cover the full file for total_size={$total_size}" );
		}
	}

	public function test_only_the_last_chunk_is_flagged_is_last(): void {
		$chunks = $this->planner->plan( self::ALIGNED_CHUNK * 3, self::ALIGNED_CHUNK );

		$this->assertCount( 3, $chunks );
		$this->assertFalse( $chunks[0]->is_last() );
		$this->assertFalse( $chunks[1]->is_last() );
		$this->assertTrue( $chunks[2]->is_last() );
	}

	public function test_content_range_header_format_matches_graphs_documented_syntax(): void {
		$chunks = $this->planner->plan( 1000, self::ALIGNED_CHUNK );
		$this->assertSame( 'bytes 0-999/1000', $chunks[0]->content_range_header() );
	}

	public function test_multi_chunk_content_range_headers_are_correct(): void {
		$total  = self::ALIGNED_CHUNK + 500;
		$chunks = $this->planner->plan( $total, self::ALIGNED_CHUNK );

		$this->assertSame( 'bytes 0-' . ( self::ALIGNED_CHUNK - 1 ) . '/' . $total, $chunks[0]->content_range_header() );
		$this->assertSame( 'bytes ' . self::ALIGNED_CHUNK . '-' . ( $total - 1 ) . '/' . $total, $chunks[1]->content_range_header() );
	}

	public function test_zero_byte_file_produces_no_chunks(): void {
		$this->assertSame( array(), $this->planner->plan( 0, self::ALIGNED_CHUNK ) );
	}

	public function test_negative_total_size_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->planner->plan( -1, self::ALIGNED_CHUNK );
	}

	public function test_chunk_size_not_a_multiple_of_the_graph_alignment_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->planner->plan( 1000, 1000 ); // not a multiple of 327680
	}

	public function test_zero_or_negative_chunk_size_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->planner->plan( 1000, 0 );
	}
}
