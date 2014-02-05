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
class MzkMarcRecord extends PortalMarcRecord
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

        $data['institution'] = $this->getHierarchicalInstitutions();
        
        $z30 = $this->getField("Z30");
        $availability_id_str = $this->getSubfield($z30, 'w');
        if (!empty($availability_id_str)) {
            $data['availability_id_str'] = $availability_id_str;
        }
        return $data;
    }

}