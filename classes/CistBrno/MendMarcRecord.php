<?php
require_once 'CistBrnoMarcRecord.php';

/**
 * MarcRecord Class - local customization for cistbrno
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package RecordManager
 * @author Michal Merta <merta.m@gmail.com>
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link https://github.com/moravianlibrary/RecordManager
 */
class MendMarcRecord extends CistBrnoMarcRecord {
	
	/**
	 * Constructor
	 *
	 * @param string $data
	 *        	Metadata
	 * @param string $oaiID
	 *        	Record ID received from OAI-PMH (or empty string for file import)
	 * @param string $source
	 *        	Source ID
	 */
	public function __construct($data, $oaiID, $source) {
		parent::__construct ( $data, $oaiID, $source );
		// check for nesscesary format mapping
		global $configArray;
		if (! $configArray ['CistBrno'] || ! $configArray ['CistBrno'] ['format_unification_array']) {
			throw new Exception ( "Missing format unification mapping (settings->CistBrno->format_unification)" );
		}
	}
	
	public function toSolrArray() {
		$data = parent::toSolrArray ();
		
		$data ['institution'] = $this->getHierarchicalInstitutions ();
		return $data;
	}
	
	public function getID() {
		$id = parent::getField ( '998' );
		if (empty ( $id )) {
			$id = parent::getID ();
		}
		if (is_array ( $id )) {
			$reference = &$id;
			if (array_key_exists ( 's', $reference )) {
				$reference = &$reference ['s'];
			} else {
				return null;
			}
			if (array_key_exists ( 0, $reference )) {
				$reference = &$reference [0];
			} else {
				return null;
			}
			if (array_key_exists ( 'a', $reference )) {
				$id = $reference ['a'];
			} else {
				return null;
			}
		}
		return trim ( $id );
	}
	
	public function getFormat() {
		$formats = parent::getFormat ();
		
		$field = $this->getField ( '520' );
		if ($field) {
			$subfield = $this->getSubfield ( $field, 'a' );
			if ($subfield && preg_match ( '/^bakalářsk | ^disertační | ^habilitační | ^diplomov | ^závěrečn | ^disertace | ^habilitační | ^kandidátské | ^klauzutní | ^rigorozní/i'
			  , $subfield )) {
				$formats = array_merge($formats, $this->unifyFormats('dissertations_theses'));
			}
		}
		return $formats;
	}
}
