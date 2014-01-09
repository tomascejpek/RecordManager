<?php
/**
 * File Splitter with input format and czech encoding detection
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
 * EncodingDetectFileSplitter
 *
 * This class is wrapper around AutoDetectFilleSplitter. It creates AutoDetectFileSplitter
 * object and also can detect encoding of input file. Note that encoding detection is skiped
 * if 'inputEncoding' is specified in datasource settings.
 *
 * 
 * NOTE: This implementation is experimental, it depends on external library Enca (http://freecode.com/projects/enca)
 *       and is specific for UNIX/Linux.
 *
 * NOTE: Encoding detection is particialy probabilistic.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 */

require_once 'AutoDetectFileSplitter.php';

class EncodingDetectFileSplitter {

    protected $fileSplitter = null;

    protected $logger;
    protected $finalDecision = false;
    protected $detectedEncoding = "utf-8";

    function __construct($filename, $source)
    {
        $this->fileSplitter = new AutoDetectFileSplitter($filename, $source);

        global $dataSourceSettings;
        $settings = $dataSourceSettings[$source];
        
        global $logger;
        if (array_key_exists('inputEncoding', $settings) && isset($settings['inputEncoding'])) {
            $logger->log('EncodingDetectFileSplitter', 'Skipping encoding detection, \''.$settings['inputEncoding'].'\' from settings will be used.', LOGGER::INFO);
        } else {           
            $language = 'none';
            if (!array_key_exists('inputLanguage', $settings) || !isset($settings['inputLanguage'])) {
                $logger->log('EncodingDetectFileSplitter', "Missing 'inputLanguage' option in settings.", LOGGER::WARNING);
            } else {
                $language = $settings['inputLanguage'];
            }
            
            exec("enca -iL $language $filename", $output, $return);
            if ($return > 0 || $output[0] == '???') {
                $logger->log('EncodingDetectFileSplitter', "Encoding detection of file '$filename' failed.", LOGGER::WARNING);
            } else {
                $settings['inputEncoding'] = $output[0];
                $logger->log("EncodingDetectFileSplitter", "Encoding of file '$filename' detected as: $output[0].", LOGGER::INFO);
                $dataSourceSettings[$source] = $settings;
            }
        }
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
