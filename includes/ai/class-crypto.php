<?php
/**
 * Modulo AI — cifratura della chiave API.
 *
 * La chiave OpenAI è salvata cifrata in wp_options (§6) e mai esposta al
 * client. Usa libsodium (presente in PHP 8.1+); la chiave di cifratura è
 * derivata dai salt di WordPress, quindi non viene mai memorizzata.
 *
 * @package Palladio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cifratura simmetrica per i segreti del plugin.
 */
class Palladio_AI_Crypto {

	/**
	 * Verifica la disponibilità di libsodium.
	 *
	 * @return bool
	 */
	public static function has_sodium() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * Deriva la chiave di cifratura dai salt di WordPress.
	 *
	 * @return string Chiave binaria di lunghezza corretta.
	 */
	private static function key() {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Cifra un valore.
	 *
	 * @param string $plaintext Valore in chiaro.
	 * @return string Stringa opaca da salvare (vuota se input vuoto).
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! self::has_sodium() ) {
			// Fallback: offuscamento (non è cifratura reale).
			return 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return 'v1:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decifra un valore prodotto da encrypt().
	 *
	 * @param string $stored Valore salvato.
	 * @return string Valore in chiaro (vuoto se non decifrabile).
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		if ( 0 !== strpos( $stored, 'v1:' ) || ! self::has_sodium() ) {
			return '';
		}

		$decoded = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return ( false === $plain ) ? '' : $plain;
	}
}
