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

use Symfony\Component\HttpFoundation\RedirectResponse;

/**
* @ignore
*/
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

// Start session management
$user->session_begin();
$auth->acl($user->data);

// Get and set some variables
$mode		= $request->variable('mode', '');
$session_id	= $request->variable('s', '');
$start		= $request->variable('start', 0);
$sort_key	= $request->variable('sk', 'b');
$sort_dir	= $request->variable('sd', 'd');
$show_guests	= ($config['load_online_guests']) ? $request->variable('sg', 0) : 0;

/** @var \phpbb\controller\helper $controller_helper */
$controller_helper = $phpbb_container->get('controller.helper');

if ($mode == 'whois')
{
	$response = new RedirectResponse(
		$controller_helper->route('phpbb_members_online_whois', array(
			's'	=> $session_id,
		), false),
		301
	);
	$response->send();
}


$response = new RedirectResponse(
	$controller_helper->route('phpbb_members_online', array(
		'mode'	=> $mode,
		's'		=> $session_id,
		'start'	=> $start,
		'sk'	=> $sort_key,
		'sd'	=> $sort_dir,
		'sg'	=> $show_guests,
	), false),
	301
);
$response->send();
