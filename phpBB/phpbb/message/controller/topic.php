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

use \phpbb\db\driver\driver_interface;
use \phpbb\message\form\form;
use \phpbb\controller\helper;
use \phpbb\language\language;
use \phpbb\request\request_interface;
use \phpbb\template\template;

class topic
{
	/**
	 * @var driver_interface
	 */
	protected $db;

	/**
	 * @var form
	 */
	protected $form;

	/**
	 * @var helper
	 */
	protected $helper;

	/**
	 * @var language
	 */
	protected $language;

	/**
	 * @var request_interface
	 */
	protected $request;

	/**
	 * @var template
	 */
	protected $template;

	public function __construct(driver_interface $db, form $form, helper $helper, language $language, request_interface $request, template $template)
	{
		$this->db				= $db;
		$this->form				= $form;
		$this->helper			= $helper;
		$this->language			= $language;
		$this->request			= $request;
		$this->template			= $template;
	}

	/**
	 * Controller for /email/topic/{topic_id} routes
	 *
	 * @param int		$topic_id		Topic id
	 *
	 * @return \Symfony\Component\HttpFoundation\Response a Symfony response object
	 */
	public function handle($topic_id)
	{
		global $phpbb_root_path, $phpEx;

		if (!function_exists('generate_forum_nav'))
		{
				include($phpbb_root_path . 'includes/functions_display.' . $phpEx);
		}

		if (!class_exists('messenger'))
		{
			include($phpbb_root_path . 'includes/functions_messenger.' . $phpEx);
		}

		// Load language strings
		$this->language->add_lang('memberlist');

		// Form stuff
		$this->request->overwrite('topic_id', $topic_id); // Dirty hack
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

		// Breadcrums
		$sql = 'SELECT f.parent_id, f.forum_parents, f.left_id, f.right_id, f.forum_type, f.forum_name, f.forum_id, f.forum_desc, f.forum_desc_uid, f.forum_desc_bitfield, f.forum_desc_options, f.forum_options, t.topic_title
				FROM ' . FORUMS_TABLE . ' as f,
					' . TOPICS_TABLE . ' as t
				WHERE t.forum_id = f.forum_id';
		$result = $this->db->sql_query($sql);
		$topic_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		generate_forum_nav($topic_data);
		$this->template->assign_block_vars('navlinks', array(
			'BREADCRUMB_NAME'	=> $topic_data['topic_title'],
			'U_BREADCRUMB'		=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t=$topic_id"),
		));

		$this->template->assign_block_vars('navlinks', array(
			'BREADCRUMB_NAME'	=> $this->language->lang('EMAIL_TOPIC'),
			'U_BREADCRUMB'		=> $this->helper->route('phpbb_message_topic', ['topic_id' => $topic_id]),
		));

		// Render
		return $this->helper->render($this->form->get_template_file(), $this->form->get_page_title());
	}

}
