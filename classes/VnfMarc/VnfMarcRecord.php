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
class VnfMarcRecord extends PortalsCommonMarcRecord
{
   
    protected function getInstitution() {
        if (isset($this->settings) && isset($this->settings['institution'])) {
            return $this->settings['institution'];
        } else {
            throw new Exception("No institution name set for datasource: $this->source");
        }
        
        //check for CistBrno settings
        global $configArray;
        if (!$configArray['VNF']['format_unification_array']) {
            throw new Exception("No format unification for VNF");
        }
    }
    
    /**
     * adds field 028 to unique ids
     * @see MarcRecord::getUniqueIDs()
     */
    public function getUniqueIDs() {
        $uIds = parent::getUniqueIDs();
        $field = $this->getField('028');
        if ($field) {
            $subA = $this->getSubfield($field, 'a');
            $subB = $this->getSubfield($field, 'b');
            if (empty($subA) || empty($subB)) {
                return $uIds;
            }
            $subB = explode(' ', $subB); 
            $uIds[] = '(kat)' . MetadataUtils::normalize($subA. $subB[0]);
        }
        return $uIds;
    }

    public function toSolrArray() {
        
        $data = parent::toSolrArray();
        
        foreach (parent::getUniqueIDs() as $uid) {
            if (strlen($uid) > 5) {
                $prefix = substr($uid, 1,3);
                if ($prefix == 'ian') {
                    $data['ean_str_mv'] = substr($uid,5);
                }
            }
        }
        
        $field = parent::getField('260');
        if ($field) {
            $year = parent::getSubfield($field, 'c');
            $matches = array();
            if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                $data['publishDate_display'] = $matches[1];
            }
        }
       
        $data['institutionAlbumsOnly'] = $this->getInstitution();

        if (isset($data['url']) && !empty($data['url'])) {
            $data['externalLinks_str_mv'] = array();
            for($i = 0; $i < count($data['url']); $i++) {
                $data['externalLinks_str_mv'][$i] = $this->source . ';' . $data['url'][$i];
            }
        }

        return $data;
    }
    
     /**
     * if one of record formats is 'remove' and there are less or equal than two formats (one is always track/album), then record is ignored
     * NOTE: this method must be explicitly enabled for record source in datasources.ini file
     */
    public function checkRecord() {
        if (isset($this->settings) && isset($this->settings['enableRecordCheck']) && $this->settings['enableRecordCheck']) {
            $formats = $this->getFormat(true);
            return count($formats) > 2 || !in_array('remove', $formats);
        }
        return true;     
          
    }
    
    /**
     * @param keepRemove - decides whether format 'remove' should be kept in output
     */
    public function getFormat($keepRemove = false) {
        $formats = parent::getFormat();
        
        if (!in_array('vnf_track', $formats)) {
            $formats[] = 'vnf_album';
        }
        
        $field = $this->getField('007');
        if ($field && is_string($field) && strlen($field) > 2) {
            if ($field[0] == 's') {
                switch ($field[1]) {
                    case 'd':
                        if (strlen($field) >= 4 && $field[3] == 'd') {
                            return $this->mergeAndUnify($formats, 'vnf_shellac', $keepRemove);
                        }
                        if (strlen($field) >= 11 && $field[10] == '2') {
                            return $this->mergeAndUnify($formats, array('vnf_shellac'), $keepRemove);
                        }
                        if (strlen($field) >= 7 && $field[6] == 'd') {
                            return $this->mergeAndUnify($formats, array('vnf_CD'), $keepRemove);
                        }
                        break;
                    case 's':
                        return $this->mergeAndUnify($formats, array('vnf_SoundCassette'), $keepRemove);
                    case 't':
                        return $this->mergeAndUnify($formats, array('vnf_magneticTape'), $keepRemove);
                        //no need for this?
//                     default:
//                         return $this->mergeAndUnify($formats, array('vnf_unspecified'));
                }
            }
        }
        
        $field = $this->getField('300');
        if ($field) {
            $subfields = $this->getAllSubfields($field);
            if ($subfields) {
                $concat = implode('__', $subfields);
                if (preg_match('/.*obsahuje\sCD.*|.*kompaktn[ií]\sdisk.*|.*zvukov[eéaá]\sCD.*/iu', $concat)) {
                    return $this->mergeAndUnify($formats, array('vnf_CD'), $keepRemove);
                }
                if (preg_match('/.*zvukov(a|á|e|é|ych|ých)\sdes(ka|ky|ek).*/iu', $concat)) {
                    if (preg_match('/.*digital.*|.*12\scm.*/iu', $concat)) {
                        return $this->mergeAndUnify($formats, array('vnf_CD'), $keepRemove);
                    }
                    
                    $numbersOnly = preg_replace("/[^\d]+/", ' ', $concat);
                    if (preg_match('/\s*/', $concat)) {
                        // no numbers
                        if (preg_match('/.*analog.*/i', $concat)) {
                            return $this->mergeAndUnify($formats, array('vnf_vinyl'), $keepRemove);
                        }
                    } else {
                        //some numbers found - search for rotations per minute
                        if (preg_match('/.*ot\/min*/i', $concat)) {
                            $rotations = 0;
                                                  
                            $parts = preg_split('/ot\/min/i', $concat);
                            if (count($parts) > 1) {
                                for ($i = 0; $i < count($parts) -1; $i++) {
                                    $currentPart = ltrim($parts[$i]);
                                    $min = strlen($currentPart) > 4 ? 5 : strlen($parts[$i]);
                                    //get last characters before 'ot/min'
                                    $currentPart = substr($currentPart, -1 * $min);
                                    //replace all non numbers with space
                                    $replaced = preg_replace("/[^\d]+/", ' ', $currentPart);
                                    $trimmed = trim($replaced);
                                    if ($trimmed) {
                                        //current rotations = last number before 'ot/min'
                                        $currentRotations = intval(end(preg_split('/ /', $trimmed)));
                                        $rotations = $currentRotations > $rotations ? $currentRotations : $rotations;
                                    }
                            
                                }
                            }
                            global $logger;
                            $logger->log('getFormat', "Rotations: \t$rotations found for ID " . $this->getID());
                              
                            if ($rotations > 45) {
                                return $this->mergeAndUnify($formats, 'vnf_shellac', $keepRemove);
                            } elseif ($rotations > 0 || preg_match('/.*analog.*/i', $concat)) {
                                return $this->mergeAndUnify($formats, 'vnf_vinyl', $keepRemove);
                            }
  
                        }
                    }
                }
                if (preg_match('/.*zvukov(a|á|e|é|ych|ých)\s+kaze(ta|ty|t)|.*MC.*|.*KZ.*|.*MGK.*.*/iu', $concat)) {
                    return $this->mergeAndUnify($formats, array('vnf_SoundCassette'), $keepRemove);
                }
                if (preg_match('/.*LP.*/i', $concat)) {
                    return $this->mergeAndUnify($formats, array('vnf_vinyl'), $keepRemove);
                }
                 
            }
        }
              
        return $this->unifyFormats($formats, $keepRemove);
    }
    
    /**
     *
     * @param unify given formats according to corresponding mapping
     * @param keepRemove - decides whether format 'remove' should be kept in output
     * @return array
     */
    protected function unifyFormats($formats, $keepRemove = false) {
        global $configArray;
        global $logger;
        
        $unificationArray = &$configArray['VNF']['format_unification_array'];
        $unified = array();
        foreach ($formats as $format) {
            if (substr($format, 0, 4) == 'vnf_') {
                 $unified = array_merge($unified, explode(',', $format));
                 continue;
            }
            if (empty($format) || in_array($format, array('ignore')) || /* TODO remove */ substr($format,0, 4) == 'cist' || substr($format,0, 4) == 'unma') {
                //ignore prefix ignore
                continue;
            }
            if ($format == 'remove') {
                if ($keepRemove) {
                    $unified[] = $format;
                }
                continue;
            }
            if (!array_key_exists($format, $unificationArray)) {
                $logger->log('unifyVNFFormats', "No mapping found for: $format \t". $this->getID(), Logger::WARNING);
                $unified[] = 'unmapped_' . $format;
            } else {
                if ($unificationArray[$format] == 'ignore') {
                    continue;
                }
                $unified = array_merge($unified, explode(',', $unificationArray[$format]));
            }
        }
        
        //remove 'vnf_unspecified format if there are at least two other formats (one is always vnf_album or vnf_track)
        if (in_array('vnf_unspecified', $unified) && count($unified) > 2) {
            $unified = array_filter($unified, function($a) { return strcasecmp($a, 'vnf_unspecified') !== 0; });
        }
        
        return array_unique($unified);
    }
    
    protected function mergeAndUnify($newFormats, $formats, $keepRemove = false) {
        return $this->unifyFormats(array_merge(is_array($formats) ? $formats : array($formats), is_array($newFormats) ? $newFormats : array($newFormats)), $keepRemove);
    }

}
