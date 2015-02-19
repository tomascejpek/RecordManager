<?php
require_once 'VnfMarcRecord.php';

class NmMarcRecord extends VnfMarcRecord
{

    public function __construct($data, $oaiID, $source)
    {
        parent::__construct($data, $oaiID, $source);
    }

    public function getFormat($keepRemove = false) 
    {
        $nmFormat = $this->getFieldSubfields('655', array('a'));
        if (preg_match('/.*(ep|lp|sp)\s*desky.*/ui', $nmFormat)) {
            return array(self::VNF_ALBUM, self::VNF_VINYL);
        }

        if (preg_match('/.*desky\s*\d{2}\s*cm.*/ui', $nmFormat)) {
            return array(self::VNF_ALBUM, self::VNF_SHELLAC);
        }
        
        if (preg_match('/.*fonoválečky.*/ui', $nmFormat)) {
            return array(self::VNF_ALBUM, self::VNF_PHONOGRAPH);
        }
        
        if (preg_match('/.*kompaktní\s*desky.*/ui', $nmFormat)) {
            return array(self::VNF_ALBUM, self::VNF_CD);
        }
        
        if (preg_match('/.*(magnetofonové\s*kazety|kazety\s*r-dat).*/ui', $nmFormat)) {
            return array(self::VNF_ALBUM, self::VNF_SOUND_CASSETTE);
        }
        
        if (preg_match('/.*magnetofonové\s*pásy.*/ui', $nmFormat)) {
            return array(self::VNF_ALBUM, self::VNF_MAGNETIC_TAPE);
        }
        
        return parent::getFormat($keepRemove);;
    }
}
