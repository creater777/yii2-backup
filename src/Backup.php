<?php

namespace creater777\backup;

use creater777\backup\archive\Archive;
use creater777\backup\archive\Bzip2;
use creater777\backup\archive\Gzip;
use creater777\backup\archive\Tar;
use creater777\backup\archive\Zip;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;

/**
 * Backup component
 *
 * @author Alonso Mora <adelfunscr@gmail.com>
 * @since 1.0
 */
class Backup extends Component
{

    /** @var integer Minimum age in seconds for valid backup. */
    const EXPIRE_TIME_MIN = 86400;

    /** @var integer Maximum age in seconds for valid backup. */
    const EXPIRE_TIME_MAX = 31536000;

    /** @var integer Number of seconds of a day. */
    const DURATION_TIME_DAY = 86400;

    /** @var integer Number of seconds of a week. */
    const DURATION_TIME_WEEK = 604800;

    /** @var integer Number of seconds of a month (30 days). */
    const DURATION_TIME_MONTH = 2592000;

    /** @var integer Number of seconds of a year (365 days). */
    const DURATION_TIME_YEAR = 31536000;

    /** @var string Pattern for backup names. */
    const FILE_NAME_FORMAT = '%sT%s_%s';

    /** @var string Path/Alias to folder for backups storing. */
    public $backupDir = '';

    /**
     * Number of seconds after which the file is considered deprecated and
     * will be deleted during clean up.
     * Default value is 86400 secs (1 day)
     *
     * @var int
     */
    public $expireTime = self::DURATION_TIME_DAY;

    /**
     * List of files or directories to include in backup.
     * Format: <inner backup filename> => <path/to/dir>
     *
     * @var array
     */
    public $directories = [];

    /**
     * List of directories to exclude from backup.
     * Format: <path/to/dir>
     *
     * @var array
     */
    public $excludeDirectories = [];

    /** @var array List of files to ignore in backup. */
    public $skipFiles = [];

    /** @var array List of databases connections to backup. */
    public $databases = ['db'];

    /** @var string Suffix for backup file. */
    public $fileName = 'backup';

    /** @var float $freeSpiceLimit check the free disk spice before create backup. 0 is dont check*/
    public $freeSpiceLimit = 0;

    /**
     * Compression method to apply to backup file.
     * Available options:
     * 'none' or 'tar' for tar files, backup file is not compressed.
     * 'bzip2' for tar.bz2 files, backup file is compressed with Bzip2 compression.
     * 'gzip' for tar.gz files, backup file is compressed with Gzip compression.
     * 'zip' for zip files, backup file is compressed with Zip compression.
     *
     * @var string
     */
    public $compression = 'none';

    /** @var mixed The MySQL handler type and configuration. */
    public $mysqlHandler = [
        'class' => 'creater777\backup\db\Mysql'
    ];

    /** @var mixed The SQLite handler type and configuration. */
    public $sqliteHandler = [
        'class' => 'creater777\backup\db\Sqlite'
    ];

    /** @var mixed The PostgreSQL handler type and configuration. */
    public $postgreHandler = [
        'class' => 'creater777\backup\db\PostgreSQL'
    ];

    /** @var int Timestamp of the backup. */
    private $_backupTime;

    /** @var Archive Instance of archive class to handle the backup file. */
    private $_backup;

    public function __construct(array $config = [])
    {
        ini_set("max_execution_time",0);
        parent::__construct($config);
        $this->excludeDirectories = array_map(function($dir){
            return realpath(Yii::getAlias($dir));
        }, $this->excludeDirectories);
        $this->backupDir = realpath($this->backupDir);
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->_backupTime = time();
    }

    /**
     * Appends a whole directory to backup file
     *
     * @param string $name Directory name inside the backup
     * @param string $folder Full path of the directory to append
     * @return boolean True if directory was appended to backup file, false otherwise
     */
    public function backupFolder($name, $folder) {
        if (in_array(realpath($folder), $this->excludeDirectories)){
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder),
            \RecursiveDirectoryIterator::SKIP_DOTS |
            \RecursiveDirectoryIterator::CURRENT_AS_PATHNAME
        );
        foreach($files as $file) {
            if ($file->getFilename() === '.' || $file->getFilename() === '..'){
                continue;
            }
            if ($file->isDir()){
                $this->backupFolder($name.DIRECTORY_SEPARATOR.$file->getBasename(), $folder.DIRECTORY_SEPARATOR.$file->getBasename());
                continue;
            }
            $this->addFileToBackup($file->getPathname(), $name.DIRECTORY_SEPARATOR.$file->getFilename());
        }
    }

    /**
     * Creates dump of all directories and all databases and saves result to
     * backup folder with timestamp named file.
     *
     * @return string Full path to created backup file
     */
    public function create()
    {
        $this->validateSettings();
        $this->openArchive();
        $db=[];
        foreach ($this->databases as $database) {
            $db[] = $this->backupDatabase($database);
        }
        $this->excludeDirectories = array_map(function($dir){
            return Yii::getAlias($dir);
        }, $this->excludeDirectories);
        foreach ($this->directories as $name => $folder) {
            $this->backupFolder($name, realpath(Yii::getAlias($folder)));
        }
        $this->closeArchive();
        array_map('unlink', $db);
        return $this->_backup->getName();
    }

    /**
     * Restores files and databases from backup file.
     *
     * @param string $file Backup file to restore
     * It can be a full path or a file inside the backup folder.
     * @return boolean True if the file exists, false otherwise
     */
    public function restore($file)
    {
        $this->validateSettings();
        $localBackup = Yii::getAlias($this->backupDir) . DIRECTORY_SEPARATOR . $file;
        if (file_exists($file)) {
            $this->_backup = $this->openArchive($file);
        } else if (file_exists($localBackup)) {
            $this->_backup = $this->openArchive($localBackup);
        } else {
            return false;
        }

        foreach ($this->databases as $database) {
            $this->extractDatabase($database);
        }

        foreach ($this->directories as $name => $value) {
            $folder = Yii::getAlias($value);
            $this->extractFolder($name, $folder);
        }

        return true;
    }

    /**
     * Deletes backup files that are too old and returns number of obsolete and deleted files
     *
     * @return array Number of expired and deleted backup files
     */
    public function deleteDeprecated()
    {
        $this->validateSettings();
        $deletedFiles = [];
        $expiredFiles = $this->getExpiredFiles();
        foreach ($expiredFiles as $file) {
            if (@unlink($file)) {
                array_push($deletedFiles, $file);
            } else {
                Yii::error('Cannot delete backup file: ' . $file);
            }
        }

        return [
            'expiredFiles' => count($expiredFiles),
            'deletedFiles' => count($deletedFiles),
        ];
    }

    /**
     * Checks if the component configuration is valid
     *
     * @throws InvalidConfigException
     */
    protected function validateSettings()
    {
        $this->validateBackupDir();
        $this->validateFreeDiskSpice();
        $this->validateExpireTime();
        $this->validateFiles();
        $this->validateSkipFiles();
        $this->validateDatabases();
        $this->validateFileName();
        $this->validateCompression();
    }

    /**
     * Checks if property backupDir is valid
     *
     * @return boolean True if property value is valid
     * @throws InvalidConfigException if the property value is not valid
     */
    protected function validateBackupDir()
    {
        if (!is_string($this->backupDir)) {
            throw new InvalidConfigException('"' . get_class($this) . '::backupDir" should be string, "' . gettype($this->backupDir) . '" given.');
        } else if (empty($this->backupDir)) {
            throw new InvalidConfigException('"' . get_class($this) . '::backupDir" can not be empty"');
        }

        $backupDir = Yii::getAlias($this->backupDir);
        if (!file_exists($backupDir)) {
            throw new InvalidConfigException('"' . $this->backupDir . '" does not exists"');
        }
        if (!is_writable($backupDir)) {
            throw new InvalidConfigException('"' . $this->backupDir . '" is not writeable');
        }
        return true;
    }

    /**
     * Checks if property freeSpiceLimit
     * @return bool
     * @throws InvalidConfigException
     */
    protected function validateFreeDiskSpice(){
        if (disk_free_space($this->backupDir) < $this->freeSpiceLimit && $this->freeSpiceLimit !== 0){
            throw new InvalidConfigException('"' . get_class($this) . '::freeSpiceLimit" free disk spice is not allowed, "' . $this->freeSpiceLimit . '" given.');
        }
        return true;
    }

    /**
     * Checks if property expirteTime is valid
     *
     * @return boolean True if property value is valid
     * @throws InvalidConfigException if the property value is not valid
     */
    protected function validateExpireTime()
    {
        if (!is_int($this->expireTime)) {
            throw new InvalidConfigException('"' . get_class($this) . '::expireTime" should be integer, "' . gettype($this->expireTime) . '" given.');
        } else if (self::EXPIRE_TIME_MIN > $this->expireTime) {
            throw new InvalidConfigException('"' . get_class($this) . '::expireTime" should be at least ' . self::EXPIRE_TIME_MIN . ' seconds');
        } else if (self::EXPIRE_TIME_MAX < $this->expireTime) {
            throw new InvalidConfigException('"' . get_class($this) . '::expireTime" should be at most ' . self::EXPIRE_TIME_MAX . ' seconds');
        }
        return true;
    }

    /**
     * Checks if property directories is valid
     *
     * @return boolean True if property value is valid
     * @throws InvalidConfigException if the property value is not valid
     */
    protected function validateFiles()
    {
        if (!is_array($this->directories)) {
            throw new InvalidConfigException('"' . get_class($this) . '::directories" should be array, "' . gettype($this->directories) . '" given.');
        }
        return true;
    }

    /**
     * Checks if property skipfiles is valid
     *
     * @return boolean True if property value is valid
     * @throws InvalidConfigException if the property value is not valid
     */
    protected function validateSkipFiles()
    {
        if (!is_array($this->skipFiles)) {
            throw new InvalidConfigException('"' . get_class($this) . '::skipFiles" should be array, "' . gettype($this->skipFiles) . '" given.');
        }
        return true;
    }

    /**
     * Checks if property databases is valid
     *
     * @return boolean True if property value is valid
     * @throws InvalidConfigException if the property value is not valid
     */
    protected function validateDatabases()
    {
        if (!is_array($this->databases)) {
            throw new InvalidConfigException('"' . get_class($this) . '::databases" should be array, "' . gettype($this->databases) . '" given.');
        } else if (empty($this->databases)) {
            throw new InvalidConfigException('"' . get_class($this) . '::databases" can not be empty"');
        }
        return true;
    }

    /**
     * Checks if property fileName is valid
     *
     * @return boolean True if property value is valid
     * @throws InvalidConfigException if the property value is not valid
     */
    protected function validateFileName()
    {
        if (!is_string($this->fileName)) {
            throw new InvalidConfigException('"' . get_class($this) . '::fileName" should be string, "' . gettype($this->fileName) . '" given.');
        } else if (empty($this->fileName)) {
            throw new InvalidConfigException('"' . get_class($this) . '::fileName" can not be empty"');
        }
        return true;
    }

    /**
     * Checks if property compression is valid
     *
     * @return boolean True if property value is valid
     * @throws InvalidConfigException if the property value is not valid
     */
    protected function validateCompression()
    {
        if (!is_string($this->compression)) {
            throw new InvalidConfigException('"' . get_class($this) . '::compression" should be string, "' . gettype($this->fileName) . '" given.');
        } else if (empty($this->compression)) {
            throw new InvalidConfigException('"' . get_class($this) . '::compression" can not be empty"');
        } else if (!in_array($this->compression, ['none', 'tar', 'zip', 'gzip', 'bzip2'])) {
            throw new InvalidConfigException('"' . get_class($this) . '::compression" is not a valid option"');
        }
        return true;
    }

    /**
     * Gets a database instance according to used database driver of the connection
     *
     * @param string $db Name of database connection
     * @return mixed Database instance if driver is supported, null otherwise
     */
    protected function getDriver($db)
    {
        $handler = null;
        $driver = \Yii::$app->$db->driverName;
        switch ($driver) {
            case 'mysql':
                $handler = \Yii::createObject($this->mysqlHandler);
                break;
            case 'sqlite':
                $handler = \Yii::createObject($this->sqliteHandler);
                break;
            case 'pgsql':
                $handler = \Yii::createObject($this->postgreHandler);
                break;
            default:
                break;
        }
        return $handler;
    }

    /**
     * Inits the backup instance and creates the backup file.
     * It sets the path, name and list of ignored files of the archive instance.
     */
    protected function openArchive()
    {
        $name = sprintf(self::FILE_NAME_FORMAT, date('Y-m-d', $this->_backupTime), date('HisO', $this->_backupTime), $this->fileName);
        $config = [
            'overwrite'=>1,
            'level'=>3,
            'inmemory'=>0,
            'name' => $this->backupDir.DIRECTORY_SEPARATOR.$name.".".$this->compression,
            'type' => $this->compression
        ];
        switch ($this->compression) {
            case 'bzip2':
                $this->_backup = new Bzip2($config);
                break;
            case 'gzip':
                $this->_backup = new Gzip($config);
                break;
            case 'zip':
                $this->_backup = new Zip($config);
                break;
            case 'none':
            case 'tar':
            default:
                $this->_backup = new Tar($config);
                break;
        }
        $this->_backup->exclude = $this->skipFiles;
    }

    /**
     * Triggers the close action of the archive instance
     */
    protected function closeArchive()
    {
        $this->_backup->createArchive();
    }

    /**
     * Creates the database dump file and adds it to backup file
     *
     * @param string $db Name of database connection
     * @return boolean True if dump file was created and added to backup, false otherwise
     */
    protected function backupDatabase($db)
    {
        $dbDump = $this->getDriver($db);
        $file = $dbDump->dumpDatabase($db, $this->backupDir);
        if ($file !== false) {
            $this->addFileToBackup($file, 'sql'.DIRECTORY_SEPARATOR."$db.sql");
        }
        return $file;
    }

    /**
     * Appends a file to backup file
     *
     * @param string $name File name inside the backup
     * @param string $file Full path of the file to append
     * @return boolean True if file was appended to backup file, false otherwise
     */
    protected function addFileToBackup($file, $name)
    {
        return $this->_backup->addFiles($file, $name);
    }

    /**
     * Extracts database dump file from backup and imports data into the database
     * of the database connection.
     * The dump file must match the database connection name.
     *
     * @param string $db Connection name to use
     * @return boolean True if dump was imported, false otherwise
     */
    protected function extractDatabase($db)
    {
        $flag = true;
        $name = 'sql/' . $db . '.sql';
        $file = Yii::getAlias($this->backupDir) . DIRECTORY_SEPARATOR . $db . '.sql';

        if ($this->_backup->extractFileFromBackup($name, $file)) {
            $dbDump = $this->getDriver($db);
            $flag = $dbDump->importDatabase($db, $file);
            @unlink($file);
        } else {
            $flag = false;
        }

        return $flag;
    }

    /**
     * Extracts a directory from backup and restores it to a target location
     *
     * @param string $name Directory name to extract
     * @param string $folder Full path of target directory
     */
    protected function extractFolder($name, $folder)
    {
        $this->_backup->extractFolderFromBackup($name, $folder);
    }

    /**
     * Gets the list of expired/deprecated files in backup directory
     *
     * @return array List of expired/deprecated files in backup directory
     */
    protected function getExpiredFiles()
    {
        $backupsFolder = \Yii::getAlias($this->backupDir);
        $expireTimestamp = time() - $this->expireTime;
        $extensions = ['tar', 'bz2', 'gzip', 'zip'];

        $filter = function ($path) use ($expireTimestamp, $extensions) {
            $isFile = is_file($path);
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $lastUpdateTime = filemtime($path);

            if ($isFile && in_array($extension, $extensions) && $lastUpdateTime <= $expireTimestamp) {
                return true;
            }

            return false;
        };

        $files = FileHelper::findFiles($backupsFolder, [
            'recursive' => false,
            'filter' => $filter,
        ]);
        return $files;
    }

}