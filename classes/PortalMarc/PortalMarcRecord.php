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

        return $data;
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
                $institution .= '/'.$subField;
                $instArray[] = $depth.'/'.$institution;
            }
        }
        return $instArray;
    }

}
