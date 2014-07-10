<?php

require_once 'PortalMarcRecord.php';
require_once __DIR__.'/../MappableMarcRecord.php';

/**
 * MarcRecord Class - local customization for cistbrno
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Vaclav Rosecky <xrosecky@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class MappablePortalMarcRecord extends MappableMarcRecord
{
    
    /**
     * Return languages - local modification
     *
     * kept:
     * 041a - language code of text/sound track or separate title
     * 041d - language code of sung or spoken text
     *
     * added:
     * 041e - language code of librettos
     *
     * removed (confusing for readers):
     * 041h - language code of original
     * 041j - language code of subtitles or captions
     *
     * @return array
     *
     */
    public function getLanguages()
    {
        $languages = array(substr($this->getField('008'), 35, 3));
        $languages += $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '041', array('a')),
                array(MarcRecord::GET_NORMAL, '041', array('d')),
                array(MarcRecord::GET_NORMAL, '041', array('e')),
            ),
            false, true, true
        );
        return $languages;
    }
    
    /**
     * Get title for sorting
     * 
     * @return NULL|string
     */
    public function getSortableTitle()
    {
        $title = $this->getFirstFieldSubfields(array(
            array(MarcRecord::GET_NORMAL, '245', array('a', 'b', 'k')),
        ));
        if ($title == '') {
            return null;
        }
        $title = strtolower($title);
        $ind2 = (int) $this->getIndicator($this->getField('245'), 2);
        $sortableTitle = trim(substr($title, $ind2), ' ["!(-.');
        return $sortableTitle;
    }
    
    public function getStatuses()
    {
        $present = false;
        $absent = false;
        $freeStack = false;
        foreach ($this->getFields('996') as $field) {
            $status = $this->getSubfield($field, 's');
            if (strtolower($status) == 'p') { // present
                $present = true;
            } else if (strtolower($status) == 'a') { // absent
                $absent = true;
            }
            $readyInHours = $this->getSubfield($field, 'a');
            if ($readyInHours == '0') {
                $freeStack = true;
            }
        }
        $statuses = array();
        if ($absent) {
            $statuses[] = 'absent';
        } else if ($present) {
            $statuses[] = 'present';
        }
        if ($freeStack && !empty($statuses)) {
            $statuses[] = 'free_stack';
        }
        return $statuses;
    }
    
    public function getPublishDate()
    {
        $years = $this->getPublishDateFromItems('996', 'y');
        if (!empty($years)) {
            return $years;
        }
        $years = array();
        $field008 = $this->getField('008');
        if ($field008 == null || strlen($field008) < 16) {
            return null;
        }
        $type = substr($field008, 6, 1);
        $from = substr($field008, 7, 4);
        $from = intval(str_replace(array('u', '?', ' '), '0', $from));
        $to = substr($field008, 11, 4);
        if (trim($to) == '' || $type == 'e') {
            $to = $from;
        } else {
            $to = intval(str_replace(array('u', '?', ' '), '9', $to));
        }
        if ($from == 0 && $to == 0) {
            return $years;
        }
        if ($from == 0) {
            $from = $to;
        }
        if ($to == 0) {
            $to = $from;
        }
        if ($to > 2013) {
            $to = 2013;
        }
        for ($year = $from; $year <= $to; $year+=1) {
            $years[] = sprintf("%04d", $year);
        }
        return $years;
    }
    
    public function getPublishDateForSorting()
    {
        $years = $this->getPublishDate();
        if (!empty($years)) {
            return min($years);
        } else {
            return null;
        }
    }

    public function getPublishDateFromItems($field, $subfield)
    {
        $result = array();
        $ranges = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, $field, array($subfield))
            )
        );
        foreach ($ranges as $range) {
            $range = trim($range);
            if (empty($range)) {
                continue;
            }
            $matches = array();
            if (preg_match('/^[0-9]{4}$/', $range)) {
                $result[] = $range;
            } else if (preg_match('/^([0-9]{4})[\-|\/]([0-9]{4})$/', $range, $matches)) { // 2001-2005, 2001/2002
                $result = range($matches[1], $matches[2]);
            } else if (preg_match('/^([0-9]{4})[\-|\/]([0-9]{2})$/', $range, $matches)) { // 1950-54
                $start = $matches[1];
                $end = substr($start, 0, 2) . $matches[2];
                $result = range($start, $end);
            } else if (preg_match('/^([0-9]{4})(,[0-9]{4})+$/', $range, $matches)) { // 1989,1990,1991
                $result = explode(',', $range);
            } else {
                //print "$range not matched\n";
            }
        }
        $result = array_unique($result);
        //var_export($result);
        return $result;
    }
    
    /**
     * Get fulltext
     *
     * @return string
     */
    public function getFulltext()
    {
        global $configArray;
        if (!isset($configArray['Fulltext']['toc_dir'])) {
            return null;
        }
        $nbn = $this->getNBN();
        if (empty($nbn)) {
            return null;
        }
        $dir = $configArray['Fulltext']['toc_dir'];
        $file = $dir . '/' . 'cnb_'. $nbn . '.txt';
        if (!file_exists($file)) {
            return null;
        }
        return file_get_contents($file);
    }
    
    /**
     * Return NBN (national bibliographic number) used for integration with
     * obalkyknih.cz.
     *
     * @return string
     */
    public function getNBN()
    {
        return $this->getFirstFieldSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '015', array('a'))
            )
        );
    }
    
    /**
     * Return created date
     *
     * @return string
     */
    public function getCreated()
    {
        $created = null;
        $field008 = $this->getField('008');
        if ($field008) {
            $created = substr($this->getField('008'), 0, 6);
            if ($created === false) {
                return null;
            }
            $date = date_create_from_format('ymd', $created);
            if ($date === false) {
                return null;
            }
            $created = date_format($date, 'Ymd');
        }
        return $created;
    }
    
    public function getKeywords()
    {
        return $this->getAllSubfieldsWithIndicator(
            array(
                array('600',  null,  '7',  array('a', 'b', 'd', 'q', 'k', 'l', 'm', 'p', 'r', 's')),
                array('610',  null,  '7',  array('a', 'b', 'c', 'k', 'l', 'm', 'p', 'r', 's')),
                array('611',  null,  '7',  array('a', 'c', 'e' ,'q', 'k', 'l', 'm', 'p', 'r', 's')),
                array('630',  null,  '7',  array('a', 'd', 'k', 'l', 'm', 'p', 'r', 's')),
                array('650',  null,  '7',  array('a', 'v', 'x', 'y', 'z')),
                array('651',  null,  '7',  array('a', 'v', 'x', 'y', 'z')),
                array('653',  null, null,  array('a')),
                array('655',  null,  '7',  array('a', 'v', 'x', 'y', 'z')),
                array('964',  null, null,  array('a')),
                array('967',  null, null,  array('a', 'b', 'c')),
            )
        );
    }

    public function getAllSubfieldsWithIndicator($specs)
    {
        $result = array();
        foreach ($specs as $spec) {
            $field = $spec[0];
            $ind1 = $spec[1];
            $ind2 = $spec[2];
            $codes = $spec[3];
            if (isset($this->fields[$field])) {
                foreach ($this->fields[$field] as $f) {
                    $i1 = $f['i1'];
                    $i2 = $f['i2'];
                    if (($ind1 == null || $i1 == $ind1) && ($ind2 == null || $i2 == $ind2)) {
                        $data = array();
                        foreach ($f['s'] as $subfield) {
                            $code = key($subfield);
                            if ($code === 0) {
                                $code = '0';
                            }
                            if (in_array($code, $codes)) {
                                $data[] = current($subfield);
                            }
                        }
                        $result[] = implode(' ', $data);
                    }
                }
            }
        }
        return $result;
    }

    public function getTitleShort()
    {
        $title = $this->getFirstFieldSubfields(array(
            array(MarcRecord::GET_NORMAL, '245', array('a'))
        ));
        // FIXME
        /*
        $title = preg_replace('/[/\\: \\]]*$/i', '', $title);
        $title = preg_replace('/^[\\[]/i', '', $title);
        */
        return $title;
    }

    public function getSecondCallNumber()
    {
        $callNo2 = $this->getFieldsSubfields(array(
            array(MarcRecord::GET_NORMAL, '996', array('h'))
        ));
        $callNo2 = str_replace(' ', '|', $callNo2);
        return $callNo2;
    }

    public function getConspectusCategory()
    {
        return $this->getConspectusField('9');
    }

    public function getConspectusSubcategory()
    {
        return $this->getConspectusField('x');
    }

    public function getConspectusField($subfield)
    {
        foreach ($this->getFields('072') as $field) {
            $type = $this->getSubfield($field, '2');
            if ($type == 'Konspekt') {
                return $this->getSubfield($field, $subfield);
            }
        }
        return null;
    }

    public function getScale()
    {
        $scale = $this->getFieldSubfields('034', array('b'));
        if ($scale == null) {
            return null;
        }
        $scale = str_replace(' ', '', $scale);
        if (is_numeric($scale)) {
            return $scale;
        }
        return null;
    }

    public function getBoundingBox()
    {
        $field = $this->getField('034');
        if ($field) {
            $westOrig = $this->getSubfield($field, 'd');
            $eastOrig = $this->getSubfield($field, 'e');
            $northOrig = $this->getSubfield($field, 'f');
            $southOrig = $this->getSubfield($field, 'g');
            $west = MetadataUtils::coordinateToDecimal($westOrig);
            $east = MetadataUtils::coordinateToDecimal($eastOrig);
            $north = MetadataUtils::coordinateToDecimal($northOrig);
            $south = MetadataUtils::coordinateToDecimal($southOrig);

            if (!is_nan($west) && !is_nan($north)) {
                if (!is_nan($east)) {
                    $longitude = ($west + $east) / 2;
                } else {
                    $longitude = $west;
                }

                if (!is_nan($south)) {
                    $latitude = ($north + $south) / 2;
                } else {
                    $latitude = $north;
                }
                if (($longitude < -180 || $longitude > 180) || ($latitude < -90 || $latitude > 90)) {
                    global $logger;
                    $logger->log('MarcRecord', "Discarding invalid coordinates $longitude,$latitude decoded from w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, record {$this->source}." . $this->getID(), Logger::WARNING);
                } else {
                    global $logger;
                    if (!$north || !$south || !$east || !$west || is_nan($north) || is_nan($south) || is_nan($east) || is_nan($north)) {
                        $logger->log('MarcRecord', "INVALID RECORD ".$this->source . $this->getID()." missig coordinate w=$west e=$east n=$north s=$south", Logger::WARNING);
                    } else {
                        return $west . ' ' . $south . ' ' . $east . ' ' . $north;
                    }
                }
            }
        }
        return null;
    }

    public function getEAN()
    {
        return $this->getAllSubfieldsWithIndicator(
            array(
                array('024', '3',  null,  array('a')),
            )
        );
    }

}
