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
class SamsonDeployConnector extends Service
{
	/** Идентификатор модуля */
	protected $id = 'deploy';

	/** Автор модуля */
	protected $author = 'Vitaly Iegorov';

	/** Версия модуля */
	protected $version = '0.3.0';
	
	/** FTP host */
	public $host 	= 'samsonos.com';
	
	/** Path to site document root */
	public $wwwroot	= './vitaly/wwwroot/karnaval-costume.com/www/';
	
	/** FTP username */
	public $username= 'vitaly';
	
	/** FTP password */
	public $password= 'Vital29121987';
	
	/** PHP version on server */
	public $php_version = '5.3.0';

	/** Список модулей от которых завист данный модуль */
	protected $requirements = array
	(			
		'Compressor'
	);
}