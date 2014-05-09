<?php
require_once 'PortalMarcRecord.php';

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
class MuniMarcRecord extends PortalMarcRecord
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
        if (isset($data['institution'][1])) {
            // map FF-S, FF-K, FF-HUD to FF
            if (preg_match('/^1\/MUNI\/FF/',$data['institution'][1])) {
                $data['institution'][1] = '1/MUNI/FF';
            }
        }
        return $data;
    }
    
    public function getID()
    {
    	$id = parent::getField('998');
    	if (empty($id)) {
    	    $id = parent::getID();
    	}
    	return trim($id);
    }

}
