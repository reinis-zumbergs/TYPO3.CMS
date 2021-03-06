<?php
namespace TYPO3\CMS\Core\Resource\Index;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;

/**
 * Repository Class as an abstraction layer to sys_file
 *
 * Every access to table sys_file_metadata which is not handled by TCEmain
 * has to use this Repository class.
 *
 * This is meant for FAL internal use only!.
 */
class FileIndexRepository implements SingletonInterface
{
    /**
     * @var string
     */
    protected $table = 'sys_file';

    /**
     * A list of properties which are to be persisted
     *
     * @var array
     */
    protected $fields = array(
        'uid', 'pid', 'missing', 'type', 'storage', 'identifier', 'identifier_hash', 'extension',
        'mime_type', 'name', 'sha1', 'size', 'creation_date', 'modification_date', 'folder_hash'
    );

    /**
     * Gets database instance
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * Gets the Resource Factory
     *
     * @return ResourceFactory
     */
    protected function getResourceFactory()
    {
        return ResourceFactory::getInstance();
    }

    /**
     * Returns an Instance of the Repository
     *
     * @return FileIndexRepository
     */
    public static function getInstance()
    {
        return GeneralUtility::makeInstance(FileIndexRepository::class);
    }

    /**
     * Retrieves Index record for a given $combinedIdentifier
     *
     * @param string $combinedIdentifier
     * @return array|bool
     */
    public function findOneByCombinedIdentifier($combinedIdentifier)
    {
        list($storageUid, $identifier) = GeneralUtility::trimExplode(':', $combinedIdentifier, false, 2);
        return $this->findOneByStorageUidAndIdentifier($storageUid, $identifier);
    }

    /**
     * Retrieves Index record for a given $fileUid
     *
     * @param int $fileUid
     * @return array|bool
     */
    public function findOneByUid($fileUid)
    {
        $row = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
            implode(',', $this->fields),
            $this->table,
            'uid=' . (int)$fileUid
        );
        return is_array($row) ? $row : false;
    }

    /**
     * Retrieves Index record for a given $storageUid and $identifier
     *
     * @param int $storageUid
     * @param string $identifier
     * @return array|bool
     *
     * @internal only for use from FileRepository
     */
    public function findOneByStorageUidAndIdentifier($storageUid, $identifier)
    {
        $identifierHash = $this->getResourceFactory()->getStorageObject($storageUid)->hashFileIdentifier($identifier);
        return $this->findOneByStorageUidAndIdentifierHash($storageUid, $identifierHash);
    }

    /**
     * Retrieves Index record for a given $storageUid and $identifier
     *
     * @param int $storageUid
     * @param string $identifierHash
     * @return array|bool
     *
     * @internal only for use from FileRepository
     */
    public function findOneByStorageUidAndIdentifierHash($storageUid, $identifierHash)
    {
        $row = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
            implode(',', $this->fields),
            $this->table,
            sprintf('storage=%u AND identifier_hash=%s', (int)$storageUid, $this->getDatabaseConnection()->fullQuoteStr($identifierHash, $this->table))
        );
        return is_array($row) ? $row : false;
    }

    /**
     * Retrieves Index record for a given $fileObject
     *
     * @param FileInterface $fileObject
     * @return array|bool
     *
     * @internal only for use from FileRepository
     */
    public function findOneByFileObject(FileInterface $fileObject)
    {
        $storageUid = $fileObject->getStorage()->getUid();
        $identifierHash = $fileObject->getHashedIdentifier();
        return $this->findOneByStorageUidAndIdentifierHash($storageUid, $identifierHash);
    }

    /**
     * Returns all indexed files which match the content hash
     * Used by the indexer to detect already present files
     *
     * @param string $hash
     * @return mixed
     */
    public function findByContentHash($hash)
    {
        if (!preg_match('/^[0-9a-f]{40}$/i', $hash)) {
            return array();
        }
        $resultRows = $this->getDatabaseConnection()->exec_SELECTgetRows(
            implode(',', $this->fields),
            $this->table,
            'sha1=' . $this->getDatabaseConnection()->fullQuoteStr($hash, $this->table)
        );
        return $resultRows;
    }

    /**
     * Find all records for files in a Folder
     *
     * @param Folder $folder
     * @return array|NULL
     */
    public function findByFolder(Folder $folder)
    {
        $resultRows = $this->getDatabaseConnection()->exec_SELECTgetRows(
            implode(',', $this->fields),
            $this->table,
            'folder_hash = ' . $this->getDatabaseConnection()->fullQuoteStr($folder->getHashedIdentifier(), $this->table) .
                ' AND storage  = ' . (int)$folder->getStorage()->getUid(),
            '',
            '',
            '',
            'identifier'
        );
        return $resultRows;
    }

    /**
     * Find all records for files in an array of Folders
     *
     * @param Folder[] $folders
     * @param bool $includeMissing
     * @param string $fileName
     * @return array|NULL
     */
    public function findByFolders(array $folders, $includeMissing = true, $fileName = null)
    {
        $storageUids = [];
        $folderIdentifiers = [];

        foreach ($folders as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }

            $storageUids[] = (int)$folder->getStorage()->getUid();
            $folderIdentifiers[] = $folder->getHashedIdentifier();
        }
        $storageUids = array_unique($storageUids);
        $folderIdentifiers = array_unique($folderIdentifiers);

        $fileRecords = $this->getDatabaseConnection()->exec_SELECTgetRows(
            implode(',', $this->fields),
            $this->table,
            'folder_hash IN ( ' . implode(',', $this->getDatabaseConnection()->fullQuoteArray($folderIdentifiers, $this->table)) . ')' .
            ' AND storage IN (' . implode(',', $storageUids) . ')' .
            (isset($fileName) ? ' AND name LIKE "%' . $this->getDatabaseConnection()->escapeStrForLike($this->getDatabaseConnection()->quoteStr($fileName, $this->table), $this->table) . '%"' : '') .
            ($includeMissing ? '' : ' AND missing = 0'),
            '',
            '',
            '',
            'identifier'
        );

        return $fileRecords;
    }

    /**
     * Adds a file to the index
     *
     * @param File $file
     * @return void
     */
    public function add(File $file)
    {
        if ($this->hasIndexRecord($file)) {
            $this->update($file);
            if ($file->_getPropertyRaw('uid') === null) {
                $file->updateProperties($this->findOneByFileObject($file));
            }
        } else {
            $file->updateProperties(array('uid' => $this->insertRecord($file->getProperties())));
        }
    }

    /**
     * Add data from record (at indexing time)
     *
     * @param array $data
     * @return array
     */
    public function addRaw(array $data)
    {
        $data['uid'] = $this->insertRecord($data);
        return $data;
    }

    /**
     * Helper to reduce code duplication
     *
     * @param array $data
     *
     * @return int
     */
    protected function insertRecord(array $data)
    {
        $data = array_intersect_key($data, array_flip($this->fields));
        $data['tstamp'] = time();
        $this->getDatabaseConnection()->exec_INSERTquery($this->table, $data);
        $data['uid'] = $this->getDatabaseConnection()->sql_insert_id();
        $this->updateRefIndex($data['uid']);
        $this->emitRecordCreatedSignal($data);
        return $data['uid'];
    }
    /**
     * Checks if a file is indexed
     *
     * @param File $file
     * @return bool
     */
    public function hasIndexRecord(File $file)
    {
        return $this->getDatabaseConnection()->exec_SELECTcountRows('uid', $this->table, $this->getWhereClauseForFile($file)) >= 1;
    }

    /**
     * Updates the index record in the database
     *
     * @param File $file
     * @return void
     */
    public function update(File $file)
    {
        $updatedProperties = array_intersect($this->fields, $file->getUpdatedProperties());
        $updateRow = array();
        foreach ($updatedProperties as $key) {
            $updateRow[$key] = $file->getProperty($key);
        }
        if (!empty($updateRow)) {
            $updateRow['tstamp'] = time();
            $this->getDatabaseConnection()->exec_UPDATEquery($this->table, $this->getWhereClauseForFile($file), $updateRow);
            $this->updateRefIndex($file->getUid());
            $this->emitRecordUpdatedSignal(array_intersect_key($file->getProperties(), array_flip($this->fields)));
        }
    }

    /**
     * Finds the files needed for second indexer step
     *
     * @param ResourceStorage $storage
     * @param int $limit
     * @return array
     */
    public function findInStorageWithIndexOutstanding(ResourceStorage $storage, $limit = -1)
    {
        return $this->getDatabaseConnection()->exec_SELECTgetRows(
            implode(',', $this->fields),
            $this->table,
            'tstamp > last_indexed AND storage = ' . (int)$storage->getUid(),
            '',
            'tstamp ASC',
            (int)$limit > 0 ? (int)$limit : ''
        );
    }

    /**
     * Helper function for the Indexer to detect missing files
     *
     * @param ResourceStorage $storage
     * @param array $uidList
     * @return array
     */
    public function findInStorageAndNotInUidList(ResourceStorage $storage, array $uidList)
    {
        $where = 'storage = ' . (int)$storage->getUid();
        if (!empty($uidList)) {
            $where .= ' AND uid NOT IN (' . implode(',', $this->getDatabaseConnection()->cleanIntArray($uidList)) . ')';
        }
        return $this->getDatabaseConnection()->exec_SELECTgetRows(implode(',', $this->fields), $this->table, $where);
    }

    /**
     * Updates the timestamp when the file indexer extracted metadata
     *
     * @param int $fileUid
     * @return void
     */
    public function updateIndexingTime($fileUid)
    {
        $this->getDatabaseConnection()->exec_UPDATEquery($this->table, 'uid = ' . (int)$fileUid, array('last_indexed' => time()));
    }

    /**
     * Marks given file as missing in sys_file
     *
     * @param int $fileUid
     * @return void
     */
    public function markFileAsMissing($fileUid)
    {
        $this->getDatabaseConnection()->exec_UPDATEquery($this->table, 'uid = ' . (int)$fileUid, array('missing' => 1));
        $this->emitRecordMarkedAsMissingSignal($fileUid);
    }

    /**
     * Returns a where clause to find a file in database
     *
     * @param File $file
     *
     * @return string
     */
    protected function getWhereClauseForFile(File $file)
    {
        if ((int)$file->_getPropertyRaw('uid') > 0) {
            $where = 'uid=' . (int)$file->getUid();
        } else {
            $where = sprintf(
                'storage=%u AND identifier LIKE %s',
                (int)$file->getStorage()->getUid(),
                $this->getDatabaseConnection()->fullQuoteStr($file->_getPropertyRaw('identifier'), $this->table)
            );
        }
        return $where;
    }

    /**
     * Remove a sys_file record from the database
     *
     * @param int $fileUid
     * @return void
     */
    public function remove($fileUid)
    {
        $this->getDatabaseConnection()->exec_DELETEquery($this->table, 'uid=' . (int)$fileUid);
        $this->updateRefIndex($fileUid);
        $this->emitRecordDeletedSignal($fileUid);
    }

    /**
     * Update Reference Index (sys_refindex) for a file
     *
     * @param int $id Record UID
     * @return void
     */
    public function updateRefIndex($id)
    {
        /** @var $refIndexObj ReferenceIndex */
        $refIndexObj = GeneralUtility::makeInstance(ReferenceIndex::class);
        $refIndexObj->updateRefIndexTable($this->table, $id);
    }

    /*
     * Get the SignalSlot dispatcher
     *
     * @return Dispatcher
     */
    protected function getSignalSlotDispatcher()
    {
        return $this->getObjectManager()->get(Dispatcher::class);
    }

    /**
     * Get the ObjectManager
     *
     * @return ObjectManager
     */
    protected function getObjectManager()
    {
        return GeneralUtility::makeInstance(ObjectManager::class);
    }

    /**
     * Signal that is called after an IndexRecord is updated
     *
     * @param array $data
     * @signal
     */
    protected function emitRecordUpdatedSignal(array $data)
    {
        $this->getSignalSlotDispatcher()->dispatch(FileIndexRepository::class, 'recordUpdated', array($data));
    }

    /**
     * Signal that is called after an IndexRecord is created
     *
     * @param array $data
     * @signal
     */
    protected function emitRecordCreatedSignal(array $data)
    {
        $this->getSignalSlotDispatcher()->dispatch(FileIndexRepository::class, 'recordCreated', array($data));
    }

    /**
     * Signal that is called after an IndexRecord is deleted
     *
     * @param int $fileUid
     * @signal
     */
    protected function emitRecordDeletedSignal($fileUid)
    {
        $this->getSignalSlotDispatcher()->dispatch(FileIndexRepository::class, 'recordDeleted', array($fileUid));
    }

    /**
     * Signal that is called after an IndexRecord is marked as missing
     *
     * @param int $fileUid
     * @signal
     */
    protected function emitRecordMarkedAsMissingSignal($fileUid)
    {
        $this->getSignalSlotDispatcher()->dispatch(FileIndexRepository::class, 'recordMarkedAsMissing', array($fileUid));
    }
}
