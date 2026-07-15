<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encodes and decodes the 7-char USCF round-result token used in the
 * D_RNDnn fields of TDEXPORT: result letter + opponent pairing number
 * (compact decimal, no zero padding) + color letter for played games,
 * right-padded with spaces to 7 chars.
 *
 * Legend recovered from the US Chess TD/Affiliate FAQ (docs/SPEC.md):
 * - W/L/D: played, rated - opponent + color required.
 * - B/H/U: byes / not paired - opponent is always 0, no color.
 * - X/F/Z: forfeits - opponent required, no color (per docs/SPEC.md's residual
 *   open question, assumed - unconfirmed against a real forfeit export).
 * - N/S/R: asymmetric special-use, structurally identical to W/L/D.
 * - I: correspondence-only "game in progress" - never used here, rejected.
 */
class WPMTM_Round_Token {

	const WIDTH = 7;

	/** result codes that carry an opponent + color (played games) */
	const PLAYED = array( 'W', 'L', 'D', 'N', 'S', 'R' );

	/** result codes with no opponent and no color (byes / unpaired) */
	const NO_OPPONENT = array( 'B', 'H', 'U' );

	/** result codes with an opponent but no color (forfeits) */
	const FORFEIT = array( 'X', 'F', 'Z' );

	/** unsupported - correspondence-only, never used here */
	const UNSUPPORTED = array( 'I' );

	/**
	 * @param string   $result   Single result letter, case-insensitive.
	 * @param int      $opponent Opponent pairing number. Must be 0 for
	 *                           B/H/U, ignored otherwise if 0 is passed
	 *                           for those codes.
	 * @param string|null $color 'W' or 'B'. Required for played/asymmetric
	 *                           codes, must be omitted for byes/forfeits.
	 * @return string 7-char right-padded token.
	 */
	public static function encode( $result, $opponent = 0, $color = null ) {
		$result = strtoupper( (string) $result );

		if ( in_array( $result, self::UNSUPPORTED, true ) ) {
			throw new InvalidArgumentException( "round result code '$result' is unsupported (correspondence-only)" );
		}

		if ( in_array( $result, self::NO_OPPONENT, true ) ) {
			if ( 0 !== (int) $opponent ) {
				throw new InvalidArgumentException( "round result '$result' must have opponent 0 (bye/unpaired)" );
			}
			$token = $result . '0';
		} elseif ( in_array( $result, self::FORFEIT, true ) ) {
			$token = $result . self::validate_opponent( $opponent );
		} elseif ( in_array( $result, self::PLAYED, true ) ) {
			$color = strtoupper( (string) $color );
			if ( 'W' !== $color && 'B' !== $color ) {
				throw new InvalidArgumentException( "round result '$result' requires color 'W' or 'B'" );
			}
			$token = $result . self::validate_opponent( $opponent ) . $color;
		} else {
			throw new InvalidArgumentException( "unknown round result code '$result'" );
		}

		if ( strlen( $token ) > self::WIDTH ) {
			throw new InvalidArgumentException( "encoded round token exceeds " . self::WIDTH . " chars: '$token'" );
		}

		return str_pad( $token, self::WIDTH, ' ', STR_PAD_RIGHT );
	}

	protected static function validate_opponent( $opponent ) {
		if ( ! is_numeric( $opponent ) || (int) $opponent < 1 ) {
			throw new InvalidArgumentException( 'opponent pairing number must be a positive integer, got: ' . var_export( $opponent, true ) );
		}
		return (string) (int) $opponent;
	}

	/**
	 * Decodes a 7-char (or shorter, pre-trim) token back to its parts.
	 *
	 * @return array{result:string, opponent:int, color:?string}
	 */
	public static function decode( $token ) {
		$trimmed = rtrim( (string) $token, ' ' );
		if ( '' === $trimmed ) {
			throw new InvalidArgumentException( 'empty round token' );
		}

		$result = strtoupper( $trimmed[0] );
		$rest   = substr( $trimmed, 1 );

		if ( in_array( $result, self::UNSUPPORTED, true ) ) {
			throw new InvalidArgumentException( "round result code '$result' is unsupported (correspondence-only)" );
		}

		if ( in_array( $result, self::NO_OPPONENT, true ) ) {
			if ( '0' !== $rest ) {
				throw new InvalidArgumentException( "malformed token for '$result': '$token'" );
			}
			return array(
				'result'   => $result,
				'opponent' => 0,
				'color'    => null,
			);
		}

		if ( in_array( $result, self::FORFEIT, true ) ) {
			if ( '' === $rest || ! ctype_digit( $rest ) ) {
				throw new InvalidArgumentException( "malformed token for '$result': '$token'" );
			}
			return array(
				'result'   => $result,
				'opponent' => (int) $rest,
				'color'    => null,
			);
		}

		if ( in_array( $result, self::PLAYED, true ) ) {
			$color = strtoupper( substr( $rest, -1 ) );
			$num   = substr( $rest, 0, -1 );
			if ( ( 'W' !== $color && 'B' !== $color ) || '' === $num || ! ctype_digit( $num ) ) {
				throw new InvalidArgumentException( "malformed token for '$result': '$token'" );
			}
			return array(
				'result'   => $result,
				'opponent' => (int) $num,
				'color'    => $color,
			);
		}

		throw new InvalidArgumentException( "unknown round result code '$result'" );
	}
}
