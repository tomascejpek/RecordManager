<?php
/**
 * XML File Splitter
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

/**
 * SAXFileSplitter
 *
 * This class allows sequence reading of records in XML format
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <mertam.m@gmail.com>
 */
class SAXFileSplitter
{
    protected $xmlParser;
    protected $recordTag;
    protected $file;

    protected $currentIndex;
    protected $currentRecord;

    protected $recordArray = array();
    protected $recordOpened = false;
    /**
     * Construct the splitter
     *
     * @param string $filename    Filename
     * @param string $recordTag   Tag name of starting record
     */
    function __construct($filename, $recordTag)
    {
        $this->recordTag = $recordTag;

        $this->file = fopen($filename, "rb");
        if (!file_exists($filename) || !is_readable ($filename)) {
            throw new Exception("Could not read file '$filename'");
        }

        $this->xmlParser = xml_parser_create("utf-8");
        xml_parser_set_option($this->xmlParser, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($this->xmlParser, array($this,"startElement"), array($this,"endElement"));
        xml_set_character_data_handler($this->xmlParser, array($this,"parseContent"));
        $this->reloadArray();
    }

    /**
     * Check whether EOF has been encountered
     *
     * @return boolean
     */
    public function getEOF()
    {
        return feof($this->file);
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
        if ($this->currentIndex >= count($this->recordArray)) {
            if ($this->getEOF()) {
                return false;
            } 
            $this->reloadArray();
        }
        return $this->recordArray[$this->currentIndex++];
    }

    /**
     * reloads internal array of records
     */
    protected function reloadArray()
    {
        $this->recordArray = array();
        while ($data = fread($this->file, 4096)) {
            xml_parse($this->xmlParser, $data, feof($this->file));
            if (count($this->recordArray) > 5) {
                break;
            }
        }
        $this->currentIndex = 0;
    }
    
    /**
     * SAX parser method
     * @param unknown $parser
     * @param unknown $name
     * @param unknown $attribs
     */
    protected function startElement($parser, $name, $attribs = array())
    {
        if (strcasecmp($name, $this->recordTag) == 0) {
            $this->recordOpened = true;
            $this->currentRecord = '';
        }

        if ($this->recordOpened) {
            $this->currentRecord .= '<'.$name;
            foreach ($attribs as $attribute => $value) {
                $this->currentRecord .= ' '.$attribute.'="'.$value.'"';
            }
            $this->currentRecord .= '>';
        }
    }
    
    /**
     * SAX parser method
     * @param unknown $parser
     * @param unknown $name
     */
    protected function endElement($parser, $name)
    {
        if ($this->recordOpened) {
            $this->currentRecord .= '</'.$name.'>';
        }
        if (strcasecmp($name, $this->recordTag) == 0) {
            $this->recordOpened = false;
            $this->recordArray[] = $this->currentRecord;
        }

    }

    /**
     * SAX parser method
     * @param unknown $parser
     * @param unknown $data
     */
    protected function parseContent($parser, $data) {
        if ($this->recordOpened) {
            $this->currentRecord .= $data;
        }
    }
}
