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

    /** PHP version on server */
    public $php_version = '5.3.0';

    /**
     * Get all entries in $path
     * @param string $path Folder path for listing
     * @return array Collection of entries int folder
     */
    protected function directoryFiles($path)
    {
        $result = array();
        // Get all entries in path
        foreach (array_diff(scandir($path), array('..', '.')) as $entry) {
            // Build full REAL path to entry
            $result[] = realpath($path . '/' . $entry);
        }
        return $result;
    }

    /**
     * Compare local file with remote file
     * @param resource $ftp FTP connection instance
     * @param string $fullPath Full local file path
     * @param int $diff Time difference between computers
     * @param int $maxAge File maximum possible age
     * @return bool True if file is old and must be updated
     */
    protected function isOld($ftp, $fullPath, $diff = 0, $maxAge = 1)
    {
        // Read ftp file modification time and count age of file and check if it is valid
        return (filemtime($fullPath) - (ftp_mdtm($ftp, basename($fullPath)) + $diff)) > $maxAge;
    }

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
     * Perform synchronizing folder via FTP connection
     * @param resource 	$ftp 		FTP connection instance
     * @param string 	$path       Local path for synchronizing
     * @param integer 	$diff	Timestamp for analyzing changes
     */
    protected function synchronize($ftp, $path, $diff = 0)
    {
        $this->log('Synchronizing remote folder [##][##]', $path, $diff);

        // Check if we can read this path
        foreach ($this->directoryFiles($path) as $fullPath) {
            $fileName = basename($fullPath);
            // If this is a file
            if (!$this->isDir($fullPath)) {
                // Check if file has to be updated
                if ($this->isOld($ftp, $fullPath, $diff)) {
                    $this->log('Uploading file [##]', $fullPath);

                    // Copy file to remote
                    if (ftp_put($ftp, $fileName, $fullPath, FTP_BINARY)) {
                        // Change rights
                        ftp_chmod($ftp, 0755, $fileName);

                        $this->log('-- Success [##]', $fullPath);
                    } else {
                        $this->log('-- Failed [##]', $fullPath);
                    }
                }
            } else { // If this is a folder - go deeper in recursion
                // Try get into this dir, maybe it already there
                if (!@ftp_chdir($ftp, $fileName)) {
                    // Create dir
                    ftp_mkdir($ftp, $fileName);
                    // Change rights
                    ftp_chmod($ftp, 0755, $fileName);
                    // Go to it
                    ftp_chdir($ftp, $fileName);
                }

                // If this is a folder - go deeper in recursion
                $this->synchronize($ftp, $fullPath, $diff);
            }
        }

        // Go one level up
        ftp_cdup($ftp);
    }

    /**
     * Get time difference between servers
     * @param resource $ftp Remote connection
     * @param string $tsFileName TS file name(timezone.dat)
     * @return float|int Time difference between servers
     */
    protected function getTimeDifference($ftp, $tsFileName = 'timezone.dat')
    {
        $diff = 0;

        // Cache local path
        $localPath = $this->sourceroot.'/'.$tsFileName;

        // Create local timestamp
        file_put_contents($localPath, '1', 0755);

        // Copy file to remote
        if (ftp_put($ftp, 'timezone.dat', $localPath, FTP_ASCII)) {
            // Get difference
            $ts_dx = abs(filemtime($localPath) - ftp_mdtm($ftp, $tsFileName));

            // Convert to hours
            $diff = floor($ts_dx / 3600) * 3600 + $ts_dx % 3600;

            ftp_delete($ftp, 'timezone.dat');
        }

        // Удалим временный файл
        unlink($localPath);

        return $diff;
    }


    /** Controller to perform deploy routine */
    public function __BASE()
    {
        // Установим ограничение на выполнение скрипта
        ini_set('max_execution_time', 120);

        s()->async(true);

        if (!isset($this->sourceroot{0})) {
            return $this->log('$sourceroot is not specified');
        }

        if (!isset($this->wwwroot{0})) {
            return $this->log('$wwwroot is not specified');
        }

        $this->title('Deploying project to '.$this->host);

        // Connect to remote
        $ftp = ftp_connect($this->host);
        // Login
        if (false !== ftp_login($ftp, $this->username, $this->password)) {
            // Switch to passive mode
            ftp_pasv($ftp, true);

            // Go to root folder
            if (ftp_chdir($ftp, $this->wwwroot)) {
                // If this is remote app - chdir to it
                if ( __SAMSON_REMOTE_APP) {
                    $base = str_replace('/', '', __SAMSON_BASE__);

                    // Попытаемся перейти/создать папку на сервере
                    if(!@ftp_chdir( $ftp, $base ))
                    {
                        // Создадим папку
                        ftp_mkdir($ftp, $base );
                        // Изменим режим доступа к папке
                        ftp_chmod( $ftp, 0755, $base );
                        // Перейдем в неё
                        ftp_chdir( $ftp, $base );
                    }
                }

                // Установим правильный путь к директории на сервере
                $this->wwwroot = ftp_pwd($ftp);

                $this->log('Entered remote folder [##]', $this->wwwroot);

                // Выполним синхронизацию папок
                $this->synchronize(
                    $ftp,
                    $this->sourceroot,
                    $this->getTimeDifference()
                );
            } else {
                $this->log('Remote folder[##] not found', $this->wwwroot);
            }
        } else {
            $this->log('Cannot login to remote server [##@##]', $this->host, $this->username);
        }

        // close the connection
        ftp_close($ftp);
    }
}