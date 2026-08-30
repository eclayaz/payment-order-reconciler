<?php

class SettingsTest extends WSR_TestCase {

	// --- is_restricted_key_format -------------------------------------------

	public function test_accepts_restricted_test_key() {
		$this->assertTrue( WSR_Settings::is_restricted_key_format( 'rk_test_51ABC' ) );
	}

	public function test_accepts_restricted_live_key() {
		$this->assertTrue( WSR_Settings::is_restricted_key_format( 'rk_live_51ABC' ) );
	}

	public function test_rejects_a_regular_secret_key() {
		$this->assertFalse( WSR_Settings::is_restricted_key_format( 'sk_test_51ABC' ), 'A full-access secret key must never be accepted, even by mistake.' );
	}

	public function test_rejects_a_publishable_key() {
		$this->assertFalse( WSR_Settings::is_restricted_key_format( 'pk_test_51ABC' ) );
	}

	public function test_rejects_garbage_input() {
		$this->assertFalse( WSR_Settings::is_restricted_key_format( 'not-a-key-at-all' ) );
		$this->assertFalse( WSR_Settings::is_restricted_key_format( '' ) );
	}

	// --- get_api_key() / key_is_from_constant() -----------------------------

	public function test_get_api_key_reads_from_option_when_no_constant_defined() {
		update_option( WSR_Settings::OPTION_API_KEY, 'rk_test_optionvalue' );
		$this->assertSame( 'rk_test_optionvalue', WSR_Settings::get_api_key() );
		$this->assertFalse( WSR_Settings::key_is_from_constant() );
	}

	public function test_get_api_key_returns_empty_string_when_nothing_configured() {
		$this->assertSame( '', WSR_Settings::get_api_key() );
	}

	// --- encryption-at-rest (independent review, round 2) -------------------

	public function test_get_api_key_transparently_migrates_a_legacy_plaintext_value() {
		// Simulates a key saved before encryption-at-rest existed.
		update_option( WSR_Settings::OPTION_API_KEY, 'rk_test_legacyplaintext' );

		$this->assertSame( 'rk_test_legacyplaintext', WSR_Settings::get_api_key(), 'Must keep working immediately, with no merchant action required.' );

		$stored = get_option( WSR_Settings::OPTION_API_KEY );
		$this->assertTrue( WSR_Encryption::is_encrypted( $stored ), 'The very next read must have upgraded the stored value to encrypted form.' );
		$this->assertStringNotContainsString( 'rk_test_legacyplaintext', $stored );
	}

	public function test_get_api_key_reads_back_an_already_encrypted_value() {
		update_option( WSR_Settings::OPTION_API_KEY, WSR_Encryption::encrypt( 'rk_test_alreadyencrypted' ), false );
		$this->assertSame( 'rk_test_alreadyencrypted', WSR_Settings::get_api_key() );
	}

	// --- environment_warning() ----------------------------------------------

	public function test_no_warning_when_no_key_configured() {
		$this->assertSame( '', WSR_Settings::environment_warning() );
	}

	public function test_no_warning_for_a_test_key_on_a_non_production_environment_type() {
		update_option( WSR_Settings::OPTION_API_KEY, 'rk_test_abc' );
		$this->assertSame( 'local', wp_get_environment_type(), 'Sanity check: the WP test suite itself runs as environment type "local".' );
		$this->assertSame( '', WSR_Settings::environment_warning(), 'A test key on a non-production environment is the expected, unremarkable case.' );
	}

	public function test_warns_on_a_live_key_when_environment_type_is_non_production() {
		update_option( WSR_Settings::OPTION_API_KEY, 'rk_live_abc' );
		$this->assertNotSame( '', WSR_Settings::environment_warning(), 'A LIVE key on a non-production environment type should be flagged.' );
	}
}
