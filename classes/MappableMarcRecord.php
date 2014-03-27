<?php
class MappableMarcRecord extends MarcRecord
{

    // example: "custom, getCallNumberSubject(1, 2, 3), translation.map"
    // \029 = ')'
    const METHOD_CALL_REGEX = '/custom, (?<method>[\w]+)\((?<args>[^\029]*)\)(?:,\s*(?<translation>.*)){0,1}/';
    
    // example: 645abc:650abd, mod1, mod2
    const MARC_FIELDS_REGEX = '/(?<fields>[\w:]+)(?:\s*,\s*(?<mode>[^,]+)){0,1}(?:\s*,\s*(?<translation>[^,]+)){0,1}/';
    
    //const RESERVED_MODIFIERS = array('all', 'first');
    
    /**
     * Array of parsed mappings keyed by source
     *
     * @var array
     */
    protected static $mappings = array();
    
    /**
     * Mapping to use
     *
     * @var array
     */
    protected $mapping;
    
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
        $this->mapping = self::getMappingBySource($source);
    }
    
    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = array();
        $data['fullrecord'] = $this->toISO2709();
        if (!$data['fullrecord']) {
            // In case the record exceeds 99999 bytes...
            $data['fullrecord'] = $this->toXML();
        }
        foreach ($this->mapping as $field => $spec) {
            if ($spec['type'] == 'fields') {
                $mode = $spec['mode'];
                if ($mode == 'all') {
                    $data[$field] =  $this->getFieldsSubfields($spec['fields']);
                } else if ($mode == 'first') {
                    $data[$field] =  $this->getFirstFieldSubfields($spec['fields']);
                }
            } else if ($spec['type'] == 'method') {
                $methodName = $spec['method'];
                $args = $spec['args'];
                $data[$field] = call_user_func_array(array($this, $methodName), $args);
            }
        }
        return $data;
    }
    
    protected static function getMappingBySource($source)
    {
        if (!isset($mappingCache[$source])) {
            return self::initializeMapping($source);
        } else {
            return $mappingCache[$source];
        }
    }
    
    protected static function initializeMapping($source)
    {
        global $configArray;
        if (!isset($configArray['Mapping'][$source])) {
            throw new Exception("Missing [Mapping][$source] in recordmanager.ini");
        }
        $files = explode(',', $configArray['Mapping'][$source]);
        $finalMap = array();
        // take precedence in the reverse order (to be compatible with SolrMarc)
        // first file has the highest priority
        foreach (array_reverse($files) as $file) {
            $overrideMap = parse_ini_file('./mapping/' . trim($file), false, INI_SCANNER_RAW);
            if ($overrideMap) {
                $finalMap = array_merge((array) $finalMap, (array) $overrideMap);
            }
        }
        $finalMap = self::parseMapping($finalMap);
        self::$mappings[$source] = $finalMap;
        return $finalMap;
    }
    
    protected static function parseMapping($config)
    {
        $mapping = array();
        foreach ($config as $key => $value) {
            $matches = array();
            if (preg_match(self::METHOD_CALL_REGEX, $value, $matches)) {
                $args = explode(',', $matches['args']);
                foreach ($args as &$arg) {
                    $arg = trim($arg, "\" ");
                }
                $conf = array(
                    'type'   => 'method',
                    'method' => $matches['method'],
                    'args'   => $args
                );
                if (isset($matches['translation'])) {
                    $conf['translation'] = $matches['translation']; 
                }
                $mapping[$key] = $conf;
            } else if (preg_match(self::MARC_FIELDS_REGEX, $value, $matches)) {
                $results = array();
                $fields = explode(':', $matches['fields']);
                foreach ($fields as $field) {
                    $fieldNo = substr($field, 0, 3);
                    $subfields = substr($field, 3);
                    $results[] = array(MarcRecord::GET_NORMAL, $fieldNo, str_split($subfields));
                }  
                $conf = array(
                    'type'   => 'fields',
                    'fields' => $results
                );
                $conf['mode'] = 'all';
                if (isset($matches['mode'])) {
                    $conf['mode'] = $matches['mode'];
                }
                if (isset($matches['translation'])) {
                    $conf['translation'] = $matches['translation']; 
                }
                $mapping[$key] = $conf;
            }
        }
        return $mapping;
    }

}
