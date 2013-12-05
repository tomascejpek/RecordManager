<?php

require_once 'HistoricalMarcRecord.php';


/**
 * MarcRecord Class - local customization for MZK
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta 
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class MzkMarcRecord extends HistoricalMarcRecord
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
        
        $field = parent::getField('100');
        if ( !empty ($field)) {
            $data['author'] = parent::getSubfields($field,"abcde");
        }
        
        $field = parent::getField('100');
        if ( !empty ($field)) {
            $data['author-letter'] = parent::getSubfields($field,"a");
        }

        return $data;
    }  
} 

