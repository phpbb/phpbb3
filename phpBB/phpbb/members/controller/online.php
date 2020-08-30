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

namespace phpbb\members\controller;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher;
use phpbb\exception\http_exception;
use phpbb\group\helper as group_helper;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\members\viewonline_helper;
use phpbb\pagination;
use phpbb\request\request;
use phpbb\routing\router;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\HttpFoundation\Response;

class online
{
	/**
	 * @var auth
	 */
	protected $auth;

	/**
	 * @var config
	 */
	protected $config;

	/**
	 * @var driver_interface
	 */
	protected $db;

	/**
	 * @var dispatcher
	 */
	protected $dispatcher;

	/**
	 * @var group_helper
	 */
	protected $group_helper;

	/**
	 * @var viewonline_helper
	 */
	protected $viewonline_helper;

	/**
	 * @var helper
	 */
	protected $helper;

	/**
	 * @var language
	 */
	protected $language;

	/**
	 * @var pagination
	 */
	protected $pagination;

	/**
	 * @var request
	 */
	protected $request;

	/**
	 * @var router
	 */
	protected $router;

	/**
	 * @var template
	 */
	protected $template;

	/**
	 * @var user
	 */
	protected $user;

	/**
	 * @var string
	 */
	protected $phpbb_root_path;

	/**
	 * @var string
	 */
	protected $phpEx;

	/**
	 * online constructor.
	 * @param auth $auth
	 * @param config $config
	 * @param driver_interface $db
	 * @param dispatcher $dispatcher
	 * @param group_helper $group_helper
	 * @param viewonline_helper $viewonline_helper
	 * @param helper $helper
	 * @param language $language
	 * @param pagination $pagination
	 * @param request $request
	 * @param router $router
	 * @param template $template
	 * @param user $user
	 * @param string $phpbb_root_path
	 * @param string $phpEx
	 */
	public function __construct(auth $auth, config $config, driver_interface $db, dispatcher $dispatcher, group_helper $group_helper, viewonline_helper $viewonline_helper, helper $helper, language $language, pagination $pagination, request $request, router $router, template $template, user $user, string $phpbb_root_path, string $phpEx)
	{
		$this->auth					= $auth;
		$this->config				= $config;
		$this->db					= $db;
		$this->dispatcher			= $dispatcher;
		$this->group_helper			= $group_helper;
		$this->viewonline_helper	= $viewonline_helper;
		$this->helper				= $helper;
		$this->language				= $language;
		$this->pagination			= $pagination;
		$this->request				= $request;
		$this->router				= $router;
		$this->template				= $template;
		$this->user					= $user;
		$this->phpbb_root_path		= $phpbb_root_path;
		$this->phpEx				= $phpEx;
	}

	/**
	 * Controller for /online route
	 *
	 * @return Response a Symfony response object
	 */
	public function handle()
	{
		// Display a listing of board admins, moderators
		if (!function_exists('display_user_activity'))
		{
			include($this->phpbb_root_path . 'includes/functions_display.' . $this->phpEx);
		}

		// Load language strings
		$this->language->add_lang('memberlist');

		// Can this user view profiles/memberlist?
		if (!$this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel'))
		{
			if ($this->user->data['user_id'] != ANONYMOUS)
			{
				throw new http_exception(403, 'NO_VIEW_USERS');
			}

			login_box('', $this->language->lang('LOGIN_EXPLAIN_VIEWONLINE'));
		}

		// Get and set some variables
		$mode			= $this->request->variable('mode', '');
		$session_id		= $this->request->variable('s', '');
		$start			= $this->request->variable('start', 0);
		$sort_key		= $this->request->variable('sk', 'b');
		$sort_dir		= $this->request->variable('sd', 'd');
		$show_guests	= ($this->config['load_online_guests']) ? $this->request->variable('sg', 0) : 0;

		$sort_key_text = ['a' => $this->language->lang('SORT_USERNAME'), 'b' => $this->language->lang('SORT_JOINED'), 'c' => $this->language->lang('SORT_LOCATION')];
		$sort_key_sql = ['a' => 'u.username_clean', 'b' => 's.session_time', 'c' => 's.session_page'];

		// Sorting and order
		if (!isset($sort_key_text[$sort_key]))
		{
			$sort_key = 'b';
		}

		$order_by = $sort_key_sql[$sort_key] . ' ' . (($sort_dir == 'a') ? 'ASC' : 'DESC');

		$this->user->update_session_infos();

		$forum_data = $this->get_forum_data();

		// Get number of online guests (if we do not display them)
		$guest_counter = (!$show_guests) ? $this->get_number_guests() : 0;

		// Get user list
		$sql_ary = [
			'SELECT'	=> 'u.user_id, u.username, u.username_clean, u.user_type, u.user_colour, s.session_id, s.session_time, s.session_page, s.session_ip, s.session_browser, s.session_viewonline, s.session_forum_id',
			'FROM'		=> [
				USERS_TABLE		=> 'u',
				SESSIONS_TABLE	=> 's',
			],
			'WHERE'		=> 'u.user_id = s.session_user_id
				AND s.session_time >= ' . (time() - ($this->config['load_online_time'] * 60)) .
				((!$show_guests) ? ' AND s.session_user_id <> ' . ANONYMOUS : ''),
			'ORDER_BY'	=> $order_by,
		];

		/**
		* Modify the SQL query for getting the user data to display viewonline list
		*
		* @event core.viewonline_modify_sql
		* @var	array	sql_ary			The SQL array
		* @var	bool	show_guests		Do we display guests in the list
		* @var	int		guest_counter	Number of guests displayed
		* @var	array	forum_data		Array with forum data
		* @since 3.1.0-a1
		* @changed 3.1.0-a2 Added vars guest_counter and forum_data
		*/
		$vars = ['sql_ary', 'show_guests', 'guest_counter', 'forum_data'];
		extract($this->dispatcher->trigger_event('core.viewonline_modify_sql', compact($vars)));

		$result = $this->db->sql_query($this->db->sql_build_query('SELECT', $sql_ary));

		$prev_id = $prev_ip = $user_list = [];
		$logged_visible_online = $logged_hidden_online = $counter = 0;

		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($row['user_id'] != ANONYMOUS && !isset($prev_id[$row['user_id']]))
			{
				$view_online = $s_user_hidden = false;
				$user_colour = ($row['user_colour']) ? ' style="color:#' . $row['user_colour'] . '" class="username-coloured"' : '';

				$username_full = ($row['user_type'] != USER_IGNORE) ? get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']) : '<span' . $user_colour . '>' . $row['username'] . '</span>';

				if (!$row['session_viewonline'])
				{
					$view_online = ($this->auth->acl_get('u_viewonline') || $row['user_id'] === $this->user->data['user_id']) ? true : false;
					$logged_hidden_online++;

					$username_full = '<em>' . $username_full . '</em>';
					$s_user_hidden = true;
				}
				else
				{
					$view_online = true;
					$logged_visible_online++;
				}

				$prev_id[$row['user_id']] = 1;

				if ($view_online)
				{
					$counter++;
				}

				if (!$view_online || $counter > $start + $this->config['topics_per_page'] || $counter <= $start)
				{
					continue;
				}
			}
			else if ($show_guests && $row['user_id'] == ANONYMOUS && !isset($prev_ip[$row['session_ip']]))
			{
				$prev_ip[$row['session_ip']] = 1;
				$guest_counter++;
				$counter++;

				if ($counter > $start + $this->config['topics_per_page'] || $counter <= $start)
				{
					continue;
				}

				$s_user_hidden = false;
				$username_full = get_username_string('full', $row['user_id'], $this->language->lang('GUEST'));
			}
			else
			{
				continue;
			}

			list($location, $location_url) = $this->get_location($row['session_page'], $row['session_forum_id']);

			/**
			* Overwrite the location's name and URL, which are displayed in the list
			*
			* @event core.viewonline_overwrite_location
			* @var	array	on_page			File name and query string
			* @var	array	row				Array with the users sql row
			* @var	string	location		Page name to displayed in the list
			* @var	string	location_url	Page url to displayed in the list
			* @var	array	forum_data		Array with forum data
			* @since 3.1.0-a1
			* @changed 3.1.0-a2 Added var forum_data
			*/
			$vars = ['on_page', 'row', 'location', 'location_url', 'forum_data'];
			extract($this->dispatcher->trigger_event('core.viewonline_overwrite_location', compact($vars)));

			$template_row = [
				'USERNAME' 			=> $row['username'],
				'USERNAME_COLOUR'	=> $row['user_colour'],
				'USERNAME_FULL'		=> $username_full,
				'LASTUPDATE'		=> $this->user->format_date($row['session_time']),
				'FORUM_LOCATION'	=> $location,
				'USER_IP'			=> ($this->auth->acl_get('a_')) ? (($mode == 'lookup' && $session_id == $row['session_id']) ? gethostbyaddr($row['session_ip']) : $row['session_ip']) : '',
				'USER_BROWSER'		=> ($this->auth->acl_get('a_user')) ? $row['session_browser'] : '',

				'U_USER_PROFILE'	=> ($row['user_type'] != USER_IGNORE) ? get_username_string('profile', $row['user_id'], '') : '',
				'U_USER_IP'			=> append_sid($this->phpbb_root_path . "viewonline." . $this->phpEx, 'mode=lookup' . (($mode != 'lookup' || $row['session_id'] != $session_id) ? '&amp;s=' . $row['session_id'] : '') . "&amp;sg=$show_guests&amp;start=$start&amp;sk=$sort_key&amp;sd=$sort_dir"),
				'U_WHOIS'			=> $this->helper->route('phpbb_members_online_whois', ['session_id' => $row['session_id']]),
				'U_FORUM_LOCATION'	=> $location_url,

				'S_USER_HIDDEN'		=> $s_user_hidden,
				'S_GUEST'			=> ($row['user_id'] == ANONYMOUS) ? true : false,
				'S_USER_TYPE'		=> $row['user_type'],
			];

			/**
			* Modify viewonline template data before it is displayed in the list
			*
			* @event core.viewonline_modify_user_row
			* @var	array	on_page			File name and query string
			* @var	array	row				Array with the users sql row
			* @var	array	forum_data		Array with forum data
			* @var	array	template_row	Array with template variables for the user row
			* @since 3.1.0-RC4
			*/
			$vars = ['on_page', 'row', 'forum_data', 'template_row'];
			extract($this->dispatcher->trigger_event('core.viewonline_modify_user_row', compact($vars)));

			$this->template->assign_block_vars('user_row', $template_row);
		}
		$this->db->sql_freeresult($result);

		$this->assign_legend_template_data();

		// Refreshing the page every 60 seconds...
		meta_refresh(60, $this->helper->route('phpbb_members_online', ['sg' => $show_guests, 'sk' => $sort_key, 'sd' => $sort_dir, 'start' => $start]));

		$start = $this->pagination->validate_start($start, $this->config['topics_per_page'], $counter);
		$base_url = $this->helper->route('phpbb_members_online', ['sg' => $show_guests, 'sk' => $sort_key, 'sd' => $sort_dir]);
		$this->pagination->generate_template_pagination($base_url, 'pagination', 'start', $counter, $this->config['topics_per_page'], $start);

		// Send data to template
		$this->template->assign_vars([
			'TOTAL_REGISTERED_USERS_ONLINE'	=> $this->language->lang('REG_USERS_ONLINE', (int) $logged_visible_online, $this->language->lang('HIDDEN_USERS_ONLINE', (int) $logged_hidden_online)),
			'TOTAL_GUEST_USERS_ONLINE'		=> $this->language->lang('GUEST_USERS_ONLINE', (int) $guest_counter),

			'U_SORT_USERNAME'		=> $this->helper->route('phpbb_members_online', ['sg' => (int) $show_guests, 'sk' => 'a', 'sd' => (($sort_key == 'a' && $sort_dir == 'a') ? 'd' : 'a')]),
			'U_SORT_UPDATED'		=> $this->helper->route('phpbb_members_online', ['sg' => (int) $show_guests, 'sk' => 'b', 'sd' => (($sort_key == 'b' && $sort_dir == 'a') ? 'd' : 'a')]),
			'U_SORT_LOCATION'		=> $this->helper->route('phpbb_members_online', ['sg' => (int) $show_guests, 'sk' => 'c', 'sd' => (($sort_key == 'c' && $sort_dir == 'a') ? 'd' : 'a')]),

			'U_SWITCH_GUEST_DISPLAY'	=> $this->helper->route('phpbb_members_online', ['sg' => (int) !$show_guests]),
			'L_SWITCH_GUEST_DISPLAY'	=> ($show_guests) ? $this->language->lang('HIDE_GUESTS') : $this->language->lang('DISPLAY_GUESTS'),
			'S_SWITCH_GUEST_DISPLAY'	=> ($this->config['load_online_guests']) ? true : false,
			'S_VIEWONLINE'				=> true,
		]);

		$this->template->assign_block_vars('navlinks', [
			'BREADCRUMB_NAME'	=> $this->language->lang('WHO_IS_ONLINE'),
			'U_BREADCRUMB'		=> $this->helper->route('phpbb_members_online'),
		]);

		make_jumpbox(append_sid($this->phpbb_root_path . "viewforum." . $this->phpEx));

		// Render
		return $this->helper->render('viewonline_body.html', $this->language->lang('WHO_IS_ONLINE'));
	}

	/**
	 * @param $session_page
	 * @param $forum_id
	 * @return array
	 * @throws \Exception
	 */
	protected function get_location($session_page, $forum_id) // $forum_id can be removed in the future i think https://tracker.phpbb.com/browse/PHPBB3-15434
	{
		global $phpbb_adm_relative_path;

		try
		{
			$session_page = parse_url($session_page,  PHP_URL_PATH); // Remove query string
			$session_page = '/' . ((substr($session_page, 0, 8) == 'app.php/') ? substr($session_page, 8) : $session_page); // Remove app.php/

			$match = $this->router->match($session_page);

			switch ($match['_route'])
			{
				case 'phpbb_help_bbcode_controller':
					$location = $this->language->lang('VIEWING_FAQ');
					$location_url = $this->helper->route('phpbb_help_faq_controller');
				break;

				case 'phpbb_members_online':
				case 'phpbb_members_online_whois':
					$location = $this->language->lang('VIEWING_ONLINE');
					$location_url = $this->helper->route('phpbb_members_online');
				break;

				case 'phpbb_report_pm_controller':
				case 'phpbb_report_post_controller':
					$location = $this->language->lang('REPORTING_POST');
					$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
				break;

				case 'phpbb_message_admin':
				// https://github.com/phpbb/phpbb/pull/5391/files
				// The current behavior is show users in contact admin, but they will be diferent controllers
				//case 'phpbb_message_topic':
				//case 'phpbb_message_user':
					$location = $this->language->lang('VIEWING_CONTACT_ADMIN');
					$location_url = append_sid($this->phpbb_root_path . "memberlist." . $this->phpEx, 'mode=contactadmin');
				break;

				case 'phpbb_members_profile':
					$location_url = append_sid($this->phpbb_root_path . "memberlist." . $this->phpEx);
					$location = $this->language->lang('VIEWING_MEMBER_PROFILE');
				break;

				case 'phpbb_members_team':
					$location_url = append_sid($this->phpbb_root_path . "memberlist." . $this->phpEx);
					$location = $this->language->lang('VIEWING_MEMBERS');
				break;

				default:
					// Is a route, but not in the switch
					$location = $this->language->lang('INDEX');
					$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
			}
		}
		catch (\RuntimeException $e) // Urls without route
		{
			$on_page = $this->viewonline_helper->get_user_page($session_page);

			switch ($on_page[1])
			{
				case 'index':
					$location = $this->language->lang('INDEX');
					$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
				break;

				case $phpbb_adm_relative_path . 'index':
					$location = $this->language->lang('ACP');
					$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
				break;

				case 'posting':
				case 'viewforum':
				case 'viewtopic':

					$forum_data = $this->get_forum_data();

					if ($forum_id && $this->auth->acl_get('f_list', $forum_id))
					{
						$location = '';
						$location_url = append_sid($this->phpbb_root_path . "viewforum." . $this->phpEx, 'f=' . $forum_id);

						if ($forum_data[$forum_id]['forum_type'] == FORUM_LINK)
						{
							$location = $this->language->lang('READING_LINK', $forum_data[$forum_id]['forum_name']);
							break;
						}

						switch ($on_page[1])
						{
							case 'posting':
								preg_match('#mode=([a-z]+)#', $session_page, $on_page);
								$posting_mode = (!empty($on_page[1])) ? $on_page[1] : '';

								switch ($posting_mode)
								{
									case 'reply':
									case 'quote':
										$location = $this->language->lang('REPLYING_MESSAGE', $forum_data[$forum_id]['forum_name']);
									break;

									default:
										$location = $this->language->lang('POSTING_MESSAGE', $forum_data[$forum_id]['forum_name']);
									break;
								}
							break;

							case 'viewtopic':
								$location = $this->language->lang('READING_TOPIC', $forum_data[$forum_id]['forum_name']);
							break;

							case 'viewforum':
								$location = $this->language->lang('READING_FORUM', $forum_data[$forum_id]['forum_name']);
							break;
						}
					}
					else
					{
						$location = $this->language->lang('INDEX');
						$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
					}
				break;

				case 'search':
					$location = $this->language->lang('SEARCHING_FORUMS');
					$location_url = append_sid($this->phpbb_root_path . "search." . $this->phpEx);
				break;

				case 'memberlist':
					$location_url = append_sid($this->phpbb_root_path . "memberlist." . $this->phpEx);
					$location = $this->language->lang('VIEWING_MEMBERS');
				break;

				case 'mcp':
					$location = $this->language->lang('VIEWING_MCP');
					$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
				break;

				case 'ucp':
					$location = $this->language->lang('VIEWING_UCP');

					// Grab some common modules
					$url_params = [
						'mode=register'		=> 'VIEWING_REGISTER',
						'i=pm&mode=compose'	=> 'POSTING_PRIVATE_MESSAGE',
						'i=pm&'				=> 'VIEWING_PRIVATE_MESSAGES',
						'i=profile&'		=> 'CHANGING_PROFILE',
						'i=prefs&'			=> 'CHANGING_PREFERENCES',
					];

					foreach ($url_params as $param => $lang)
					{
						if (strpos($session_page, $param) !== false)
						{
							$location = $this->language->lang($lang);
							break;
						}
					}

					$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
				break;

				default:
					$location = $this->language->lang('INDEX');
					$location_url = append_sid($this->phpbb_root_path . "index." . $this->phpEx);
				break;
			}
		}

		return [$location, $location_url];
	}

	protected function get_number_guests()
	{
		switch ($this->db->get_sql_layer())
		{
			case 'sqlite3':
				$sql = 'SELECT COUNT(session_ip) as num_guests
					FROM (
						SELECT DISTINCT session_ip
							FROM ' . SESSIONS_TABLE . '
							WHERE session_user_id = ' . ANONYMOUS . '
								AND session_time >= ' . (time() - ($this->config['load_online_time'] * 60)) .
					')';
			break;

			default:
				$sql = 'SELECT COUNT(DISTINCT session_ip) as num_guests
					FROM ' . SESSIONS_TABLE . '
					WHERE session_user_id = ' . ANONYMOUS . '
						AND session_time >= ' . (time() - ($this->config['load_online_time'] * 60));
			break;
		}
		$result = $this->db->sql_query($sql);
		$guest_counter = (int) $this->db->sql_fetchfield('num_guests');
		$this->db->sql_freeresult($result);

		return $guest_counter;
	}

	protected function get_forum_data()
	{
		// Forum info
		$sql_ary = [
			'SELECT'	=> 'f.forum_id, f.forum_name, f.parent_id, f.forum_type, f.left_id, f.right_id',
			'FROM'		=> [
				FORUMS_TABLE	=> 'f',
			],
			'ORDER_BY'	=> 'f.left_id ASC',
		];

		/**
		* Modify the forum data SQL query for getting additional fields if needed
		*
		* @event core.viewonline_modify_forum_data_sql
		* @var	array	sql_ary			The SQL array
		* @since 3.1.5-RC1
		*/
		$vars = ['sql_ary'];
		extract($this->dispatcher->trigger_event('core.viewonline_modify_forum_data_sql', compact($vars)));

		$result = $this->db->sql_query($this->db->sql_build_query('SELECT', $sql_ary), 600);
		unset($sql_ary);

		$forum_data = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_data[$row['forum_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $forum_data;
	}

	protected function assign_legend_template_data()
	{
		$order_legend = $this->config['legend_sort_groupname'] ? 'group_name' : 'group_legend';

		// Grab group details for legend display
		if ($this->auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel'))
		{
			$sql = 'SELECT group_id, group_name, group_colour, group_type, group_legend
				FROM ' . GROUPS_TABLE . '
				WHERE group_legend > 0
				ORDER BY ' . $order_legend . ' ASC';
		}
		else
		{
			$sql = 'SELECT g.group_id, g.group_name, g.group_colour, g.group_type, g.group_legend
				FROM ' . GROUPS_TABLE . ' g
				LEFT JOIN ' . USER_GROUP_TABLE . ' ug
					ON (
						g.group_id = ug.group_id
						AND ug.user_id = ' . $this->user->data['user_id'] . '
						AND ug.user_pending = 0
					)
				WHERE g.group_legend > 0
					AND (g.group_type <> ' . GROUP_HIDDEN . ' OR ug.user_id = ' . $this->user->data['user_id'] . ')
				ORDER BY g.' . $order_legend . ' ASC';
		}
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('legend', [
				'GROUP_ID' => $row['group_id'],
				'GROUP_NAME' => $this->group_helper->get_name($row['group_name']),
				'GROUP_COLOUR' => $row['group_colour'],
				'GROUP_TYPE' => $row['group_type'],
				'GROUP_LEGEND' => $row['group_legend'],
				'U_GROUP' => append_sid($this->phpbb_root_path . "memberlist." . $this->phpEx, 'mode=group&amp;g=' . $row['group_id'])
			]);
		}
		$this->db->sql_freeresult($result);

	}
}
