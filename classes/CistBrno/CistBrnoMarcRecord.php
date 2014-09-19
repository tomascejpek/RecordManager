<?php

require_once __DIR__.'/../PortalsCommonMarcRecord.php';
require_once __DIR__.'/../MetadataUtils.php';
require_once __DIR__.'/../Logger.php';

/**
 * MarcRecord Class - local customization for VNF
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class CistBrnoMarcRecord extends PortalsCommonMarcRecord
{
    
    protected $allFields = array(856, 880, 902, 928, 964, 975, 978, 981, 982, 983, 984, 985);
    
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
        
        //check for CistBrno settings
        global $configArray;
        if (!$configArray['CistBrno']['format_unification_array']) {
        	throw new Exception("No format unification for cistbrno");
        }
       
    }

    public function toSolrArray() {
        $data = parent::toSolrArray();

        /*
        $field = parent::getField('260');
        if ($field) {
            $year = parent::getSubfield($field, 'c');
            $matches = array();
            if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                $data['publishDate_display'] = $matches[1];
            }
        }
        */
        $data['title'] = $this->getTitleDisplay();
        $data['title_full'] = $this->getTitleFull();
        $data['title_display'] = $this->getTitleDisplay();
        
        $data['publishDate_display'] = $this->getPublishDateDisplay();
        $data['publishDate_txt_mv'] = $this->getPublishDate();
        
        $data['nbn'] = $this->getNBN();
        $data['barcode_str_mv'] = $this->getBarcodes();
        $data['fulltext'] = $this->getFulltext();

        // autocomplete field for concatenation of author and title
        if (isset($data['author']) && isset($data['title_short'])) {
            $author_title = $data['author'] . ': ' . $data['title_short'];
            $data['author_title_autocomplete'] = $author_title;
            $data['author_title_str'] = $author_title;
        }

        // bib record created date
        $data['acq_int'] = $this->getCreated();

        // conspectus
        foreach ($this->getFields('072') as $field) {
            $type = $this->getSubfield($field, '2');
            if ($type == 'Konspekt') {
                $categoryId = $this->getSubfield($field, '9');
                if ($categoryId) {
                    $data['category_txtF'] = $categoryId;
                }
                $subcategory = $this->getSubfield($field, 'x');
                if ($subcategory) {
                    $data['subcategory_txtF'] = $subcategory;
                }
            }
        }
         
        $data['topic'] = $this->getKeywords();

        //holdings information
        $holdingsArray = array();
        foreach (array('993', '996') as $fieldNo) {
             foreach ($this->getFields($fieldNo) as $field) {
                 $result = '';
                 foreach ($field['s'] as $subfield) {
                     foreach ($subfield as $code => $value) {
                         $result .= '$' . $code . $value;
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
        }
        foreach ($holdingsArray as $fieldNo => $holdings) {
            $data['holdings'. $fieldNo . '_str_mv'] = $holdings;
        }
        
        
        $data['topic'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '600', array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '610', array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'u', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '611', array('a', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k', 'l', 'n', 'p', 'q', 's', 't', 'u', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '630', array('a', 'd', 'e', 'f', 'g', 'h', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '650', array('a', 'b', 'c', 'd', 'e', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '964', array('a'))
            )
        );
        
        $data['era_facet'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '630', array('y')),
                array(MarcRecord::GET_NORMAL, '648', array('a')),
                array(MarcRecord::GET_NORMAL, '648', array('y')),
                array(MarcRecord::GET_NORMAL, '650', array('y')),
                array(MarcRecord::GET_NORMAL, '651', array('y')),
                array(MarcRecord::GET_NORMAL, '655', array('y')),
                array(MarcRecord::GET_NORMAL, '985', array('a'))
            ),
            false, true, true
        );
        
        $data['genre_facet'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '600', array('v')),
                array(MarcRecord::GET_NORMAL, '610', array('v')),
                array(MarcRecord::GET_NORMAL, '611', array('v')),
                array(MarcRecord::GET_NORMAL, '630', array('v')),
                array(MarcRecord::GET_NORMAL, '648', array('v')),
                array(MarcRecord::GET_NORMAL, '650', array('v')),
                array(MarcRecord::GET_NORMAL, '651', array('v')),
                array(MarcRecord::GET_NORMAL, '655', array('a')),
                array(MarcRecord::GET_NORMAL, '655', array('v'))
            ),
            false, true, true
       );
       
        
        
        $data['title_portaly_txtP'] =  $this->getFieldSubfields('245', array('a', 'b'));
        return $data;
    }
    
    /**
     * Return languages - local modification
     * 
     * kept:
     * 041a - language code of text/sound track or separate title
     * 041d - language code of sung or spoken text
     * 
     * added:
     * 041e - language code of librettos
     * 
     * removed (confusing for readers):
     * 041h - language code of original
     * 041j - language code of subtitles or captions
     *
     * @return array
     * 
     */
    public function getLanguages()
    {
        $languages = array(substr($this->getField('008'), 35, 3));
        $languages += $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '041', array('a')),
                array(MarcRecord::GET_NORMAL, '041', array('d')),
                array(MarcRecord::GET_NORMAL, '041', array('e')),
            ),
            false, true, true
        );
        return $languages;
    }
    
    /**
     * Return author for indexing in author2 solr field
     *
     */
    public function getFullAuthor() {
        return $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_ALT, '100', array('a', 'b', 'c', 'd')),
                array(MarcRecord::GET_BOTH, '110', array('a', 'b')),
                array(MarcRecord::GET_BOTH, '111', array('a', 'b')),
                array(MarcRecord::GET_BOTH, '700', array('a', 'b', 'c', 'd', 'e')),
                array(MarcRecord::GET_BOTH, '710', array('a', 'b')),
                array(MarcRecord::GET_BOTH, '711', array('a', 'b')),
                array(MarcRecord::GET_BOTH, '975', array('a', 'b', 'c', 'd')),
                array(MarcRecord::GET_BOTH, '978', array('a', 'b', 'c')),
                array(MarcRecord::GET_BOTH, '981', array('a', 'b', 'c', 'd')),
                array(MarcRecord::GET_BOTH, '982', array('a')),
                array(MarcRecord::GET_BOTH, '983', array('a', 'b', 'c', 'd')),
            )
        );
    }
    
    /**
     * Return full title
     * 
     * @return string
     */
    public function getTitleFull()
    {
        $fields = str_split('abdefghijklmnopqrstuvwxyz0123456789');
        return $this->getFieldSubfields('245', $fields);
    }
    
    /**
     * Return title used for display in search results / record page
     * 
     * @return string
     * 
     */
    public function getTitleDisplay()
    {
        return $this->getFirstFieldSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '245', array('a', 'b', 'n', 'p'))
            )
        );
    }
    
    /**
     * Return textual representation of publish date display - to display
     * in search results, not used for searching. 
     * 
     * @return string
     */
    public function getPublishDateDisplay()
    {
        return $this->getFirstFieldSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '260', array('c'))
            )
        );
    }
    
    public function getPublishDate()
    {
        return $this->getPublishDateFromItems('Z30', 'a');
    }
    
    public function getPublishDateFromItems($field, $subfield)
    {
        $result = array();
        $ranges = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, $field, array($subfield))
            )
        );
        foreach ($ranges as $range) {
            $range = trim($range);
            if (empty($range)) {
                continue;
            }
            $matches = array();
            if (preg_match('/^[0-9]{4}$/', $range)) {
                $result[] = $range;
            } else if (preg_match('/^([0-9]{4})[\-|\/]([0-9]{4})$/', $range, $matches)) { // 2001-2005, 2001/2002
                $result = range($matches[1], $matches[2]);
            } else if (preg_match('/^([0-9]{4})[\-|\/]([0-9]{2})$/', $range, $matches)) { // 1950-54
                $start = $matches[1];
                $end = substr($start, 0, 2) . $matches[2];
                $result = range($start, $end);
            } else if (preg_match('/^([0-9]{4})(,[0-9]{4})+$/', $range, $matches)) { // 1989,1990,1991
                $result = explode(',', $range);
            } else {
                print "$range not matched\n";
            }
        }
        $result = array_unique($result);
        //var_export($result);
        return $result;
    }
    
    /**
     * Return NBN (national bibliographic number) used for integration with
     * obalkyknih.cz.
     * 
     * @return string
     */
    public function getNBN() {
        return $this->getFirstFieldSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '015', array('a'))
            )
        );
    }
    
    /**
     * Return created date
     *
     * @return string
     */
    public function getCreated()
    {
        $created = null;
        $field008 = $this->getField('008');
        if ($field008) {
            $created = substr($this->getField('008'), 0, 6);
            if ($created === false) {
                return null;
            }
            $date = date_create_from_format('ymd', $created);
            if ($date === false) {
                return null;
            }
            $created = date_format($date, 'Ymd');
        }
        return $created;
    }
    
    public function getKeywords()
    {
        return $this->getAllSubfieldsWithIndicator(
            array(
                array('600',  null,  '7',  array('a', 'b', 'd', 'q', 'k', 'l', 'm', 'p', 'r', 's')),
                array('610',  null,  '7',  array('a', 'b', 'c', 'k', 'l', 'm', 'p', 'r', 's')),
                array('611',  null,  '7',  array('a', 'c', 'e' ,'q', 'k', 'l', 'm', 'p', 'r', 's')),
                array('630',  null,  '7',  array('a', 'd', 'k', 'l', 'm', 'p', 'r', 's')),
                array('650',  null,  '7',  array('a', 'v', 'x', 'y', 'z')),
                array('651',  null,  '7',  array('a', 'v', 'x', 'y', 'z')),
                array('653',  null, null,  array('a')),
                array('655',  null,  '7',  array('a', 'v', 'x', 'y', 'z')),
                array('964',  null, null,  array('a')),
                array('967',  null, null,  array('a', 'b', 'c'))
            )
        );
    }

    public function getBarcodes()
    {
        return $this->getFieldSubfields('996', array('b'));
    }
    
    /**
     * Get fulltext
     * 
     * @return string
     */
    protected function getFulltext()
    {
        global $configArray;
        if (!isset($configArray['Fulltext']['toc_dir'])) {
            return null;
        }
        $nbn = $this->getNBN();
        if (empty($nbn)) {
            return null;
        }
        $dir = $configArray['Fulltext']['toc_dir'];
        $file = $dir . '/' . 'cnb_'. $nbn . '.txt';
        if (!file_exists($file)) {
            return null;
        }
        return file_get_contents($file);
    }


    
    /**
     * Returns name of current institution
     * 
     *  @return institution name loaded from settings 
     */
    protected function getInstitution() {
        if (isset($this->settings) && isset($this->settings['institution'])) {
            return $this->settings['institution'];
        } else {
            throw new Exception("No institution name set for datasource: $this->source");
        }
    }
    
    
    /**
     * returns hierarchical array of institutions
     * i.e. [0/MUNI, 1/MUNI/FI]
     * @param $lvl2field MARC field identifier
     * @param $lvl2subfield MARC subfield identifier
     * 
     * @return array
     */
    protected function getHierarchicalInstitutions($lvl2field = null, $lvl2subfield = null) {
        $institution = $this->getInstitution();
        $depth = 0;
        $instArray = array();
        
        $instArray[] = $depth.'/'.$institution;
        if ($lvl2field == null || $lvl2subfield == null) {
            return $instArray;
        }
        
        foreach (parent::getFields($lvl2field) as $field) {       
            $subField = parent::getSubfields($field, $lvl2subfield);
            if ($subField) {
                $instArray[] = '1/'. $institution . '/'.$subField;
            }
        }
        
        return array_values(array_unique($instArray));
    }
    
    /**
     * 
     * @param unify given formats according to corresponding mapping
     * @return array
     */
    protected function unifyFormats($formats) {
    	global $configArray;
    	global $logger;
    	$unificationArray = &$configArray['CistBrno']['format_unification_array'];
    	$unified = array();
    	foreach ($formats as $format) {
    		if (!$unificationArray[$format]) {
    			$logger->log('unifyCistBrnoFormats', "No mapping found for: $format \t". $this->getID(), Logger::WARNING);
    			$unified[] = 'unmapped_' . $format;
    		} else {
    			$unified = array_merge($unified, explode(',', $unificationArray[$format]));
    		}
    	}
    	return array_unique($unified);
    }
    
    
    protected function getAllFields()
    {
        $allFields = array();
        $subfieldFilter = array(
                '650' => array('2', '6', '8'),
                '773' => array('6', '7', '8', 'w'),
                '856' => array('6', '8', 'q')
        );
        $allFields = array();
        foreach ($this->fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 841) || in_array($tag, $this->allFields)) {
                foreach ($fields as $field) {
                    $subfields = $this->getAllSubfields(
                            $field,
                            isset($subfieldFilter[$tag]) ? $subfieldFilter[$tag] : array('6', '8')
                    );
                    if ($subfields) {
                        $allFields = array_merge($allFields, $subfields);
                    }
                }
            }
        }
        $allFields = array_map(
                function($str) {
                    return MetadataUtils::stripLeadingPunctuation(
                            MetadataUtils::stripTrailingPunctuation($str)
                    );
                },
                $allFields
        );
        return array_values(array_unique($allFields));
    }
    
    public function getISBNs()
    {
        $arr = array();
        $fields = array_merge($this->getFields('020'), $this->getFields('902'));
        foreach ($fields as $field) {
            $isbn = $this->getSubfield($field, 'a');
            
            $isbn = str_replace('-', '', $isbn);
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $isbn, $matches)) {
                continue;
            };
            $isbn = $matches[1];
            if (strlen($isbn) == 10) {
                $isbn = MetadataUtils::isbn10to13($isbn);
            }
            if ($isbn) {
                $arr[] = $isbn;
            }
        }

        return array_values(array_unique($arr));
    }
    
    public function getFormat() {
        $formats = parent::getFormat ();
        $string = '';
        foreach (array('500', '502') as $fieldNo) {
            $field = $this->getField ($fieldNo);
    	    if ($field) {
                $subfield = $this->getSubfield ( $field, 'a' );
                if ($subfield) {
                    $string .= '+' . $subfield;
                }
    	    }
        }

        if (empty($string)) {
            return $this->cleanupFormats($formats);
        }
        //assume UTF-8?
        $translit = iconv('UTF-8', 'ASCII//TRANSLIT', $string);
        if ($translit !== false && preg_match ('/bakalarsk|di[sz]+ertacni|habilitacni|diplomov|zaverecn|di[sz]+ertace|habilitacn|kandidatske|klauzutni|rigorozni|rocnikov/i',
            $translit )) {
             $formats = array_merge($formats, $this->unifyFormats(array('dissertations_theses')));
         }
        return $this->cleanupFormats($formats);
    }

    /**
     * cleanup in formats - remove eliminating formats
     */
    protected function cleanupFormats($formats = array()) 
    {
        //remove manuscript format from dissertations
        if (in_array('dissertations_theses', $formats) || in_array('cistbrno_dissertations_theses', $formats)) {
            if (in_array('cistbrno_manuscripts', $formats)) {
                return array_filter($formats, function ($a) { return strcasecmp($a, 'cistbrno_manuscripts') != 0; });   
            }
        }
        return $formats;
    }
}
