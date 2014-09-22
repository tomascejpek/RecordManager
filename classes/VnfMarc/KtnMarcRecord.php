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
 * MarcRecord Class - local customization for MZK
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class KtnMarcRecord extends VnfMarcRecord
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
    
    public function checkRecord() {
        $field = $this->getField('300');
        if ($field) {
            $subfields = $this->getAllSubfields($field);
            if ($subfields) {
                $concat = implode('__', $subfields);
                //filter records in brail
                return !preg_match('/.*brail.*/i', $concat);
            } 
        }
        return true;
    }

    protected function parseLineMarc($marc) {
        
        $lines = explode("\n", $marc);
               
        $finalField = array();
        foreach ($lines as $line) {
            $line = trim($line,"\n\r");
            
            $field = substr($line, 0, 3);
            $line = substr($line, 3, strlen($line));

            if ($field == 'LDR') {
                $finalField['000'] = array($line);
                continue;
            }

            $arrayField = array();
            //handle this for wrong-formated fields 001, 003, 005, 008
            if ($field == '001' || $field == '003' || $field == '005' || $field == '008') {
                $finalField[$field] = array($this->encodeString($line));
                continue;
            }
            
            $arrayField['i1'] = $line[0];
            $arrayField['i2'] = $line[1];
            $line = substr($line,2);

            $subfield = null;
            $currentString = "";
            $arrayField['s'] = array();

            for ($i = 0; $i < strlen($line);) {
                if ($line[$i] == '$' && ctype_alnum($line[$i+1])) {
                    if ($subfield != null) {
                        $arrayField['s'][] = array($subfield => $this->encodeString($currentString));
                    }
                    $subfield = null;
                    $currentString = "";
                    $subfield = $line[++$i];
                    $i++;
                } else {
                    $currentString .= $line[$i++];
                }
            }
            $arrayField['s'][] = array($subfield => $this->encodeString($currentString));
            $finalField[$field] = array($arrayField);

        }
        $this->fields = $finalField;
    }

}
?>