<?php

require_once 'MzkMarcRecord.php';

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
class MzkNormsMarcRecord extends MzkMarcRecord
{
    
    /**
     * Record id
     * 
     */
    protected $id;
    
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
        list($base, $sysno) = explode('-', $ident);
        $this->id = $sysno;
    }
    
    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return $this->id;
    }
    
    public function getLinkingID()
    {
        return $this->id;
    }
    
}