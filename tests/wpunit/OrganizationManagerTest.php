<?php
/**
 * Tests for Organization\Manager.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Organization\Manager;
use WorkOS\Database\Schema;

/**
 * Organization management tests.
 */
class OrganizationManagerTest extends WPTestCase {

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		\WorkOS\Config::set_active_environment( 'production' );
		update_option( 'workos_production', [
			'api_key'         => 'sk_test_fake',
			'client_id'       => 'client_fake',
			'environment_id'  => 'environment_test',
			'organization_id' => 'org_configured',
		] );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		Schema::activate();

		remove_all_actions( 'user_register' );
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up tables.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_org_memberships" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_org_sites" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}workos_organizations" );

		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * Test upsert creates new org.
	 */
	public function test_upsert_creates_new_org(): void {
		$org_id = Manager::upsert_organization( [
			'id'   => 'org_new_123',
			'name' => 'Test Organization',
		] );

		$this->assertIsInt( $org_id );
		$this->assertGreaterThan( 0, $org_id );

		$org = Manager::get( $org_id );
		$this->assertSame( 'Test Organization', $org->name );
		$this->assertSame( 'org_new_123', $org->workos_org_id );
	}

	/**
	 * Test upsert updates existing org.
	 */
	public function test_upsert_updates_existing_org(): void {
		$org_id1 = Manager::upsert_organization( [
			'id'   => 'org_update_123',
			'name' => 'Original Name',
		] );

		$org_id2 = Manager::upsert_organization( [
			'id'   => 'org_update_123',
			'name' => 'Updated Name',
		] );

		$this->assertEquals( $org_id1, $org_id2 );

		$org = Manager::get( $org_id1 );
		$this->assertSame( 'Updated Name', $org->name );
	}

	/**
	 * Test upsert links to current site.
	 */
	public function test_upsert_links_to_current_site(): void {
		Manager::upsert_organization( [
			'id'   => 'org_site_link',
			'name' => 'Site Linked Org',
		] );

		$orgs = Manager::get_for_site( get_current_blog_id() );
		$this->assertCount( 1, $orgs );
		$this->assertSame( 'org_site_link', $orgs[0]->workos_org_id );
	}

	/**
	 * Test get_by_workos_id returns org.
	 */
	public function test_get_by_workos_id_returns_org(): void {
		Manager::upsert_organization( [
			'id'   => 'org_get_test',
			'name' => 'Get Test Org',
		] );

		$org = Manager::get_by_workos_id( 'org_get_test' );
		$this->assertNotNull( $org );
		$this->assertSame( 'Get Test Org', $org->name );
	}

	/**
	 * Test get_by_workos_id returns null when missing.
	 */
	public function test_get_by_workos_id_returns_null_when_missing(): void {
		$this->assertNull( Manager::get_by_workos_id( 'org_nonexistent' ) );
	}

	/**
	 * Test add_membership creates membership.
	 */
	public function test_add_membership_creates(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_mem_test', 'name' => 'Membership Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'admin' ] );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertCount( 1, $user_orgs );
		$this->assertSame( 'admin', $user_orgs[0]->workos_role );
	}

	/**
	 * Test add_membership updates existing.
	 */
	public function test_add_membership_updates_existing(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_mem_update', 'name' => 'Update Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'member' ] );
		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'admin' ] );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertCount( 1, $user_orgs );
		$this->assertSame( 'admin', $user_orgs[0]->workos_role );
	}

	/**
	 * Test remove_membership deletes row.
	 */
	public function test_remove_membership_deletes_row(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_mem_remove', 'name' => 'Remove Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id );
		Manager::remove_membership( $org_id, $user_id );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertCount( 0, $user_orgs );
	}

	/**
	 * Test get_membership_id returns id.
	 */
	public function test_get_membership_id_returns_id(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_memid', 'name' => 'MemId Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_membership_id' => 'om_12345' ] );

		$result = Manager::get_membership_id_for_user( $user_id, 'org_memid' );
		$this->assertSame( 'om_12345', $result );
	}

	/**
	 * Test get_membership_id returns empty when missing.
	 */
	public function test_get_membership_id_returns_empty_when_missing(): void {
		$result = Manager::get_membership_id_for_user( 999, 'org_nonexistent' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test store_membership_id updates record.
	 */
	public function test_store_membership_id_updates_record(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_store_memid', 'name' => 'Store Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_membership_id' => 'old_id' ] );
		Manager::store_membership_id( $user_id, 'org_store_memid', 'new_id' );

		$result = Manager::get_membership_id_for_user( $user_id, 'org_store_memid' );
		$this->assertSame( 'new_id', $result );
	}

	/**
	 * Test update_membership_role updates both roles.
	 */
	public function test_update_membership_role_updates_both_roles(): void {
		$org_id  = Manager::upsert_organization( [ 'id' => 'org_role_update', 'name' => 'Role Org' ] );
		$user_id = self::factory()->user->create();

		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'member' ] );
		Manager::update_membership_role( $user_id, 'org_role_update', 'admin', 'administrator' );

		$user_orgs = Manager::get_user_orgs( $user_id );
		$this->assertSame( 'admin', $user_orgs[0]->workos_role );
		$this->assertSame( 'administrator', $user_orgs[0]->wp_role );
	}
	/**
	 * Batch priming groups memberships and caches misses without per-user SQL.
	 */
	public function test_prime_user_orgs_batches_and_invalidates(): void {
		global $wpdb;
		$users = self::factory()->user->create_many( 3 );
		$alpha = Manager::upsert_organization( [ 'id' => 'org_alpha', 'name' => 'Alpha' ] );
		$zulu  = Manager::upsert_organization( [ 'id' => 'org_zulu', 'name' => 'Zulu' ] );
		Manager::add_membership( $zulu, $users[0], [ 'workos_role' => 'admin' ] );
		Manager::add_membership( $alpha, $users[0] );
		Manager::add_membership( $alpha, $users[1] );

		$before = $wpdb->num_queries;
		Manager::prime_user_orgs( array_merge( $users, [ $users[0], 0 ] ) );
		$this->assertSame( 1, $wpdb->num_queries - $before );
		$this->assertSame( [ 'Alpha', 'Zulu' ], wp_list_pluck( Manager::get_user_orgs( $users[0] ), 'name' ) );
		$this->assertCount( 1, Manager::get_user_orgs( $users[1] ) );
		$this->assertSame( [], Manager::get_user_orgs( $users[2] ) );
		Manager::prime_user_orgs( $users );
		$this->assertSame( 1, $wpdb->num_queries - $before );

		Manager::add_membership( $alpha, $users[2] );
		$this->assertCount( 1, Manager::get_user_orgs( $users[2] ) );
		Manager::update_membership_role( $users[1], 'org_alpha', 'editor', 'editor' );
		$this->assertSame( 'editor', Manager::get_user_orgs( $users[1] )[0]->workos_role );
		Manager::remove_membership( $alpha, $users[1] );
		$this->assertSame( [], Manager::get_user_orgs( $users[1] ) );
	}

	/**
	 * Cold role scans batch native user reads and retain comparison semantics.
	 */
	public function test_role_scan_batches_users_across_membership_pages(): void {
		global $wpdb;
		remove_all_actions( 'set_user_role' );
		$org_id = Manager::upsert_organization( [ 'id' => 'org_configured', 'name' => 'Configured' ] );
		$users  = self::factory()->user->create_many( 201, [ 'role' => 'subscriber' ] );
		foreach ( $users as $user_id ) {
			Manager::add_membership( $org_id, $user_id, [ 'workos_role' => 'admin' ] );
			clean_user_cache( $user_id );
		}
		// Warm configuration/organization independently of the measured scan.
		Manager::get_by_workos_id( 'org_configured' );
		\WorkOS\Sync\RoleMapper::get_role_map();

		$before = $wpdb->num_queries;
		$report = \WorkOS\Sync\RoleMapper::get_out_of_sync_users();
		$queries = $wpdb->num_queries - $before;

		$this->assertCount( 201, $report );
		$this->assertSame( $users, array_column( $report, 'user_id' ) );
		$this->assertSame( 'administrator', $report[0]['expected_wp_role'] );
		$this->assertSame( 'subscriber', $report[0]['actual_wp_role'] );
		$this->assertLessThanOrEqual( 8, $queries, 'Two batches must not perform per-user reads.' );

		$before = $wpdb->num_queries;
		Manager::prime_user_orgs( $users );
		$this->assertSame( 2, $wpdb->num_queries - $before );
		foreach ( $users as $user_id ) {
			$this->assertSame( 'admin', Manager::get_user_orgs( $user_id )[0]->workos_role );
		}
		$this->assertSame( 2, $wpdb->num_queries - $before );
	}

	/**
	 * Rendering navigation primes only the displayed users before their columns.
	 */
	public function test_users_table_primes_visible_memberships(): void {
		global $wpdb, $wp_list_table;
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-users-list-table.php';
		$previous_table = $wp_list_table;
		new \WorkOS\Admin\UserList();
		$users = self::factory()->user->create_many( 3 );
		$org_id = Manager::upsert_organization( [ 'id' => 'org_visible', 'name' => 'Visible' ] );
		foreach ( $users as $id ) {
			Manager::add_membership( $org_id, $id );
		}
		$wp_list_table = new \WP_Users_List_Table( [ 'screen' => 'users' ] );
		$wp_list_table->items = [ get_userdata( $users[0] ), get_userdata( $users[1] ) ];
		try {
			$before = $wpdb->num_queries;
			do_action( 'manage_users_extra_tablenav', 'top' );
			$this->assertCount( 1, Manager::get_user_orgs( $users[0] ) );
			$this->assertCount( 1, Manager::get_user_orgs( $users[1] ) );
			$this->assertSame( 1, $wpdb->num_queries - $before );
			$this->assertFalse( wp_cache_get( "user_orgs_{$users[2]}", 'workos' ) );
		} finally {
			$wp_list_table = $previous_table;
		}
	}

}
