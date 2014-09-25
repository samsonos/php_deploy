<?php
namespace samson\deploy;

use samson\core\Service;

/**
 * Интерфейс для подключения модуля в ядро фреймворка SamsonPHP
 *
 * @package SamsonPHP
 * @author Vitaly Iegorov <vitalyiegorov@gmail.com>
 * @version 0.1
 */
class Deploy extends Service
{
	/** Идентификатор модуля */
	protected $id = 'deploy';

	/** Автор модуля */
	protected $author = 'Vitaly Iegorov';

	/** Версия модуля */
	protected $version = '0.3.0';
	
	/** FTP host */
	public $host 	= '';
	
	/** Path to site document root on remote server */
	public $wwwroot	= '';
	
	/** Path to site document root on local server */
	public $soureroot = '';
	
	/** FTP username */
	public $username= '';
	
	/** FTP password */
	public $password= '';
	
	/** PHP version on server */
	public $php_version = '5.3.0';

	/** Список модулей от которых завист данный модуль */
	protected $requirements = array('core');
	
	/**
	 * Perform synchronizing folder via FTP connrection
	 * @param resource 	$ftp 		FTP connection instance
	 * @param string 	$local_path Local path for synchronizing
	 * @param number 	$ts_hours	Timestamp for analyzing changes
	 */
	protected function ftp_sync( $ftp, $local_path, $ts_hours = 0 )
	{
		// Откроем папку
		if ( file_exists($local_path) &&  $handle = opendir( $local_path ) )
		{
			/* Именно этот способ чтения элементов каталога является правильным. */
			while ( FALSE !== ( $entry = readdir( $handle ) ) )
			{
				// Если это зарезервированные ресурсы - пропустим
				if( $entry == '..' || $entry == '.') continue;
	
				// Сформируем полный путь к ресурсу
				$full_path = $local_path.'/'.$entry;
	
				// Если это подкаталог то углубимся в рекурсию
				if(is_dir($full_path))
				{
					// Попытаемся перейти/создать папку на сервере
					if(!@ftp_chdir( $ftp, $entry ))
					{
						// Создадим папку
						ftp_mkdir( $ftp, $entry );
						// Изменим режим доступа к папке
						ftp_chmod( $ftp, 0755, $entry );
						// Перейдем в неё
						ftp_chdir( $ftp, $entry );
					}
	
					// Углубимся в рекурсию
					$this->ftp_sync( $ftp, $full_path, $ts_hours );
				}
				else
				{
					// Флаг необходимости загрузки файла на сервер
					$upload = true;
	
					// Если файл существует на удаленном сервере
					if( ($mdtm = ftp_mdtm( $ftp, $entry )) !== -1 )
					{
						// Получим разницу во времени модицикации файлов
						$ts_diff = filemtime($full_path) - $mdtm + $ts_hours;
							
						//trace($entry.' '.date("Y-m-d H:i:s", filemtime($full_path)).'/'.date("Y-m-d H:i:s", $mdtm).'('.$ts_diff.')');
	
						// Если разница во времени модификации  меньше чем максимальная допустимая
						if( $ts_diff < 0 ) $upload = false;
					}
	
					// Если необходимо загрузить файл на сервер
					if( $upload === true )
					{
						elapsed('Загрузка файла на сервер: '.$full_path );
							
						// Создадим пустышку на сервере
						if( ftp_put( $ftp, $entry, $full_path, FTP_BINARY ) )
						{
							// Изменим режим доступа к файлу
							ftp_chmod( $ftp, 0755, $entry );
	
							elapsed('   -- Файл успешно загружен: '.$full_path );
						}
						else e('Ошибка загрузки файла ##', E_ERROR, $full_path );
					}
				}
			}
	
			// Подымимся на 1 папку вверх на сервере
			ftp_cdup( $ftp );
	
			// Закроем чтение папки
			closedir($handle);
		}
	}	
	
	
	/** Controller to perform deploy routine */
	public function __BASE()
	{
		// Установим ограничение на выполнение скрипта
		ini_set( 'max_execution_time', 120 );
	
		s()->async(true);	
				
		$this->title('Выгрузка проекта на рабочий сервер');
	
		// Разница во времени между сервером и локальной машиной
		$ts_hours = 0;
		
		$ftp = ftp_connect( $this->host );
		
		// Попытаемся подключиться к FTP
		if( false !== ftp_login( $ftp, $this->username, $this->password ))		
		{		
			// Switch to passive mode
			ftp_pasv( $ftp, true );
	
			// Перейдем в директорию сайта
			if( ftp_chdir( $ftp, $this->wwwroot ))
			{
				// If this is remote app - chdir to it
				if( __SAMSON_REMOTE_APP )
				{
					$base = str_replace('/', '', __SAMSON_BASE__);
	
					// Попытаемся перейти/создать папку на сервере
					if(!@ftp_chdir( $ftp, $base ))
					{
						// Создадим папку
						ftp_mkdir( $ftp, $base );
						// Изменим режим доступа к папке
						ftp_chmod( $ftp, 0755, $base );
						// Перейдем в неё
						ftp_chdir( $ftp, $base );
					}
				}
					
				// If compressor module exists
				if( class_exists( ns_classname( 'Compressor', 'samsonos\compressor'), false ) )
				{
					// Create Compressor instance
					$cmp = & m('compressor');//new \samson\compressor\Compressor();
						
					// Perform site compress
					$cmp->compress( $this->php_version, true, true );
						
					// Set local site document root
					$this->soureroot = $cmp->output;
				}
				// If HTML module exists
				else if( class_exists( ns_classname( 'HTMLGenerator', 'samson\html'), false ) )
				{
					// Create Compressor instance
					$cmp = & m('html');
						
					// Perform site compress
					$cmp->compress();
						
					// Set local site document root
					$this->soureroot = $cmp->output;					
				}
				
				// Установим правильный путь к директории на сервере
				$this->wwwroot = ftp_pwd( $ftp );
					
				// Создадим файл пустышку на локальном сервере
				file_put_contents( $this->soureroot.'/'.'timezone.dat', '1', 0755 );
					
				// Создадим пустышку на сервере
				if( ftp_put( $ftp, 'timezone.dat', $this->soureroot.'/'.'timezone.dat', FTP_ASCII ) )
				{
					// Рассчитаем разницу вов времени модификации
					$ts_dx = abs(filemtime( $this->soureroot.'/'.'timezone.dat' ) - ftp_mdtm( $ftp, 'timezone.dat'));
						
					// Получим разницу в часах между машинами
					$ts_hours = floor($ts_dx / 3600) * 3600 + $ts_dx % 3600;
	
					//trace($ts_hours);
				}
					
				// Удалим временный файл
				unlink($this->soureroot.'/'.'timezone.dat');
				ftp_delete( $ftp, 'timezone.dat');
	
				// Выполним синхронизацию папок
				$this->ftp_sync( $ftp, $this->soureroot, $ts_hours );
			}
			else e('Папка ## не существует на сервере', E_ERROR, $this->wwwroot );
	
			// close the connection
			ftp_close( $ftp );
		}
	}	
}