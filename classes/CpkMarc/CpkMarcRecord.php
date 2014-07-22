<?php
require_once __DIR__.'/../MarcRecord.php';
require_once __DIR__.'/../MetadataUtils.php';
require_once __DIR__.'/../Logger.php';

/**
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class CpkMarcRecord extends MarcRecord
{

    public function parseXML($xml) {
        //fixes occasional namespace bug
        $content = strstr($xml, 'controlfield', true);
        if($content) {
            $content = rtrim($content);
            if ($content[strlen($content) -1] == '<') {
                $xml = preg_replace('/<record.*>/', '<record>', $xml);
            } 
        }
        return parent::parseXML($xml);
    }
}
