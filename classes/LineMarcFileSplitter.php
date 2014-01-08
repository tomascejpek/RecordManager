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
 * This class splits line MARC into multiple records
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */

class LineMarcFileSplitter {
    
    const MAX_LENGTH = 10000;
    
    protected $file;
    protected $settings = array();
    
    public function __construct($filename, $source)
    {
        if (!is_readable($filename)) {
            throw new Exception('LineMarcFileSplitter: input file \''.$filename.'\' isn\'t readable');
        }
        
        global $dataSourceSettings;
        $this->settings = $dataSourceSettings[$source];
        
        $this->file = fopen($filename, "rb");
        
        if (!array_key_exists('lineRecordLeader', $this->settings) || !isset($this->settings['lineRecordLeader'])) {
            //detect leader if it's not found in settings   
            $line = fgets($this->file);
            $line = trim($line);
            rewind($this->file);
            
            $array = preg_split("/[\s]+/", $line);
            if (count($array) < 2) {
                 throw new Exception('LineMarcFileSplitter: Leader recognition failed');
            }
            $this->settings['lineRecordLeader'] = $array[0];
            fseek($this->file, strlen( $this->settings['lineRecordLeader']));
        }
    }
    
    public function getEOF() 
    {
        return feof($this->file);
    }
    
    public function getNextRecord($dummyArg = null)
    {
        return  $this->settings['lineRecordLeader'] . stream_get_line($this->file, self::MAX_LENGTH,  $this->settings['lineRecordLeader']);
    }
}
?>