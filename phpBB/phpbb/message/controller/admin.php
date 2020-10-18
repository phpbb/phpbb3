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

namespace phpbb\message\controller;

use phpbb\message\form\form;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\request\request_interface;
use phpbb\template\template;
use Symfony\Component\HttpFoundation\Response;

class admin
{
	/** @var form */
	protected $form;

	/** @var helper */
	protected $helper;

	/** @var language */
	protected $language;

	/** @var request_interface */
	protected $request;

	/** @var template */
	protected $template;

	/**
	 * admin constructor.
	 *
	 * @param form $form
	 * @param helper $helper
	 * @param language $language
	 * @param request_interface $request
	 * @param template $template
	 */
	public function __construct(form $form, helper $helper, language $language, request_interface $request, template $template)
	{
		$this->form				= $form;
		$this->helper			= $helper;
		$this->language			= $language;
		$this->request			= $request;
		$this->template			= $template;
	}

	/**
	 * Controller for /contact/admin route
	 *
	 * @return Response a Symfony response object
	 */
	public function handle()
	{
		global $phpbb_root_path, $phpEx;

		if (!class_exists('messenger'))
		{
			include($phpbb_root_path . 'includes/functions_messenger.' . $phpEx);
		}

		// https://tracker.phpbb.com/browse/PHPBB3-15812

		// Make page available when user is banned
		//define('SKIP_CHECK_BAN', true);

		// Make page available when board is disabled
		//define('SKIP_CHECK_DISABLED', true);

		// Load language strings
		$this->language->add_lang('memberlist');

		// Form stuff
		$this->form->bind($this->request);

		$error = $this->form->check_allow();

		if ($error)
		{
			return $this->helper->message($error);
		}

		if ($this->request->is_set_post('submit'))
		{
			$messenger = new \messenger(false);
			$this->form->submit($messenger);
		}

		$this->form->render($this->template);

		// Breadcrumbs
		$this->template->assign_block_vars('navlinks', array(
			'BREADCRUMB_NAME'	=> $this->language->lang('CONTACT_ADMIN'),
			'U_BREADCRUMB'		=> $this->helper->route('phpbb_message_admin'),
		));

		// Render
		return $this->helper->render($this->form->get_template_file(), $this->form->get_page_title());
	}
}
