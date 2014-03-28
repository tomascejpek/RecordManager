<?php
class MappableMarcRecord extends MarcRecord
{

    // example: "custom, getCallNumberSubject(1, 2, 3), translation.map"
    // \029 = ')'
    const METHOD_CALL_REGEX = '/custom, (?<method>[\w]+)\((?<args>[^\029]*)\)(?:,\s*(?<translation>.*)){0,1}/';
    
    const CONSTANT_DECL_REGEX = '/"(?<constant>[^"]*)"/';
    
    // example: 645abc:650abd, mod1, mod2
    const MARC_FIELDS_REGEX = '/(?<fields>[\w:]+)(?:\s*,\s*(?<mode>[^,]+)){0,1}(?:\s*,\s*(?<translation>[^,]+)){0,1}/';
    
    const VAR_FIELD_SPEC = '/(?<field>[\w]{3})(?<subfields>[\w]*)/';
    
    const FIXED_FIELD_SPEC = '/(?<field>[\w]{3})(?:\[(?<start>[\d]+)\-(?<end>[\d]+)\])/';
    
    const ALL_SUBFIELDS = 'abdefghijklmnopqrstuvwxyz0123456789';
    
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
            $values = array();
            if ($spec['type'] == 'fields') {
                $mode = $spec['mode'];
                $values = array();
                if ($mode == 'all') {
                    $values =  $this->getFieldsSubfields($spec['fields']);
                } else if ($mode == 'first') {
                    $values =  $this->getFirstFieldSubfields($spec['fields']);
                } 
            } else if ($spec['type'] == 'method') {
                $methodName = $spec['method'];
                $args = $spec['args'];
                $values = call_user_func_array(array($this, $methodName), $args);
            } else if ($spec['type'] == 'constant') {
                $values = $spec['constant'];
            }
            if (is_scalar($values)) {
                if (trim($values) !== '') {
                    $values = array($values);
                } else {
                    $values = array();
                }
            }
            if ($values == null) {
                $values = array();
            }
            if (isset($spec['translation'])) {
                $translated = array();
                $translation = $spec['translation'];
                if (empty($values) && isset($translation['##empty'])) {
                    $translated = $translation['##empty'];
                }
                foreach ($values as $value) {
                    if (isset($translation[$value])) {
                        $translated[] = $translation[$value];
                    } else if (isset($translation['##default'])) {
                        $translated[] = $translation['##default'];
                    }
                }
                $values = $translated;
            }
            $data[$field] = $values;
        }
        return $data;
    }
    
    protected static function getMappingBySource($source)
    {
        if (!isset(self::$mappings[$source])) {
            return self::initializeMapping($source);
        } else {
            return self::$mappings[$source];
        }
    }
    
    protected static function initializeMapping($source)
    {
        global $dataSourceSettings;
        if (!isset($dataSourceSettings[$source]['indexer_properties'])) {
            throw new Exception("Missing [$source][indexer_properties] in recordmanager.ini");
        }
        $files = explode(',', $dataSourceSettings[$source]['indexer_properties']);
        $finalMap = array();
        // take precedence in the reverse order (to be compatible with SolrMarc)
        // first file has the highest priority
        foreach (array_reverse($files) as $file) {
            $file = trim($file);
            $overrideMap = parse_ini_file("./marc_mapping/{$file}", false, INI_SCANNER_RAW);
            if ($overrideMap) {
                $finalMap = array_merge((array) $finalMap, (array) $overrideMap);
            } else {
                throw new Exception('');
            }
        }
        $finalMap = self::parseMapping($finalMap);
        self::$mappings[$source] = $finalMap;
        var_export($finalMap);
        return $finalMap;
    }
    
    protected static function parseMapping($config)
    {
        $mapping = array();
        foreach ($config as $key => $value) {
            $matches = array();
            $conf = array();
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
                $mapping[$key] = $conf;
            } else if (preg_match(self::CONSTANT_DECL_REGEX, $value, $matches)) {
                $conf = array(
                    'type'   => 'constant',
                    'fields' => $matches['constant']
                );
            } else if (preg_match(self::MARC_FIELDS_REGEX, $value, $matches)) {
                $results = array();
                $fields = explode(':', $matches['fields']);
                foreach ($fields as $field) {
                    $args = array();
                    if (preg_match(self::VAR_FIELD_SPEC, $field, $args)) {
                        $fieldNo = $args['field'];
                        $subfields = self::ALL_SUBFIELDS;
                        if (isset($args['subfields'])) {
                            $subfields = $args['subfields'];
                        }
                        $results[] = array(MarcRecord::GET_NORMAL, $fieldNo, str_split($subfields));
                    }
                }
                $conf = array(
                    'type'   => 'fields',
                    'fields' => $results,
                );
                $conf['mode'] = 'all';
                if (isset($matches['mode'])) {
                    $conf['mode'] = $matches['mode'];
                }
            } else {
                throw new Exception('Unsupported mapping:' . $key);
            }
            if (isset($matches['translation'])) {
                $file = $matches['translation'];
                $translation = parse_ini_file("./translation_maps/{$file}", false, INI_SCANNER_RAW);
                if ($translation) {
                    $conf['translation'] = $translation;
                }
            }
            $mapping[$key] = $conf;
        }
        return $mapping;
    }

}
