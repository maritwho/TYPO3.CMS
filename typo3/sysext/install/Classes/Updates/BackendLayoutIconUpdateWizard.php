<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Install\Updates;

/**
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
use Doctrine\DBAL\DBALException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Upgrade wizard which goes through all files referenced in backend_layout.icon
 * and creates sys_file records as well as sys_file_reference records for each hit.
 */
class BackendLayoutIconUpdateWizard extends AbstractUpdate
{
    /**
     * @var string
     */
    protected $title = 'Migrate all file relations from backend_layout.icon to sys_file_references';

    /**
     * @var ResourceStorage
     */
    protected $storage;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * Table to migrate records from
     *
     * @var string
     */
    protected $table = 'backend_layout';

    /**
     * Table field holding the migration to be
     *
     * @var string
     */
    protected $fieldToMigrate = 'icon';

    /**
     * the source file resides here
     *
     * @var string
     */
    protected $sourcePath = 'uploads/media/';

    /**
     * target folder after migration
     * Relative to fileadmin
     *
     * @var string
     */
    protected $targetPath = '_migrated/backend_layouts/';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    /**
     * Checks if an update is needed
     *
     * @param string &$description The description for the update
     *
     * @return bool TRUE if an update is needed, FALSE otherwise
     */
    public function checkForUpdate(&$description)
    {
        if ($this->isWizardDone()) {
            return false;
        }

        // If there are no valid records, the wizard can be marked as done directly
        $dbQueries = [];
        $records = $this->getRecordsFromTable($dbQueries);
        if (empty($records)) {
            $this->markWizardAsDone();
            return false;
        }

        $description = 'This update wizard goes through all files that are referenced in the backend_layout.icon field'
            . ' and adds the files to the FAL File Index.<br />'
            . 'It also moves the files from uploads/ to the fileadmin/_migrated/ path.';

        return true;
    }

    /**
     * Performs the database update.
     *
     * @param array &$dbQueries Queries done in this update
     * @param string &$customMessage Custom message
     * @return bool TRUE on success, FALSE on error
     */
    public function performUpdate(array &$dbQueries, &$customMessage)
    {
        $customMessage = '';
        try {
            $storages = GeneralUtility::makeInstance(StorageRepository::class)->findAll();
            $this->storage = $storages[0];

            $records = $this->getRecordsFromTable($dbQueries);
            foreach ($records as $record) {
                $this->migrateField($record, $customMessage, $dbQueries);
            }

            $this->markWizardAsDone();
        } catch (\Exception $e) {
            $customMessage .= PHP_EOL . $e->getMessage();
        }

        return empty($customMessage);
    }

    /**
     * Get records from table where the field to migrate is not empty (NOT NULL and != '')
     * and also not numeric (which means that it is migrated)
     *
     * @param array $dbQueries
     *
     * @return array
     * @throws \RuntimeException
     */
    protected function getRecordsFromTable(&$dbQueries)
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll();

        try {
            $result = $queryBuilder
                ->select('uid', 'pid', $this->fieldToMigrate)
                ->from($this->table)
                ->where(
                    $queryBuilder->expr()->isNotNull($this->fieldToMigrate),
                    $queryBuilder->expr()->neq(
                        $this->fieldToMigrate,
                        $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->comparison(
                        'CAST(CAST(' . $queryBuilder->quoteIdentifier($this->fieldToMigrate) . ' AS DECIMAL) AS CHAR)',
                        ExpressionBuilder::NEQ,
                        'CAST(' . $queryBuilder->quoteIdentifier($this->fieldToMigrate) . ' AS CHAR)'
                    )
                )
                ->orderBy('uid')
                ->execute();

            $dbQueries[] = $queryBuilder->getSQL();

            return $result->fetchAll();
        } catch (DBALException $e) {
            throw new \RuntimeException(
                'Database query failed. Error was: ' . $e->getPrevious()->getMessage(),
                1511950673
            );
        }
    }

    /**
     * Migrates a single field.
     *
     * @param array $row
     * @param string $customMessage
     * @param array $dbQueries
     *
     * @throws \Exception
     */
    protected function migrateField($row, &$customMessage, &$dbQueries)
    {
        $fieldItems = GeneralUtility::trimExplode(',', $row[$this->fieldToMigrate], true);
        if (empty($fieldItems) || is_numeric($row[$this->fieldToMigrate])) {
            return;
        }
        $fileadminDirectory = rtrim($GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'], '/') . '/';
        $i = 0;

        $storageUid = (int)$this->storage->getUid();
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        foreach ($fieldItems as $item) {
            $fileUid = null;
            $sourcePath = PATH_site . $this->sourcePath . $item;
            $targetDirectory = PATH_site . $fileadminDirectory . $this->targetPath;
            $targetPath = $targetDirectory . basename($item);

            // maybe the file was already moved, so check if the original file still exists
            if (file_exists($sourcePath)) {
                if (!is_dir($targetDirectory)) {
                    GeneralUtility::mkdir_deep($targetDirectory);
                }

                // see if the file already exists in the storage
                $fileSha1 = sha1_file($sourcePath);

                $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_file');
                $queryBuilder->getRestrictions()->removeAll();
                $existingFileRecord = $queryBuilder->select('uid')->from('sys_file')->where(
                    $queryBuilder->expr()->eq(
                        'sha1',
                        $queryBuilder->createNamedParameter($fileSha1, \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->eq(
                        'storage',
                        $queryBuilder->createNamedParameter($storageUid, \PDO::PARAM_INT)
                    )
                )->execute()->fetch();

                // the file exists, the file does not have to be moved again
                if (is_array($existingFileRecord)) {
                    $fileUid = $existingFileRecord['uid'];
                } else {
                    // just move the file (no duplicate)
                    rename($sourcePath, $targetPath);
                }
            }

            if ($fileUid === null) {
                // get the File object if it hasn't been fetched before
                try {
                    // if the source file does not exist, we should just continue, but leave a message in the docs;
                    // ideally, the user would be informed after the update as well.
                    /** @var File $file */
                    $file = $this->storage->getFile($this->targetPath . $item);
                    $fileUid = $file->getUid();
                } catch (\InvalidArgumentException $e) {

                    // no file found, no reference can be set
                    $this->logger->notice(
                        'File ' . $this->sourcePath . $item . ' does not exist. Reference was not migrated.',
                        [
                            'table' => $this->table,
                            'record' => $row,
                            'field' => $this->fieldToMigrate,
                        ]
                    );

                    $format = 'File \'%s\' does not exist. Referencing field: %s.%d.%s. The reference was not migrated.';
                    $message = sprintf(
                        $format,
                        $this->sourcePath . $item,
                        $this->table,
                        $row['uid'],
                        $this->fieldToMigrate
                    );
                    $customMessage .= PHP_EOL . $message;
                    continue;
                }
            }

            if ($fileUid > 0) {
                $fields = [
                    'fieldname' => $this->fieldToMigrate,
                    'table_local' => 'sys_file',
                    'pid' => $this->table === 'pages' ? $row['uid'] : $row['pid'],
                    'uid_foreign' => $row['uid'],
                    'uid_local' => $fileUid,
                    'tablenames' => $this->table,
                    'crdate' => time(),
                    'tstamp' => time(),
                    'sorting' => $i + 256,
                    'sorting_foreign' => $i,
                ];

                $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_file_reference');
                $queryBuilder->insert('sys_file_reference')->values($fields)->execute();
                $dbQueries[] = str_replace(LF, ' ', $queryBuilder->getSQL());
                ++$i;
            }
        }

        // Update referencing table's original field to now contain the count of references,
        // but only if all new references could be set
        if ($i === count($fieldItems)) {
            $queryBuilder = $connectionPool->getQueryBuilderForTable($this->table);
            $queryBuilder->update($this->table)->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($row['uid'], \PDO::PARAM_INT)
                )
            )->set($this->fieldToMigrate, $i)->execute();
            $dbQueries[] = str_replace(LF, ' ', $queryBuilder->getSQL());
        }
    }
}
