<?php 
require_once 'VnfMarcRecord.php';

class VkolMarcRecord extends VnfMarcRecord
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

    public function checkRecord()
    {
        $continueFlag = false;
        $leader = $this->getField('000');
        $leaderBit = substr($leader, 6, 1);
        if (strtoupper($leaderBit) == 'I' || strtoupper($leaderBit) == 'J') {
            $continueFlag = true;
        }

        $fields = $this->getFields('007');
        foreach ($fields as $field) {
            if (strtoupper(substr($field, 0, 1)) == 'S') {
                $continueFlag = true;
            }
        }
        return $continueFlag && parent::checkRecord();     
    }
    
    public function getFormat($keepRemove = false) {
        $field = $this->getField('300');
        if ($field) {
            $subfields = $this->getAllSubfields($field);
            if ($subfields) {
                $concat = implode('__', $subfields);
                if (preg_match('/.*kazet.*/i', $concat)) {
                    return array(self::VNF_ALBUM, self::VNF_SOUND_CASSETTE);
                }
            }
        }
        
        $fields = $this->getFields('996');
        foreach ($fields as $field) {
            $subfield = $this->getSubfield($field, 'c');
            if ($subfield && preg_match('/.*CD.*/', $subfield)) {
                return array(self::VNF_ALBUM, self::VNF_CD);    
            }
        }
        
        return parent::getFormat($keepRemove);
    }
}