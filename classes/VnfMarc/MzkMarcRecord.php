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
 * MarcRecord Class - local customization for VNF
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Vaclav Rosecky <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class MzkMarcRecord extends VnfMarcRecord
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

    public function getFormat($keepRemove = false) 
    {
        $formats = parent::getFormat($keepRemove);
        if (in_array(self::VNF_UNSPEC, $formats)) {
            $field = $this->getField('996');
            if ($field) {
                $subfield = $this->getSubfield($field, 'c');
                if ($subfield ) {
                    if (preg_match('/.*CD.*/', $subfield)) {
                        return array(self::VNF_ALBUM, self::VNF_CD);
                    } elseif (preg_match('/.*LP.*|.*SP.*/', $subfield)) {
                        return array(self::VNF_ALBUM, self::VNF_VINYL);
                    } elseif (preg_match('/.*KZ.*|.*MC.*/', $subfield)) {
                        return array(self::VNF_ALBUM, self::VNF_SOUND_CASSETTE);
                    } elseif (preg_match('/.*GD.*/', $subfield)) {
                        return array(self::VNF_ALBUM, self::VNF_SHELLAC);
                    }  
                }
            }
        }
        return $formats;
    }
}
