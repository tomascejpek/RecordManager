<?php

require_once 'MappablePortalMarcRecord.php';

/**
 * MarcRecord Class - local customization for cistbrno
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class MzkMarcRecord extends MappablePortalMarcRecord
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

    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        return $data;
    }

    public function getBarcodes()
    {
        return $this->getFieldSubfields('996', array('b'));
    }
    
    public function getAvailabilityId()
    {
        $holdings = $this->getFields('996');
        foreach ($holdings as $holding) {
            $holdId = $this->getSubfield($holding, 'w');
            if ($holdId) {
                return $holdId;
            }
        }
        return null;
    }
    
    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return $this->getField('998');
    }
    
    public function getStatuses()
    {
        $statuses = parent::getStatuses();
        $eod = $this->getFieldSubfields('993', array('a'));
        if ($eod == 'Y') {
            $statuses[] = 'available_for_eod';
        }
        $links = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '996', array('u')), // really in 996?
                array(MarcRecord::GET_NORMAL, '856', array('u')),
            )
        );
        $online = false;
        foreach ($links as $link) {
            if (self::startsWith($link, "http://kramerius.mzk.cz/") ||
                 self::startsWith($link, "http://imageserver.mzk.cz/")) {
                $online = true;
            }
        }
        if ($online) {
            $statuses[] = 'available_online';
        }
        return $statuses;
    }
    
    /**
     * Dedup: Return format from predefined values
     *
     * Local costumizations:
     *
     * - field 007 is not used
     * - eBook, eNewspaper, eJournal, eSerial, eBookSection and eArticle not used
     * - treat integrating resource as LawsOrOthers
     *
     * @return string
     */
    public function getFormat()
    {
        // photography
        $fields072 = $this->getFields('072');
        foreach ($fields072 as $field) {
            $sa = $this->getSubfield($field, 'a');
            $sx = $this->getSubfield($field, 'x');
            if (!empty($sa) && !empty($sx) && strtolower($sa) == '77'
                && strtolower($sx) == 'fotografie') {
                return 'Photography';
            }
        }
        $fields655 = $this->getFields('655');
        foreach ($fields655 as $field) {
            $sa = $this->getSubfield($field, 'a');
            $s7 = $this->getSubfield($field, 'x');
            if (!empty($sa) && !empty($s7) && strtolower($sa) == 'fotografie'
                && strtolower($s7) == 'fd132277') {
                return 'Photography';
            }
        }

        // electronic resource
        $electronic = $this->getFieldSubfields('245', array('h'));
        if ($electronic != null && strpos(strtolower($electronic),
            '[electronic resource]') !== FALSE) {
            return 'Electronic';
        }

        // 990 in OAI = FMT field
        $format = $this->getField('990');
        if ($format == 'AZ') {
            return 'Article';
        }

        // 991 in OAI = MZK field
        $norm = $this->getFieldSubfields('991', array('n'));
        if ($norm != null) {
            return 'Norm';
        }

        $leader = $this->getField('000');
        $leaderFormat = substr($leader, 5, 3);
        if ($leaderFormat == 'nai' || $leaderFormat == 'cai') {
            return 'LawsOrOthers';
        }

        // check the Leader at position 6
        $leader = $this->getField('000');
        $leaderBit = substr($leader, 6, 1);
        switch (strtoupper($leaderBit)) {
            case 'C':
            case 'D':
                return 'MusicalScore';
            case 'E':
            case 'F':
                return 'Map';
            case 'G':
                return 'Slide';
            case 'I':
                return 'SoundRecording';
            case 'J':
                return 'MusicRecording';
            case 'K':
                return 'Photo';
                break;
            case 'M':
                return 'Electronic';
            case 'O':
            case 'P':
                return 'Kit';
            case 'R':
                return 'PhysicalObject';
            case 'T':
                return 'Manuscript';
        }

        // check the Leader at position 7
        $leaderBit = substr($leader, 7, 1);
        switch (strtoupper($leaderBit)) {
            // Monograph
            case 'M':
                return 'Book';
            case 'S':
                return 'NewspaperOrJournal';
        }
        return 'Unknown';
    }

    public function getSecondCallNumbers()
    {
        $locations = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '996', array('h')),
            )
        );
        foreach($locations as &$location) {
            $location = str_replace(' ', '|', $location);
        }
        return $locations;
    }

    public function getVisible()
    {
        // MZK field is remapped to 991 in OAI
        $mzkHidden = $this->getFieldSubfields('991', array('s'));
        if ($mzkHidden == 'SKRYTO') {
            return 'hidden';
        }
        // FMT field is remapped to 990 in OAI and is control field
        $format = $this->getField('990');
        if ($format == 'AZ') {
            return 'hidden';
        }
        // STA field is remapped to 992 in OAI
        $sta = $this->getFieldSubfields('992', array('a'));
        if (self::startsWith($sta, 'SUPPRESSED') || self::startsWith($sta, 'SKRYTO')) {
            return 'hidden';
        }
        // BAS field is remapped to 995 in OAI (acquisition order)
        $bas = $this->getFieldSubfields('995', array('a'));
        if (self::startsWith($bas, 'AK')) {
            return 'hidden';
        }
        return 'visible';
    }
    
    public function getAcquisitionDate()
    {
        $mzkAcqDate = $this->getFieldSubfields('991', array('b'));
        if ($mzkAcqDate == null) {
            return null;
        }
        $mzkAcqDate = trim($mzkAcqDate);
        if (strlen($mzkAcqDate) > 6) {
            $mzkAcqDate = substr($mzkAcqDate, 0, 6);
        }
        if (ctype_digit($mzkAcqDate)) {
            return $mzkAcqDate;
        }
        return null;
    }
    
    public function getBases()
    {
        static $allowedBases = array(
            'MZK01' => array("33", "44", "99"),
            'MZK03' => array("mzk", "rajhrad", "znojmo", "trebova", "dacice"),
        );
        global $dataSourceSettings;
        $base = $dataSourceSettings[$this->source]['base'];
        $firstBase = 'facet_base_' . $base;
        $bases = array($firstBase);
        $secBase = $this->getFieldSubfields('991', ($base == 'MZK01') ? array('x') : array('k'));
        if ($secBase != null && in_array($secBase, $allowedBases[$base])) {
            $bases[] = $firstBase . '_' . $secBase;
        }
        return $bases;
    }
    
    public function getSysno()
    {
        return $this->getField('998');
    }
    
    public function getAuthorAndTitle()
    {
        $author = $this->getFieldSubfields('100', array('a', 'd'));
        $title = $this->getFieldSubfields('245', array('a'));
        if ($author != null && $title != null) {
            return $author . ": " . $title;
        }
        return null;
        
    }
    
    public function getRelevancy()
    {
        $relevancy = "default";
        // platnost normy
        $summary = $this->getFieldSubfields('520', array('a'));
        if ($summary != null && $summary == "Norma je neplatná") {
            $relevancy = "invalid_norm";
        }
        // vychazejici casopisy ci noviny
        /*
        $pse = $this->getFirstFieldVal('PSE', array('q'));
        if (pse != null && $pse == currentYearTwoDigits) {
            $relevancy = "live_periodical";
        }
        */
        // hovadiny
        $sig = $this->getFieldSubfields('910', array('b'));
        if ($sig != null && self::startsWith($sig, 'UP')) {
            $relevancy = "rubbish";
        }
        return $relevancy;
    }

    public static function startsWith($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }

}