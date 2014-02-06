<?php

require_once __DIR__.'/../MarcRecord.php';
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
class PortalMarcRecord extends MarcRecord
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
        $data['nbn'] = $this->getNBN();
        $data['barcode_str_mv'] = $this->getBarcodes();

        // autocomplete field for concatenation of author and title
        if (isset($data['author']) && isset($data['title_short'])) {
            $author_title = $data['author'] . ': ' . $data['title_short'];
            $data['author_title_autocomplete'] = $author_title;
            $data['author_title_str'] = $author_title;
        }

        // bib record created date
        $field008 = $this->getField('008');
        if ($field008) {
            $created = substr($this->getField('008'), 0, 6);
            $created = date_format(date_create_from_format('ymd', $created), 'Ymd');
            $data['acq_int'] = $created;
        }

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
        
        // 600#7abdqklmprs 610#7abcklmprs 611#7aceqklmprs 630#7adklmprs 650#7avxyz 651#7avxyz 653##a 655#7avxyz 964##a 967##abc" 
        $data['topic'] = $this->getKeywords();

        $data['statuses'] = $this->getStatuses();

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
     * Return publish date display. 
     * 
     * @return string
     */
    public function getPublishDateDisplay() {
        return $this->getFirstFieldSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '260', array('c'))
            )
        );
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
                array('967',  null, null,  array('a', 'b', 'c')),
            )
        );
    }

    public function getBarcodes()
    {
        return $this->getFieldSubfields('996', array('b'));
    }

    /**
     * 
     * 
     * @return string
     */
    protected function getAllSubfieldsWithIndicator($specs)
    {
        $result = array();
        foreach ($specs as $spec) {
            $field = $spec[0];
            $ind1 = $spec[1];
            $ind2 = $spec[2];
            $codes = $spec[3];
            if (isset($this->fields[$field])) {
                foreach ($this->fields[$field] as $f) {
                    $i1 = $f['i1'];
                    $i2 = $f['i2'];
                    if (($ind1 == null || $i1 == $ind1) && ($ind2 == null || $i2 == $ind2)) {
                        $data = array();
                        foreach ($f['s'] as $subfield) {
                            $code = key($subfield);
                            if ($code === 0) {
                                $code = '0';
                            }
                            if (in_array($code, $codes)) {
                                $data[] = current($subfield);
                            }
                        }      
                        $result[] = implode(' ', $data);
                    }
                }
            }
        }
        return $result;
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
        
        $field = parent::getField($lvl2field);
        if ($field) {       
            $subField = parent::getSubfields($field, $lvl2subfield);
            if ($subField) {
                $depth++;
                $institution .= '/'.$second;
                $instArray[] = $depth.'/'.$institution;
            }
        }
        return $instArray;
    }

    protected function getStatuses() {
        $present = false;
        $absent = false;
        $freeStack = false;
        foreach ($this->getFields('996') as $field) {
            $status = $this->getSubfield($field, 's');
            if (strtolower($status) == 'p') { // present
                $present = true;
            } else if (strtolower($status) == 'a') { // absent
                $absent = true;
            }
            $readyInHours = $this->getSubfield($field, 'n');
            if ($readyInHours == '0') {
                $freeStack = true;
            }
        }
        $statuses = array();
        if ($absent) {
            $statuses[] = 'absent';
        } else if ($present) {
            $statuses[] = 'present';
        }
        if ($freeStack && !empty($statuses)) {
            $statuses[] = 'free-stack';
        }
        return $statuses;
    }

}
