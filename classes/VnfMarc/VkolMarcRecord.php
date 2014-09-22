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
        $continueFlag = true;
//         $leader = $this->getField('000');
//         $leaderBit = substr($leader, 6, 1);
//         if (strtoupper($leaderBit) == 'I' || strtoupper($leaderBit) == 'J') {
//             $continueFlag = true;
//         }

//         $fields = $this->getFields('007');
//         foreach ($fields as $field) {
//             if (strtoupper(substr($field, 0, 1)) == 'S') {
//                 $continueFlag = true;
//             }
//         }
        return $continueFlag && parent::getFormat(true);     
    }
}