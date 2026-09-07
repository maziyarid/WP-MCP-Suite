<?php
/**
 * Plugin Name: Tez Portfolio Multilingual
 * Description: Lightweight Persian/English routing, SEO and automation safeguards for the Tez thesis portfolio.
 * Version: 0.2.1
 * Author: MAZ//ID
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: tez-multilingual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tez_Portfolio_Multilingual {
	const VERSION          = '0.2.1';
	const META_LANGUAGE    = '_tez_language';
	const META_TRANSLATION = '_tez_translation_id';
	const QUERY_LANGUAGE   = 'tez_language';
	const QUERY_PATH       = 'tez_en_path';
	const QUERY_SITEMAP    = 'tez_sitemap';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_content_meta' ), 5 );
		add_action( 'init', array( $this, 'register_rewrites' ), 20 );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'resolve_english_request' ), 5 );
		add_action( 'pre_get_posts', array( $this, 'filter_frontend_queries' ), 20 );

		add_filter( 'locale', array( $this, 'filter_locale' ) );
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 20, 2 );
		add_filter( 'body_class', array( $this, 'filter_body_classes' ) );
		add_action( 'wp_head', array( $this, 'print_direction_styles' ), 1 );
		add_action( 'wp_head', array( $this, 'print_hreflang' ), 4 );
		add_filter( 'gettext', array( $this, 'translate_theme_string' ), 20, 3 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_language_menu_items' ), 20, 2 );
		add_filter( 'wp_nav_menu_items', array( $this, 'ensure_english_home_menu_item' ), 30, 2 );
		add_filter( 'theme_mod_teznevise_phone_display', array( $this, 'filter_english_phone' ) );
		add_filter( 'theme_mod_teznevise_hours', array( $this, 'filter_english_hours' ) );
		add_filter( 'option_blogname', array( $this, 'filter_english_site_name' ) );

		add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 3 );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 20, 4 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 3 );
		add_filter( 'redirect_canonical', array( $this, 'filter_canonical_redirect' ), 20, 2 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_rank_math_canonical' ) );
		add_filter( 'rank_math/frontend/title', array( $this, 'filter_rank_math_title' ) );
		add_filter( 'rank_math/frontend/description', array( $this, 'filter_rank_math_description' ) );
		add_filter( 'rank_math/opengraph/facebook/og_url', array( $this, 'filter_rank_math_og_url' ) );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( $this, 'filter_rank_math_social_title' ) );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( $this, 'filter_rank_math_social_description' ) );
		add_filter( 'rank_math/opengraph/twitter/twitter_title', array( $this, 'filter_rank_math_social_title' ) );
		add_filter( 'rank_math/opengraph/twitter/twitter_description', array( $this, 'filter_rank_math_social_description' ) );
		add_filter( 'rank_math/json_ld', array( $this, 'filter_rank_math_json_ld' ), 20, 2 );

		add_action( 'template_redirect', array( $this, 'render_language_sitemap' ), 0 );
		add_filter( 'robots_txt', array( $this, 'add_sitemaps_to_robots' ), 20, 2 );

		add_action( 'add_meta_boxes', array( $this, 'add_language_metabox' ) );
		add_action( 'save_post', array( $this, 'save_language_metabox' ), 20, 2 );
		add_filter( 'manage_posts_columns', array( $this, 'add_admin_language_column' ) );
		add_filter( 'manage_pages_columns', array( $this, 'add_admin_language_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_admin_language_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'render_admin_language_column' ), 10, 2 );

		add_shortcode( 'tez_language_switcher', array( $this, 'render_language_switcher' ) );

		add_action( 'after_setup_theme', array( $this, 'pause_account_features' ), 100 );
		add_action( 'template_redirect', array( $this, 'redirect_paused_account' ), -10 );
		add_action( 'admin_menu', array( $this, 'hide_paused_account_admin' ), 999 );
		add_filter( 'pre_option_users_can_register', array( $this, 'disable_public_registration' ) );
	}

	public function register_content_meta() {
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_LANGUAGE,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => 'fa',
					'sanitize_callback' => array( $this, 'sanitise_language' ),
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
					'show_in_rest'      => true,
				)
			);

			register_post_meta(
				$post_type,
				self::META_TRANSLATION,
				array(
					'type'              => 'integer',
					'single'            => true,
					'default'           => 0,
					'sanitize_callback' => 'absint',
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
					'show_in_rest'      => true,
				)
			);

			register_rest_field(
				$post_type,
				'tez_language',
				array(
					'get_callback'    => function ( $object ) {
						return $this->get_post_language( (int) $object['id'] );
					},
					'update_callback' => function ( $value, $post ) {
						return (bool) update_post_meta( $post->ID, self::META_LANGUAGE, $this->sanitise_language( $value ) );
					},
					'schema'          => array(
						'type' => 'string',
						'enum' => array( 'fa', 'en' ),
					),
				)
			);

			register_rest_field(
				$post_type,
				'tez_translation_id',
				array(
					'get_callback'    => function ( $object ) {
						return (int) get_post_meta( (int) $object['id'], self::META_TRANSLATION, true );
					},
					'update_callback' => function ( $value, $post ) {
						return $this->link_translations( $post->ID, absint( $value ) );
					},
					'schema'          => array( 'type' => 'integer' ),
				)
			);

			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'guard_rest_language' ), 10, 2 );
			add_action( "rest_after_insert_{$post_type}", array( $this, 'persist_rest_language' ), 10, 3 );
		}
	}

	public function sanitise_language( $language ) {
		return 'en' === strtolower( (string) $language ) ? 'en' : 'fa';
	}

	public function register_rewrites() {
		add_rewrite_rule( '^sitemap-(fa|en)\.xml$', 'index.php?' . self::QUERY_SITEMAP . '=$matches[1]', 'top' );
		add_rewrite_rule( '^en/?$', 'index.php?' . self::QUERY_LANGUAGE . '=en', 'top' );
		add_rewrite_rule( '^en/category/([^/]+)/?$', 'index.php?' . self::QUERY_LANGUAGE . '=en&category_name=$matches[1]', 'top' );
		add_rewrite_rule( '^en/tag/([^/]+)/?$', 'index.php?' . self::QUERY_LANGUAGE . '=en&tag=$matches[1]', 'top' );
		add_rewrite_rule( '^en/(.+?)/?$', 'index.php?' . self::QUERY_LANGUAGE . '=en&' . self::QUERY_PATH . '=$matches[1]', 'top' );
	}

	public function maybe_flush_rewrites() {
		if ( self::VERSION !== get_option( 'tez_multilingual_version' ) ) {
			flush_rewrite_rules( false );
			update_option( 'tez_multilingual_version', self::VERSION, false );
		}
	}

	public function register_query_vars( $vars ) {
		$vars[] = self::QUERY_LANGUAGE;
		$vars[] = self::QUERY_PATH;
		$vars[] = self::QUERY_SITEMAP;

		return $vars;
	}

	public function resolve_english_request( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_PATH ] ) ) {
			return;
		}

		$path       = trim( sanitize_text_field( wp_unslash( $wp->query_vars[ self::QUERY_PATH ] ) ), '/' );
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );
		$resolved = get_page_by_path( $path, OBJECT, array_values( $post_types ) );

		if ( $resolved && 'en' === $this->get_post_language( $resolved->ID ) ) {
			$wp->query_vars['p']         = $resolved->ID;
			$wp->query_vars['post_type'] = $resolved->post_type;
			unset( $wp->query_vars['name'], $wp->query_vars['pagename'] );
		}
	}

	public function filter_frontend_queries( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->get( self::QUERY_SITEMAP ) ) {
			return;
		}

		$language = $this->request_language();
		$existing = (array) $query->get( 'meta_query' );

		if ( 'en' === $language ) {
			$existing[] = array(
				'key'     => self::META_LANGUAGE,
				'value'   => 'en',
				'compare' => '=',
			);
		} else {
			$existing[] = array(
				'relation' => 'OR',
				array(
					'key'     => self::META_LANGUAGE,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::META_LANGUAGE,
					'value'   => 'fa',
					'compare' => '=',
				),
			);
		}

		$query->set( 'meta_query', $existing );
	}

	public function request_language() {
		$queried = get_query_var( self::QUERY_LANGUAGE );
		if ( 'en' === $queried ) {
			return 'en';
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			if ( is_string( $path ) && preg_match( '#^/en(?:/|$)#', $path ) ) {
				return 'en';
			}
		}

		if ( is_singular() ) {
			return $this->get_post_language( get_queried_object_id() );
		}

		return 'fa';
	}

	public function get_post_language( $post_id ) {
		$language = get_post_meta( $post_id, self::META_LANGUAGE, true );
		return 'en' === $language ? 'en' : 'fa';
	}

	public function filter_locale( $locale ) {
		return 'en' === $this->request_language() ? 'en_US' : $locale;
	}

	public function filter_language_attributes( $output, $doctype ) {
		unset( $output, $doctype );
		return 'en' === $this->request_language() ? 'lang="en-US" dir="ltr"' : 'lang="fa-IR" dir="rtl"';
	}

	public function filter_body_classes( $classes ) {
		$language = $this->request_language();
		$classes[] = 'tez-lang-' . $language;
		$classes[] = 'en' === $language ? 'tez-ltr' : 'tez-rtl';

		return array_unique( $classes );
	}

	public function print_direction_styles() {
		?>
		<style id="tez-multilingual-ltr">
			<?php if ( $this->account_features_paused() ) : ?>
			.nav-credits,.nav-account,a[href*="/account/"],a[href*="?tab=profile"]{display:none!important}
			<?php endif; ?>
			<?php if ( 'en' === $this->request_language() ) : ?>
			html[dir="ltr"],html[dir="ltr"] body{direction:ltr;text-align:left}
			html[dir="ltr"] input,html[dir="ltr"] textarea,html[dir="ltr"] select{direction:ltr;text-align:left}
			html[dir="ltr"] .menu,html[dir="ltr"] nav ul{direction:ltr}
			html[dir="ltr"] blockquote{border-right:0;border-left:4px solid currentColor}
			html[dir="ltr"] .announce-utils,html[dir="ltr"] .nav-quick-actions{display:none!important}
			<?php endif; ?>
		</style>
		<?php
	}

	public function translate_theme_string( $translated, $original, $domain ) {
		if ( 'en' !== $this->request_language() || 'teznevise' !== $domain ) {
			return $translated;
		}

		$map = array(
			'رفتن به محتوای اصلی'              => 'Skip to main content',
			'شنبه تا پنجشنبه، ۹ تا ۲۱'          => 'Saturday–Thursday, 09:00–21:00',
			'مشاوره محرمانه و تخصصی'             => 'Confidential specialist consultation',
			'حریم خصوصی'                         => 'Privacy',
			'بازخورد مشتریان'                    => 'Client feedback',
			'ثبت درخواست'                        => 'Submit a request',
			'باز کردن منو'                       => 'Open menu',
			'بستن منو'                           => 'Close menu',
			'جستجو'                              => 'Search',
			'درباره ما'                          => 'About us',
			'خانه'                               => 'Home',
			'مقالات'                             => 'Articles',
			'بلاگ'                               => 'Research guides',
			'خدمات'                              => 'Services',
			'ابزارها'                            => 'Research tools',
			'دانلودها'                           => 'Downloads',
			'مطالب جدید'                         => 'Latest articles',
			'ادامه مطلب'                         => 'Read more',
			'نتیجه‌ای پیدا نشد.'                 => 'No results found.',
			'لینک‌های راهنما'                    => 'Guide links',
			'منوی اصلی'                          => 'Main navigation',
			'منوی موبایل'                        => 'Mobile navigation',
			'منو'                                => 'Menu',
			'نمایش زیرمنو'                       => 'Show submenu',
			'ورود'                               => 'Sign in',
			'پروفایل'                            => 'Profile',
		);

		return isset( $map[ $original ] ) ? $map[ $original ] : $translated;
	}

	public function filter_language_menu_items( $items, $args ) {
		unset( $args );
		$english = 'en' === $this->request_language();

		foreach ( $items as $key => $item ) {
			$url_path = (string) wp_parse_url( $item->url, PHP_URL_PATH );
			if ( $this->account_features_paused() && ( false !== strpos( $url_path, '/account/' ) || false !== strpos( $item->url, 'tab=profile' ) ) ) {
				unset( $items[ $key ] );
				continue;
			}

			if ( ! $english ) {
				continue;
			}

			$object_id = isset( $item->object_id ) ? absint( $item->object_id ) : 0;
			$is_english_object = $object_id && 'en' === $this->get_post_language( $object_id );
			$is_english_url    = preg_match( '#^/en(?:/|$)#', $url_path );
			if ( ! $is_english_object && ! $is_english_url ) {
				unset( $items[ $key ] );
			}
		}

		return array_values( $items );
	}

	public function ensure_english_home_menu_item( $items, $args ) {
		unset( $args );
		if ( 'en' !== $this->request_language() ) {
			return $items;
		}

		$english_home = home_url( '/en/' );
		if ( false !== strpos( $items, esc_url( $english_home ) ) ) {
			return $items;
		}

		$home_item = sprintf(
			'<li class="menu-item tez-english-home"><a class="nav-link" href="%s" hreflang="en">%s</a></li>',
			esc_url( $english_home ),
			esc_html__( 'Home', 'tez-multilingual' )
		);

		return $home_item . $items;
	}

	public function filter_english_phone( $phone ) {
		return 'en' === $this->request_language() ? '+98 930 282 2091' : $phone;
	}

	public function filter_english_hours( $hours ) {
		return 'en' === $this->request_language() ? 'Saturday–Thursday, 09:00–21:00' : $hours;
	}

	public function filter_english_site_name( $name ) {
		return 'en' === $this->request_language() ? 'Teznevise' : $name;
	}

	public function filter_post_link( $permalink, $post, $leavename ) {
		unset( $leavename );
		return $this->prefix_english_permalink( $permalink, $post );
	}

	public function filter_post_type_link( $permalink, $post, $leavename, $sample ) {
		unset( $leavename, $sample );
		return $this->prefix_english_permalink( $permalink, $post );
	}

	public function filter_page_link( $permalink, $post_id, $sample ) {
		unset( $sample );
		return $this->prefix_english_permalink( $permalink, get_post( $post_id ) );
	}

	private function prefix_english_permalink( $permalink, $post ) {
		if ( ! $post || 'en' !== $this->get_post_language( $post->ID ) || is_admin() ) {
			return $permalink;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path      = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$relative  = '/' . ltrim( preg_replace( '#^' . preg_quote( rtrim( $home_path, '/' ), '#' ) . '#', '', $path ), '/' );

		$english_permalink = home_url( '/en' . $relative );
		$query             = wp_parse_url( $permalink, PHP_URL_QUERY );
		if ( $query ) {
			wp_parse_str( $query, $query_args );
			$english_permalink = add_query_arg( $query_args, $english_permalink );
		}

		return $english_permalink;
	}

	public function filter_canonical_redirect( $redirect_url, $requested_url ) {
		unset( $requested_url );
		return 'en' === $this->request_language() ? false : $redirect_url;
	}

	public function filter_rank_math_canonical( $canonical ) {
		if ( is_singular() ) {
			return get_permalink( get_queried_object_id() );
		}
		if ( 'en' === $this->request_language() ) {
			return home_url( '/en/' );
		}

		return $canonical;
	}

	public function filter_rank_math_title( $title ) {
		if ( 'en' === $this->request_language() && ! is_singular() ) {
			return 'Academic Research Guides | Teznevise';
		}
		return $title;
	}

	public function filter_rank_math_description( $description ) {
		if ( 'en' === $this->request_language() && ! is_singular() ) {
			return 'Evidence-based guides to thesis writing, research methodology, academic analysis and graduate study.';
		}
		return $description;
	}

	public function filter_rank_math_og_url( $url ) {
		return 'en' === $this->request_language() ? $this->filter_rank_math_canonical( $url ) : $url;
	}

	public function filter_rank_math_social_title( $title ) {
		return 'en' === $this->request_language() && ! is_singular() ? 'Academic Research Guides | Teznevise' : $title;
	}

	public function filter_rank_math_social_description( $description ) {
		return 'en' === $this->request_language() && ! is_singular()
			? 'Evidence-based guides to thesis writing, research methodology, academic analysis and graduate study.'
			: $description;
	}

	public function filter_rank_math_json_ld( $data, $json_ld ) {
		unset( $json_ld );
		if ( 'en' !== $this->request_language() || is_singular() || ! is_array( $data ) ) {
			return $data;
		}

		$english_home       = home_url( '/en/' );
		$english_breadcrumb = $english_home . '#breadcrumb';
		$english_webpage    = $english_home . '#webpage';

		foreach ( $data as &$entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}

			$type = isset( $entity['@type'] ) ? (string) $entity['@type'] : '';
			if ( 'Organization' === $type || 'WebSite' === $type ) {
				$entity['name'] = 'Teznevise';
			}
			if ( 'BreadcrumbList' === $type ) {
				$entity['@id'] = $english_breadcrumb;
				if ( isset( $entity['itemListElement'][0]['item'] ) && is_array( $entity['itemListElement'][0]['item'] ) ) {
					$entity['itemListElement'][0]['item']['@id']  = $english_home;
					$entity['itemListElement'][0]['item']['name'] = 'Home';
				}
			}
			if ( 'CollectionPage' === $type ) {
				$entity['@id']       = $english_webpage;
				$entity['url']       = $english_home;
				$entity['name']      = 'Academic Research Guides | Teznevise';
				$entity['inLanguage'] = 'en-US';
				$entity['breadcrumb'] = array( '@id' => $english_breadcrumb );
			}
		}
		unset( $entity );

		return $data;
	}

	private function account_features_paused() {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return 'teznevise.ir' === preg_replace( '/^www\./', '', $host ) && '0' !== (string) get_option( 'tez_pause_account_features', '1' );
	}

	public function pause_account_features() {
		if ( ! $this->account_features_paused() ) {
			return;
		}

		remove_action( 'template_redirect', 'teznevise_handle_front_auth', 8 );
		remove_action( 'user_register', 'teznevise_maybe_welcome_coins' );
		remove_action( 'comment_post', 'teznevise_award_comment_coins', 10 );
		remove_action( 'wp_ajax_teznevise_share_reward', 'teznevise_ajax_share_reward' );
		remove_action( 'personal_options_update', 'teznevise_save_extra_profile_fields' );
		remove_action( 'edit_user_profile_update', 'teznevise_save_extra_profile_fields' );
	}

	public function redirect_paused_account() {
		if ( ! $this->account_features_paused() || is_admin() ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( preg_match( '#^/account(?:/|$)#', $path ) ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			wp_safe_redirect( home_url( '/inquiry/' ), 302, 'Tez feature pause' );
			exit;
		}
	}

	public function hide_paused_account_admin() {
		if ( ! $this->account_features_paused() ) {
			return;
		}
		remove_submenu_page( 'themes.php', 'teznevise-tezcoin' );
		remove_submenu_page( 'themes.php', 'teznevise-ledger' );
	}

	public function disable_public_registration( $value ) {
		return $this->account_features_paused() ? 0 : $value;
	}

	public function print_hreflang() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id        = get_queried_object_id();
		$language       = $this->get_post_language( $post_id );
		$translation_id = absint( get_post_meta( $post_id, self::META_TRANSLATION, true ) );
		$current_url    = get_permalink( $post_id );

		printf( "\n<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n", esc_attr( 'en' === $language ? 'en' : 'fa-IR' ), esc_url( $current_url ) );

		if ( $translation_id && 'publish' === get_post_status( $translation_id ) ) {
			$translation_language = $this->get_post_language( $translation_id );
			printf( "<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n", esc_attr( 'en' === $translation_language ? 'en' : 'fa-IR' ), esc_url( get_permalink( $translation_id ) ) );
		}
	}

	public function add_sitemaps_to_robots( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$lines = array(
			'Sitemap: ' . home_url( '/sitemap-fa.xml' ),
			'Sitemap: ' . home_url( '/sitemap-en.xml' ),
		);

		foreach ( $lines as $line ) {
			if ( false === strpos( $output, $line ) ) {
				$output .= "\n" . $line;
			}
		}

		return trim( $output ) . "\n";
	}

	public function render_language_sitemap() {
		$language = get_query_var( self::QUERY_SITEMAP );
		if ( ! in_array( $language, array( 'fa', 'en' ), true ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );
		$meta_query = 'en' === $language
			? array( array( 'key' => self::META_LANGUAGE, 'value' => 'en' ) )
			: array(
				array(
					'relation' => 'OR',
					array( 'key' => self::META_LANGUAGE, 'compare' => 'NOT EXISTS' ),
					array( 'key' => self::META_LANGUAGE, 'value' => 'fa' ),
				),
			);

		$ids = get_posts(
			array(
				'post_type'              => array_values( $post_types ),
				'post_status'            => 'publish',
				'posts_per_page'         => 50000,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query,
			)
		);

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ( $ids as $post_id ) {
			$modified = get_post_modified_time( DATE_W3C, true, $post_id );
			echo '<url><loc>' . esc_xml( get_permalink( $post_id ) ) . '</loc><lastmod>' . esc_xml( $modified ) . '</lastmod></url>';
		}
		echo '</urlset>';
		exit;
	}

	public function add_language_metabox() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );
		foreach ( $post_types as $post_type ) {
			add_meta_box( 'tez-language', __( 'Language and translation', 'tez-multilingual' ), array( $this, 'render_language_metabox' ), $post_type, 'side', 'high' );
		}
	}

	public function render_language_metabox( $post ) {
		$language       = $this->get_post_language( $post->ID );
		$translation_id = absint( get_post_meta( $post->ID, self::META_TRANSLATION, true ) );
		wp_nonce_field( 'tez_save_language', 'tez_language_nonce' );
		?>
		<p><label for="tez-language-select"><?php esc_html_e( 'Language', 'tez-multilingual' ); ?></label></p>
		<select id="tez-language-select" name="tez_language" class="widefat">
			<option value="fa" <?php selected( $language, 'fa' ); ?>>فارسی (RTL)</option>
			<option value="en" <?php selected( $language, 'en' ); ?>>English (LTR)</option>
		</select>
		<p><label for="tez-translation-id"><?php esc_html_e( 'Translation post ID', 'tez-multilingual' ); ?></label></p>
		<input id="tez-translation-id" name="tez_translation_id" class="widefat" type="number" min="0" value="<?php echo esc_attr( $translation_id ); ?>">
		<?php
	}

	public function save_language_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['tez_language_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tez_language_nonce'] ) ), 'tez_save_language' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$language = isset( $_POST['tez_language'] ) ? $this->sanitise_language( wp_unslash( $_POST['tez_language'] ) ) : 'fa';
		update_post_meta( $post_id, self::META_LANGUAGE, $language );

		$translation_id = isset( $_POST['tez_translation_id'] ) ? absint( $_POST['tez_translation_id'] ) : 0;
		$this->link_translations( $post_id, $translation_id );
	}

	private function link_translations( $post_id, $translation_id ) {
		$post_id        = absint( $post_id );
		$translation_id = absint( $translation_id );

		if ( ! $translation_id ) {
			delete_post_meta( $post_id, self::META_TRANSLATION );
			return true;
		}

		if ( $post_id === $translation_id || ! get_post( $translation_id ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_TRANSLATION, $translation_id );
		update_post_meta( $translation_id, self::META_TRANSLATION, $post_id );

		return true;
	}

	public function add_admin_language_column( $columns ) {
		$columns['tez_language'] = __( 'Language', 'tez-multilingual' );
		return $columns;
	}

	public function render_admin_language_column( $column, $post_id ) {
		if ( 'tez_language' !== $column ) {
			return;
		}

		echo 'en' === $this->get_post_language( $post_id ) ? 'EN · LTR' : 'FA · RTL';
		$translation_id = absint( get_post_meta( $post_id, self::META_TRANSLATION, true ) );
		if ( $translation_id ) {
			echo '<br><small>' . esc_html( sprintf( '↔ #%d', $translation_id ) ) . '</small>';
		}
	}

	public function render_language_switcher() {
		if ( ! is_singular() ) {
			return '';
		}

		$post_id        = get_queried_object_id();
		$translation_id = absint( get_post_meta( $post_id, self::META_TRANSLATION, true ) );
		if ( ! $translation_id || 'publish' !== get_post_status( $translation_id ) ) {
			return '';
		}

		$label = 'en' === $this->get_post_language( $post_id ) ? 'فارسی' : 'English';
		return sprintf( '<a class="tez-language-switcher" href="%s" hreflang="%s">%s</a>', esc_url( get_permalink( $translation_id ) ), esc_attr( 'English' === $label ? 'en' : 'fa-IR' ), esc_html( $label ) );
	}

	public function guard_rest_language( $prepared_post, $request ) {
		if ( 'POST' !== $request->get_method() || ! empty( $request['id'] ) ) {
			return $prepared_post;
		}

		$language = $this->language_from_rest_request( $request );
		if ( $language ) {
			return $prepared_post;
		}

		$title   = isset( $request['title'] ) ? wp_strip_all_tags( $this->rest_text_value( $request['title'] ) ) : '';
		$content = isset( $request['content'] ) ? wp_strip_all_tags( $this->rest_text_value( $request['content'] ) ) : '';
		$text    = trim( $title . ' ' . $content );

		if ( $this->looks_english( $text ) ) {
			return new WP_Error(
				'tez_language_required',
				__( 'English content requires tez_language=en. Use the Tez multilingual endpoint or supply the registered language field.', 'tez-multilingual' ),
				array( 'status' => 400 )
			);
		}

		return $prepared_post;
	}

	private function rest_text_value( $value ) {
		if ( is_array( $value ) && isset( $value['raw'] ) ) {
			return (string) $value['raw'];
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	private function looks_english( $text ) {
		if ( ! $text ) {
			return false;
		}

		$latin   = preg_match_all( '/[A-Za-z]/u', $text );
		$persian = preg_match_all( '/[\x{0600}-\x{06FF}]/u', $text );

		return $latin >= 25 && $latin > ( 2 * max( 4, $persian ) );
	}

	private function language_from_rest_request( $request ) {
		foreach ( array( 'tez_language', 'language', 'lang' ) as $key ) {
			if ( isset( $request[ $key ] ) && in_array( strtolower( (string) $request[ $key ] ), array( 'fa', 'en' ), true ) ) {
				return $this->sanitise_language( $request[ $key ] );
			}
		}

		if ( isset( $request['meta'] ) && is_array( $request['meta'] ) && isset( $request['meta'][ self::META_LANGUAGE ] ) ) {
			return $this->sanitise_language( $request['meta'][ self::META_LANGUAGE ] );
		}

		return null;
	}

	public function persist_rest_language( $post, $request, $creating ) {
		$language = $this->language_from_rest_request( $request );
		if ( $language ) {
			update_post_meta( $post->ID, self::META_LANGUAGE, $language );
		} elseif ( $creating && ! metadata_exists( 'post', $post->ID, self::META_LANGUAGE ) ) {
			update_post_meta( $post->ID, self::META_LANGUAGE, 'fa' );
		}
	}

	public function register_rest_routes() {
		register_rest_route(
			'tez-multilingual/v1',
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return rest_ensure_response(
						array(
							'version'   => self::VERSION,
							'languages' => array( 'fa', 'en' ),
							'default'   => 'fa',
							'english'   => home_url( '/en/' ),
						)
					);
				},
			)
		);

		register_rest_route(
			'tez-multilingual/v1',
			'/posts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => array( $this, 'rest_create_post' ),
				'args'                => array(
					'title'    => array( 'required' => true, 'type' => 'string' ),
					'language' => array( 'required' => true, 'type' => 'string', 'enum' => array( 'fa', 'en' ) ),
					'status'   => array( 'type' => 'string', 'default' => 'draft' ),
				),
			)
		);
	}

	public function rest_create_post( WP_REST_Request $request ) {
		$allowed_statuses = array( 'draft', 'pending', 'private', 'publish', 'future' );
		$status           = in_array( $request['status'], $allowed_statuses, true ) ? $request['status'] : 'draft';
		if ( in_array( $status, array( 'publish', 'future' ), true ) && ! current_user_can( 'publish_posts' ) ) {
			$status = 'draft';
		}

		$post_data = array(
			'post_type'    => 'post',
			'post_status'  => $status,
			'post_title'   => sanitize_text_field( $request['title'] ),
			'post_content' => isset( $request['content'] ) ? wp_kses_post( $request['content'] ) : '',
			'post_excerpt' => isset( $request['excerpt'] ) ? wp_kses_post( $request['excerpt'] ) : '',
			'post_name'    => isset( $request['slug'] ) ? sanitize_title( $request['slug'] ) : '',
			'meta_input'   => array(
				self::META_LANGUAGE => $this->sanitise_language( $request['language'] ),
			),
		);

		if ( isset( $request['categories'] ) && is_array( $request['categories'] ) ) {
			$post_data['post_category'] = array_map( 'absint', $request['categories'] );
		}

		$post_id = wp_insert_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( isset( $request['tags'] ) && is_array( $request['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'absint', $request['tags'] ) );
		}
		if ( ! empty( $request['featured_media'] ) ) {
			set_post_thumbnail( $post_id, absint( $request['featured_media'] ) );
		}
		if ( ! empty( $request['translation_id'] ) ) {
			$this->link_translations( $post_id, absint( $request['translation_id'] ) );
		}

		$seo_fields = array(
			'rank_math_title'         => 'rank_math_title',
			'rank_math_description'   => 'rank_math_description',
			'rank_math_focus_keyword' => 'rank_math_focus_keyword',
		);
		foreach ( $seo_fields as $request_key => $meta_key ) {
			if ( isset( $request[ $request_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $request[ $request_key ] ) );
			}
		}

		return new WP_REST_Response(
			array(
				'id'             => $post_id,
				'language'       => $this->get_post_language( $post_id ),
				'translation_id' => (int) get_post_meta( $post_id, self::META_TRANSLATION, true ),
				'permalink'      => get_permalink( $post_id ),
				'status'         => get_post_status( $post_id ),
			),
			201
		);
	}
}

Tez_Portfolio_Multilingual::instance();

register_activation_hook(
	__FILE__,
	function () {
		Tez_Portfolio_Multilingual::instance()->register_rewrites();
		flush_rewrite_rules( false );
		update_option( 'tez_multilingual_version', Tez_Portfolio_Multilingual::VERSION, false );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules( false );
	}
);
