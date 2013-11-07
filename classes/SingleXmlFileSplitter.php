<?
/**
 * simple FileSplitter implementation for signle XML marc record in file
 * @author Michal Merta
 *
 */
class SingleXmlFileSplitter extends FileSplitter {

    protected $readOnce = false;
    protected $data;

    function __construct($data, $recordXPath, $oaiIDXPath) {
        parent::__construct($data, $recordXPath, $oaiIDXPath);
        $this->data=$data;
    }

    /**
     * allows read file only one
     * @see FileSplitter::getEOF()
     */
    public function getEOF() {
          if ($this->readOnce == false) {
              $this->readOnce = true;
              return false;
          }
          return $this->readOnce;
    }
    
    /**
     * returns content of XML file 
     * @see FileSplitter::getNextRecord()
     */
    public function getNextRecord($oaiID) {
          return $this->data;
    }
}
?>
