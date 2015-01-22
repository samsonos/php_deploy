<?php
namespace samsonphp\deploy;

use samson\core\Service;

/**
 * SamsonPHP deployment service
 *
 * @package samsonphp\deploy
 * @author Vitaly Iegorov <egorov@samsonos.com>
 */
class Deploy extends Service
{
    /** Идентификатор модуля */
    protected $id = 'deploy';

    /** @var resource Remote connection  */
    protected $ftp;

    /** @var array Collection of path names to be ignored */
    public $ignorePath = array('cms');

    /** Path to site document root on local server */
    public $sourceroot = '';

    /** FTP host */
    public $host 	= '';

    /** Path to site document root on remote server */
    public $wwwroot	= '';

    /** FTP username */
    public $username= '';

    /** FTP password */
    public $password= '';

    /**
     * Generic log function for further modification
     * @param string $message
     * @return mixed
     */
    protected function log($message)
    {
        // Get passed vars
        $vars = func_get_args();
        // Remove first message var
        array_shift($vars);

        // Render debug message
        return trace(debug_parse_markers($message, $vars));
    }

    /**
     * Create remote directory and get into it
     * @param $path
     */
    protected function mkDir($path)
    {
        // Try get into this dir, maybe it already there
        if (!@ftp_chdir($this->ftp, $path)) {
            // Create dir
            ftp_mkdir($this->ftp, $path);
            // Change rights
            ftp_chmod($this->ftp, 0755, $path);
            // Go to it
            ftp_chdir($this->ftp, $path);
        }
    }

    /**
     * Get all entries in $path
     * @param string $path Folder path for listing
     * @return array Collection of entries int folder
     */
    protected function directoryFiles($path)
    {
        $result = array();
        // Get all entries in path
        foreach (array_diff(scandir($path), array_merge($this->ignorePath, array('..', '.'))) as $entry) {
            // Build full REAL path to entry
            $result[] = realpath($path . '/' . $entry);
        }
        return $result;
    }

    /**
     * Compare local file with remote file
     * @param string $fullPath Full local file path
     * @param int $diff Time difference between computers
     * @param int $maxAge File maximum possible age
     * @return bool True if file is old and must be updated
     */
    protected function isOld($fullPath, $diff = 0, $maxAge = 1)
    {
        // Read ftp file modification time and count age of file and check if it is valid
        return (filemtime($fullPath) - (ftp_mdtm($this->ftp, basename($fullPath)) + $diff)) > $maxAge;
    }

    /**
     * Perform synchronizing folder via FTP connection
     * @param string 	$path       Local path for synchronizing
     * @param integer 	$diff	Timestamp for analyzing changes
     */
    protected function synchronize($path, $diff = 0)
    {
        $this->log('Synchronizing remote folder [##][##]', $path, $diff);

        // Check if we can read this path
        foreach ($this->directoryFiles($path) as $fullPath) {
            $fileName = basename($fullPath);
            // If this is a folder
            if (is_dir($fullPath)) {
                // Try to create it
                $this->mkDir($fileName);

                // Go deeper in recursion
                $this->synchronize($fullPath, $diff);
            } elseif ($this->isOld($fullPath, $diff)) { // Check if file has to be updated
                $this->log('Uploading file [##]', $fullPath);

                // Copy file to remote
                if (ftp_put($this->ftp, $fileName, $fullPath, FTP_BINARY)) {
                    // Change rights
                    ftp_chmod($this->ftp, 0755, $fileName);

                    $this->log('-- Success [##]', $fullPath);
                } else {
                    $this->log('-- Failed [##]', $fullPath);
                }
            }
        }

        // Go one level up
        ftp_cdup($this->ftp);
    }

    /**
     * Get time difference between servers
     * @param string $tsFileName TS file name(timezone.dat)
     * @return float|int Time difference between servers
     */
    protected function getTimeDifference($tsFileName = 'timezone.dat')
    {
        $diff = 0;

        // Cache local path
        $localPath = $this->sourceroot.'/'.$tsFileName;

        // Create local timestamp
        file_put_contents($localPath, '1', 0755);

        // Copy file to remote
        if (ftp_put($this->ftp, 'timezone.dat', $localPath, FTP_ASCII)) {
            // Get difference
            $diff = abs(filemtime($localPath) - ftp_mdtm($this->ftp, $tsFileName));

            // Convert to hours
            $diff = floor($diff / 3600) * 3600 + $diff % 3600;

            ftp_delete($this->ftp, $tsFileName);
        }

        // Remover
        unlink($localPath);

        return $diff;
    }

    /**
     * Try to write to remote
     * @return bool True if we can write to remote
     */
    protected function isWritable()
    {
        // Create temp file
        $path = tempnam(sys_get_temp_dir(), 'test');

        // Copy file to remote
        if (ftp_put($this->ftp, 'timezone.dat', $path, FTP_ASCII)) {
            return true;
        }

        $this->log('Remote path [##] is not writable', $this->wwwroot);

        return false;
    }

    /**
     * Initialize module
     * @param array $params
     * @return bool
     */
    public function init(array $params = array())
    {
        // Check configuration
        if (!isset($this->sourceroot{0})) {
            $this->log('Local folder[##] is not specified', $this->sourceroot);
            return false;
        }

        // Check configuration
        if (!isset($this->sourceroot{0})) {
            $this->log('Remote folder[##] is not specified', $this->wwwroot);
            return false;
        }

        return parent::init($params);
    }

    /**
     * Connect to remote
     * @return bool True if we have successfully connected
     */
    protected function connect()
    {
        // Установим ограничение на выполнение скрипта
        ini_set('max_execution_time', 120);

        // Connect to remote
        $this->ftp = ftp_connect($this->host);

        // Login
        if (false !== ftp_login($this->ftp, $this->username, $this->password)) {
            // Switch to passive mode
            ftp_pasv($this->ftp, true);

            // Go to root folder
            if ($this->isWritable() && ftp_chdir($this->ftp, $this->wwwroot)) {
                return true;
            } else {
                $this->log('Remote folder[##] not found', $this->wwwroot);

                return false;
            }
        }

        $this->log('Cannot login to remote server [##@##]', $this->username, $this->host);

        return false;
    }


    /** Controller to perform deploy routine */
    public function __BASE()
    {
        $this->title('Deploying project to '.$this->host);

        s()->async(true);

        // Connect to remote
        if ($this->connect()) {
            // If this is remote app - chdir to it
            if (__SAMSON_REMOTE_APP) {
                $base = str_replace('/', '', __SAMSON_BASE__);

                // Create folder
                $this->mkDir($base);
            }

            // Установим правильный путь к директории на сервере
            $this->wwwroot = ftp_pwd($this->ftp);

            $this->log('Entered remote folder [##]', $this->wwwroot);

            // Выполним синхронизацию папок
            $this->synchronize(
                $this->sourceroot,
                $this->getTimeDifference()
            );

            $this->log('Congratulations! Project[##] has been successfully deployed to [##]', $this->sourceroot, $this->host);
        }

        // close the connection
        return ftp_close($this->ftp);
    }
}
