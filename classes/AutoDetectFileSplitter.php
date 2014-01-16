<?php
/**
 * File Splitter with input format detection
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
 * AutoDetectFileSplitter
 *
 * This class is wrapper around other file splitters. It detects format of input file,
 * checks for required settings and creates corresponding file splitter class.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 */
class AutoDetectFileSplitter {

    protected $fileSplitter = null;
    protected $settings = array();

    public function __construct($filename, $source)
    {
        if (!is_readable($filename)) {
            throw new Exception('AutoDetectFileSplitter: input file \''.$filename.'\' isn\'t readable');
        }

        global $dataSourceSettings;
        $this->settings = $dataSourceSettings[$source];

        global $logger;
        
        $fh = fopen($filename, "rb");
        $content = fread($fh, 10000);
        fclose($fh);

        $content = trim($content);

        if ($content[0] == '<') {
            $logger->log("AutoDetectFileSplitter", "File $filename is in XML format, SAXFileSplitter will be used");
            if (!array_key_exists('recordTag', $this->settings) || !isset($this->settings['recordTag'])) {
                throw new Exception('AutoDetectFileSplitter: missing field \'recordTag\' in settings');
            }
            require_once 'SAXFileSplitter.php';
            $this->fileSplitter = new SAXFileSplitter($filename, $this->settings['recordTag']);
            return;
        }

        for ($i = 0; $i < strlen($content); $i++) {
            if ($content[$i] == "\x1E") {
                $logger->log("AutoDetectFileSplitter", "File $filename is in ISO2709 format, BinaryMarcFileSplitter will be used");
                require_once 'BinaryMarcFileSplitter.php';
                $this->fileSplitter = new BinaryMarcFileSplitter($filename);
                return;
            }
        }

        if (ctype_alpha($content[0])) {
            $logger->log("AutoDetectFileSplitter", "File $filename is in line Marc format, LineMarcFileSplitter will be used");
            require_once 'LineMarcFileSplitter.php';
            $this->fileSplitter = new LineMarcFileSplitter($filename, $source);
            return;
        }

        throw new Exception('AutoDetectFileSplitter: format recognition failed');

    }

    /**
     * Check whether EOF has been encountered
     *
     * @return boolean
     */
    public function getEOF()
    {
        return $this->fileSplitter->getEOF();
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
        return $this->fileSplitter->getNextRecord($oaiID);
    }
}
