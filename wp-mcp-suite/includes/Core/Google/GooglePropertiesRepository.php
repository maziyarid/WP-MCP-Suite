<?php
/**
 * Storage for the Google property identifiers Search Console and GA4 need
 * in addition to the shared service-account credentials: which GSC
 * property (site URL or domain property) and which GA4 property ID to
 * query. These are not secrets, so they're stored as a plain option rather
 * than going through Encryption.
 *
 * @package MCPSuite\Core\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Google;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GooglePropertiesRepository {

	private const OPTION_KEY = 'mcp_suite_google_properties';

	public function get_gsc_site_url(): string {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? (string) ( $stored['gsc_site_url'] ?? '' ) : '';
	}

	public function get_ga4_property_id(): string {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? (string) ( $stored['ga4_property_id'] ?? '' ) : '';
	}

	public function save( string $gsc_site_url, string $ga4_property_id ): bool {
		return update_option(
			self::OPTION_KEY,
			array(
				'gsc_site_url'    => sanitize_text_field( $gsc_site_url ),
				'ga4_property_id' => sanitize_text_field( $ga4_property_id ),
			)
		);
	}
}
