<?php
require_once 'VnfMarcRecord.php';

/**
 * MarcRecord Class - local customization for VNF marc record
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class SvkkMarcRecord extends VnfMarcRecord
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

    public function getFormat()
    {
        $field = $this->getField('910');
        if ($field) {
            $subfield = $this->getSubfield($field, 'b');
            if ($subfield) {
                $format = explode(' ', $subfield);
                if (is_array($format) && count($format) > 0) {
                    switch($format[0]) {
                        case 'CD': return array(self::VNF_ALBUM, self::VNF_CD);
                        case 'G':  return array(self::VNF_ALBUM, self::VNF_VINYL);
                        case 'K':  return array(self::VNF_ALBUM, self::VNF_SOUND_CASSETTE);
                    }
                }
            }
            
        }
        return $this->mergeAndUnify(parent::getFormat(), self::VNF_UNSPEC);
    }
}
