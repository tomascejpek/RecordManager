<?php

require_once 'PortalMarcRecord.php';
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
class KjmMarcRecord extends PortalMarcRecord
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

    public function toSolrArray() 
    {
        $data = parent::toSolrArray();
        
        $data['institution'] = $this->getHierarchicalInstitutions('993', 'l');
        return $data;
    }

    protected function parseXML($xml)
    {
       $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOENT);
       if ($document === false) {
             throw new Exception('MarcRecord: failed to parse from XML');
       }

       $query = $document->leader;
       if ($query) {
           $this->fields['000'] = $query->__toString();
       } else {
           $this->fields['000'] = '';
       }

       $query = $document->controlfield;
       foreach ($query as $field) {
           $this->fields[$field->attributes()->tag->__toString()] = $field->__toString();
       }

       $query = $document->datafield;
       foreach ($query as $field) {
           $newField = array(
              'i1' => str_pad($field->attributes()->ind1 ? $field->attributes()->ind1->__toString() : '' , 1),
              'i2' => str_pad($field->attributes()->ind2 ? $field->attributes()->ind2->__toString() : '' , 1)
           );
           $subfieldQuery = $field->subfield;
           foreach ($subfieldQuery as $subfield) {
               $newField['s'][] = array($subfield->attributes()->code->__toString() => $subfield->__toString());
           }
           $tag = $field->attributes()->tag->__toString();
           if (substr($tag, 0, 2) != '00') {
               $this->fields[$tag][] = $newField;
           }
       }
    }

}
