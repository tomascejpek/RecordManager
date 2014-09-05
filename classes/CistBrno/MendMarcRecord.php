<?php
require_once 'CistBrnoMarcRecord.php';

/**
 * MarcRecord Class - local customization for cistbrno
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package RecordManager
 * @author Michal Merta <merta.m@gmail.com>
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public
 *          License
 * @link https://github.com/moravianlibrary/RecordManager
 */
class MendMarcRecord extends CistBrnoMarcRecord
{
    
    /**
     * Constructor
     *
     * @param string $data
     *            Metadata
     * @param string $oaiID
     *            Record ID received from OAI-PMH (or empty string for file
     *            import)
     * @param string $source
     *            Source ID
     */
    public function __construct ($data, $oaiID, $source)
    {
        parent::__construct($data, $oaiID, $source);
        // check for nesscesary format mapping
        global $configArray;
        if (!$configArray['CistBrno'] ||
                 !$configArray['CistBrno']['format_unification_array']) {
            throw new Exception(
                    "Missing format unification mapping (settings->CistBrno->format_unification)");
        }
        
        $iniContent = false;
        //check subfield mapping
        if (!$this->settings['status_map'] || !is_array($this->settings['status_map'])) {
            global $dataSourceSettings;
            global $basePath;
            if ($dataSourceSettings[$source]['status_map']) {
                 $iniContent = parse_ini_file($basePath . '/mappings/' . $dataSourceSettings[$source]['status_map']);
                 if (is_array($iniContent)) {
                     $dataSourceSettings[$source]['status_map'] = $iniContent;
                 }
            }
            if (!is_array($dataSourceSettings[$source]['status_map'])) {
                throw new Exception('Portal_mend - missing statuses mapping for holdings');
            }
            $this->settings = $dataSourceSettings[$source];
        }
        
        if (!$this->settings['location_map'] || !is_array($this->settings['location_map'])) {
            global $dataSourceSettings;
            global $basePath;
            if ($dataSourceSettings[$source]['location_map']) {
                 $iniContent = parse_ini_file($basePath . '/mappings/' . $dataSourceSettings[$source]['location_map']);
                 if (is_array($iniContent)) {
                     $dataSourceSettings[$source]['location_map'] = $iniContent;
                 }
            }
            if (!is_array($dataSourceSettings[$source]['location_map'])) {
                throw new Exception('Portal_mend - missing location mapping for holdings');
            }
            $this->settings = $dataSourceSettings[$source];
        }
    }

    public function toSolrArray ()
    {
        $data = parent::toSolrArray();
        
        $data['institution'] = $this->getHierarchicalInstitutions();
        
        //holdings information
        $holdingsArray = array();
        
        $fieldNo = '980';
        foreach ($this->getFields($fieldNo) as $field) {
            $result = '';
            foreach ($field['s'] as $subfield) {
                foreach ($subfield as $code => $value) {
                    if (isset($this->settings['status_map']) && is_array($this->settings['status_map'])) {
                        if ($code == 'k') {
                            $value = $this->mapString($this->settings['status_map'], $value);
                        }
                    }
                    if (isset($this->settings['location_map']) && is_array($this->settings['location_map'])) {
                        if ($code == 'l') {
                            $value = $this->mapString($this->settings['location_map'], $value);
                        }
                    }
                    $result .= '$' . $code . $value;
                }
            }
            $description = $this->getFieldSubfields('245', array('n', 'p'));
            if ($description) {
                $result .= '$%' . $description;  
            }    
            $result .= '$@' . $this->getInstitution();
            $result .= '$*' . $this->getID();
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

    public function getID ()
    {
        $id = parent::getField('998');
        if (empty($id)) {
            $id = parent::getID();
        }
        if (is_array($id)) {
            $reference = &$id;
            if (array_key_exists('s', $reference)) {
                $reference = &$reference['s'];
            } else {
                return null;
            }
            if (array_key_exists(0, $reference)) {
                $reference = &$reference[0];
            } else {
                return null;
            }
            if (array_key_exists('a', $reference)) {
                $id = $reference['a'];
            } else {
                return null;
            }
        }
        return trim($id);
    }
    
    protected function mapString(&$mapping, $key)
    {
        if (!is_array($mapping) || !array_key_exists($key, $mapping)) {
            global $logger;
            $logger->log('MendMarcRecord', 'No mapping found for key:' . $key, Logger::WARNING);
            return $key;
        }
        return $mapping[$key];
    }
}
