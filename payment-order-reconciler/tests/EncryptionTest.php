<?php

class EncryptionTest extends WSR_TestCase {

	public function test_encrypt_then_decrypt_round_trips() {
		$encrypted = WSR_Encryption::encrypt( 'rk_test_abc123' );
		$this->assertSame( 'rk_test_abc123', WSR_Encryption::decrypt( $encrypted ) );
	}

	public function test_encrypted_value_is_prefixed_and_not_the_plaintext() {
		$encrypted = WSR_Encryption::encrypt( 'rk_test_abc123' );
		$this->assertStringStartsWith( 'wsr_enc_v1:', $encrypted );
		$this->assertStringNotContainsString( 'rk_test_abc123', $encrypted, 'The plaintext key must never appear verbatim in the stored value.' );
	}

	public function test_is_encrypted_detects_the_format() {
		$encrypted = WSR_Encryption::encrypt( 'rk_test_abc123' );
		$this->assertTrue( WSR_Encryption::is_encrypted( $encrypted ) );
	}

	public function test_is_encrypted_false_for_legacy_plaintext() {
		$this->assertFalse( WSR_Encryption::is_encrypted( 'rk_test_abc123' ) );
	}

	public function test_is_encrypted_false_for_empty_string() {
		$this->assertFalse( WSR_Encryption::is_encrypted( '' ) );
	}

	public function test_decrypt_of_legacy_plaintext_passes_through_unchanged() {
		// Callers (WSR_Settings::get_api_key()) are responsible for
		// migrating this — decrypt() itself must not try to "fix" it.
		$this->assertSame( 'rk_test_abc123', WSR_Encryption::decrypt( 'rk_test_abc123' ) );
	}

	public function test_decrypt_of_empty_string_returns_empty_string() {
		$this->assertSame( '', WSR_Encryption::decrypt( '' ) );
	}

	public function test_encrypt_of_empty_string_returns_empty_string() {
		// Must never produce a non-empty "encrypted empty string" — that
		// would make get_api_key() treat "no key configured" as configured.
		$this->assertSame( '', WSR_Encryption::encrypt( '' ) );
	}

	public function test_decrypt_fails_closed_on_tampered_ciphertext() {
		$encrypted = WSR_Encryption::encrypt( 'rk_test_abc123' );
		// Flip a character inside the base64 payload — GCM's auth tag must
		// reject this, not decrypt to silently-wrong bytes.
		$tampered = substr( $encrypted, 0, -4 ) . 'XXXX';
		$this->assertSame( '', WSR_Encryption::decrypt( $tampered ), 'Tampered/corrupted ciphertext must fail closed (empty string), never return garbage as if it were the real key.' );
	}

	public function test_decrypt_fails_closed_on_truncated_payload() {
		$encrypted = WSR_Encryption::encrypt( 'rk_test_abc123' );
		$truncated = substr( $encrypted, 0, strlen( $encrypted ) - 20 );
		$this->assertSame( '', WSR_Encryption::decrypt( $truncated ) );
	}

	public function test_two_encryptions_of_the_same_plaintext_differ() {
		// A fresh random IV every call — confirms this isn't a naive
		// deterministic scheme (e.g. plain hashing) that would leak
		// whether two stored keys are identical.
		$a = WSR_Encryption::encrypt( 'rk_test_abc123' );
		$b = WSR_Encryption::encrypt( 'rk_test_abc123' );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 'rk_test_abc123', WSR_Encryption::decrypt( $a ) );
		$this->assertSame( 'rk_test_abc123', WSR_Encryption::decrypt( $b ) );
	}
}
