<?php
/**
 * The confirmation gate's token lifecycle.
 *
 * Every case here is a defect that reached a review round. They are ordering and identity
 * claims, which is why they belong in a test rather than in a comment.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use WooCommerce\AgentTools\Safety\Confirm;

/**
 * The confirmation gate's token lifecycle.
 */
final class ConfirmTest extends TestCase {

	/**
	 * The gate under test.
	 *
	 * @var Confirm
	 */
	private $confirm;

	protected function setUp(): void {
		WPOptions::reset();
		$this->confirm = new Confirm();
	}

	/**
	 * A first call refuses and hands back a token.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input.
	 */
	private function preview( string $ability, array $input ): string {
		$refusal = $this->confirm->gate( $ability, 'Do a thing', $input );
		$this->assertInstanceOf( WP_Error::class, $refusal );

		return (string) $refusal->get_error_data()['confirm'];
	}

	/**
	 * The private claim, which is where the ordering guarantees live.
	 *
	 * @param string $token Token.
	 * @return string 'claimed', 'lost' or 'dead'.
	 */
	private function claim( string $token ): string {
		$method = new ReflectionMethod( Confirm::class, 'claim' );
		$method->setAccessible( true );

		return (string) $method->invoke( $this->confirm, $token );
	}

	private function armed( string $token ): bool {
		return false !== get_transient( Confirm::PREFIX . $token );
	}

	private function spent( string $token ): bool {
		return false !== get_transient( Confirm::SPENT_PREFIX . $token );
	}

	public function test_a_first_call_runs_nothing_and_arms_a_token(): void {
		$refusal = $this->confirm->gate( 'test/write', 'Do a thing', array( 'id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'wcat_confirmation_required', $refusal->get_error_code() );
		$this->assertSame( 428, $refusal->get_error_data()['status'] );
		$this->assertSame( Confirm::TTL, $refusal->get_error_data()['expires_in'] );
		$this->assertTrue( $this->armed( (string) $refusal->get_error_data()['confirm'] ) );
	}

	public function test_the_matching_token_runs_once(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );

		$this->assertTrue(
			$this->confirm->gate(
				'test/write',
				'Do a thing',
				array(
					'id'      => 1,
					'confirm' => $token,
				)
			)
		);
		$this->assertFalse( $this->armed( $token ), 'the armed token is consumed' );
		$this->assertTrue( $this->spent( $token ), 'the spend is recorded' );
	}

	public function test_resending_a_spent_token_is_refused(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );
		$this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$again = $this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'wcat_confirmation_spent', $again->get_error_code() );
		$this->assertFalse( $this->armed( $token ), 'a refusal must not re-arm a spent token' );
	}

	/**
	 * The token is derived from the payload, so starting over produces the same one. Without
	 * the marker read on this path the caller was handed it back under a fresh preview.
	 */
	public function test_restarting_without_a_token_is_refused_and_offers_none(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );
		$this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$restart = $this->confirm->gate( 'test/write', 'Do a thing', array( 'id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $restart );
		$this->assertSame( 'wcat_confirmation_spent', $restart->get_error_code() );
		$this->assertArrayNotHasKey( 'confirm', (array) $restart->get_error_data() );
		$this->assertFalse( $this->armed( $token ) );
	}

	public function test_a_different_payload_is_unaffected_by_a_spend(): void {
		$first = $this->preview( 'test/write', array( 'id' => 1 ) );
		$this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $first,
			)
		);

		$second = $this->preview( 'test/write', array( 'id' => 2 ) );

		$this->assertNotSame( $first, $second );
		$this->assertTrue(
			$this->confirm->gate(
				'test/write',
				'Do a thing',
				array(
					'id'      => 2,
					'confirm' => $second,
				)
			)
		);
	}

	public function test_an_expired_token_is_refused_but_re_armed_for_a_fresh_preview(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );
		WPOptions::travel( Confirm::TTL + 1 );

		$refusal = $this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'wcat_confirmation_required', $refusal->get_error_code() );
		$this->assertTrue( $this->armed( $token ), 'an expired confirmation can be asked for again' );
	}

	public function test_a_forged_token_is_refused(): void {
		$this->preview( 'test/write', array( 'id' => 1 ) );

		$refusal = $this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => str_repeat( 'a', 32 ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'wcat_confirmation_required', $refusal->get_error_code() );
	}

	public function test_claim_reports_dead_for_a_token_never_issued(): void {
		$this->assertSame( 'dead', $this->claim( str_repeat( 'b', 32 ) ) );
		$this->assertFalse( $this->spent( str_repeat( 'b', 32 ) ), 'a dead token leaves no mark' );
	}

	/**
	 * The loser read the token as live and its own delete found nothing, so another caller
	 * claimed it. The marker it wrote on the way through stays.
	 */
	public function test_claim_reports_lost_when_another_caller_got_there_first(): void {
		$token                          = $this->preview( 'test/write', array( 'id' => 1 ) );
		WPOptions::$vanish_after_read[] = Confirm::PREFIX . $token;

		$this->assertSame( 'lost', $this->claim( $token ) );
		$this->assertTrue( $this->spent( $token ), 'a lost race still means someone claimed it' );
	}

	/**
	 * A delete that fails with the row still present is a storage failure, not a race, and
	 * treating it as spent locked the payload out over a call that never ran.
	 */
	public function test_a_storage_failure_does_not_lock_the_payload_out(): void {
		$token                    = $this->preview( 'test/write', array( 'id' => 1 ) );
		WPOptions::$undeletable[] = Confirm::PREFIX . $token;

		$refusal = $this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->assertSame( 'wcat_confirmation_required', $refusal->get_error_code(), 'not reported as already run' );
		$this->assertFalse( $this->spent( $token ), 'its own marker is withdrawn' );
	}

	/**
	 * The marker is written before the token stops being claimable. With the other order
	 * there was a window where a restart re-armed a token it could then redeem.
	 */
	public function test_a_lost_race_leaves_the_spend_recorded_and_the_token_gone(): void {
		$token                          = $this->preview( 'test/write', array( 'id' => 1 ) );
		WPOptions::$vanish_after_read[] = Confirm::PREFIX . $token;

		$this->claim( $token );

		$this->assertTrue( $this->spent( $token ) );
		$this->assertFalse( $this->armed( $token ) );
	}

	public function test_release_gives_a_confirmation_back_when_the_call_did_not_run(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );
		$this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->confirm->release( 'test/write' );

		$this->assertTrue( $this->armed( $token ) );
		$this->assertFalse( $this->spent( $token ) );
	}

	public function test_release_cannot_re_arm_a_call_that_committed(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );
		$this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->confirm->commit( 'test/write' );
		$this->confirm->release( 'test/write' );

		$this->assertFalse( $this->armed( $token ), 'a call that ran keeps its confirmation spent' );
		$this->assertTrue( $this->spent( $token ) );
	}

	public function test_release_is_a_no_op_for_anything_it_did_not_claim(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );
		$this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->confirm->release( 'test/other' );
		$this->assertFalse( $this->armed( $token ) );

		$this->confirm->release( 'test/write' );
		$this->confirm->release( 'test/write' );
		$this->assertTrue( $this->armed( $token ), 'releasing twice is releasing once' );
	}

	public function test_release_with_nothing_claimed_does_nothing(): void {
		$this->confirm->release( 'test/never' );

		$this->assertSame( array(), WPOptions::$options );
	}

	public function test_the_token_is_a_function_of_the_payload_and_not_its_order(): void {
		$one = $this->preview(
			'test/write',
			array(
				'a' => 1,
				'b' => 2,
			)
		);
		WPOptions::reset();
		$two = $this->preview(
			'test/write',
			array(
				'b' => 2,
				'a' => 1,
			)
		);

		$this->assertSame( $one, $two );
	}

	public function test_the_token_ignores_the_confirm_field_itself(): void {
		$bare = $this->preview( 'test/write', array( 'id' => 1 ) );
		WPOptions::reset();
		$with = $this->preview(
			'test/write',
			array(
				'id'      => 1,
				'confirm' => 'anything',
			)
		);

		$this->assertSame( $bare, $with );
	}

	public function test_a_token_does_not_authorise_another_ability(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );

		$other = $this->confirm->gate(
			'test/other',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $other );
		$this->assertSame( 'wcat_confirmation_required', $other->get_error_code() );
	}

	public function test_a_token_does_not_authorise_another_user(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );

		WPOptions::$user_id = 2;
		$refusal            = $this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'wcat_confirmation_required', $refusal->get_error_code() );
		$this->assertNotSame( $token, (string) $refusal->get_error_data()['confirm'], 'each user gets their own token' );
	}

	/**
	 * Sorting only the top level would make a nested payload's key order change the token, so
	 * a client that serialised an object differently could never confirm what it previewed.
	 */
	public function test_the_token_ignores_key_order_at_every_depth(): void {
		$one = $this->preview(
			'test/write',
			array(
				'line' => array(
					'sku' => 'a',
					'qty' => 2,
				),
			)
		);
		WPOptions::reset();
		$two = $this->preview(
			'test/write',
			array(
				'line' => array(
					'qty' => 2,
					'sku' => 'a',
				),
			)
		);

		$this->assertSame( $one, $two );
	}

	/**
	 * The record of a spend outlives the token it spent, because the refusal and the docs both
	 * promise the payload is held for ten minutes rather than for the token's own five.
	 */
	public function test_a_spend_is_remembered_after_its_token_would_have_expired(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );
		$this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'      => 1,
				'confirm' => $token,
			)
		);

		WPOptions::travel( Confirm::TTL + 1 );
		$inside = $this->confirm->gate( 'test/write', 'Do a thing', array( 'id' => 1 ) );
		$this->assertSame( 'wcat_confirmation_spent', $inside->get_error_code() );

		WPOptions::travel( Confirm::TTL * 2 );
		$after = $this->confirm->gate( 'test/write', 'Do a thing', array( 'id' => 1 ) );
		$this->assertSame( 'wcat_confirmation_required', $after->get_error_code(), 'and no longer than that' );
	}

	public function test_an_underscored_field_name_is_not_shown_as_code(): void {
		$refusal = $this->confirm->gate( 'test/write', 'Do a thing', array( 'discount_type' => 'percent' ) );

		$message = $refusal->get_error_message();
		$this->assertStringContainsString( 'discount type: percent', $message );
		$this->assertStringNotContainsString( 'discount_type=', $message );
	}

	public function test_the_preview_states_the_arguments_it_would_send(): void {
		$refusal = $this->confirm->gate(
			'test/write',
			'Create a coupon',
			array(
				'code'   => 'summer',
				'amount' => 5,
				'force'  => false,
				'parent' => null,
			)
		);

		$message = $refusal->get_error_message();
		$this->assertStringContainsString( 'Create a coupon', $message );
		$this->assertStringContainsString( 'code: summer', $message );
		$this->assertStringContainsString( 'force: false', $message, 'a false must not read as absent' );
		$this->assertStringContainsString( 'parent: null', $message );
	}

	public function test_an_opaque_preview_names_the_arguments_the_agent_inferred(): void {
		$refusal = $this->confirm->gate(
			'test/tos',
			'Record ToS acceptance.',
			array( 'accepted' => true ),
			'https://example.test/wp-admin/',
			true
		);

		$message = $refusal->get_error_message();
		$this->assertStringContainsString( 'declares no parameters', $message );
		$this->assertStringContainsString( 'accepted: true', $message, 'what the merchant approves must be visible' );
		$this->assertStringContainsString( 'https://example.test/wp-admin/', $message );
		$this->assertSame( 'https://example.test/wp-admin/', $refusal->get_error_data()['complete_in_admin'] );
	}

	public function test_an_opaque_preview_with_no_arguments_says_so(): void {
		$refusal = $this->confirm->gate( 'test/refresh', 'Refresh service data.', array(), '', true );

		$message = $refusal->get_error_message();
		$this->assertStringContainsString( 'Nothing is being sent with it.', $message );
		$this->assertStringContainsString( "in this store's wp-admin", $message, 'no screen is named when none passed the origin check' );
		$this->assertArrayNotHasKey( 'complete_in_admin', (array) $refusal->get_error_data() );
	}

	public function test_a_long_multibyte_value_is_truncated_without_breaking_the_message(): void {
		$refusal = $this->confirm->gate( 'test/write', 'Do a thing', array( 'note' => str_repeat( 'あ', 300 ) ) );

		$message = $refusal->get_error_message();
		$this->assertStringContainsString( str_repeat( 'あ', 200 ) . '…', $message, 'capped at 200 characters' );
		$this->assertStringNotContainsString( str_repeat( 'あ', 201 ), $message, 'and no further' );
		$this->assertSame( 1, preg_match( '//u', $message ), 'truncation must not split a character' );
		$this->assertNotFalse( wp_json_encode( $message ) );
	}

	/**
	 * Every write and no read, which is the whole of the docblock's claim. Two data points let
	 * a predicate keyed on the method itself through, and that leaves PUT and PATCH ungated:
	 * in WooCommerce those are how an order, a price and a coupon get changed.
	 *
	 * @dataProvider methods
	 * @param string $method  HTTP method.
	 * @param bool   $is_read Whether the compiler judged the call read-only.
	 * @param bool   $gated   Whether it must be confirmed.
	 */
	public function test_every_write_needs_confirming_and_no_read_does( string $method, bool $is_read, bool $gated ): void {
		$this->assertSame( $gated, $this->confirm->required( '/wc/v3/orders', $method, $is_read ) );
	}

	/**
	 * Every method the compiler can hand the gate, with how it should be judged.
	 *
	 * @return array<string,array{0:string,1:bool,2:bool}>
	 */
	public function methods(): array {
		return array(
			'GET'     => array( 'GET', true, false ),
			'HEAD'    => array( 'HEAD', true, false ),
			'OPTIONS' => array( 'OPTIONS', true, false ),
			'POST'    => array( 'POST', false, true ),
			'PUT'     => array( 'PUT', false, true ),
			'PATCH'   => array( 'PATCH', false, true ),
			'DELETE'  => array( 'DELETE', false, true ),
		);
	}

	public function test_the_gating_decision_is_filterable(): void {
		add_filter(
			'woocommerce_agent_tools_route_needs_confirmation',
			function () {
				return false;
			}
		);

		$this->assertFalse(
			$this->confirm->required( '/wc/v3/orders', 'POST', false ),
			'the published filter must be consulted'
		);
	}

	/**
	 * The gate promises a lifetime in `expires_in` and arms the token separately, and nothing
	 * tied the two together: it could advertise five minutes and arm for thirty seconds.
	 */
	public function test_a_token_lives_as_long_as_the_preview_said_it_would(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );

		WPOptions::travel( Confirm::TTL - 1 );

		$this->assertTrue(
			$this->confirm->gate(
				'test/write',
				'Do a thing',
				array(
					'id'      => 1,
					'confirm' => $token,
				)
			)
		);
	}

	/**
	 * A payload the encoder cannot represent cannot be told apart from another one, and
	 * hashing the failure gave every such payload the same token.
	 */
	public function test_a_payload_that_cannot_be_identified_is_refused_outright(): void {
		$deep = array( 'a' => 1 );
		for ( $i = 0; $i < 600; $i++ ) {
			$deep = array( 'nested' => $deep );
		}

		$refusal = $this->confirm->gate( 'test/write', 'Do a thing', $deep );

		$this->assertInstanceOf( WP_Error::class, $refusal );
		$this->assertSame( 'wcat_unidentifiable_payload', $refusal->get_error_code() );
		$this->assertArrayNotHasKey( 'confirm', (array) $refusal->get_error_data() );
	}

	public function test_the_token_is_long_enough_to_be_unguessable(): void {
		$token = $this->preview( 'test/write', array( 'id' => 1 ) );

		$this->assertSame( 1, preg_match( '/^[0-9a-f]{32}$/', $token ) );
	}

	public function test_the_refusal_carries_the_arguments_a_client_can_read(): void {
		$refusal = $this->confirm->gate(
			'test/write',
			'Do a thing',
			array(
				'id'   => 7,
				'note' => 'x',
			)
		);

		$this->assertSame(
			array(
				'id'   => 7,
				'note' => 'x',
			),
			$refusal->get_error_data()['arguments_previewed']
		);
	}
}
