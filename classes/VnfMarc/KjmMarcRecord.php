<?php

require_once 'VnfMarcRecord.php';

/**
 * MarcRecord Class - local customization for VNF
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Vaclav Rosecky <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class KjmMarcRecord extends VnfMarcRecord
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
        $prefix = substr($this->getID(), 0, 2);
        $formats = parent::getFormat($keepRemove);
        if (strcasecmp($prefix, 'CD') ===  0) {
            $field = $this->getField('245');
            if ($field && ($subfield = $this->getSubfield($field, 'h'))) {
                switch ($subfield) {
                    case 'videozáznam' : $formats[] = 'kjm_video'; break;
                    case 'elektronický zdroj': $formats[] = 'kjm_electronic_resources'; break;
                    case 'zvukový' : $formats[] = 'kjm_audio'; break;
                }
            }
        }
        
        //replace wrong dected magnetic tapes
        if (in_array(self::VNF_MAGNETIC_TAPE, $formats)) {
            $formats = array(self::VNF_CD);
        }
        
        if (!$this->isTrack()) {
            $formats[] = self::VNF_ALBUM;
        }
        $formats[] = $prefix;
        $formats = $this->unifyFormats($formats);
        return $formats;
    }

    public function toSolrArray() {
        $data = parent::toSolrArray();
        if ($this->isTrack()) {
            unset($data['institutionAlbumsOnly']);
        }
        return $data;
    }
    
    public function isTrack() {
        $prefix = substr($this->getID(), 0, 2);
        return strcasecmp($prefix, 'SK') == 0;
    }
    
}
