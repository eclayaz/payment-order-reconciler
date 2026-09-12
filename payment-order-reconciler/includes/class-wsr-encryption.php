<?php
/**
 * Encryption-at-rest for the Stripe restricted API key, closing the gap
 * TECHNICAL_SPEC.md always called for ("constant or encrypted option") but
 * this plugin never actually implemented — independent review, round 2,
 * flagged the option as stored in plain text.
 *
 * Threat model: this protects the key from someone who obtains a copy of
 * the database only — a leaked backup, an exposed phpMyAdmin/adminer
 * instance, a database dump shared over an insecure channel — without
 * also having the WordPress installation's own secret keys. It does NOT
 * protect against a fully compromised WordPress instance, since the
 * running code can always derive the same key and decrypt (no secret
 * exists that the site itself doesn't already have access to — there's
 * no external vault to delegate trust to here). That's the same
 * threat model every "encrypt using wp_salt()" WordPress plugin accepts,
 * since core has no Secrets API yet to build on instead.
 *
 * Key derivation depends on wp_salt('auth'), which in turn depends on the
 * AUTH_KEY/AUTH_SALT constants when defined in wp-config.php. If a site
 * later rotates those constants, this becomes permanently undecryptable
 * (get_api_key() then correctly returns '', the same "no key configured"
 * state as never having saved one — not a security bug, just means the
 * merchant needs to re-enter the key once). Accepted trade-off of having
 * no external secret store; documented here rather than silently risked.
 */

defined( 'ABSPATH' ) || exit;

class WSR_Encryption {

	const CIPHER = 'aes-256-gcm';

	/**
	 * Distinguishes our encrypted format from a legacy plaintext key saved
	 * before this class existed — a real restricted key always starts
	 * with `rk_live_`/`rk_test_`, never with this, so there's no
	 * ambiguity. Bump the version segment if the format ever changes.
	 */
	const PREFIX = 'wsr_enc_v1:';

	public static function is_encrypted( $stored ) {
		return is_string( $stored ) && '' !== $stored && 0 === strpos( $stored, self::PREFIX );
	}

	/**
	 * @param string $plaintext
	 * @return string Encrypted-and-prefixed, or the plaintext unchanged if
	 *                 OpenSSL isn't available / encryption fails — failing
	 *                 open to plaintext storage (the pre-existing behavior)
	 *                 rather than losing the merchant's key entirely.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}

		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$tag = '';
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- not a hashing use; genuine symmetric encryption of a secret we must be able to read back.
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::derive_key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $ciphertext ? $plaintext : self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * @param string $stored The raw option value — encrypted, legacy
	 *                        plaintext, or empty.
	 * @return string The plaintext key, or '' if $stored is our format but
	 *                 fails to decrypt (wrong/rotated site secret,
	 *                 corrupted data, or OpenSSL unavailable) — fails
	 *                 closed, never returns ciphertext or a partial value.
	 *                 A legacy plaintext (or empty) $stored passes through
	 *                 unchanged; callers are responsible for migrating it.
	 */
	public static function decrypt( $stored ) {
		if ( ! self::is_encrypted( $stored ) ) {
			return (string) $stored;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding this class's own binary ciphertext envelope for storage in a text column, not obfuscating code. $strict=true rejects anything that isn't valid base64 outright.
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw ) {
			return '';
		}

		$iv_len  = openssl_cipher_iv_length( self::CIPHER );
		$tag_len = 16; // GCM authentication tag — fixed size regardless of key/cipher variant.
		if ( strlen( $raw ) < $iv_len + $tag_len ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_len );
		$tag        = substr( $raw, $iv_len, $tag_len );
		$ciphertext = substr( $raw, $iv_len + $tag_len );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::derive_key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * 32 raw bytes (AES-256) derived from this site's own auth salt —
	 * stable across requests, unique per site, never itself stored
	 * anywhere by this plugin.
	 */
	private static function derive_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|woo-stripe-reconcile-api-key|', true );
	}
}
