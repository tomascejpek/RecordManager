<?php
require_once 'CistBrnoMarcRecord.php';

/**
 * MarcRecord Class - local customization for cistbrno
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class MuniMarcRecord extends CistBrnoMarcRecord
{

    /**
     * Constructor
     *
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source Source ID
     */
    public function __construct($data, $oaiID, $source)
    {
        parent::__construct($data, $oaiID, $source);
    }

    public function toSolrArray() {
        $data = parent::toSolrArray();

        $data['institution'] = $this->getHierarchicalInstitutions('996', 'l');
        for ($i = 0; $i < count($data['institution']); $i++) {
            if (preg_match('/^1\/MUNI\/FF/',$data['institution'][$i])) {
                $data['institution'][$i] = '1/MUNI/FF';
            }
            if (preg_match('/^1\/MUNI\/Přír/u',$data['institution'][$i])) {
                $data['institution'][$i] = '1/MUNI/PRIF';
            }
        }
        return $data;
    }
    
    public function getID()
    {
    	$field = parent::getField('990');
    	if ($field) {
    	    $subfield = $this->getSubfield($field, 'a');
    	    if ($subfield) {
    	        $id = trim($subfield);
    	    }
    	}
    	return isset($id) ? $id : parent::getID();
    }
 
}
