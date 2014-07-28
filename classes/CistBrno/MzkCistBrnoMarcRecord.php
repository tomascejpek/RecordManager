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
class MzkCistBrnoMarcRecord extends CistBrnoMarcRecord
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
        list($oai, $domain, $ident) = explode(':', $oaiID);
        $this->id = $ident;
    }

    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $data['institution'] = "0/MZK";
        return $data;
    }

    public function getFormat() {
    	$formats = parent::getFormat();
    	
    	if (preg_match('/.*MZK04.*/', $this->getID())) {
    		$formats[] = 'standards_patents';
    	}
    	return $formats;
    }
    
    public function getID()
    {
        return $this->id;
    }
}

