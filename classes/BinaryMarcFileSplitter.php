<?php
/**
 * Binary Marc Splitter
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-20112
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
 * @author   Václav Rosecký <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */

/**
 * BinaryMarcSplitter
 *
 * This class splits binary MARC to multiple records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Václav Rosecký <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */

require_once 'File/MARC.php';
 
class BinaryMarcFileSplitter
{

    protected $records;
    protected $current;

    /**
     * Construct the splitter
     * 
     * @param mixed  $data        XML string or DOM document
     * @param string $recordXPath XPath used to find the records
     * @param string $oaiIDXPath  XPath used to find the records' oaiID's (relative to record)
     */
    function __construct($file)
    {
        $this->records = new File_MARC($file);
        $this->current = $this->records->nextRaw();
    }

    /**
     * Check whether EOF has been encountered
     * 
     * @return boolean
     */
    public function getEOF()
    {
        return  !((bool) $this->current);
    }

    /**
     * Get next record
     * 
     * @param string &$oaiID OAI Identifier (if XPath specified in constructor)
     * 
     * @return string|boolean
     */
    public function getNextRecord(&$oaiID)
    {
        $result = $this->current;
        $this->current = $this->records->nextRaw(); 
        return $result;
    }
}