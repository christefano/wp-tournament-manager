<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic dBASE III (.dbf) writer, Character-type fields only.
 *
 * Produces the binary layout USCF rating-report exports use for uploads:
 * 32-byte file header, one 32-byte field descriptor
 * per field, 0x0D descriptor terminator, fixed-length records prefixed with
 * a 0x20 delete flag, 0x1A end-of-file marker. Verified byte-for-byte
 * against tests/fixtures/*.DBF via tests/inspect-fixtures.php - see
 * docs/SPEC.md for the recovered schema.
 *
 * No numeric/date DBF field types - everything is Character, left-aligned,
 * space-padded ASCII, per the source format.
 */
class WPMTM_DBF_Writer {

	const VERSION_BYTE = 0x03;
	const DELETE_FLAG  = 0x20;
	const FIELD_TERM   = 0x0D;
	const EOF_BYTE     = 0x1A;
	const MAX_NAME_LEN = 10;

	/** @var array[] field definitions: ['name' => string, 'length' => int, 'type' => 'C'] */
	protected $fields = array();

	/** @var array[] records, each an assoc array keyed by field name */
	protected $records = array();

	/** @var array{year:int,month:int,day:int} */
	protected $update_date;

	/**
	 * @param array[]    $fields      Field definitions (name, length; type defaults to 'C').
	 * @param array[]    $records     Optional initial records (assoc arrays keyed by field name).
	 * @param array|null $update_date Optional ['year'=>, 'month'=>, 'day'=>] (year = calendar year, not offset).
	 */
	public function __construct( array $fields, array $records = array(), ?array $update_date = null ) {
		foreach ( $fields as $field ) {
			$this->validate_field_def( $field );
		}
		$this->fields = $fields;

		if ( null === $update_date ) {
			$update_date = array(
				'year'  => (int) date( 'Y' ),
				'month' => (int) date( 'n' ),
				'day'   => (int) date( 'j' ),
			);
		}
		$this->set_update_date( $update_date['year'], $update_date['month'], $update_date['day'] );

		foreach ( $records as $record ) {
			$this->add_record( $record );
		}
	}

	/**
	 * Sets the file's update-date header bytes. Exposed as a setter (not
	 * just a constructor arg) so tests can pin it to a fixture's own date
	 * without reconstructing the whole writer.
	 */
	public function set_update_date( $year, $month, $day ) {
		$year  = (int) $year;
		$month = (int) $month;
		$day   = (int) $day;

		if ( $year < 1900 || $year > 2155 ) {
			throw new InvalidArgumentException( 'update date year out of dBASE III range (1900-2155): ' . $year );
		}
		if ( $month < 1 || $month > 12 ) {
			throw new InvalidArgumentException( 'update date month out of range: ' . $month );
		}
		if ( $day < 1 || $day > 31 ) {
			throw new InvalidArgumentException( 'update date day out of range: ' . $day );
		}

		$this->update_date = array(
			'year'  => $year,
			'month' => $month,
			'day'   => $day,
		);
	}

	/**
	 * Appends one record. Missing fields are treated as empty; every value
	 * is validated ASCII and length-checked against its field definition
	 * before storage.
	 */
	public function add_record( array $record ) {
		$row = array();
		foreach ( $this->fields as $field ) {
			$name  = $field['name'];
			$value = array_key_exists( $name, $record ) ? (string) $record[ $name ] : '';
			$this->validate_value( $field, $value );
			$row[ $name ] = $value;
		}
		$this->records[] = $row;
	}

	protected function validate_field_def( array $field ) {
		if ( empty( $field['name'] ) ) {
			throw new InvalidArgumentException( 'field definition missing name' );
		}
		if ( ! $this->is_ascii( $field['name'] ) ) {
			throw new InvalidArgumentException( 'field name must be ASCII: ' . $field['name'] );
		}
		if ( strlen( $field['name'] ) > self::MAX_NAME_LEN ) {
			throw new InvalidArgumentException( 'field name exceeds ' . self::MAX_NAME_LEN . ' chars: ' . $field['name'] );
		}
		$type = isset( $field['type'] ) ? $field['type'] : 'C';
		if ( 'C' !== $type ) {
			throw new InvalidArgumentException( 'only Character (C) fields are supported: ' . $field['name'] );
		}
		if ( ! isset( $field['length'] ) || $field['length'] < 1 || $field['length'] > 254 ) {
			throw new InvalidArgumentException( 'field length must be 1-254: ' . $field['name'] );
		}
	}

	/**
	 * ASCII-refuse rather than corrupt: a non-ASCII value would silently
	 * mis-align every byte offset after it for downstream fixed-width
	 * readers, so this throws instead of transliterating or truncating.
	 */
	protected function validate_value( array $field, $value ) {
		if ( ! $this->is_ascii( $value ) ) {
			throw new InvalidArgumentException( 'field ' . $field['name'] . ' value is not ASCII: ' . $value );
		}
		if ( strlen( $value ) > $field['length'] ) {
			throw new InvalidArgumentException(
				'field ' . $field['name'] . ' value exceeds declared length ' . $field['length'] . ' (' . strlen( $value ) . '): ' . $value
			);
		}
	}

	protected function is_ascii( $s ) {
		return (bool) preg_match( '/^[\x00-\x7F]*$/', $s );
	}

	public function record_length() {
		$sum = 1; // delete flag byte
		foreach ( $this->fields as $field ) {
			$sum += $field['length'];
		}
		return $sum;
	}

	public function header_length() {
		return 32 + 32 * count( $this->fields ) + 1;
	}

	/**
	 * Builds the full binary file contents.
	 */
	public function build() {
		$out  = $this->build_file_header();
		$out .= $this->build_field_descriptors();
		$out .= chr( self::FIELD_TERM );
		foreach ( $this->records as $record ) {
			$out .= $this->build_record( $record );
		}
		$out .= chr( self::EOF_BYTE );
		return $out;
	}

	protected function build_file_header() {
		$header  = chr( self::VERSION_BYTE );
		$header .= chr( $this->update_date['year'] - 1900 );
		$header .= chr( $this->update_date['month'] );
		$header .= chr( $this->update_date['day'] );
		$header .= pack( 'V', count( $this->records ) );
		$header .= pack( 'v', $this->header_length() );
		$header .= pack( 'v', $this->record_length() );
		$header .= str_repeat( "\0", 20 ); // reserved - zero in these exports
		return $header;
	}

	protected function build_field_descriptors() {
		$out = '';
		foreach ( $this->fields as $field ) {
			$out .= str_pad( substr( $field['name'], 0, self::MAX_NAME_LEN ), 11, "\0" );
			$out .= 'C';
			$out .= str_repeat( "\0", 4 );  // field data address - unused, zero in these exports
			$out .= chr( $field['length'] );
			$out .= chr( 0 );               // decimal count - always 0, character fields only
			$out .= str_repeat( "\0", 14 ); // reserved - zero in these exports
		}
		return $out;
	}

	protected function build_record( array $record ) {
		$out = chr( self::DELETE_FLAG );
		foreach ( $this->fields as $field ) {
			$value = $record[ $field['name'] ];
			$out  .= str_pad( $value, $field['length'], ' ', STR_PAD_RIGHT );
		}
		return $out;
	}

	public function write_file( $path ) {
		$bytes  = $this->build();
		$result = file_put_contents( $path, $bytes );
		if ( false === $result ) {
			throw new RuntimeException( 'failed to write DBF file: ' . $path );
		}
		return $result;
	}
}
