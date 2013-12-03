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

    const CHUNK_SIZE = 1048576; //1 MB
    protected $records;
    protected $current;
    protected $file;

    protected $restFromLastRound;

    /**
     * Construct the splitter
     *
     * last two args are not used, only keeps arity of FileSplitter constructor
     * @param mixed  $data        XML string or DOM document
     */
    function __construct($fileName)
    {
        $this->file = fopen($fileName, "rb");
        if (!file_exists($fileName) || !is_readable ($fileName)) {
            throw new Exception("Could not read file '$fileName'");
        }
        
        $this->reloadArray();
    }

    /**
     * Check whether EOF has been encountered
     *
     * @return boolean
     */
    public function getEOF()
    {
        return  feof($this->file);
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
        $result = trim($this->current,"\n\r");
        $this->current = $this->records->nextRaw();
        if ( !(bool) $this->current) {
            $this->reloadArray();
        }
        return $result;
    }

    /**
     * reads another chunk from input file and creates records from it
     */
    protected function reloadArray() {
        $bytes = fread($this->file, self::CHUNK_SIZE);
        $endsArray = array();

        $first = null;
        $last = null;
        for ($i = 0; $i < strlen($bytes); $i++) {
            if ($bytes[$i] == File_MARC::END_OF_RECORD) {
                $first = $i;
                break;
            }
        }
        
        for ($i = strlen($bytes) -1; $i > 0; $i--) {
            if ($bytes[$i] == File_MARC::END_OF_RECORD) {
                $last = $i;
                break;
            }
        }
        
        if (empty($first) && empty($last)) {
            //any record nor starts nor ends in chunk 
            $this->restFromLastRound .= $bytes;
            self::reloadArray();
            return;
        }
        
        $rest = null;
        if ($last < strlen($bytes)) {
            $rest = substr($bytes, $last + 1);
            $bytes = substr($bytes, 0, $last + 1);
        }
        
        if ($this->restFromLastRound != null) {
            $bytes = $this->restFromLastRound.$bytes;
        } 

        $this->restFromLastRound =  trim($rest,"\n\r");
        $this->records = new File_MARC($bytes, File_MARC::SOURCE_STRING);
        $this->current = $this->records->nextRaw();


    }
}