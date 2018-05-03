<?php
/**
 * Record Manager
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012.
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

declare(ticks = 1);

require_once 'PEAR.php';
require_once 'HTTP/Request2.php';
require_once 'Logger.php';
require_once 'RecordFactory.php';
require_once 'FileSplitter.php';
require_once 'HarvestOaiPmh.php';
require_once 'HarvestMetaLib.php';
require_once 'HarvestSfx.php';
require_once 'XslTransformation.php';
require_once 'MetadataUtils.php';
require_once 'SolrUpdater.php';
require_once 'PerformanceCounter.php';

/**
 * RecordManager Class
 *
 * This is the main class for RecordManager.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class RecordManager
{
    public $verbose = false;
    public $quiet = false;
    public $harvestFromDate = null;
    public $harvestUntilDate = null;

    protected $basePath = '';
    protected $log = null;
    protected $db = null;
    protected $dataSourceSettings = null;

    protected $harvestType = '';
    protected $format = '';
    protected $idPrefix = '';
    protected $sourceId = '';
    protected $institution = '';
    protected $recordXPath = '';
    protected $componentParts = '';
    protected $dedup = false;
    protected $normalizationXSLT = null;
    protected $solrTransformationXSLT = null;
    protected $recordSplitter = null; 
    protected $pretransformation = '';
    protected $indexMergedParts = true;
    protected $counts = false;
    protected $compressedRecords = true;
    protected $enableRecordCheck = false;
    protected $inputEncoding;
    protected $lineRecordLeader = false;
    
    //storing buffer size
   	private $bufferSize = 100;
   	private $bufferedRecords = array();
   	
   	private $linkArray = array();
    
    /**
     * Constructor
     * 
     * @param boolean $console Specify whether RecordManager is executed on the console, 
     *                           so log output is also output to the console.
     */
    public function __construct($console = false)
    {
        global $configArray;
        global $logger;
        global $dataSourceSettings;
        
        date_default_timezone_set($configArray['Site']['timezone']);

        $this->log = new Logger();
        if ($console) {
            $this->log->logToConsole = true;
        }
        // Store logger in a global so that others can access it easily
        $logger = $this->log;
        
        if (isset($configArray['Mongo']['counts']) && $configArray['Mongo']['counts']) {
            $this->counts = true;
        }
        if (isset($configArray['Mongo']['compressed_records']) && !$configArray['Mongo']['compressed_records']) {
            $this->compressedRecords = false;
        }
        
        $basePath = substr(__FILE__, 0, strrpos(__FILE__, DIRECTORY_SEPARATOR));
        $basePath = substr($basePath, 0, strrpos($basePath, DIRECTORY_SEPARATOR));
        $this->dataSourceSettings = parse_ini_file("$basePath/conf/datasources.ini", true);
        $dataSourceSettings = $this->dataSourceSettings;
        $this->basePath = $basePath;

        $timeout = isset($configArray['Mongo']['connect_timeout']) ? $configArray['Mongo']['connect_timeout'] : 300000;
        $mongo = new Mongo($configArray['Mongo']['url'], array('connectTimeoutMS' => $timeout));
        $this->db = $mongo->selectDB($configArray['Mongo']['database']);
        MongoCursor::$timeout = isset($configArray['Mongo']['cursor_timeout']) ? $configArray['Mongo']['cursor_timeout'] : 300000;
        
        if (isset($configArray['Site']['full_title_prefixes'])) {
            MetadataUtils::$fullTitlePrefixes = array_map(array('MetadataUtils', 'normalize'), file("$basePath/conf/{$configArray['Site']['full_title_prefixes']}",  FILE_IGNORE_NEW_LINES));
        }

        // Read the abbreviations file
        MetadataUtils::$abbreviations = isset($configArray['Site']['abbreviations']) 
            ? $this->readListFile($configArray['Site']['abbreviations']) : array();
        
        // Read the artices file
        MetadataUtils::$articles = isset($configArray['Site']['articles']) 
            ? $this->readListFile($configArray['Site']['articles']) : array();

        // Read settings for VNF
        if ($configArray['VNF'] && $configArray['VNF']['format_unification']) {
            $configArray['VNF']['format_unification_array'] = parse_ini_file($configArray['VNF']['format_unification']);
        }
    }

    /**
     * Catch the SIGINT signal and signal the main thread to terminate
     *
     * @param int $signal Signal ID
     *
     * @return void
     */
    public function sigIntHandler($signal)
    {
        $this->terminate = true;
        echo "Termination requested\n";
    }
    
    /**
     * Load records into the database from a file
     * 
     * @param string $source Source id
     * @param string $files  Wildcard pattern of files containing the records
     * 
     * @throws Exception
     * @return int Number of records loaded
     */
    public function loadFromFile($source, $files)
    {
        $this->loadSourceSettings($source);
        
        global $dataSourceSettings;
        $dataSourceSettings = $this->dataSourceSettings;
        
        if (!$this->recordXPath) {
            $this->log->log('loadFromFile', 'recordXPath not defined', Logger::FATAL);
            throw new Exception('recordXPath not defined');
        }
        $count = 0;
        foreach (glob($files) as $file) {
            $this->log->log('loadFromFile', "Loading records from '$file' into '$source'");
            
            $data = null;
            if ($this->pretransformation) {
                $data = file_get_contents($file);
                if ($data === false) {
                    throw new Exception("Could not read file '$file'");
                }
                if ($this->verbose) {
                    echo "Executing pretransformation\n";
                }
                $data = $this->pretransform($data);
            }
            
            if ($this->verbose) {
                echo "Creating FileSplitter\n";
            }
            $splitter = null;
            if ($this->fileSplitter) {
                require_once $this->fileSplitter;
                $className = str_replace('.php', '', $this->fileSplitter);
                if ($className == 'AutoDetectFileSplitter') {
                    $splitter = new AutoDetectFileSplitter($file, $source);
                } elseif ($className == 'EncodingDetectFileSplitter') {
                    $splitter = new EncodingDetectFileSplitter($file, $source);
                } elseif ($className == 'LineMarcFileSplitter') {
                    $splitter = new LineMarcFileSplitter($file, $source);
                } elseif ($className == 'BinaryMarcFileSplitter') {
                    $splitter = new $className($file);
                } elseif ($className == 'SAXFileSplitter') {
                    $splitter = new $className($file, 'record');
                } else {
                    $data = $data == null ? file_get_contents($file) : $data;
                    $splitter = new $className($data,$this->recordXPath,$this->oaiIDXPath);
                }
            } else {
                $data = $data == null ? file_get_contents($file) : $data;
                if ($data === false) {
                    throw new Exception("Could not read file '$file'");
                }
                $splitter = new FileSplitter($data, $this->recordXPath, $this->oaiIDXPath);
            }
            
            
            if ($this->verbose) {
                echo "Storing records\n";
            }
            while (!$splitter->getEOF()) {
                $oaiID = '';
                $data = $splitter->getNextRecord($oaiID);
                if ($this->verbose) {
                    echo "Storing a record\n";
                }
                try {
                    $count += $this->storeRecord($oaiID, false, $data);
                } catch (Exception $e) {
                    $this->log->log('loadFromFile', 'Exception: ' . $e->getMessage(), Logger::FATAL);
                }
                if ($this->verbose) {
                    echo "Stored records: $count\n";
                }
            }
            $this->log->log('loadFromFile', "$count records loaded");
        }
        
        $this->log->log('loadFromFile', "Total $count records loaded");
        $this->flushBuffer();
        $this->completeLinks($oaiID);
        return $count;
    }

    /**
     * Export records from the database to a file
     * 
     * @param string $file        File name where to write exported records
     * @param string $deletedFile File name where to write ID's of deleted records
     * @param string $fromDate    Starting date (e.g. 2011-12-24)
     * @param int    $skipRecords Export only one per each $skipRecords records for a sample set
     * @param string $sourceId    Source ID to export, or empty or * for all
     * @param string $singleId    Export only a record with the given ID
     * @param string $xpath       Optional XPath expression to limit the export with
     * 
     * @return void
     */
    public function exportRecords($file, $deletedFile, $fromDate, $skipRecords = 0, $sourceId = '', $singleId = '', $xpath = '', $inputFile = '', $outputFormat = 'xml')
    {
        if ($file == '-') {
            $file = 'php://stdout';
        }

        if (file_exists($file)) {
            unlink($file);
        }
        if ($deletedFile && file_exists($deletedFile)) {
            unlink($deletedFile);
        }
        $listID = array();
        if ($inputFile != '') {
            $content = file_get_contents($inputFile);
            if ($content == false) {
                $this->log->log('exportRecords', "File ". $inputFile ." reading failed", Logger::WARNING);
            } else {
                $listID = explode("\n", $content);
                $this->log->log('exportRecords', count($listID). " IDs loaded from file ". $inputFile);
            }
        }
        
        $format = 'xml';
        if (strtolower($outputFormat) == 'iso') {
            $format = 'iso';
        } elseif (strtolower($outputFormat) == 'line') {
            $format = 'line';
        } else {
            file_put_contents($file, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection>\n", FILE_APPEND);
        }
        foreach ($this->dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                $this->loadSourceSettings($source);

                $this->log->log('exportRecords', "Creating record list (from " . ($fromDate ? $fromDate : 'the beginning') . ", source '$source')");

                $params = array();
                $params['source_id'] = $source;
                if ($singleId) {
                    $params['_id'] = $singleId;
                } elseif ($listID) {
                   // $params['_id'] = array('$in' => $listID);
                } else {
                    if ($fromDate) {
                        $params['updated'] = array('$gte' => new MongoDate(strtotime($fromDate)));
                    }
//                  $params['update_needed'] = false;
                }

                $records = $this->db->record->find($params);
                $total = $this->counts ? $records->count() : 'the';
                $count = 0;
                $deduped = 0;
                $deleted = 0;
                $this->log->log('exportRecords', "Exporting $total records from '$source'");
                if ($skipRecords) {
                    $this->log->log('exportRecords', "(1 per each $skipRecords records)");
                }
                foreach ($records as $record) {
                    $metadataRecord = RecordFactory::createRecord($record['format'], MetadataUtils::getRecordData($record, true), $record['oai_id'], $record['source_id']);
                    if ($xpath) {
                        $xml = $metadataRecord->toXML();
                        $xpathResult = simplexml_load_string($xml)->xpath($xpath);
                        if ($xpathResult === false) {
                            throw new Exception("Failed to evaluate XPath expression '$xpath'");
                        }
                        if (!$xpathResult) {
                            continue;
                        }
                    }
                    if ($record['deleted']) {
                        if ($deletedFile) {
                            file_put_contents($deletedFile, "{$record['_id']}\n", FILE_APPEND);
                        }
                        ++$deleted;
                    } else {
                        ++$count;
                        if ($skipRecords > 0 && $count % $skipRecords != 0) {
                            continue;
                        }
                        if (isset($record['dedup_id'])) {
                            ++$deduped;
                        }
                        $metadataRecord->addDedupKeyToMetadata((isset($record['dedup_id'])) ? $record['dedup_id'] : $record['_id']);
                        switch ($format) {
                            case 'iso' :                        	
                                $output = $metadataRecord->toISO2709();
                                break;
                            case 'line':
                                $output = $metadataRecord->toLineMarc();
                                break;
                            default:
                                $output = $metadataRecord->toXML();
                                $output = preg_replace('/^<\?xml.*?\?>[\n\r]*/', '', $output);
                        }
                        file_put_contents($file, $output . "\n", FILE_APPEND);
                    }
                    if ($count % 1000 == 0) {
                        $this->log->log('exportRecords', "$deleted deleted, $count normal (of which $deduped deduped) records exported from '$source'");
                    }
                }
                $this->log->log('exportRecords', "Completed with $deleted deleted, $count normal (of which $deduped deduped) records exported from '$source'");
            } catch (Exception $e) {
                $this->log->log('exportRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
        if ($format == 'xml') {
            file_put_contents($file, "</collection>\n", FILE_APPEND);
        }
    }

    /**
     * Send updates to a Solr index (e.g. VuFind)
     * 
     * @param string|null $fromDate Starting date for updates (if empty 
     *                              string, last update date stored in the database
     *                              is used and if null, all records are processed)
     * @param string      $sourceId Source ID to update, or empty or * for all 
     *                              sources (ignored if record merging is enabled)
     * @param string      $singleId Export only a record with the given ID
     * @param bool        $noCommit If true, changes are not explicitly committed
     * 
     * @return void
     */
    public function updateSolrIndex($fromDate = null, $sourceId = '', $singleId = '', $noCommit = false)
    {
        global $configArray;
        foreach (explode(',', $sourceId) as $source) {
            $this->loadSourceSettings($source);
        }
        $updater = new SolrUpdater($this->db, $this->basePath, $this->log, $this->verbose);
        
        if (isset($configArray['Solr']['merge_records']) && $configArray['Solr']['merge_records']) {
            return $updater->updateMergedRecords($fromDate, $sourceId, $singleId, $noCommit);
        }
        return $updater->updateIndividualRecords($fromDate, $sourceId, $singleId, $noCommit);
    }

    /**
     * Renormalize records in a data source
     *
     * @param string $sourceId Source ID to renormalize
     * @param string $singleId Renormalize only a single record with the given ID
     * 
     * @return void
     */
    public function renormalize($sourceId, $singleId)
    {
        foreach ($this->dataSourceSettings as $source => $settings) {
            if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                continue;
            }
            if (empty($source) || empty($settings)) {
                continue;
            }
            $this->loadSourceSettings($source);
            $this->log->log('renormalize', "Creating record list for '$source'");
    
            $params = array('deleted' => false);
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['source_id'] = $source;
            } else {
                $params['source_id'] = $source;
            }
            $records = $this->db->record->find($params)->batchSize(5000);
            $records->immortal(true);
            $total = $this->counts ? $records->count() : 'the';
            $count = 0;
    
            $this->log->log('renormalize', "Processing $total records from '$source'");
            $pc = new PerformanceCounter();
            foreach ($records as $record) {
                $originalData = MetadataUtils::getRecordData($record, false);
                $normalizedData = $originalData;
                if (isset($this->normalizationXSLT)) {
                    $origMetadataRecord = RecordFactory::createRecord($record['format'], $originalData, $record['oai_id'], $record['source_id']);
                    $normalizedData = $this->normalizationXSLT->transform($origMetadataRecord->toXML(), array('oai_id' => $record['oai_id']));
                }
    
                $metadataRecord = RecordFactory::createRecord($record['format'], $normalizedData, $record['oai_id'], $record['source_id']);
                $metadataRecord->normalize();
                $hostID = $metadataRecord->getHostRecordID();
                $normalizedData = $metadataRecord->serialize();
                if ($this->dedup && !$hostID) {
                    $this->updateDedupCandidateKeys($record, $metadataRecord);
                    $record['update_needed'] = true;
                } else {
                    unset($record['title_keys']);                
                    unset($record['isbn_keys']);                
                    unset($record['id_keys']);                
                    unset($record['dedup_id']);
                    $record['update_needed'] = false;
                }

                $record['original_data'] = $this->compressedRecords ? new MongoBinData(gzdeflate($originalData), 2) : $originalData;
                if ($normalizedData == $originalData) {
                    $record['normalized_data'] = '';
                } else {
                    $record['normalized_data'] = $this->compressedRecords ? new MongoBinData(gzdeflate($normalizedData), 2) : $normalizedData;
                }
                $record['linking_id'] = $metadataRecord->getLinkingID();
                if ($hostID) {
                    $record['host_record_id'] = $hostID;
                } else {
                    unset($record['host_record_id']);
                }
                $record['updated'] = new MongoDate();
                $this->db->record->save($record);
                
                if ($this->verbose) {
                    echo "Metadata for record {$record['_id']}: \n";
                    $record['normalized_data'] = MetadataUtils::getRecordData($record, true);
                    $record['original_data'] = MetadataUtils::getRecordData($record, false);
                    if ($record['normalized_data'] === $record['original_data']) {
                        $record['normalized_data'] = '';
                    }
                    print_r($record);
                }
                                
                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->log->log('renormalize', "$count records processed from '$source', $avg records/sec");
                }
            }
            $this->log->log('renormalize', "Completed with $count records processed from '$source'");
        }
    }

    /**
     * Find duplicate records and give them dedup keys
     * 
     * @param string $sourceId   Source ID to process, or empty or * for all sources where dedup is enabled
     * @param string $allRecords If true, process all records regardless of their status (otherwise only freshly imported or updated records are processed)
     * @param string $singleId   Process only a record with the given ID
     * 
     * @return void
     */
    public function deduplicate($sourceId, $allRecords = false, $singleId = '')
    {
        // Used for format mapping
        $this->solrUpdater = new SolrUpdater($this->db, $this->basePath, $this->log, $this->verbose);
        
        // Install a signal handler so that we can exit cleanly if interrupted
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, array($this, 'sigIntHandler'));
            $this->log->log('deduplicate', 'Interrupt handler set');
        } else {
            $this->log->log('deduplicate', 'Could not set an interrupt handler -- pcntl not available');
        }
        
        if ($allRecords) {
            foreach ($this->dataSourceSettings as $source => $settings) {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['dedup']) || !$settings['dedup']) {
                    continue;
                }
                $this->log->log('deduplicate', "Marking all records for processing in '$source'");
                $this->db->record->update(
                    array('source_id' => $source, 'host_record_id' => array('$exists' => false), 'deleted' => false),
                    array('$set' => array('update_needed' => true)),
                    array('multiple' => true, 'safe' => true, 'timeout' => 3000000)
                );
            }
        }
     
        
        foreach ($this->dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['dedup']) || !$settings['dedup']) {
                    continue;
                }

                $this->loadSourceSettings($source);
                $this->log->log('deduplicate', "Creating record list for '$source'" . ($allRecords ? ' (all records)' : '') . '');

                $params = array('deleted' => false, 'source_id' => $source);
                if ($singleId) {
                    $params['_id'] = $singleId;
                } else {
                    $params['update_needed'] = true;
                }
                $records = $this->db->record->find($params)->batchSize(5000);
                $records->immortal(true);
                $total = $this->counts ? $records->count() : 'the';
                $count = 0;
                $deduped = 0;
                $pc = new PerformanceCounter();
                $this->tooManyCandidatesKeys = array();
                $this->log->log('deduplicate', "Processing $total records for '$source'");
                foreach ($records as $record) {
                    if (isset($this->terminate)) {
                        $this->log->log('deduplicate', 'Termination upon request');
                        exit(1);
                    }
                    $startRecordTime = microtime(true);
                    if ($this->dedupRecord($record)) {
                        if ($this->verbose) {
                            echo '+';
                        }
                        ++$deduped;
                    } else {
                        if ($this->verbose) {
                            echo '.';
                        }
                    }
                    
                    if (isset($record['specType'])) {
                    	if ($record['specType'] == 'sup_record') {
                    		continue;
                    	} 
                    }
                    if (microtime(true) - $startRecordTime > 0.7) {
                        if ($this->verbose) {
                            echo "\n";
                        }
                        $this->log->log('deduplicate', 'Deduplication of ' . $record['_id'] . ' took ' . (microtime(true) - $startRecordTime));
                    }
                    ++$count;
                    if ($count % 1000 == 0) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        if ($this->verbose) {
                            echo "\n";
                        }
                        $this->log->log('deduplicate', "$count records processed for '$source', $deduped deduplicated, $avg records/sec");
                        $starttime = microtime(true);
                    }
                }
                $this->log->log('deduplicate', "Completed with $count records processed for '$source', $deduped deduplicated");
            } catch (Exception $e) {
                $this->log->log('deduplicate', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
            //flush buffer after each datasource deduplication
            $this->flushBuffer();
        }
    }

    /**
     * Harvest records from a data source
     * 
     * @param string      $repository           Source ID to harvest
     * @param string      $harvestFromDate      Override start date (otherwise harvesting is done from the previous harvest date)
     * @param string      $harvestUntilDate     Override end date (otherwise current date is used)
     * @param string      $startResumptionToken Override OAI-PMH resumptionToken to resume interrupted harvesting process (note 
     *                                     that tokens may have a limited lifetime)
     * @param string      $exclude              Source ID's to exclude whe using '*' for repository
     * @param bool|string $reharvest            Whether to consider this a full reharvest where sets may have changed 
     *                                          (deletes records not received during this harvesting)                                     
     *                                     
     * @return void
     */
    public function harvest($repository = '', $harvestFromDate = null, $harvestUntilDate = null, $startResumptionToken = '', $exclude = null, $reharvest = false)
    {
        global $configArray;

        if (empty($this->dataSourceSettings)) {
            $this->log->log('harvest', "Please add data source settings to datasources.ini", Logger::FATAL);
            throw new Exception("Data source settings missing in datasources.ini");
        }

        $excludedSources = isset($exclude) ? explode(',', $exclude) : array();
        
        // Loop through all the sources and perform harvests
        foreach ($this->dataSourceSettings as $source => $settings) {
            try {
                if ($repository && $repository != '*' && $source != $repository) {
                    continue;
                }
                if ((!$repository || $repository == '*') && in_array($source, $excludedSources)) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['url'])) {
                    continue;
                }
                $this->log->log('harvest', "Harvesting from '{$source}'" . ($reharvest ? ' (full reharvest)' : ''));

                $this->loadSourceSettings($source);

                if ($this->verbose) {
                    $settings['verbose'] = true;
                }
                
                if ($this->harvestType == 'metalib') {
                    // MetaLib doesn't handle deleted records, so we'll just fetch everything and compare with what we have
                    $this->log->log('harvest', "Fetching records from MetaLib");
                    $harvest = new HarvestMetaLib($this->log, $this->db, $source, $this->basePath, $settings);
                    $harvestedRecords = $harvest->launch();

                    $this->log->log('harvest', "Processing MetaLib records");
                    // Create keyed array
                    $records = array();
                    foreach ($harvestedRecords as $record) {
                        $marc = RecordFactory::createRecord('marc', $record, '', $source);
                        $id = $marc->getID();
                        $records["$source.$id"] = $record;
                    }
                    
                    $this->log->log('harvest', "Merging results with the records in database");
                    $deleted = 0;
                    $unchanged = 0;
                    $changed = 0;
                    $added = 0; 
                    $dbRecords = $this->db->record->find(array('deleted' => false, 'source_id' => $source));
                    foreach ($dbRecords as $dbRecord) {
                        $id = $dbRecord['_id'];
                        if (!isset($records[$id])) {
                            // Record not in harvested records, mark deleted
                            $this->storeRecord($id, true, '');
                            unset($records[$id]);
                            ++$deleted;
                            continue;
                        }
                        // Check if the record has changed
                        $dbMarc = RecordFactory::createRecord('marc', MetadataUtils::getRecordData($dbRecord, false), '', $source);
                        $marc = RecordFactory::createRecord('marc', $records[$id], '', $source);
                        if ((isset($harvestFromDate) && $harvestFromDate == '-') || $marc->serialize() != MetadataUtils::getRecordData($dbRecord, false)) {
                            // Record changed, update...
                            $this->storeRecord($id, false, $records[$id]);
                            ++$changed;
                        } else {
                            ++$unchanged;
                        }
                        unset($records[$id]);
                    }
                    $this->log->log('harvest', "Adding new records");
                    foreach ($records as $id => $record) {
                        $this->storeRecord($id, false, $record);
                        ++$added;
                    }
                    $this->log->log('harvest', "$added new, $changed changed, $unchanged unchanged and $deleted deleted records processed");
                } elseif ($this->harvestType == 'sfx') {
                    $harvest = new HarvestSfx($this->log, $this->db, $source, $this->basePath, $settings);
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    $harvest->launch(array($this, 'storeRecord'));
                } else {
                    if ($reharvest) {
                        if (is_string($reharvest)) {
                            $dateThreshold = new MongoDate(strtotime($reharvest));
                        } else {
                            $dateThreshold = new MongoDate();
                        }
                        $this->log->log('harvest', 'Reharvest date threshold: ' . strftime('%F %T', $dateThreshold->sec));
                    }
                    
                    $harvest = new HarvestOAIPMH($this->log, $this->db, $source, $this->basePath, $settings, $startResumptionToken);
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate == '-' ? null : $harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    
                    $callbacks = array();
                    $callbacks[] = array($this, 'flushBuffer');
                    $callbacks[] = array($this, 'completeLinks');
                    
                    $harvest->harvest(array($this, 'storeRecord'), $callbacks);
                    
                    if ($reharvest) {
                        $this->log->log('harvest', 'Marking deleted all records not received during the harvesting');
                        $records = $this->db->record->find(
                            array(
                                'source_id' => $this->sourceId,
                                'deleted' => false,
                                'updated' => array('$lt' => $dateThreshold)
                            )
                        );
                        $records->immortal(true);
                        $count = 0;
                        foreach ($records as $record) {
                            $this->storeRecord($record['oai_id'], true, '');
                            if (++$count % 1000 == 0) {
                                $this->log->log('harvest', "Deleted $count records");
                                
                            }   
                        }
                        $this->log->log('harvest', "Deleted $count records");
                    }
                    
                    if (isset($settings['deletions']) && strncmp($settings['deletions'], 'ListIdentifiers', 15) == 0) {
                        // The repository doesn't support reporting deletions, so list all identifiers
                        // and mark deleted records that were not found
                        $processDeletions = true;
                        $interval = null;
                        $deletions = explode(':', $settings['deletions']);
                        if (isset($deletions[1])) {
                            $state = $this->db->state->findOne(array('_id' => "Last Deletion Processing Time $source"));
                            if (isset($state)) {
                                $interval = round((time() - $state['value']) / 3600 / 24);
                                if ($interval < $deletions[1]) {
                                    $this->log->log('harvest', "Not processing deletions, $interval days since last time");    
                                    $processDeletions = false;
                                } 
                            }
                        }
                        
                        if ($processDeletions) {
                            $this->log->log('harvest', 'Processing deletions' . (isset($interval) ? " ($interval days since last time)" : ''));
                            
                            $this->log->log('harvest', 'Unmarking records');
                            $this->db->record->update(
                                array('source_id' => $this->sourceId, 'deleted' => false),
                                array('$unset' => array('mark' => 1)),
                                array('multiple' => true)
                            );
    
                            $this->log->log('harvest', "Fetching identifiers");
                            $harvest->listIdentifiers(array($this, 'markRecord'));
                            
                            $this->log->log('harvest', "Marking deleted records");
                            $result = $this->db->record->update(
                                array('source_id' => $this->sourceId, 'deleted' => false, 'mark' => array('$exists' => false)),
                                array('$set' => array('deleted' => true, 'updated' => new MongoDate())),
                                array('safe' => true, 'timeout' => 3000000, 'multiple' => true)
                            );
                            $state = array('_id' => "Last Deletion Processing Time $source", 'value' => time());
                            $this->db->state->save($state);
                            
                            $this->log->log('harvest', $result['n'] . " deleted records");
                        }
                    }
                }
                $this->log->log('harvest', "Harvesting from '{$source}' completed");
            } catch (Exception $e) {
                $this->log->log('harvest', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
    }

    /**
     * Dump a single record to console
     * 
     * @param string $recordID ID of the record to be dumped
     * 
     * @return void
     */
    public function dumpRecord($recordID)
    {
        if (!$recordID) {
            throw new Exception('dump: record id must be specified');
        }
        $records = $this->db->record->find(array('_id' => $recordID));
        foreach ($records as $record) {
            $record['original_data'] = MetadataUtils::getRecordData($record, false);
            $record['normalized_data'] = MetadataUtils::getRecordData($record, true);
            if ($record['original_data'] == $record['normalized_data']) {
                $record['normalized_data'] = '';
            }
            print_r($record);
        }
    }
    
    /**
     * Mark deleted records of a single data source
     * 
     * @param string $sourceId Source ID
     * 
     * @return void
     */
    public function markDeleted($sourceId)
    {
        $this->log->log('markDeleted', "Creating record list for '$sourceId'");

        $params = array('deleted' => false, 'source_id' => $sourceId);
        $records = $this->db->record->find($params);
        $records->immortal(true);
        $total = $this->counts ? $records->count() : 'the';
        $count = 0;

        $this->log->log('markDeleted', "Marking deleted $total records from '$sourceId'");
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            if (isset($record['dedup_id'])) {
                $this->removeFromDedupRecord($record['dedup_id'], $record['_id']);
            }
            $record['deleted'] = true;
            $record['updated'] = new MongoDate();
            $this->db->record->save($record);

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log('markDeleted', "$count records marked deleted from '$sourceId', $avg records/sec");
            }
        }
        $this->log->log('markDeleted', "Completed with $count records marked deleted from '$sourceId'");

        $this->log->log('markDeleted', "Deleting last harvest date from data source '$sourceId'");
        $this->db->state->remove(array('_id' => "Last Harvest Date $sourceId"), array('safe' => true));
        $this->log->log('markDeleted', "Marking of $sourceId completed");
    }

    /**
     * Delete records of a single data source from the Mongo database
     * 
     * @param string $sourceId Source ID
     * 
     * @return void
     */
    public function deleteRecords($sourceId)
    {
        $params = array();
        $params['source_id'] = $sourceId;
        $this->log->log('deleteRecords', "Creating record list for '$sourceId'");

        $params = array('deleted' => false, 'source_id' => $sourceId);
        $records = $this->db->record->find($params);
        $records->immortal(true);
        $total = $this->counts ? $records->count() : 'the';
        $count = 0;

        $this->log->log('deleteRecords', "Deleting $total records from '$sourceId'");
        $pc = new PerformanceCounter();
        foreach ($records as $record) {
            if (isset($record['dedup_id'])) {
                $this->removeFromDedupRecord($record['dedup_id'], $record['_id']);
            }
            $this->db->record->remove(array('_id' => $record['_id']));

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log('deleteRecords', "$count records deleted from '$sourceId', $avg records/sec");
            }
        }
        $this->log->log('deleteRecords', "Completed with $count records deleted from '$sourceId'");

        $this->log->log('deleteRecords', "Deleting last harvest date from data source '$sourceId'");
        $this->db->state->remove(array('_id' => "Last Harvest Date $sourceId"), array('safe' => true));
        $this->log->log('deleteRecords', "Deletion of $sourceId completed");
    }

    /**
     * Delete records of a single data source from the Solr index
     * 
     * @param string $sourceId Source ID
     * 
     * @return void
     */
    public function deleteSolrRecords($sourceId)
    {
        global $configArray;
        
        $updater = new SolrUpdater($this->db, $this->basePath, $this->log, $this->verbose);
        if (isset($configArray['Solr']['merge_records']) && $configArray['Solr']['merge_records']) {
            $this->log->log('deleteSolrRecords', "Deleting data source '$sourceId' from merged records via Solr update for merged records");
            $updater->updateMergedRecords('', $sourceId, '', false, true);
        } 
        $this->log->log('deleteSolrRecords', "Deleting data source '$sourceId' directly from Solr");
        $updater->deleteDataSource($sourceId);
        $this->log->log('deleteSolrRecords', "Deletion of '$sourceId' from Solr completed");
    }
    
    public function deleteSolrCore() {
    	$updater = new SolrUpdater($this->db, $this->basePath, $this->log, $this->verbose);
    	$updater->deleteSolrCore();
    }
    
    /**
     * Optimize the Solr index
     * 
     * @return void
     */
    public function optimizeSolr()
    {
        $updater = new SolrUpdater($this->db, $this->basePath, $this->log, $this->verbose);
        
        $this->log->log('optimizeSolr', 'Optimizing Solr index');
        $updater->optimizeIndex();
        $this->log->log('optimizeSolr', 'Solr optimization completed');
    }
    
    /**
     * Save a record into the database. Used by e.g. OAI-PMH harvesting.
     * 
     * @param string $oaiID      ID of the record as received from OAI-PMH
     * @param bool   $deleted    Whether the record is to be deleted
     * @param string $recordData Record metadata
     * 
     * @throws Exception
     * @return integer Number of records processed (can be > 1 for split records)
     */
    public function storeRecord($oaiID, $deleted, $recordData)
    {
        if ($deleted) {
            // A single OAI-PMH record may have been split to multiple records
            $records = $this->db->record->find(array('source_id' => $this->sourceId, 'oai_id' => $oaiID));
            $count = 0;
            foreach ($records as $record) {
                if (isset($record['dedup_id'])) {
                    $this->removeFromDedupRecord($record['dedup_id'], $record['_id']);
                }
                $record['deleted'] = true;
                unset($record['dedup_id']);
                $record['updated'] = new MongoDate();
                $this->db->record->save($record);
                ++$count;
            }
            return $count;
        }

        $dataArray = Array();
        if ($this->recordSplitter) {
            if ($this->verbose) {
                echo "Splitting records\n";
            }
            if (is_string($this->recordSplitter)) {
                include_once $this->recordSplitter;
                $className = substr($this->recordSplitter, 0, -4);
                $splitter = new $className($recordData);
                while (!$splitter->getEOF()) {
                    $dataArray[] = $splitter->getNextRecord();
                }
            } else {
                $doc = new DOMDocument();
                $doc->loadXML($recordData);
                if ($this->verbose) {
                    echo "XML Doc Created\n";
                }
                $transformedDoc = $this->recordSplitter->transformToDoc($doc);
                if ($this->verbose) {
                    echo "XML Transformation Done\n";
                }
                $records = simplexml_import_dom($transformedDoc);
                if ($this->verbose) {
                    echo "Creating record array\n";
                }
                foreach ($records as $record) {
                    $dataArray[] = $record->saveXML();
                }
            }
        } else {
            $dataArray = array($recordData);
        }

        if ($this->verbose) {
            echo "Storing array of " . count($dataArray) . " records\n";
        }
        
        // Store start time so that we can mark deleted any child records not present anymore  
        $startTime = new MongoDate();
                
        $count = 0;
        $mainID = '';
        foreach ($dataArray as $data) {
            if (isset($this->normalizationXSLT)) {
                $metadataRecord = RecordFactory::createRecord($this->format, $this->normalizationXSLT->transform($data, array('oai_id' => $oaiID)), $oaiID, $this->sourceId);
                $metadataRecord->normalize();
                $normalizedData = $metadataRecord->serialize();
                $originalData = RecordFactory::createRecord($this->format, $data, $oaiID, $this->sourceId)->serialize();
            } else {
                $metadataRecord = RecordFactory::createRecord($this->format, $data, $oaiID, $this->sourceId);
                $originalData = $metadataRecord->serialize();
                $metadataRecord->normalize();
                $normalizedData = $metadataRecord->serialize();
            }
    
            $hostID = $metadataRecord->getHostRecordID();
            $id = $metadataRecord->getID();
            if (!$id) {
                if (!$oaiID) {
                    $this->log->log('storeRecord', 'No ID found for record', LOGGER::WARNING);
                    continue;
                }
                $id = $oaiID;
            }
            $id = $this->idPrefix . $id;
			
            $hashOfContent = md5($originalData);
            $dbRecord = $this->db->record->findOne(array('_id' => $id));
            if ($dbRecord) {
            	if (isset($dbRecord['content_hash']) && strcmp($dbRecord['content_hash'], $hashOfContent) == 0) {
            	    // don't touch unchanged records
            	    ++$count;
            	    continue;
            	}
            	$dbRecord['updated'] = new MongoDate();
            	
            } else {
            	$dbRecord = array();
            	$dbRecord['source_id'] = $this->sourceId;
            	$dbRecord['_id'] = $id;
            	$dbRecord['created'] = $dbRecord['updated'] = new MongoDate();
            }
            
            
            $hierarchyFields = $metadataRecord->getHierarchyField();
            if (!empty($hierarchyFields)) {
                foreach ($hierarchyFields as $hierarchyField) {
    
                    if (strcasecmp($hierarchyField['a'], 'UP') == 0) {
                        $this->__putLinkToBuffer($hierarchyField['b'], 'DN', $id);
                    }
                    
                    if (strcasecmp($hierarchyField['a'], 'DN') == 0) {
                        $this->__putLinkToBuffer($hierarchyField['b'], 'UP', $id);
                    }
                }
            }
            
            $specType = $metadataRecord->getSpecialRecordType();
            if ($specType != null) {
            	$dbRecord['specType'] = $specType;
            }
            $dbRecord['date'] = $dbRecord['updated'];
            if ($normalizedData) {
                if ($originalData == $normalizedData) {
                    $normalizedData = '';
                };
            }
            $dbRecord['content_hash'] = $hashOfContent;
            if ($this->compressedRecords) {
                $originalData = new MongoBinData(gzdeflate($originalData), 2);
                if ($normalizedData) {
                    $normalizedData = new MongoBinData(gzdeflate($normalizedData), 2);
                }
            }
            $dbRecord['oai_id'] = $oaiID;
            $dbRecord['deleted'] = false;
            $dbRecord['linking_id'] = $metadataRecord->getLinkingID();
            if ($mainID) {
                $dbRecord['main_id'] = $mainID;
            }
            if ($hostID) {
                $dbRecord['host_record_id'] = $hostID;
            } else {
                unset($dbRecord['host_record_id']);
            }
            $dbRecord['format'] = $this->format;
            $dbRecord['original_data'] = $originalData;
            $dbRecord['normalized_data'] = $normalizedData;
            if ($this->dedup && !$hostID) {
                $this->updateDedupCandidateKeys($dbRecord, $metadataRecord);
                if (isset($dbRecord['dedup_id'])) {
                    $this->removeFromDedupRecord($dbRecord['dedup_id'], $dbRecord['_id']);
                }
                unset($dbRecord['dedup_id']);
                $dbRecord['update_needed'] = true;
            } else {
                unset($dbRecord['title_keys']);
                unset($dbRecord['isbn_keys']);
                unset($dbRecord['id_keys']);
                $dbRecord['update_needed'] = false;
            }
            //store record enableRecordCheck is not or record is succesfully checked
            if ($this->enableRecordCheck) {
                if ($metadataRecord->checkRecord() === true) {            
                    $this->__storeIntoBuffer($dbRecord);
                    ++$count;
                } else {
//                     $this->log->log('StoreRecord', 'Skipping record '.$metadataRecord->getID());
                }
            } else {
                $this->__storeIntoBuffer($dbRecord);
                ++$count;
            }
            if (!$mainID) {
                $mainID = $id;
            }
        }
        
        if ($count > 1 && $mainID) {
            // We processed a hierarchical record. Mark deleted any children that were not updated.
            $this->db->record->update(
                array(
                    'source_id' => $this->sourceId,
                    'main_id' => $mainID,
                    'updated' => array('$lt' => $startTime)
                ),
                array('$set' => array('deleted' => true, 'updated' => $startTime)),
                array('multiple' => true)
            );
        }
        
        return $count;
    }

    /**
     * Count distinct values in the specified field (that would be added to the Solr index)
     * 
     * @param string $sourceId Source ID
     * @param string $field    Field name
     * 
     * @return void
     */
    public function countValues($sourceId, $field)
    {
        if (!$field) {
            echo "Field must be specified\n";
            exit;
        }
        $updater = new SolrUpdater($this->db, $this->basePath, $this->log, $this->verbose);
        $updater->countValues($sourceId, $field);
    }
    
    /**
     * Mark a record "seen". Used by OAI-PMH harvesting when deletions are not supported.
     *
     * @param string $oaiID   ID of the record as received from OAI-PMH
     * @param bool   $deleted Whether the record is to be deleted
     * 
     * @throws Exception
     * @return void
     */
    public function markRecord($oaiID, $deleted)
    {
        if ($deleted) {
            // Don't mark deleted records...
            return;
        }
        $this->db->record->update(
            array('source_id' => $this->sourceId, 'oai_id' => $oaiID),
            array('$set' => array('mark' => true)),
            array('multiple' => true)
        );
    }

    /**
     * Update the geocoding table with the geocoder selected in settings.
     *  
     * @param unknown $placeFile File containing places to add (one per file)
     * 
     * @return void
     */
    public function updateGeocodingTable($placeFile)
    {
        global $configArray;
        
        if (!isset($configArray['Geocoding']) || !isset($configArray['Geocoding']['geocoder'])) {
            throw new Exception('Error: no geocoder defined');
        }
        
        include_once $configArray['Geocoding']['geocoder'] . '.php';
        $geocoder = new $configArray['Geocoding']['geocoder']($this->db, $this->log, $this->verbose);
        $geocoder->init($configArray['Geocoding']);
        $geocoder->geocode($placeFile);
    }
    
    /**
     * Resimplify the geocoding table with current geocoder settings.
     *  
     * @return void
     */
    public function resimplifyGeocodingTable()
    {
        global $configArray;
        
        if (!isset($configArray['Geocoding']) || !isset($configArray['Geocoding']['geocoder'])) {
            throw new Exception('Error: no geocoder defined');
        }
        
        include_once $configArray['Geocoding']['geocoder'] . '.php';
        $geocoder = new $configArray['Geocoding']['geocoder']($this->db, $this->log, $this->verbose);
        $geocoder->init($configArray['Geocoding']);
        $geocoder->resimplify();
    }
    
    /**
     * Verify consistency of dedup records links with actual records
     * 
     * @return void
     */
    public function checkDedupRecords()
    {
        $this->log->log('checkDedupRecords', "Checking dedup record consistency");
        
        $dedupRecords = $this->db->dedup->find(array('deleted' => false));
        $dedupRecords->immortal(true);
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        foreach ($dedupRecords as $dedupRecord) {
            foreach ($dedupRecord['ids'] as $id) {
                $record = $this->db->record->findOne(array('_id' => $id));
                if ($record['deleted'] || !isset($record['dedup_id']) || $record['dedup_id'] != $dedupRecord['_id']) {
                    $this->removeFromDedupRecord($dedupRecord['_id'], $id);
                    if ($record['deleted']) {
                        $reason = 'record deleted';
                    } elseif (!isset($record['dedup_id'])) {
                        $reason = 'record not linked';
                    } else {
                        $reason = 'record linked with another dedup record';
                    }         
                    $this->log->log('checkDedupRecords', "Removed '$id' from dedup record '{$dedupRecord['_id']}' ($reason)");
                    ++$fixed;
                }
            }
            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->log->log('checkDedupRecords', "$count records checked with $fixed links fixed, $avg records/sec");
            }
        }
        $this->log->log('checkDedupRecords', "Completed with $count records checked with $fixed links fixed");
    }
    
    /**
     * Update dedup candidate keys for the given record
     * 
     * @param object &$record        Database record
     * @param object $metadataRecord Metadata record for the used format
     * 
     * @return void
     */
    protected function updateDedupCandidateKeys(&$record, $metadataRecord)
    {
        $record['title_keys'] = array(MetadataUtils::createTitleKey($metadataRecord->getTitle(true)));
        if (empty($record['title_keys'])) {
            unset($record['title_keys']);
        }
        $record['isbn_keys'] = $metadataRecord->getISBNs();
        if (empty($record['isbn_keys'])) {
            unset($record['isbn_keys']);
        }
        $record['id_keys'] = $metadataRecord->getUniqueIDs();
        if (empty($record['id_keys'])) {
            unset($record['id_keys']);
        }
    }

    /**
     * Find a single duplicate for the given record and set a dedup key for them
     * 
     * @param object $record Database record
     * 
     * @return boolean Whether a duplicate was found
     */
    protected function dedupRecord($record)
    {
        $startTime = microtime(true);
        if ($this->verbose) {
            echo 'Original ' . $record['_id'] . ":\n" . MetadataUtils::getRecordData($record, true) . "\n";
        }
        
        $keyArray = isset($record['title_keys']) ? $record['title_keys'] : array();
        $ISBNArray = isset($record['isbn_keys']) ? $record['isbn_keys'] : array();
        $IDArray = isset($record['id_keys']) ? $record['id_keys'] : array();
        
        $origRecord = null;
        $matchRecord = null;
        $candidateCount = 0;
        foreach (array('isbn_keys' => $ISBNArray, 'id_keys' => $IDArray, 'title_keys' => $keyArray) as $type => $array) {
            foreach ($array as $keyPart) {
                if (!$keyPart) {
                    continue;
                }
                  
                if ($this->verbose) {
                    echo "Search: '$keyPart'\n";
                }
                $candidates = $this->db->record->find(
                    array(
                        $type => $keyPart,
                        '_id' => array('$ne' => $record['_id']),
                        'source_id' => (array('$ne' => $record['source_id']))
//                         'dedup_id' => array('$exists' => false)
                    )
                );
                $processed = 0;
                // Go through the candidates, try to match
                $matchRecord = null;
                
                //pre-sort candidates - already deduped records has higher priority
                //fixed permutation cycles in deduped records
                $sortedCandidates = array();
                foreach ($candidates as $candidate) {
                    $sortedCandidates[] = $candidate;
                }
                usort($sortedCandidates, 
                    function ($a, $b) {
                        $hasA = array_key_exists('dedup_id', $a);
                        $hasB = array_key_exists('dedup_id', $b);
                        if ($hasA == $hasB)
                            return 0;
                        elseif ($hasA)
                            return -1;
                        return 1; 
                    }
                );
                
                foreach ($sortedCandidates as $candidate) {
                    // Don't dedup with this source or deleted. It's faster to check here than in find!
                    if ($candidate['deleted']) {
                        continue;
                    }
                    if ($type != 'isbn_keys') {
                        if (isset($candidate['isbn_keys'])) {
                            $sameKeys = array_intersect($ISBNArray, $candidate['isbn_keys']);
                            if ($sameKeys) {
                                continue;
                            }
                        }
                        if ($type != 'id_keys' && isset($candidate['id_keys'])) {
                            $sameKeys = array_intersect($IDArray, $candidate['id_keys']);
                            if ($sameKeys) {
                                continue;
                            }
                        }
                    }
                    ++$candidateCount;
                    // Verify the candidate has not been deduped with this source yet
//                     if (isset($candidate['dedup_id']) && (!isset($record['dedup_id']) || $candidate['dedup_id'] != $record['dedup_id'])) {
//                         if ($this->db->record->find(array('dedup_id' => $candidate['dedup_id'], 'source_id' => $this->sourceId))->limit(1)->count() > 0) {
//                             if ($this->verbose) {
//                                 echo "Candidate {$candidate['_id']} already deduplicated\n";
//                             }
//                             continue;
//                         }
//                     }

                    if (++$processed > 1000 || (isset($this->tooManyCandidatesKeys["$type=$keyPart"]) && $processed > 100)) {
                        // Too many candidates, give up..
                        $this->log->log('dedupRecord', "Too many candidates for record " . $record['_id'] . " with key '$keyPart'", Logger::DEBUG);
                        if (count($this->tooManyCandidatesKeys) > 2000) {
                            array_shift($this->tooManyCandidatesKeys);
                        }
                        $this->tooManyCandidatesKeys["$type=$keyPart"] = 1;
                        break;
                    }

                    if (!isset($origRecord)) {
                        $origRecord = RecordFactory::createRecord($record['format'], MetadataUtils::getRecordData($record, true), $record['oai_id'], $record['source_id']);
                    }
                    if ($this->matchRecords($record, $origRecord, $candidate)) {
                        if ($this->verbose && ($processed > 300 || microtime(true) - $startTime > 0.7)) {
                            echo "Found match $type=$keyPart with candidate $processed in " . (microtime(true) - $startTime) . "\n";
                        }
                        $matchRecord = $candidate;
                        break 3;
                    }
                }
                if ($this->verbose && ($processed > 300 || microtime(true) - $startTime > 0.7)) {
                    echo "No match $type=$keyPart with $processed candidates in " . (microtime(true) - $startTime) . "\n";
                }
            }
        }

        if ($this->verbose && microtime(true) - $startTime > 0.2) {
            echo "Candidate search among $candidateCount records (" . ($matchRecord ? 'success' : 'failure') . ") completed in " . (microtime(true) - $startTime) . "\n";           
        }
        
        if ($matchRecord) {
            $this->markDuplicates($record, $matchRecord);
            
            if ($this->verbose && microtime(true) - $startTime > 0.2) {
                echo "DedupRecord among $candidateCount records (" . ($matchRecord ? 'success' : 'failure') . ") completed in " . (microtime(true) - $startTime) . "\n";           
            }
            
            return true;
        }
        
        //---
        //deduplication speed improvement - records that needs update only are buffered
        if (!isset($record['dedup_id']) && $record['update_needed']) {
            $record['updated'] = new MongoDate();
            $record['update_needed'] = false;
            $this->__storeIntoBuffer($record);
        }
        //-----
        
        if (isset($record['dedup_id']) || $record['update_needed']) {
            if (isset($record['dedup_id'])) {
                $this->removeFromDedupRecord($record['dedup_id'], $record['_id']);
            }
            unset($record['dedup_id']);
            $record['updated'] = new MongoDate();
            $record['update_needed'] = false;
            $this->__storeIntoBuffer($record);

            //flush buffer
            $this->flushBuffer();
        
        }
        
        if ($this->verbose && microtime(true) - $startTime > 0.2) {
            echo "DedupRecord among $candidateCount records (" . ($matchRecord ? 'success' : 'failure') . ") completed in " . (microtime(true) - $startTime) . "\n";           
        }
        
        return false;
    }

    /**
     * Check if records are duplicate matches
     * 
     * @param object $record     Mongo record
     * @param object $origRecord Metadata record (from Mongo record) 
     * @param object $candidate  Candidate Mongo record
     * 
     * @return boolean
     */
    protected function matchRecords($record, $origRecord, $candidate)
    {
        $cRecord = RecordFactory::createRecord($candidate['format'], MetadataUtils::getRecordData($candidate, true), $candidate['oai_id'], $candidate['source_id']);
        if ($this->verbose) {
            echo "\nCandidate " . $candidate['_id'] . ":\n" . MetadataUtils::getRecordData($candidate, true) . "\n";
        }
        
        $origFormat = $origRecord->getFormat();
        $cFormat = $cRecord->getFormat();
        //format match is nescessary
        if (!$this->matchFormats($origFormat, $cFormat)) {
            if ($this->verbose) {
                echo "--Format mismatch: " . print_r($origFormat, true) . "!=" . print_r($cFormat, true) . "\n";
            }
            return false;
        }
         
        // Check for common ISBN
        $origISBNs = $origRecord->getISBNs();
        $cISBNs = $cRecord->getISBNs();
        $isect = array_intersect($origISBNs, $cISBNs);
        if (!empty($isect)) {
            // Shared ISBN -> match
            if ($this->verbose) {
                echo "++ISBN match:\n";
                print_r($origISBNs);
                print_r($cISBNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return true; 
        }
        
        // Check for other common ID (e.g. NBN)
        $origIDs = $origRecord->getUniqueIDs();
        $cIDs = $cRecord->getUniqueIDs();
        $isect = array_intersect($origIDs, $cIDs);
        if (!empty($isect)) {
            // Shared ID -> match
            if ($this->verbose) {
                echo "++ID match:\n";
                print_r($origIDs);
                print_r($cIDs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return true; 
        }
        
        
    
        $origYear = $origRecord->getPublicationYear();
        $cYear = $cRecord->getPublicationYear();
        if (!$origYear || !$cYear || $origYear != $cYear) {
            if ($this->verbose) {
                echo "--Year mismatch: $origYear != $cYear\n";
            }
            return false;
        }
        
        $origISSNs = $origRecord->getISSNs();
        $cISSNs = $cRecord->getISSNs();
        $commonISSNs = array_intersect($origISSNs, $cISSNs);
        if (!empty($origISSNs) && !empty($cISSNs) && empty($commonISSNs)) {
            // Both have ISSNs but none match
            if ($this->verbose) {
                echo "++ISSN mismatch:\n";
                print_r($origISSNs);
                print_r($cISSNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return false;
        }
        
        
        $pages = $origRecord->getPageCount();
        $cPages = $cRecord->getPageCount();
        if ($pages && $cPages && abs($pages-$cPages) > 10) {
            if ($this->verbose) {
                echo "--Pages mismatch ($pages != $cPages)\n";
            }
            return false;
        }
        
        if ($origRecord->getSeriesISSN() != $cRecord->getSeriesISSN()) {
            return false;
        }
        if ($origRecord->getSeriesNumbering() != $cRecord->getSeriesNumbering()) {
            return false;
        }
        
        $origTitle = MetadataUtils::normalize($origRecord->getTitle(true));
        $cTitle = MetadataUtils::normalize($cRecord->getTitle(true));
        if (!$origTitle || !$cTitle) {
            // No title match without title...
            if ($this->verbose) {
                echo "No title - no further matching\n";
            }
            return false;
        }
        $lev = levenshtein(substr($origTitle, 0, 255), substr($cTitle, 0, 255));
        $lev = $lev / strlen($origTitle) * 100;
        if ($lev >= 10) {
            if ($this->verbose) {
                echo "--Title lev discard: $lev\nOriginal:  $origTitle\nCandidate: $cTitle\n";
            }
            return false;
        }
        
        $origAuthor = MetadataUtils::normalize($origRecord->getMainAuthor());
        $cAuthor = MetadataUtils::normalize($cRecord->getMainAuthor());
        $authorLev = 0;
        if ($origAuthor || $cAuthor) {
            if (!$origAuthor || !$cAuthor) {
                if ($this->verbose) {
                    echo "\nAuthor discard:\nOriginal:  $origAuthor\nCandidate: $cAuthor\n";
                }
                return false;
            }
            if (!MetadataUtils::authorMatch($origAuthor, $cAuthor)) {
                $authorLev = levenshtein(substr($origAuthor, 0, 255), substr($cAuthor, 0, 255));
                $authorLev = $authorLev / mb_strlen($origAuthor) * 100;
                if ($authorLev > 20) {
                    if ($this->verbose) {
                        echo "\nAuthor lev discard (lev: $lev, authorLev: $authorLev):\nOriginal:  $origAuthor\nCandidate: $cAuthor\n";
                    }
                    return false;
                }
            }
        }

        if ($this->verbose) {
            echo "\nTitle match (lev: $lev, authorLev: $authorLev):\n";
            echo $origRecord->getFullTitle() . "\n";
            echo "   $origAuthor - $origTitle.\n";
            echo $cRecord->getFullTitle() . "\n";
            echo "   $cAuthor - $cTitle.\n";
        }
        // We have a match!
        return true;
    }
    
    /**
     * Mark two records as duplicates
     * 
     * @param object $rec1 Mongo record for which a duplicate was searched
     * @param object $rec2 Mongo record for the found duplicate
     * 
     * @return void
     */
    protected function markDuplicates($rec1, $rec2)
    {
        $setValues = array('updated' => new MongoDate(), 'update_needed' => false);
        if (isset($rec2['dedup_id']) && $rec2['dedup_id']) {
            $this->addToDedupRecord($rec2['dedup_id'], $rec1['_id']);
            if (isset($rec1['dedup_id']) && $rec1['dedup_id'] != $rec2['dedup_id']) {
                $this->removeFromDedupRecord($rec1['dedup_id'], $rec1['_id']);
            }
            $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id']; 
        } else {
            if (isset($rec1['dedup_id']) && $rec1['dedup_id']) {
                $this->addToDedupRecord($rec1['dedup_id'], $rec2['_id']);
                $setValues['dedup_id'] = $rec2['dedup_id'] = $rec1['dedup_id'];
            } else {
                $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id'] = $this->createDedupRecord($rec1['_id'], $rec2['_id']);                
            }
        }
        if ($this->verbose) {
            echo "Marking {$rec1['_id']} as duplicate with {$rec2['_id']} with dedup id {$rec2['dedup_id']}\n";
        }
        
        if (!isset($rec1['host_record_id'])) {
            $count = $this->dedupComponentParts($rec1);
            if ($this->verbose && $count > 0) {
                echo "Deduplicated $count component parts for {$rec1['_id']}\n";
            }
        }
        
        $this->db->record->update(
            array('_id' => array('$in' => array($rec1['_id'], $rec2['_id']))),
            array('$set' => $setValues),
            array('multiple' => true)
        );
    }
    

    /**
     * Create a new dedup record
     * 
     * @param string $id1 ID of first record
     * @param string $id2 ID of second record
     * 
     * @return MongoId ID of the dedup record
     */
    protected function createDedupRecord($id1, $id2)
    {
        
        $record = $this->db->dedup->findOne(
            array('$or' =>
                array(
                    array('ids' => $id1),
                    array('ids' => $id2)
                )
            )
        );
        
        if ($record) {
            $ids = is_array($record['ids']) ? $record['ids'] : array();
            $record['ids'] = array_values(array_unique(array_merge($ids, array($id1, $id2))));
            $this->db->dedup->save($record);
            return $record['_id'];
        }
        
        $record = array(
            '_id' => new MongoId(),
            'changed' => new MongoDate(),
            'deleted' => false,
            'ids' => array(
                $id1,
                $id2
             )
        );
        $this->db->dedup->insert($record);
        return $record['_id'];
    }
    
    /**
     * Add another record to an existing dedup record
     * 
     * @param string $dedupId ID of the dedup record 
     * @param string $id      Record ID to add
     * 
     * @return void
     */
    protected function addToDedupRecord($dedupId, $id)
    {
        $record = $this->db->dedup->findOne(array('_id' => $dedupId));
        if (!$record) {
            $this->log->log('addToDedupRecord', "Found dangling reference to dedup record $dedupId", Logger::ERROR);
            return;
        }
        if (!in_array($id, $record['ids'])) {
            $record['changed'] = new MongoDate();
            $record['ids'][] = $id;
            $this->db->dedup->save($record);
        }
    }

    /**
     * Remove a record from a dedup record
     * 
     * @param string $dedupId ID of the dedup record 
     * @param string $id      Record ID to remove
     *
     * @return void
     */
    protected function removeFromDedupRecord($dedupId, $id)
    {
        $record = $this->db->dedup->findOne(array('_id' => $dedupId));
        if ($record == null) return;
        if (in_array($id, $record['ids'])) {
            $record['ids'] = array_values(array_diff($record['ids'], array($id)));
            
            // If there is only one record remaining, remove dedup_id from it too
            if (count($record['ids']) == 1) {
                $otherId = reset($record['ids']);
                $otherRecord = $this->db->record->findOne(array('_id' => $otherId));
                unset($otherRecord['dedup_id']);
                $otherRecord['changed'] = new MongoDate();
                $this->db->record->save($otherRecord);
                $record['ids'] = array();   
                $record['deleted'] = true;
            } elseif (count($record['ids']) == 0) {
                $record['deleted'] = true;
            }
            $record['changed'] = new MongoDate();
            $this->db->dedup->save($record);
        }
    }
    
    /**
     * Deduplicate component parts of a record
     * 
     * Component part deduplication is special. It will only go through
     * component parts of other records deduplicated with the host record
     * and stops when it finds a set of component parts that match.
     * 
     * @param object $hostRecord Mongo record for the host record
     * 
     * @return integer Number of component parts deduplicated
     */
    protected function dedupComponentParts($hostRecord)
    {
        if ($this->verbose) {
            echo "Deduplicating component parts\n";
        }
        if (!$hostRecord['linking_id']) {
            $this->log->log('dedupComponentParts', 'Linking ID missing from record ' . $hostRecord['_id'], Logger::ERROR);
            return 0;
        }
        $components1 = $this->getComponentPartsSorted($hostRecord['source_id'], $hostRecord['linking_id']);
        $component1count = count($components1);
        
        // Go through all other records with same dedup id and see if their component parts match
        $marked = 0;
        $otherRecords = $this->db->record->find(array('dedup_id' => $hostRecord['dedup_id'], 'deleted' => false));
        foreach ($otherRecords as $otherRecord) {
            if ($otherRecord['source_id'] == $hostRecord['source_id']) {
                continue;
            }
            $components2 = $this->getComponentPartsSorted($otherRecord['source_id'], $otherRecord['linking_id']);
            $component2count = count($components2);
            
            if ($component1count != $component2count) {
                $allMatch = false;
            } else {
                $allMatch = true;
                $idx = -1;
                foreach ($components1 as $component1) {
                    $component2 = $components2[++$idx];
                    if ($this->verbose) {
                        echo "Comparing {$component1['_id']} with {$component2['_id']}\n";
                    }
                    if ($this->verbose) {
                        echo 'Original ' . $component1['_id'] . ":\n" . MetadataUtils::getRecordData($component1, true) . "\n";
                    }
                    $metadataComponent1 = RecordFactory::createRecord($component1['format'], MetadataUtils::getRecordData($component1, true), $component1['oai_id'], $component1['source_id']);
                    if (!$this->matchRecords($component1, $metadataComponent1, $component2)) {
                        $allMatch = false;
                        break;
                    }
                }
            }

            if ($allMatch) {
                if ($this->verbose) {
                    echo microtime(true) . " All component parts match between {$hostRecord['_id']} and {$otherRecord['_id']}\n";
                }
                $idx = -1;
                foreach ($components1 as $component1) {
                    $component2 = $components2[++$idx];
                    $this->markDuplicates($component1, $component2);
                    ++$marked;
                }
                break;
            } else {
                if ($this->verbose) {
                    echo microtime(true) . " Not all component parts match between {$hostRecord['_id']} and {$otherRecord['_id']}\n";
                }
            }
        }
        return $marked;
    }

    /**
     * Get component parts in a sorted array
     * 
     * @param string $sourceId     Source ID
     * @param string $hostRecordId Host record ID (doesn't include source id)
     * 
     * @return array Array of component parts
     */
    protected function getComponentPartsSorted($sourceId, $hostRecordId)
    {
        $componentsIter = $this->db->record->find(array('source_id' => $sourceId, 'host_record_id' => $hostRecordId));
        $components = array();
        foreach ($componentsIter as $component) {
            $components[MetadataUtils::createIdSortKey($component['_id'])] = $component;
        }
        ksort($components);
        return array_values($components);        
    }
    
    /**
     * Execute a pretransformation on data before it is split into records and loaded. Used when loading from a file.
     * 
     * @param string $data The original data
     * 
     * @return string Transformed data
     */
    protected function pretransform($data)
    {
        if (!isset($this->preXSLT)) {
            $style = new DOMDocument();
            $style->load($this->basePath . '/transformations/' . $this->pretransformation);
            $this->preXSLT = new XSLTProcessor();
            $this->preXSLT->importStylesheet($style);
            $this->preXSLT->setParameter('', 'source_id', $this->sourceId);
            $this->preXSLT->setParameter('', 'institution', $this->institution);
            $this->preXSLT->setParameter('', 'format', $this->format);
            $this->preXSLT->setParameter('', 'id_prefix', $this->idPrefix);
        }
        $doc = new DOMDocument();
        $doc->loadXML($data, LIBXML_PARSEHUGE);
        return $this->preXSLT->transformToXml($doc);
    }

    /**
     * Load the data source settings and setup some functions
     *
     * @param string $source Source ID
     *  
     * @throws Exception
     * @return void
     */
    protected function loadSourceSettings($source)
    {
        if (!isset($this->dataSourceSettings[$source])) {
            $this->log->log('loadSourceSettings', "Settings not found for data source $source", Logger::FATAL);
            throw new Exception("Error: settings not found for $source\n");
        }
        $settings = $this->dataSourceSettings[$source];
        if (!isset($settings['institution'])) {
            $this->log->log('loadSourceSettings', "institution not set for $source", Logger::FATAL);
            throw new Exception("Error: institution not set for $source\n");
        }
        if (!isset($settings['format'])) {
            $this->log->log('loadSourceSettings', "format not set for $source", Logger::FATAL);
            throw new Exception("Error: format not set for $source\n");
        }
        $this->format = $settings['format'];
        $this->sourceId = $source;
        $this->idPrefix = $source . '.';
        if (isset($settings['idPrefix']) && $settings['idPrefix']) {
            $this->idPrefix = $settings['idPrefix'];
            if (substr($this->idPrefix, -1) != '-') {
                $this->idPrefix .= '.';
            }
        }
        $this->institution = $settings['institution'];
        $this->recordXPath = isset($settings['recordXPath']) ? $settings['recordXPath'] : '';
        $this->oaiIDXPath = isset($settings['oaiIDXPath']) ? $settings['oaiIDXPath'] : '';
        $this->dedup = isset($settings['dedup']) ? $settings['dedup'] : false;
        $this->componentParts = isset($settings['componentParts']) && $settings['componentParts'] ? $settings['componentParts'] : 'as_is';
        $this->pretransformation = isset($settings['preTransformation']) ? $settings['preTransformation'] : '';
        $this->indexMergedParts = isset($settings['indexMergedParts']) ? $settings['indexMergedParts'] : true;
        $this->harvestType = isset($settings['type']) ? $settings['type'] : '';
        
        $params = array('source_id' => $this->sourceId, 'institution' => $this->institution, 'format' => $this->format, 'id_prefix' => $this->idPrefix);
        $this->normalizationXSLT = isset($settings['normalization']) && $settings['normalization'] ? new XslTransformation($this->basePath . '/transformations', $settings['normalization'], $params) : null;
        $this->solrTransformationXSLT = isset($settings['solrTransformation']) && $settings['solrTransformation'] ? new XslTransformation($this->basePath . '/transformations', $settings['solrTransformation'], $params) : null;
        
        if (isset($settings['recordSplitter'])) {
            if (substr($settings['recordSplitter'], -4) == '.php') {
                $this->recordSplitter = $settings['recordSplitter']; 
            } else {
                $style = new DOMDocument();
                $xslFile = $this->basePath . '/transformations/' . $settings['recordSplitter'];
                if ($style->load($xslFile) === false) {
                    throw new Exception("Could not load $xslFile");
                }
                $this->recordSplitter = new XSLTProcessor();
                $this->recordSplitter->importStylesheet($style);
            }
        } else {
            $this->recordSplitter = null;
        }
        if (isset($settings['fileSplitter'])) {
            $this->fileSplitter = $settings['fileSplitter'];
        } else {
            $this->fileSplitter = null;
        }
        if (isset($settings['enableRecordCheck'])) {
            $this->enableRecordCheck = $settings['enableRecordCheck'] == true ? true : false;
        } else {
            $this->enableRecordCheck = false;
        }
        if (isset($settings['inputEncoding'])) {
            $this->inputEncoding = strtolower($settings['inputEncoding']);
        } 
        if (isset($settings['lineRecordLeader'])) {
            $this->lineRecordLeader = $settings['lineRecordLeader'];
        }
    }
    
    /**
     * Read a list file into an array
     * 
     * @param string $filename List file name
     * 
     * @return string[]
     */
    protected static function readListFile($filename)
    {
        global $basePath;
        
        $filename = "$basePath/conf/$filename"; 
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $this->log->log('readListFile', "Could not open list file '$filename'", Logger::ERROR);
            return array();                
        }
        array_walk(
            $lines, 
            function(&$value) {
                $value = trim($value, "'");
            }
        );
        
        return $lines;
    }
    /**
     * Puts record into buffer of objects to be stored.
     * @param $record
     */
    private function __storeIntoBuffer($record) 
    {
    	array_push($this->bufferedRecords, $record);
    	if (count($this->bufferedRecords) % $this->bufferSize == 0) {
    		$this->flushBuffer();
    	}
    }
    
    private function __putLinkToBuffer($id, $direction, $target) 
    {
        if (!array_key_exists($id, $this->linkArray)) {
            $this->linkArray[$id] = array();
        }
        $this->linkArray[$id][] = array('direction' => $direction, 'target' => $target);
    }
    /**
     * stores buffered records in this->$bufferedRecords into db. Must be called at the end of storing precudures.
     * Also stores link information into DB.
     * @param array $bufferedRecords records to be stored into database
     */
    public function flushBuffer() 
    {
        if (isset($this->linkArray) && !empty($this->linkArray)) {    
            foreach ($this->linkArray as $id => $values) {
                
                $existingLink = $this->db->links->findOne(array('_id' => $id));
                if (isset($existingLink)) {
                    $this->db->links->update(array('_id' => $id), array('$addToSet' => array('links' => $values)));
                } else {
                    $data = array();
                    $data['_id'] = $id;
                    $data['links'] = array($values);
                    $this->db->links->save($data);
                }  
            }
            $this->linkArray = array();
        }
        
    	if (!isset($this->bufferedRecords)) {
    		return;
    	}
  
    	foreach ($this->bufferedRecords as $record) {
    		$this->db->record->save($record);
    	}
    	
    	$this->bufferedRecords = array();
    }
    
    /**
     * adds missing UP and DN links to loaded records.
     */
    public function completeLinks($oaiID = '') 
    {
        $linkCount = 0;
        $recordCount = 0;
        
        $links = $this->db->links->find(); 
        foreach ($links as $id => $values) {
            $dbRecord = $this->db->record->findOne(array('_id' => $id));
            if (isset($dbRecord)) {
                $recordContent = MetadataUtils::getRecordData($dbRecord, true);
                $metadataRecord = RecordFactory::createRecord($this->format, $recordContent, $oaiID, $this->sourceId);
         
                foreach ($values['links'][0] as $linkData) { 
                    $metadataRecord->addHierarchyLink($linkData['direction'], $linkData['target']);
                    $linkCount++;
                }
                $originalData = $metadataRecord->serialize();
                $metadataRecord->normalize();
                $normalizedData = $metadataRecord->serialize();
                
                $dbRecord['date'] = $dbRecord['updated'];
                
                  if ($normalizedData) {
                    if ($originalData == $normalizedData) {
                        $normalizedData = '';
                    };
                }
                if ($this->compressedRecords) {
                    $originalData = new MongoBinData(gzdeflate($originalData), 2);
                    if ($normalizedData) {
                        $normalizedData = new MongoBinData(gzdeflate($normalizedData), 2);
                    }
                }

                $dbRecord['original_data'] = $originalData;
                $dbRecord['normalized_data'] = $normalizedData;

                $dbRecord['update_needed'] = true;
                $dbRecord['updated'] = new MongoDate();
                $this->db->record->save($dbRecord);
                $recordCount++;
            }
        }
        $this->log->log('completeRecords', "$linkCount links successfully completed in $recordCount records", Logger::INFO);
        $this->db->links->remove();
    }
    
    /**
     * updates deduplication keys for stored records
     * @param $source
     */
    public function updateDedupKeys($source) 
    {
        $this->loadSourceSettings($source);
        $count = 0;
        $buffer = array();
        $records = $this->db->record->find(array('source_id' => $source));
        foreach ($records as $record) {
            $content = MetadataUtils::getRecordData($record, true);
            $metadataRecord = RecordFactory::createRecord($this->format, $content, '', $this->sourceId);
            $this->updateDedupCandidateKeys($record, $metadataRecord);
            
            $record['update_needed'] = true;
            $record['updated'] = new MongoDate();
            unset($record['dedup_id']);
            $buffer[] = $record;
            if (count($buffer) > 100) {
                $this->db->record->save($buffer);
                $buffer = array();
            }
            if (++$count % 1000 == 0) {
                print($count . ' updated' . PHP_EOL);
            }
        }
        $this->db->record->save($buffer);
    }

    /**
     * outputs solr array of records into file
     * @param $source source of records
     * @param $file   filename for output
     * @param $max    maximum number of records on output
     * @param $id     id of specific record, only one record is on output if $id != null
     * @return void
     */
    public function dummyUpdate($source, $file="out.txt", $max=10, $id=null)
    {
        $params = $id == null ? array('source_id' => $source) : array('_id' => $id);
        $records = $this->db->record->find($params);
        $records->immortal(true);
        $count = 0;
        $fout = fopen($file,'w');
        foreach ($records as $record) {
            $metadataRecord = RecordFactory::createRecord($record['format'], MetadataUtils::getRecordData($record, true), $record['oai_id'], $record['source_id']);
            fwrite($fout , print_r($metadataRecord->toSolrArray(), true));
            if (++$count >= $max) break;
        }
    }
    
    /**
     * decides whether two formats match
     * @param mixed $origFormat
     * @param mixed $cFormat
     * @return boolean
     */
    protected function matchFormats($origFormat, $cFormat)
    {  
        $first = is_array($origFormat) ? $origFormat : array($origFormat);
        $second = is_array($cFormat) ? $cFormat : array($cFormat);
        
        $origFormat = array_diff($origFormat, array(VnfMarcRecord::VNF_UNSPEC, VnfMarcRecord::VNF_ALBUM));
        $origFormat = array_diff($origFormat, array(VnfMarcRecord::VNF_UNSPEC, VnfMarcRecord::VNF_ALBUM));

        //VNF dont't match tracks
        if (in_array(VnfMarcRecord::VNF_TRACK, $cFormat) || in_array(VnfMarcRecord::VNF_TRACK, $origFormat)) {
            return false;
        }
        
        //skipped matching througn solrUpdater
        $intersect = array_uintersect($origFormat, $cFormat, 'strcasecmp'); 
        //either both or any are in brail
        return count($intersect) > 0 && in_array('vnf_books_in_braill', $first) === in_array('vnf_books_in_braill', $second);
    }
}
