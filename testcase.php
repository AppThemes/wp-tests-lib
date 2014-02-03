<?php

require_once dirname( __FILE__ ) . '/factory.php';
require_once dirname( __FILE__ ) . '/trac.php';

class WP_UnitTestCase extends PHPUnit_Framework_TestCase {

	protected static $forced_tickets = array();
	protected $expected_deprecated = array();
	protected $caught_deprecated = array();
	protected $expected_doing_it_wrong = array();
	protected $caught_doing_it_wrong = array();

	/**
	 * @var WP_UnitTest_Factory
	 */
	protected $factory;

	function setUp() {
		set_time_limit(0);

		global $wpdb;
		$wpdb->suppress_errors = false;
		$wpdb->show_errors = true;
		$wpdb->db_connect();
		ini_set('display_errors', 1 );
		$this->factory = new WP_UnitTest_Factory;
		$this->clean_up_global_scope();
		$this->start_transaction();
		$this->expectDeprecated();
		add_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );
	}

	function tearDown() {
		global $wpdb;
		$this->expectedDeprecated();
		$wpdb->query( 'ROLLBACK' );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );
	}

	function clean_up_global_scope() {
		$_GET = array();
		$_POST = array();
		$this->flush_cache();
	}

	function flush_cache() {
		global $wp_object_cache;
		$wp_object_cache->group_ops = array();
		$wp_object_cache->stats = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache = array();
		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset();
		}
		wp_cache_flush();
		wp_cache_add_global_groups( array( 'users', 'userlogins', 'usermeta', 'user_meta', 'site-transient', 'site-options', 'site-lookup', 'blog-lookup', 'blog-details', 'rss', 'global-posts', 'blog-id-cache' ) );
		wp_cache_add_non_persistent_groups( array( 'comment', 'counts', 'plugins' ) );
	}

	function start_transaction() {
		global $wpdb;
		$wpdb->query( 'SET autocommit = 0;' );
		$wpdb->query( 'START TRANSACTION;' );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	function _create_temporary_tables( $query ) {
		if ( 'CREATE TABLE' === substr( trim( $query ), 0, 12 ) )
			return substr_replace( trim( $query ), 'CREATE TEMPORARY TABLE', 0, 12 );
		return $query;
	}

	function _drop_temporary_tables( $query ) {
		if ( 'DROP TABLE' === substr( trim( $query ), 0, 10 ) )
			return substr_replace( trim( $query ), 'DROP TEMPORARY TABLE', 0, 10 );
		return $query;
	}

	function get_wp_die_handler( $handler ) {
		return array( $this, 'wp_die_handler' );
	}

	function wp_die_handler( $message ) {
		throw new WPDieException( $message );
	}

	function expectDeprecated() {
		$annotations = $this->getAnnotations();
		foreach ( array( 'class', 'method' ) as $depth ) {
			if ( ! empty( $annotations[ $depth ]['expectedDeprecated'] ) )
				$this->expected_deprecated = array_merge( $this->expected_deprecated, $annotations[ $depth ]['expectedDeprecated'] );
			if ( ! empty( $annotations[ $depth ]['expectedIncorrectUsage'] ) )
				$this->expected_doing_it_wrong = array_merge( $this->expected_doing_it_wrong, $annotations[ $depth ]['expectedIncorrectUsage'] );
		}
		add_action( 'deprecated_function_run', array( $this, 'deprecated_function_run' ) );
		add_action( 'deprecated_argument_run', array( $this, 'deprecated_function_run' ) );
		add_action( 'doing_it_wrong_run', array( $this, 'doing_it_wrong_run' ) );
		add_action( 'deprecated_function_trigger_error', '__return_false' );
		add_action( 'deprecated_argument_trigger_error', '__return_false' );
		add_action( 'doing_it_wrong_trigger_error',      '__return_false' );
	}

	function expectedDeprecated() {
		$not_caught_deprecated = array_diff( $this->expected_deprecated, $this->caught_deprecated );
		foreach ( $not_caught_deprecated as $not_caught ) {
			$this->fail( "Failed to assert that $not_caught triggered a deprecated notice" );
		}

		$unexpected_deprecated = array_diff( $this->caught_deprecated, $this->expected_deprecated );
		foreach ( $unexpected_deprecated as $unexpected ) {
			$this->fail( "Unexpected deprecated notice for $unexpected" );
		}

		$not_caught_doing_it_wrong = array_diff( $this->expected_doing_it_wrong, $this->caught_doing_it_wrong );
		foreach ( $not_caught_doing_it_wrong as $not_caught ) {
			$this->fail( "Failed to assert that $not_caught triggered an incorrect usage notice" );
		}

		$unexpected_doing_it_wrong = array_diff( $this->caught_doing_it_wrong, $this->expected_doing_it_wrong );
		foreach ( $unexpected_doing_it_wrong as $unexpected ) {
			$this->fail( "Unexpected incorrect usage notice for $unexpected" );
		}
	}

	function deprecated_function_run( $function ) {
		if ( ! in_array( $function, $this->caught_deprecated ) )
			$this->caught_deprecated[] = $function;
	}

	function doing_it_wrong_run( $function ) {
		if ( ! in_array( $function, $this->caught_doing_it_wrong ) )
			$this->caught_doing_it_wrong[] = $function;
	}

	function assertWPError( $actual, $message = '' ) {
		$this->assertInstanceOf( 'WP_Error', $actual, $message );
	}

	function assertEqualFields( $object, $fields ) {
		foreach( $fields as $field_name => $field_value ) {
			if ( $object->$field_name != $field_value ) {
				$this->fail();
			}
		}
	}

	function assertDiscardWhitespace( $expected, $actual ) {
		$this->assertEquals( preg_replace( '/\s*/', '', $expected ), preg_replace( '/\s*/', '', $actual ) );
	}

	function assertEqualSets( $expected, $actual ) {
		$this->assertEquals( array(), array_diff( $expected, $actual ) );
		$this->assertEquals( array(), array_diff( $actual, $expected ) );
	}

	function go_to( $url ) {
		// note: the WP and WP_Query classes like to silently fetch parameters
		// from all over the place (globals, GET, etc), which makes it tricky
		// to run them more than once without very carefully clearing everything
		$_GET = $_POST = array();
		foreach (array('query_string', 'id', 'postdata', 'authordata', 'day', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages', 'pagenow') as $v) {
			if ( isset( $GLOBALS[$v] ) ) unset( $GLOBALS[$v] );
		}
		$parts = parse_url($url);
		if (isset($parts['scheme'])) {
			$req = $parts['path'];
			if (isset($parts['query'])) {
				$req .= '?' . $parts['query'];
				// parse the url query vars into $_GET
				parse_str($parts['query'], $_GET);
			}
		} else {
			$req = $url;
		}
		if ( ! isset( $parts['query'] ) ) {
			$parts['query'] = '';
		}

		$_SERVER['REQUEST_URI'] = $req;
		unset($_SERVER['PATH_INFO']);

		$this->flush_cache();
		unset($GLOBALS['wp_query'], $GLOBALS['wp_the_query']);
		$GLOBALS['wp_the_query'] = new WP_Query();
		$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
		$GLOBALS['wp'] = new WP();
		_cleanup_query_vars();

		$GLOBALS['wp']->main($parts['query']);
	}

	protected function checkRequirements() {
		parent::checkRequirements();
		if ( WP_TESTS_FORCE_KNOWN_BUGS )
			return;
		$tickets = PHPUnit_Util_Test::getTickets( get_class( $this ), $this->getName( false ) );
		foreach ( $tickets as $ticket ) {
			if ( is_numeric( $ticket ) ) {
				$this->knownWPBug( $ticket );
			} elseif ( 'UT' == substr( $ticket, 0, 2 ) ) {
				$ticket = substr( $ticket, 2 );
				if ( $ticket && is_numeric( $ticket ) )
					$this->knownUTBug( $ticket );
			} elseif ( 'Plugin' == substr( $ticket, 0, 6 ) ) {
				$ticket = substr( $ticket, 6 );
				if ( $ticket && is_numeric( $ticket ) )
					$this->knownPluginBug( $ticket );
			}
		}
	}

	/**
	 * Skips the current test if there is an open WordPress ticket with id $ticket_id
	 */
	function knownWPBug( $ticket_id ) {
		if ( WP_TESTS_FORCE_KNOWN_BUGS || in_array( $ticket_id, self::$forced_tickets ) )
			return;
		if ( ! TracTickets::isTracTicketClosed( 'https://core.trac.wordpress.org', $ticket_id ) )
			$this->markTestSkipped( sprintf( 'WordPress Ticket #%d is not fixed', $ticket_id ) );
	}

	/**
	 * Skips the current test if there is an open unit tests ticket with id $ticket_id
	 */
	function knownUTBug( $ticket_id ) {
		if ( WP_TESTS_FORCE_KNOWN_BUGS || in_array( 'UT' . $ticket_id, self::$forced_tickets ) )
			return;
		if ( ! TracTickets::isTracTicketClosed( 'https://unit-tests.trac.wordpress.org', $ticket_id ) )
			$this->markTestSkipped( sprintf( 'Unit Tests Ticket #%d is not fixed', $ticket_id ) );
	}

	/**
	 * Skips the current test if there is an open plugin ticket with id $ticket_id
	 */
	function knownPluginBug( $ticket_id ) {
		if ( WP_TESTS_FORCE_KNOWN_BUGS || in_array( 'Plugin' . $ticket_id, self::$forced_tickets ) )
			return;
		if ( ! TracTickets::isTracTicketClosed( 'https://plugins.trac.wordpress.org', $ticket_id ) )
			$this->markTestSkipped( sprintf( 'WordPress Plugin Ticket #%d is not fixed', $ticket_id ) );
	}

	public static function forceTicket( $ticket ) {
		self::$forced_tickets[] = $ticket;
	}

	/**
	 * Define constants after including files.
	 */
	function prepareTemplate( Text_Template $template ) {
		$template->setVar( array( 'constants' => '' ) );
		$template->setVar( array( 'wp_constants' => PHPUnit_Util_GlobalState::getConstantsAsString() ) );
		parent::prepareTemplate( $template );
	}

	/**
	 * Returns the name of a temporary file
	 */
	function temp_filename() {
		$tmp_dir = '';
		$dirs = array( 'TMP', 'TMPDIR', 'TEMP' );
		foreach( $dirs as $dir )
			if ( isset( $_ENV[$dir] ) && !empty( $_ENV[$dir] ) ) {
				$tmp_dir = $dir;
				break;
			}
		if ( empty( $tmp_dir ) ) {
			$tmp_dir = '/tmp';
		}
		$tmp_dir = realpath( $dir );
		return tempnam( $tmp_dir, 'wpunit' );
	}

	/**
	 * Check each of the WP_Query is_* functions/properties against expected boolean value.
	 *
	 * Any properties that are listed by name as parameters will be expected to be true; any others are
	 * expected to be false. For example, assertQueryTrue('is_single', 'is_feed') means is_single()
	 * and is_feed() must be true and everything else must be false to pass.
	 *
	 * @param string $prop,... Any number of WP_Query properties that are expected to be true for the current request.
	 */
	function assertQueryTrue(/* ... */) {
		global $wp_query;
		$all = array(
			'is_single', 'is_preview', 'is_page', 'is_archive', 'is_date', 'is_year', 'is_month', 'is_day', 'is_time',
			'is_author', 'is_category', 'is_tag', 'is_tax', 'is_search', 'is_feed', 'is_comment_feed', 'is_trackback',
			'is_home', 'is_404', 'is_comments_popup', 'is_paged', 'is_admin', 'is_attachment', 'is_singular', 'is_robots',
			'is_posts_page', 'is_post_type_archive',
		);
		$true = func_get_args();

		$passed = true;
		$not_false = $not_true = array(); // properties that were not set to expected values

		foreach ( $all as $query_thing ) {
			$result = is_callable( $query_thing ) ? call_user_func( $query_thing ) : $wp_query->$query_thing;

			if ( in_array( $query_thing, $true ) ) {
				if ( ! $result ) {
					array_push( $not_true, $query_thing );
					$passed = false;
				}
			} else if ( $result ) {
				array_push( $not_false, $query_thing );
				$passed = false;
			}
		}

		$message = '';
		if ( count($not_true) )
			$message .= implode( $not_true, ', ' ) . ' should be true. ';
		if ( count($not_false) )
			$message .= implode( $not_false, ', ' ) . ' should be false.';
		$this->assertTrue( $passed, $message );
	}
}
