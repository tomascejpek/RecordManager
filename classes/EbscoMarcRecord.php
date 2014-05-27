<?php

require_once 'PortalMarc/MappablePortalMarcRecord.php';

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
class EbscoMarcRecord extends MappablePortalMarcRecord
{

    public function getID()
    {
        return $this->getField('001');
    }

}
