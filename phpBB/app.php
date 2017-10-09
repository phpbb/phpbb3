<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

use phpbb\kernel;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
* @ignore
*/
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);

require($phpbb_root_path . 'includes/startup.' . $phpEx);
require($phpbb_root_path . 'phpbb/class_loader.' . $phpEx);

$phpbb_class_loader = new \phpbb\class_loader('phpbb\\', "{$phpbb_root_path}phpbb/", $phpEx);
$phpbb_class_loader->register();

$phpbb_config_php_file = new \phpbb\config_php_file($phpbb_root_path, $phpEx);
extract($phpbb_config_php_file->get_all());

$phpbb_class_loader_ext = new \phpbb\class_loader('\\', "{$phpbb_root_path}ext/", $phpEx);
$phpbb_class_loader_ext->register();


if (!defined('PHPBB_INSTALLED'))
{
	$response = new RedirectResponse('install/app.php', 302);
	$response->send();
}

if (!defined('PHPBB_ENVIRONMENT'))
{
	@define('PHPBB_ENVIRONMENT', 'production');
}

$phpbb_kernel = new kernel($phpbb_config_php_file, $phpbb_root_path, $phpEx, PHPBB_ENVIRONMENT, defined('DEBUG') && DEBUG);
$phpbb_kernel->boot();

/* @var $symfony_request \phpbb\symfony_request */
$symfony_request = $phpbb_container->get('symfony_request');

$response = null;

try
{
	$response = $phpbb_kernel->handle($symfony_request, HttpKernelInterface::MASTER_REQUEST, false);
}
catch (NotFoundHttpException $e)
{
	// handle legacy
	/** @var \phpbb\legacy\http\legacy_handler $legacyHandler */
	$legacyHandler = $phpbb_kernel->get_container()->get('legacy.handler');

	try
	{
		$response = $legacyHandler->parse($symfony_request);
	}
	catch (NotFoundHttpException $_)
	{
		$response = $legacyHandler->handleException($e, $symfony_request);
	}

	if (!$response)
	{
		$legacyHandler->bootLegacy();

		try
		{
			try
			{
				require_once $legacyHandler->getLegacyPath();
			}
			catch (\phpbb\legacy\exception\exit_exception $e)
			{
				// Do nothing
			}

			$response = $legacyHandler->handleResponse();
		}
		catch (\Throwable $e)
		{
			goto catch_exception_for_pretty;
		}
		catch (\Exception $e)
		{
			catch_exception_for_pretty:
			try
			{
				// In case we have an error in the legacy, we want to be able to
				// have a nice error page instead of a blank page.
				$response = $legacyHandler->handleException($e, $symfony_request);
			}
			catch (\Throwable $e)
			{
				goto catch_exception;
			}
			catch (\Exception $_)
			{
				catch_exception:
				// In case we have an error in the error handling fail we want to display the original error
				throw $e;
			}
		}
	}
}
catch (\phpbb\legacy\exception\exit_exception $e)
{
	// Do nothing
}

if ($response !== null)
{
	$response->send();
}
else
{
	$response = new \Symfony\Component\HttpFoundation\Response();
}

$phpbb_kernel->terminate($symfony_request, $response);
