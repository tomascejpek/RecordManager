<?php
/**
 * Line Marc Splitter
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
 * @author   Michal Merta
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */

/**
 * LineMarcFileSplitter
 *
 * This class splits binary MARC to multiple records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */

class LineMarcFileSplitter {
    
    protected $splitedData;
    protected $leader;
    protected $currentIndex;
    
    public function __construct($data, $leader, $dummyArg = null)
    {
        if (empty($leader)) {
            if ($xml === false) {
                throw new Exception('LineMarcFileSplitter: Missing record leader');
            }
        }
        $this->leader = $leader;
        $this->currentIndex = 0;
        $this->splitedData = preg_split("/^$this->leader/m", $data, -1, PREG_SPLIT_NO_EMPTY);
    }
    
    public function getEOF() 
    {
        return $this->currentIndex >= count($this->splitedData);
    }
    
    public function getNextRecord($dummyArg = null)
    {
        return $this->leader.$this->splitedData[$this->currentIndex++];
    }
    
    
}
?>