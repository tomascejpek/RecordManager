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

require_once 'MarcRecord.php';
require_once 'MetadataUtils.php';
require_once 'Logger.php';

/**
 * MarcRecord Class - local customization for MZK
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Vaclav Rosecky <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class MzkMarcRecord extends MarcRecord
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
        
        //normalize IDs in LKR field
        if (isset($this->fields['LKR'])) {
            for ($i = 0; $i < count($this->fields['LKR']); $i++) {
                for ($j = 0; $j < count($this->fields['LKR'][$i]['s']); $j++) {
                    if (isset($this->fields['LKR'][$i]['s'][$j]['b'])) {
                        $id = ($this->fields['LKR'][$i]['s'][$j]['b']);
                        if (strcasecmp(substr($id, 0, strlen($source)), $source) != 0) {
                            $this->fields['LKR'][$i]['s'][$j]['b'] 
                              = $source . '.' . str_pad($this->fields['LKR'][$i]['s'][$j]['b'], 9, '0', STR_PAD_LEFT); 
                        }
                    }
                }
            }
        }
    }
    
    /**
     * return array of hierarychy information
     * 
     * @return array (array (a => direction, b => id))
     */
    public function getHierarchyField() {
        $result = array();
        if (isset($this->fields['LKR'])) {
            foreach ($this->fields['LKR'] as $lkr) {
                $a = parent::getSubfields($lkr,'a');
                $b = parent::getSubfields($lkr,'b');
                $result[] = array('a' => $a, 'b' => $b);
            }
        }
        
        return $result;
    }
    
    /**
     * adds hierarchy information to LKR field
     * @param unknown $type
     * @param unknown $target
     * @throws Exception
     */
    public function addHierarchyLink($type, $target) {
        $uplinks = 0;
        if (isset($this->fields['LKR'])) {
            for ($i = 0; $i < count($this->fields['LKR']); $i++) {
                for ($j = 0; $j < count($this->fields['LKR'][$i]['s']); $j++) {
                    if (isset($this->fields['LKR'][$i]['s'][$j]['b']) && strcasecmp($this->fields['LKR'][$i]['s'][$j]['b'], $target) == 0 ) {
                       return;
                    }
                    if (isset($this->fields['LKR'][$i]['s'][$j]['a']) && strcasecmp($this->fields['LKR'][$i]['s'][$j]['a'], 'UP') == 0 ) {
                       $uplinks++;
                    }
                }
            }
        }
        
        if ($type == 'UP' && $uplinks > 1) {
            global $logger;
            $id = $this->source . '.' . $this->fields['001'];
            $logger->log('MzkMarcRecord', "Creating more than one UP link for record '$id', link is ignored", Logger::WARNING);
        }
        
        $link = array();
        $link['i1'] = $link['i2'] = ' ';
        $link['s'] = array();
        $link['s'][] = array('a' => $type);
        $link['s'][] = array('b' => $target);
        $this->fields['LKR'][] = $link;
    }
}
