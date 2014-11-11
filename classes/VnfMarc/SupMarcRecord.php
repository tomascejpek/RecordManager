<?php
/**
 * MarcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2013
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'VnfMarcRecord.php';

/**
 * MarcRecord Class - local customization for VNF marc record
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class SupMarcRecord extends VnfMarcRecord
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
       
        if (!$this->isAlbum()) {
            unset($data['institutionAlbumsOnly']);
        }
 
        $hierarchyLinks = $this->getHierarchLinks();
        $data['sup_uplink'] = $hierarchyLinks['sup_uplink'];
        $data['sup_downlinks'] = $hierarchyLinks['sup_downlinks'];
	
	if ($this->isAlbum()) {
	    $path = $this->getImagePath(ltrim($this->getID(), '0'));
            if (!empty($path)) {
                if (isset($this->settings['labelsDirectory'])) {
                    $labelsDir = $this->settings['labelsDirectory'];

                    $pathLen = strlen($labelsDir);
                    if ($labelsDir[$pathLen -1] == '/') {
                        $labelsDir = substr($labelsDir, 0, -1);
                    }
                    
                    if (file_exists($labelsDir . $path)) {
                        $data['label_path_str_mv'] = $path;
                    }
                }
            }
        }

        return $data;
    }
    
    public function getFormat($keepRemove = false) {
        $formats = array();
        $field = $this->getField('FCT');
        if ($field) {
            $subA = $this->getSubfield($field, 'a');
            $formats[] = $subA;
        }
        
        if (count($formats) == 0) {
            $pubNo = $this->getFieldsSubfields(array(array(MarcRecord::GET_BOTH, '028', array('a'))), false, true);
            if (is_array($pubNo) && count($pubNo) > 0) {
                $end = substr($pubNo[0], -2);
                switch ($end) {
                    case '-2': $formats[] = self::VNF_CD; break;
                    case '-4': $formats[] = self::VNF_SOUND_CASSETTE; break;
                    case '-1': 
                    case '-7':
                    case '-M': $formats[] = self::VNF_VINYL; break;
                    case '-V': $formats[] = self::VNF_SHELLAC; break;
                    
                }
            }
        }
        
        if (count($formats) == 0) {
            if (is_array($pubNo) && count($pubNo) > 0) {
                $begin = substr(ltrim($pubNo[0]), 0, 2);
                if ($begin == '0 ' || $begin == '1 ') {
                    $formats[] = self::VNF_VINYL;
                }
            }
            
        }
        
        if (count($formats) == 0) {
            $formats[] = self::VNF_DATA;
        }
        
        $formats[] = $this->isAlbum() ? self::VNF_ALBUM : self::VNF_TRACK;
        return $this->unifyFormats($formats, $keepRemove);
    }
    
    public function getSpecialRecordType() {
    	return $this->isAlbum() ? 'sup_record' : null;
    }

    protected function isAlbum() {
        $field = $this->getField('000');
        if (is_string($field) && strlen($field) >= 22) {
                return $field[21] != 'c';
        }
        return true;
    }

    public function getImagePath($id, $size = 'medium')
    {
        $part = '';
        for ($i = 2; $i <= strlen($id); $i+=2) {
            $part .= '/' . $id[$i-2] . $id[$i-1];
        }
        $part .= '/' . $id . '-' . $size . '.jpg';
        return $part;
    }

    
    protected function getTOC()
    {
        if (!$this->isAlbum()) {
            return '';
        }
        
        $hierarchyLinks = $this->getHierarchLinks();
        $fields = $this->getFields('505');
        if (count($hierarchyLinks['sup_downlinks']) != count($fields)) {
            global $logger;
            $logger->log('getTOC', "Supraphon links-content mismatch in record " . $this->getID(), Logger::WARNING);
            return '';
        }
        
        $result = array();
        for ($i = 0; $i < count($fields); $i++) {
            $result[$i] = '';
            foreach ($fields[$i]['s'] as $subfield) {
                foreach ($subfield as $code => $value) {
                    $result[$i] .= '$' . $code . $value;
                }
            }
            $result[$i] .= '$x' . $hierarchyLinks['sup_downlinks'][$i]; 
        }
        
        $concat = implode(self::VNF_CONTENTS_SEPARATOR, $result);
        if (!empty($concat)) {
            $concat = 'vnf_sup:' . $concat;
        }
        return $concat;
    }
    
    protected function getHierarchLinks() {
        $result = array();
        $result['sup_downlinks'] = array();
        $result['sup_uplink'] = array();
        $fields = $this->getFields('LKR');
        if (is_array($fields)) {
            $prefix = isset($this->settings['idPrefix']) ? $this->settings['idPrefix'] : $this->settings['format'];
            if (substr($prefix, -1) != '.') {
                $prefix .= '.';
            }
            foreach ($fields as $field) {
                $type = $this->getSubfield($field, 'a');
                if ($type == 'UP') {
                    $result['sup_uplink'][] = $prefix . $this->getSubfield($field, 'b');
                } elseif ($type == 'DOWN') {
                    $result['sup_downlinks'][] = $prefix . $this->getSubfield($field, 'b');
                }
            }
        }
        return $result;
    }
    
    
}
