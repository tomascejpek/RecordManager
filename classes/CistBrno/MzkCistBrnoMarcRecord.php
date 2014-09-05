<?php

require_once 'CistBrnoMarcRecord.php';

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
class MzkCistBrnoMarcRecord extends CistBrnoMarcRecord
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
        list($oai, $domain, $ident) = explode(':', $oaiID);
        $this->id = $ident;

        //check subfield mapping
        foreach (array('996r_map', '996l_map') as $field) {
            $iniContent = false;
            if (!$this->settings[$field] || !is_array($this->settings[$field])) {
                global $dataSourceSettings;
                global $basePath;
                if ($dataSourceSettings[$source][$field]) {
                    $iniContent = parse_ini_file($basePath . '/mappings/' . $dataSourceSettings[$source][$field]);
                    if (is_array($iniContent)) {
                        $dataSourceSettings[$source][$field] = $iniContent;
                    }
                }
                if (!is_array($dataSourceSettings[$source][$field])) {
                    throw new Exception('Portal_cistbrno_mzk - missing mapping for '. $field);
                }
                $this->settings = $dataSourceSettings[$source];
            }
        }

    }

    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $data['institution'] = "0/MZK";
        
        //holdings information
        $holdingsArray = array();
        $fieldNo = '996';
        if (isset($data['holdings996_str_mv'])) {
            unset($data['holdings996_str_mv']);
        }
        foreach ($this->getFields($fieldNo) as $field) {
            $result = '';
            foreach ($field['s'] as $subfield) {
                foreach ($subfield as $code => $value) {
                    if ($code == 'r') {
                        if (isset($this->settings['996r_map']) && is_array($this->settings['996r_map'])) {
                            $value = $this->mapString($this->settings['996r_map'], $value);
                        }
                    }
                    if ($code == 'l') {
                        if (isset($this->settings['996l_map']) && is_array($this->settings['996l_map'])) {
                            $value = $this->mapString($this->settings['996l_map'], $value);
                        }
                    }

                    if (!empty($value)) {
                        $result .= '$' . $code . $value;
                    }
                }
            }
            

            $description =  $this->getFieldSubfields('245', array('n', 'p'));
            if (!empty($description)) {
                $result .= '$%' . $description;
            }
            $result .= '$@'.$this->getInstitution();
            $result .= '$*'.$this->getID();
            if (!empty($result)) {
                if (!array_key_exists($fieldNo, $holdingsArray)) {
                    $holdingsArray[$fieldNo] = array();
                }
                $holdingsArray[$fieldNo][] = $result;
            }
        }
         
         
        foreach ($holdingsArray as $fieldNo => $holdings) {
            $data['holdings'. $fieldNo . '_str_mv'] = $holdings;
        }
        
        return $data;
    }

    public function getFormat() {
    	$formats = parent::getFormat();
    	
    	if (preg_match('/.*MZK04.*/', $this->getID())) {
    		$formats[] = 'standards_patents';
    	}
    	
    	return $formats;
    }
    
    public function getID()
    {
        return empty($this->id) ? parent::getID() : $this->id;
    }
    
    protected function mapString(&$mapping, $key)
    {
        if (!is_array($mapping) || !array_key_exists($key, $mapping)) {
            global $logger;
            $logger->log('MzkCistbrnoMarcRecord', 'No mapping found for key:' . $key, Logger::WARNING);
            return $key;
        }
        return $mapping[$key];
    }
    
    public function checkRecord() {
        if (!parent::checkRecord()) {
            return false;
        }
        $valueArray = $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '991', array('s'))));
        if (count($valueArray) > 0 && preg_match('/SKRYTO/i', $valueArray[0])) {
            return false;
        }
        $valueArray = $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '992', array('a'))));
        if (count($valueArray) > 0 && preg_match('/SUPPRESSED/i', $valueArray[0])) {
            return false;
        }
        $valueArray = $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '990', array('a'))));
        if (count($valueArray) > 0 && preg_match('/AZ/i', $valueArray[0])) {
            return false;
        }
        return true;
    }
}

