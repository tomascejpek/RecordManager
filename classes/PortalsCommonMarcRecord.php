<?php

require_once 'MarcRecord.php';
require_once 'MetadataUtils.php';
require_once 'Logger.php';

/**
 * MarcRecord Class - common class for cistbrno
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class PortalsCommonMarcRecord extends MarcRecord
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
    
    public function getFormat()
    {
    	// check the 007 - this is a repeating field
    	$fields = $this->getFields('007');
    	$formatCode = '';
    	$online = false;
    	
    	$formats = array();
    	foreach ($fields as $field) {
    		$contents = $field;
    		$formatCode = strtoupper(substr($contents, 0, 1));
    		$formatCode2 = strtoupper(substr($contents, 1, 1));
    		switch ($formatCode) {
    			case 'A':
    				switch($formatCode2) {
    					case 'D':
    						$formats[] = 'Atlas_007';
    						break;
    					default:
    						$formats[] = 'Map_007';
    				}
    				break;
    			case 'C':
    				switch($formatCode2) {
    					case 'A':
    						$formats[] = 'TapeCartridge_007';
    						break;
    					case 'B':
    						$formats[] = 'ChipCartridge_007';
    						break;
    					case 'C':
    						$formats[] = 'DiscCartridge_007';
    						break;
    					case 'F':
    						$formats[] = 'TapeCassette_007';
    						break;
    					case 'H':
    						$formats[] = 'TapeReel_007';
    						break;
    					case 'J':
    						$formats[] = 'FloppyDisk_007';
    						break;
    					case 'M':
    					case 'O':
    						$formats[] = 'CDROM_007';
    						break;
    					case 'R':
    						// Do not return - this will cause anything with an
    						// 856 field to be labeled as "Electronic"
    						$online = true;
    						break;
    					default:
    						$formats[] = 'Electronic_007';
    				}
    				break;
    			case 'D':
    				$formats[] = 'Globe_007';
    				break;
    			case 'F':
    				$formats[] = 'Braille_007';
    				break;
    			case 'G':
    				switch($formatCode2) {
    					case 'C':
    					case 'D':
    						$formats[] = 'Filmstrip_007';
    						break;
    					case 'T':
    						$formats[] = 'Transparency_007';
    						break;
    					default:
    						$formats[] = 'Slide_007';
    				}
    				break;
    			case 'H':
    				$formats[] = 'Microfilm_007';
    				break;
    			case 'K':
    				switch($formatCode2) {
    					case 'C':
    						$formats[] = 'Collage_007';
    						break;
    					case 'D':
    						$formats[] = 'Drawing_007';
    						break;
    					case 'E':
    						$formats[] = 'Painting_007';
    						break;
    					case 'F':
    						$formats[] = 'Print_007';
    						break;
    					case 'G':
    						$formats[] = 'Photonegative_007';
    						break;
    					case 'J':
    						$formats[] = 'Print_007';
    						break;
    					case 'L':
    						$formats[] = 'TechnicalDrawing_007';
    						break;
    					case 'O':
    						$formats[] = 'FlashCard_007';
    						break;
    					case 'N':
    						$formats[] = 'Chart_007';
    						break;
    					default:
    						$formats[] = 'Photo_007';
    				}
    				break;
    			case 'M':
    				switch($formatCode2) {
    					case 'F':
    						$formats[] = 'VideoCassette_007';
    						break;
    					case 'R':
    						$formats[] = 'Filmstrip_007';
    						break;
    					default:
    						$formats[] = 'MotionPicture_007';
    				}
    				break;
    			case 'O':
    				$formats[] = 'Kit_007';
    				break;
    			case 'Q':
    				$formats[] = 'MusicalScore_007';
    				break;
    			case 'R':
    				$formats[] = 'SensorImage_007';
    				break;
    			case 'S':
    				$soundTech = strtoupper(substr($contents, 13, 1));
    				switch($formatCode2) {
    					case 'D':
    						$formats[] = $soundTech == 'D' ? 'CD_007' : 'SoundDisc_007';
    						break;
    					case 'S':
    						$formats[] = 'SoundCassette_007';
    						break;    						
    					default:
    						$formats[] = 'SoundRecording_007';
    						break;
    				}
    				break;
    			case 'V':
    				$videoFormat = strtoupper(substr($contents, 4, 1));
    				switch($videoFormat) {
    					case 'S':
    						$formats[] = 'BluRay_007';
    						break;
    					case 'V':
    						$formats[] = 'DVD_007';
    						break;
    				}
    
    				switch($formatCode2) {
    					case 'C':
    						$formats[] = 'VideoCartridge_007';
    						break;
    					case 'D':
    						$formats[] = 'VideoDisc_007';
    						break;
    					case 'F':
    						$formats[] = 'VideoCassette_007';
    						break;
    					case 'R':
    						$formats[] = 'VideoReel_007';
    						break;
    					default:
    						$formats[] = 'Video_007';
    				}
    				break;
    		}
    	}
    
    
    	// check the Leader at position 6
    	$leader = $this->getField('000');
    	$leaderBit = substr($leader, 6, 1);
    	switch (strtoupper($leaderBit)) {
    		case 'C':
    			$formats[] = 'MusicalScoreC_000_6';
    			break; 
    		case 'D':
    			$formats[] = 'MusicalScoreD_000_6';
    			break;
    		case 'E':
    			$formats[] = 'MapE_000_6';
    			break;
    		case 'F':
    			$formats[] = 'MapF_000_6';
    			break;
    		case 'G':
    			$formats[] = 'Slide_000_6';
    			break;
    		case 'I':
    			$formats[] = 'SoundRecording_000_6';
    			break;
    		case 'J':
    			$formats[] = 'MusicRecording_000_6';
    			break;
    		case 'K':
    			$formats[] = 'Photo_000_6';
    			break;
    		case 'M':
    			$formats[] = 'Electronic_000_6';
    			break;
    		case 'O':
    		case 'P':
    			$formats[] = 'Kit_000_6';
    			break;
    		case 'R':
    			$formats[] = 'PhysicalObject_000_6';
    			break;
    		case 'T':
    			$formats[] = 'Manuscript_000_6';
    			break;
    	}
    
    	// check the Leader at position 7
    	$leaderBit = substr($leader, 7, 1);
    	switch (strtoupper($leaderBit)) {
    		// Monograph
    		case 'M':
    			if ($online) {
    				$formats[] = 'eBook_000_7';
    			} else {
    				$formats[] = 'Book_000_7';
    			}
    			break;
    			// Serial
    		case 'S':
    			// Look in 008 to determine what type of Continuing Resource
    			$field008 = $this->getField('008');
    			$formatCode = strtoupper(substr($field008, 21, 1));
    			switch ($formatCode) {
    				case 'N':
    					$formats[] = $online ? 'eNewspaper_000_7_008' : 'Newspaper_000_7_008';
    					break;
    				case 'P':
    					$formats[] = $online ? 'eJournal_000_7_008' : 'Journal_000_7_008';
    					break;
    				default:
    					$formats[] = $online ? 'eSerial_000_7_008' : 'Serial_000_7_008';
    			}
    			break;
    
    		case 'A':
    			// Component part in monograph
    			$formats[] = $online ? 'eBookSection_000_7' : 'BookSection_000_7';
    			break;
    		case 'B':
    			// Component part in serial
    			$formats[] = $online ? 'eArticle_000_7' : 'Article_000_7';
    			break;
    		case 'C':
    			// Collection
    			$formats[] = 'Collection_000_7';
    			break;
    		case 'D':
    			// Component part in collection (sub unit)
    			$formats[] = 'SubUnit_000_7';
    			break;
    		case 'I':
    			// Integrating resource
    			$formats[] = 'ContinuouslyUpdatedResource_000_7';
    			break;
    	}
    	
    	//field 008
    	$field = $this->getField('008');
    	if ($field && strlen($field) >= 24) {
    		switch ($field[23]) {
    			case 'a' :
    		        $formats[] = 'microformsA_008';
    		        break;
    			case 'b' :
    			    $formats[] = 'microformsB_008';
    			    break;
    			case 'c' :
    			    $formats[] = 'microformsC_008';
    			    break;
    			case 'f' :
    			    $formats[] = 'books_in_braill_008';
    			    break;
    			case 's' :    
    			    $formats[] = 'electronic_resources_008';
    			    break;
    		}
    	}
    	
    	if (empty($formats)) {
    		$formats[] = 'Other';
    	}
    	
    	return $this->unifyFormats($formats);
    }
    
    /**
     *
     *
     * @return string
     */
    protected function getAllSubfieldsWithIndicator($specs)
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
