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
    //used formats
    const VNF_ALBUM = 'vnf_album';
    const VNF_TRACK = 'vnf_track';
    
    const VNF_CD             = 'vnf_CD';
    const VNF_SHELLAC        = 'vnf_shellac';
    const VNF_VINYL          = 'vnf_vinyl';
    const VNF_SOUND_CASSETTE = 'vnf_SoundCassette';
    const VNF_UNSPEC         = 'vnf_unspecified';
    const VNF_AUDIO_DOCS     = 'vnf_audio_documents';
    const VNF_MAGNETIC_TAPE  = 'vnf_magneticTape';
    const VNF_DATA           = 'vnf_data';
    const VNF_PHONOGRAPH     = 'vnf_phonograph_cylinder';
    
    const VNF_CONTENTS_SEPARATOR = "--!--";

    protected $overwrittenFields = array(self::VNF_SHELLAC, self::VNF_VINYL, self::VNF_SOUND_CASSETTE);
    
    
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
        $arr = array();
        $nbn = $this->getField('015');
        if ($nbn) {
            $nr = MetadataUtils::normalize(strtok($this->getSubfield($nbn, 'a'), ' '));
            $src = $this->getSubfield($nbn, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
                $arr[] = '(' . $src . '_view)' . $this->getSubfield($nbn, 'a');
            }
        }
        $nba = $this->getField('016');
        if ($nba) {
            $nr = MetadataUtils::normalize(strtok($this->getSubfield($nba, 'a'), ' '));
            $src = $this->getSubfield($nba, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
                $arr[] = '(' . $src . '_view)' . $this->getSubfield($nba, 'a');
            }
        }
        $id = $this->getField('024');
        if ($id) {
            $nr = MetadataUtils::normalize(strtok($this->getSubfield($id, 'a'), ' '));
            switch ($this->getIndicator($id, 1)) {
                case '0':
                    $src = 'istc';
                    break;
                case '1':
                    $src = 'upc';
                    break;
                case '2':
                    $src = 'ismn';
                    break;
                case '3':
                    $src = 'ian';
                    break;
                case '4':
                    $src = 'sici';
                    break;
                case '7':
                    $src = $this->getSubfield($id, '2');
                    break;
                default:
                    $src = '';
            }
            if ($src && $nr) {
                $arr[] = "($src)$nr";
                $arr[] = '(' . $src . '_view)' . $this->getSubfield($id, 'a');
            }
        }
        
        foreach ($this->getFields('028') as $field) {
            $kat = '';
            $content = '';
            if ($field) {
                $ind = $field['i1'];
                switch ($ind) {
                    case '0': $kat = 'iss'; $content = $this->getSubfields($field, 'ab'); break;
                    case '1': $kat = 'mat'; $content = $this->getSubfields($field, 'ab'); break;
                    case '2': $kat = 'pla'; $content = $this->getSubfields($field, 'ab'); break;
                    case '3': $kat = 'pub'; $content = $this->getSubfields($field, 'ab'); break;
                    case '6': $kat = 'pub'; $content = $this->getSubfields($field, 'ab'); break;
                    default:  $kat = 'pub'; $content = $this->getSubfields($field, 'ab'); break;
                }
                
                if (!empty($content)) {
                    $arr[] = '(' . $kat . ')' . MetadataUtils::normalize($content);
                    $arr[] = '(' . $kat . '_view)' . $content;
                }
            }
        }
        return $arr;
    }

    public function toSolrArray() {
        
        $data = parent::toSolrArray();
        
        $data['url'] = array();
        foreach ($this->getFields('856') as $field) {
            $url = $this->getSubfield($field, 'u');
            if (!empty($url)) {
                $desc = $this->getSubfields($field, 'y');
                $data['url'][] = $url . '!desc!' . $desc;
            }
        }      
        
        foreach ($this->getUniqueIDs() as $uid) {
            if (strlen($uid) > 5) {
                $prefix = substr($uid, 1, strpos($uid, ')') -1 );
                if (!is_string($prefix)) {
                    continue;
                }
                
                switch ($prefix) {
                    case 'ian': $this->checkUidArray($data, 'ean_txtP_mv');  $data['ean_txtP_mv'][] = substr($uid,5); break;
                    case 'ian_view': $this->checkUidArray($data, 'ean_view_txtP_mv');  $data['ean_view_txtP_mv'][] = substr($uid,10); break;
                    
                    case 'istc':$this->checkUidArray($data, 'isrc_txtP_mv'); $data['isrc_txtP_mv'][] = substr($uid,6); break;
                    case 'istc_view':$this->checkUidArray($data, 'isrc_view_txtP_mv'); $data['isrc_view_txtP_mv'][] = substr($uid,11); break;
                    
                    case 'upc': $this->checkUidArray($data, 'upc_txtP_mv');  $data['upc_txtP_mv'][] = substr($uid,5); break;
                    case 'upc_view': $this->checkUidArray($data, 'upc_view_txtP_mv');  $data['upc_view_txtP_mv'][] = substr($uid,10); break;
                    
                    case 'iss': $this->checkUidArray($data, 'issue_txtP_mv');$data['issue_txtP_mv'][] = substr($uid,5); break;
                    case 'iss_view': $this->checkUidArray($data, 'issue_view_txtP_mv');$data['issue_view_txtP_mv'][] = substr($uid,10); break;
                    
                    case 'mat': $this->checkUidArray($data, 'matrix_txtP_mv');$data['matrix_txtP_mv'][] = substr($uid,5); break;
                    case 'mat_view': $this->checkUidArray($data, 'matrix_view_txtP_mv');$data['matrix_view_txtP_mv'][] = substr($uid,10); break;
                    
                    case 'pla': $this->checkUidArray($data, 'plate_txtP_mv'); $data['plate_txtP_mv'][] = substr($uid,5); break;
                    case 'pla_view': $this->checkUidArray($data, 'plate_view_txtP_mv'); $data['plate_view_txtP_mv'][] = substr($uid,10); break;
                    
                    case 'pub': $this->checkUidArray($data, 'publisher_txtP_mv');$data['publisher_txtP_mv'][] = substr($uid,5); break;
                    case 'pub_view': $this->checkUidArray($data, 'publisher_view_txtP_mv');$data['publisher_view_txtP_mv'][] = substr($uid,10); break;
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
            $data['url_fct_txtF_mv'] = array();
            for($i = 0; $i < count($data['url']); $i++) {
                $data['externalLinks_str_mv'][$i] = $this->source . ';' . $data['url'][$i];
                if (preg_match('/.*kramerius.*/', $data['url'][$i])) {
                    $data['url_fct_txtF_mv'][] = preg_match('/.*public.*/', $data['url'][$i]) ? 'kramerius_public' : 'kramerius';
                } elseif (preg_match('/.*supraphonline.*/', $data['url'][$i])) {
                    $data['url_fct_txtF_mv'][] = 'supraphon';
                } elseif (preg_match('/.*radioteka.*/', $data['url'][$i])) {
                    $data['url_fct_txtF_mv'][] = 'radioteka';
                } elseif (preg_match('/.*audioteka.*/', $data['url'][$i])) {
                    $data['url_fct_txtF_mv'][] = 'audioteka';
                } else {
                    $data['url_fct_txtF_mv'][] = 'other';
                } 
            }
            
        }

        //filter out formats not importat for solr
        if (isset($data['format']) && is_array($data['format'])) {
            $data['format'] = array_diff($data['format'], array(self::VNF_AUDIO_DOCS));
            //track cannot have other format
            if (in_array(self::VNF_TRACK, $data['format'])) {
                $data['format'] = array(self::VNF_TRACK);
            }
        }
        
        $data['authors_corporate_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '110', array('a')),
                array(MarcRecord::GET_BOTH, '710', array('a'))
            ),
            false
        );
        
        $data['authors_other_str_mv'] =  $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '700', array('a')),
            ),
            false
        );
        
        $data['publisher_str'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '260', array('a', 'b', 'c')),
            ),
            true
        );
        
        $data['series_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '440', array('a')),
                array(MarcRecord::GET_BOTH, '800', array('a')),
                array(MarcRecord::GET_BOTH, '840', array('a'))
            ),
            true
        );

        $data['topic'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '600', array('a')),
                array(MarcRecord::GET_BOTH, '610', array('a')),
                array(MarcRecord::GET_BOTH, '611', array('a')),
                array(MarcRecord::GET_BOTH, '630', array('a')),
                array(MarcRecord::GET_BOTH, '648', array('a')),
                array(MarcRecord::GET_BOTH, '650', array('a')),
                array(MarcRecord::GET_BOTH, '651', array('a')),
                array(MarcRecord::GET_BOTH, '655', array('a')),
                array(MarcRecord::GET_BOTH, '656', array('a')),
                array(MarcRecord::GET_BOTH, '964', array('a'))
            ),
            false
        );
        
        $data['description_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '500', array('a')),
            ),
            false
        );
        
        $data['awards_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '586', array('a'))
            ),
            false
        );
        
        $data['audience_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '521', array('a'))
            ),
            false
              
        );
        
        $data['production_credits_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '508', array('a'))
            ),
            false
        );
        
        $data['performer_note_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '511', array('a'))
            ),
            false
        );
        
        $data['location_date_note_str_mv'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '518', array('a'))
            ),
            false
        );
        
        $data['summary_txt'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '520', array('a'))
            ),
            true
        );
        
        $data['contents'] = $this->getTOC();
        $data['contents_txt'] = preg_replace(array('/\$[a-zA-Z]/', '/--!--/', '/vnf_sup:/'), ' ', $data['contents']);
         
        return $data;
    }
    
     /**
     * if one of record formats is 'remove' and there are less or equal than two formats (one is always track/album), then record is ignored
     * NOTE: this method must be explicitly enabled for record source in datasources.ini file
     */
    public function checkRecord() {
        if (isset($this->settings) && isset($this->settings['enableRecordCheck']) && $this->settings['enableRecordCheck']) {
            $formats = $this->getFormat(true);
            return !(count($formats) < 2 || in_array('remove', $formats));
        }
        return true;     
          
    }
    
    /**
     * @param keepRemove - decides whether format 'remove' should be kept in output
     */
    public function getFormat($keepRemove = false) {
        $formats = parent::getFormat();
        
        if (!in_array(self::VNF_TRACK, $formats)) {
            $formats[] = self::VNF_ALBUM;
        }
        
        $field = $this->getField('007');
        if ($field && is_string($field) && strlen($field) > 2) {
            if ($field[0] == 's') {
                switch ($field[1]) {
                    case 'd':
                        if (strlen($field) >= 4 && $field[3] == 'd') {
                            return $this->mergeAndUnify($formats, self::VNF_SHELLAC, $keepRemove);
                        }
                        if (strlen($field) >= 11 && $field[10] == '2') {
                            return $this->mergeAndUnify($formats, self::VNF_SHELLAC, $keepRemove);
                        }
                        if (strlen($field) >= 7 && $field[6] == 'd') {
                            return $this->mergeAndUnify($formats, self::VNF_CD, $keepRemove);
                        }
                        break;
                    case 's':
                        return $this->mergeAndUnify($formats, self::VNF_SOUND_CASSETTE, $keepRemove);
                    case 't':
                        return $this->mergeAndUnify($formats, self::VNF_MAGNETIC_TAPE, $keepRemove);
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
                if (preg_match('/.*obsahuje\sCD.*|.*kompaktn[ií]\sdisk.*|.*zvukov[eéaá]\sCD.*|.*\d+\s*CD.*/iu', $concat)) {
                    return $this->mergeAndUnify($formats, self::VNF_CD, $keepRemove);
                }
                if (preg_match('/.*zvukov(a|á|e|é|ych|ých)\sdes(ka|ky|ek).*/iu', $concat)) {
                    if (preg_match('/.*digital.*|.*12\s*cm.*/iu', $concat)) {
                        return $this->mergeAndUnify($formats, self::VNF_CD, $keepRemove);
                    }
                    
                    $numbersOnly = preg_replace("/[^\d]+/", ' ', $concat);
                    if (preg_match('/\s*/', $concat)) {
                        // no numbers
                        if (preg_match('/.*analog.*/i', $concat)) {
                            return $this->mergeAndUnify($formats, self::VNF_VINYL, $keepRemove);
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
                                return $this->mergeAndUnify($formats, self::VNF_SHELLAC, $keepRemove);
                            } elseif ($rotations > 0 || preg_match('/.*analog.*/i', $concat)) {
                                return $this->mergeAndUnify($formats, self::VNF_VINYL, $keepRemove);
                            }
  
                        }
                    }
                }
                if (preg_match('/.*zvukov(a|á|e|é|ych|ých)\s+kaze(ta|ty|t)|.*MC.*|.*KZ.*|.*MGK.*.*/iu', $concat)) {
                    return $this->mergeAndUnify($formats, self::VNF_SOUND_CASSETTE, $keepRemove);
                }
                if (preg_match('/.*LP.*/i', $concat)) {
                    return $this->mergeAndUnify($formats, self::VNF_VINYL, $keepRemove);
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
    public static function unifyFormats($formats, $keepRemove = false) {
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
                $logger->log('unifyVNFFormats', "No mapping found for: $format \t", Logger::WARNING);
                $unified[] = 'unmapped_' . $format;
            } else {
                if ($unificationArray[$format] == 'ignore') {
                    continue;
                }
                $unified = array_merge($unified, explode(',', $unificationArray[$format]));
            }
        }
        
        //remove 'vnf_unspecified format if there are at least two other formats (one is always vnf_album or vnf_track)
        if (in_array(self::VNF_UNSPEC, $unified) && count($unified) > 2) {
            $unified = array_filter($unified, function($a) { return strcasecmp($a, self::VNF_UNSPEC) !== 0; });
        }
        
        //add vnf_unspecified format to albums with no format
        if (count($unified) == 1 && in_array(self::VNF_ALBUM, $unified)) {
            $unified[] = self::VNF_UNSPEC;
        }
        
        return array_unique($unified);
    }
    
    protected function mergeAndUnify($formats, $newFormats, $keepRemove = false) {
        //check for occasional bug - cassettes and vinyls are already recognized as CD from field 007
        //in that case CD format is ignored
        $overwrittenIntersect = array_intersect(is_array($newFormats) ? $newFormats : array($newFormats), $this->overwrittenFields);
        if (!empty($overwrittenIntersect)) {
            if (in_array(self::VNF_CD, $formats)) {
                $formats = array_diff($formats, array(self::VNF_CD));
            }
        }
        return $this->unifyFormats(array_merge(is_array($formats) ? $formats : array($formats), is_array($newFormats) ? $newFormats : array($newFormats)), $keepRemove);
    }
    
    protected function checkUidArray(&$data, $fieldName) {
        if (!array_key_exists($fieldName, $data)) {
            $data[$fieldName] = array();
        }
    }
    
    protected function getTOC() {
        $contents = array();
        foreach ($this->getFields('505') as $field) {
            $subfield = $this->getSubfield($field, 'a');
            if ($subfield) {
                $contents[] = $subfield;
            }
        }
        
        
       return implode(self::VNF_CONTENTS_SEPARATOR, $contents);
    }
}
