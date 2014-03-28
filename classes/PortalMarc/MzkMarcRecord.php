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

    public function toSolrArray() {
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
    
        $leaderFormat = substr($this->getField('000'), 5, 8);
        if ($leaderFormat == "nai" || $leaderFormat == "cai") {
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
            case 'A':
                // Component part in monograph
                return 'BookSection';
            case 'B':
                // Component part in serial
                return 'Article';
            case 'C':
                // Collection
                return 'Collection';
            case 'D':
                // Component part in collection (sub unit)
                return 'SubUnit';
            case 'I':
                // Integrating resource
                return 'ContinuouslyUpdatedResource';
        }
        return 'Other';
    }

}