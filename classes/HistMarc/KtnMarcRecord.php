<?

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
class KtnMarcRecord extends HistoricalMarcRecord
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

    protected function parseLineMarc($marc) {
        
        $lines = explode("\n", $marc);
       
        
        $finalField = array();
        foreach ($lines as $line) {
            if (substr($line, -1) == "\r") {
                $line = substr($line, 0, strlen($line) -1);
            }
            
            $field = substr($line, 0, 3);
            $line = substr($line, 3, strlen($line));

            if ($field == 'LDR') {
                $finalField['000'] = array($line);
                continue;
            }

            $arrayField = array();
            //handle this for wrong-formated fields 001, 003, 005, 008
            if ($field == '001' || $field == '003' || $field == '005' || $field == '008') {
                $finalField[$field] = $this->encodeString($line);
                continue;
            }
            
            $arrayField['i1'] = $line[0];
            $arrayField['i2'] = $line[1];
            $line = substr($line,2);

            $subfield = null;
            $currentString = "";
            $arrayField['s'] = array();

            for ($i = 0; $i < strlen($line);) {
                if ($line[$i] == '$' && ctype_alnum($line[$i+1])) {
                    if ($subfield != null) {
                        $arrayField['s'][] = array($subfield => $this->encodeString($currentString));
                    }
                    $subfield = null;
                    $currentString = "";
                    $subfield = $line[++$i];
                    $i++;
                } else {
                    $currentString .= $line[$i++];
                }
            }
            $arrayField['s'][] = array($subfield => $this->encodeString($currentString));
            $finalField[$field] = array($arrayField);

        }
        $this->fields = $finalField;
    }



}
?>