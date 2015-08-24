<?php 
require_once 'VnfMarcRecord.php';

class RadMarcRecord extends VnfMarcRecord
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
    
    public function getFormat($keepRemove = false) {
    	return array(self::VNF_ALBUM, self::VNF_DATA);
    }
}
