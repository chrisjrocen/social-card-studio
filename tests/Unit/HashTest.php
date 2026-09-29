<?php
/**
 * Hash determinism tests.
 *
 * Covers SPEC.md §9.1 — a hash that is not stable would regenerate every card on the
 * site for no reason.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Tests\Unit;

use ChrxDigital\SocialCardStudio\Support\Hash;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Support\Hash.
 */
final class HashTest extends TestCase {

	/**
	 * The same structure hashes identically across calls.
	 *
	 * @return void
	 */
	public function test_hash_is_deterministic(): void {
		$data = array(
			'template' => 'editorial-left',
			'version'  => 3,
		);

		$this->assertSame( Hash::of( $data ), Hash::of( $data ) );
	}

	/**
	 * Key order does not affect the digest.
	 *
	 * @return void
	 */
	public function test_key_order_does_not_change_the_hash(): void {
		$a = array(
			'b' => 2,
			'a' => 1,
		);
		$b = array(
			'a' => 1,
			'b' => 2,
		);

		$this->assertSame( Hash::of( $a ), Hash::of( $b ) );
	}

	/**
	 * A changed value changes the digest.
	 *
	 * @return void
	 */
	public function test_changed_value_changes_the_hash(): void {
		$this->assertNotSame(
			Hash::of( array( 'title' => 'Backups are lying to you' ) ),
			Hash::of( array( 'title' => 'Backups are lying to me' ) )
		);
	}

	/**
	 * Types are distinguished, so "1" and 1 and true do not collide.
	 *
	 * @return void
	 */
	public function test_types_are_distinguished(): void {
		$this->assertNotSame( Hash::of( array( 'v' => true ) ), Hash::of( array( 'v' => 1 ) ) );
		$this->assertNotSame( Hash::of( array( 'v' => null ) ), Hash::of( array( 'v' => '' ) ) );
	}

	/**
	 * Nesting is not flattened: two different shapes hash differently.
	 *
	 * @return void
	 */
	public function test_nesting_is_significant(): void {
		$this->assertNotSame(
			Hash::of( array( 'a' => array( 'b' => 1 ) ) ),
			Hash::of( array( 'a' => 'b:1' ) )
		);
	}

	/**
	 * Float formatting is stable regardless of precision settings.
	 *
	 * @return void
	 */
	public function test_floats_are_formatted_stably(): void {
		$this->assertSame( Hash::of( array( 'q' => 0.1 + 0.2 ) ), Hash::of( array( 'q' => 0.3 ) ) );
	}

	/**
	 * The filename hash is eight characters.
	 *
	 * @return void
	 */
	public function test_short_hash_length(): void {
		$this->assertSame( 8, strlen( Hash::short( array( 'post' => 1482 ) ) ) );
	}

	/**
	 * Requested lengths are clamped into range.
	 *
	 * @return void
	 */
	public function test_length_is_clamped(): void {
		$this->assertSame( 40, strlen( Hash::of( 'x', 999 ) ) );
		$this->assertSame( 1, strlen( Hash::of( 'x', 0 ) ) );
	}
}
