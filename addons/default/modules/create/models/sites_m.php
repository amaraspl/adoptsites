<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * This is the multi-site management module
 *
 * @author 		Jerel Unruh - PyroCMS Dev Team
 * @website		http://unruhdesigns.com
 * @package 	PyroCMS Premium
 * @subpackage 	Site Manager Module
 */
class Sites_m extends MY_Model {

	/**
	 * Get all sites along with their upload setting
	 *
	 * @return	obj
	 */
	public function get_sites()
	{
		$sites = $this->get_all();

		if (is_array($sites))
		{
			// Since the settings table names are prefixed we have to iterate
			foreach ($sites AS &$site)
			{
				$setting = $this->db->query("SELECT `value` FROM {$site->ref}_settings WHERE slug = 'addons_upload'")
					->row();

				$site->addons_upload = $setting->value;
			}
			unset($site);
		}
		return $sites;
	}

	/**
	 * Get a site by id along with first admin
	 *
	 * @param	int	$id	Site id
	 * @return	obj
	 */
	public function get_site($id)
	{
		$site = $this->get($id);

		$user = $this->user_m->get_default_user($site->ref);

		$site->user_id			= $user->id;
		$site->email			= $user->email;
		$site->username 		= $user->username;
		$site->first_name 		= $user->first_name;
		$site->last_name 		= $user->last_name;
		$site->password 		= '';
		$site->confirm_password = '';

		return $site;
	}

	/**
	 * Create a new site
	 *
	 * @param	array	$input	The post data
	 * @return	bool
	 */
	public function create_site($input)
	{
		// set this for any modules that may depend on it
		defined('ADDONPATH') or define('ADDONPATH', ADDON_FOLDER.$input['ref']);

		$hash = $this->user_m->_hash_password($input['password']);

		$name = $ref = $username = substr(md5($input['domain']), 0, 20);

		$insert = array('name'		=>	$name,
						'ref'		=>	$ref,
						'domain' 	=> 	$input['domain'],
						'created_on'=>	now(),
						'stripe_customer_id' => $this->subscribe()
						);

		$user = array('username'		=>	$username,
					  'first_name'		=>	$input['first_name'],
					  'last_name'       =>	$input['last_name'],
					  'email'			=>	$input['email'],
					  'password'		=>	$hash->password,
					  'salt'			=>	$hash->user_salt
					  );

		if ($this->insert($insert))
		{
			if($this->_make_folders($insert['ref']))
			{
				// Install all modules
				$this->db->set_dbprefix($insert['ref'].'_');
				if ($this->user_m->create_default_user($user))
				{
					// we have to add schema_version so migrations don't start over
					$this->dbforge->add_field(array(
						'version' => array('type' => 'INT', 'constraint' => 3),
					));

					$this->dbforge->create_table('migrations', true);

					if ($this->db->insert('migrations', array('version' => config_item('migration_version'))) )
					{
						$success = $this->module_import->import_all();

						$this->postCreateSetup($insert['ref']);

						return $success;
					}
				}
			}
		}
		return false;
	}

	/**
	 * This method does some post-creation site setup.
	 *
	 * @param string $ref The site ref
	 */
	protected function postCreateSetup($ref)
	{
		$this->db->set_dbprefix($ref);

		// Change default admin theme
		$this->db
			->set('value', 'adopt')
			->where('slug', 'admin_theme')
			->update('settings');

		$this->db->truncate('navigation_groups');
		$this->db->truncate('navigation_links');

		// Create the new Header nav group.
		$this->db->insert('navigation_groups', array('title' => 'Header', 'abbrev' => 'header'));

		// Create the new Header nav links
		$navLinks = array(
			array(
				'title' => 'Home',
				'link_type' => 'page',
				'page_id' => 1,
				'navigation_group_id' => 1,
				'position' => 1,
				'restricted_to' => 0
			),
			array(
				'title' => 'About',
				'link_type' => 'page',
				'page_id' => 1,
				'navigation_group_id' => 1,
				'position' => 2,
				'restricted_to' => 0
			),
			array(
				'title' => 'Photo Gallery',
				'link_type' => 'module',
				'module_name' => 'galleries',
				'navigation_group_id' => 1,
				'position' => 3,
				'restricted_to' => 0
			),
			array(
				'title' => 'Journal',
				'link_type' => 'module',
				'module_name' => 'blog',
				'navigation_group_id' => 1,
				'position' => 4,
				'restricted_to' => 0
			),
			array(
				'title' => 'Contact',
				'link_type' => 'page',
				'page_id' => 2,
				'navigation_group_id' => 1,
				'position' => 5,
				'restricted_to' => 0
			)
		);

		foreach ($navLinks as $link)
		{
			$this->db->insert('navigation_links', $link);
		}


	}

	protected function subscribe($plan = 'as-basic')
	{
		// Let's make sure the stripe_customer_id field exists.
		if ( ! $this->db->field_exists('stripe_customer_id', 'sites'))
		{
			$this->dbforge->add_column('sites', array(
				'stripe_customer_id' => array(
					'type' => 'VARCHAR',
					'constraint' => 20,
					'null' => true,
				),
		     ));
		}

		$this->load->config('stripe');

		Stripe::setApiKey(config_item('stripe_private_key'));

		$customer = Stripe_Customer::create(array(
		                             'plan' => $plan,
		                             'card' => $this->input->post('stripeToken'),
		                             'email' => $this->input->post('email'),
		                             'description' => $this->input->post('domain')
		                        ));

		if ($customer->id) {
			return $customer->id;
		}

		return null;
	}

	/**
	 * Edit a site
	 *
	 * @param	array	$input	The post data
	 * @param	array	$site	The old site data
	 * @return	bool
	 */
	public function edit_site($input, $site)
	{
		$insert = array('name'		=>	$input['name'],
						'ref'		=>	$input['ref'],
						'domain' 	=> 	$input['domain'],
						'updated_on'=>	now()
						);

		$user = array('id'			=>	$input['user_id'],
					  'email'		=>	$input['email'],
					  'username'	=>	$input['username'],
					  'first_name'	=>	$input['first_name'],
					  'last_name'	=>	$input['last_name']
		);

		if($input['password'] > '' and strlen($input['password']) > 3)
		{
			$hash = $this->user_m->_hash_password($input['password']);

			$user['password'] 	= $hash->password;
			$user['salt']		= $hash->user_salt;
		}

		if ($this->update($input['id'], $insert) AND
			$this->user_m->update_default_user($site->ref, $user)
			)
		{
			// delete navigation cache
			delete_files(APPPATH . 'cache/' . $site->ref . '/navigation_m');

			// make sure there aren't orphaned folders from a previous install
			if ($insert['ref'] != $site->ref)
			{
				$tables = $this->db->list_tables();

				// now rename the site's tables
				foreach ($tables AS $table)
				{
					// only rename the table if it starts with our prefix
					if (strpos($table, $site->ref.'_') === (int) 0)
					{
						$new_table = str_replace($site->ref.'_', $insert['ref'].'_', $table);
						$this->db->query("RENAME TABLE {$table} TO {$new_table}");
					}
				}

				foreach ($this->locations AS $folder_check => $sub_folders)
				{
					if (is_dir($folder_check.'/'.$insert['ref']))
					{
						$this->session->set_flashdata('error', sprintf(lang('site:folder_exists'),
																	   $folder_check.'/'.$insert['ref']));
						redirect('sites/edit/'.$input['id']);
					}
				}

				return $this->_rename_folders($input['ref'], $site->ref);
			}
			return true;
		}
		return false;
	}

	/**
	 * Delete A Site
	 *
	 * @param	int		$id		The id of the site
	 * @param	object	$site	The site data
	 * @return	bool
	 */
	public function delete_site($id, $site)
	{
		$unwritable = array();
		$tables = $this->db->list_tables();

		// drop the db record
		if ($this->delete($id) and strlen($site->ref) > 0)
		{
			// now drop the site's own tables
			foreach ($tables AS $table)
			{
				// only delete the table if it starts with our prefix
				if (strpos($table, $site->ref.'_') === 0)
				{
					$this->db->query("DROP TABLE IF EXISTS `".$table."`");
				}
			}

			// get rid of all folders
			foreach ($this->locations AS $root => $sub)
			{
				if (is_really_writable($root.'/'.$site->ref))
				{
					$this->_remove_tree($root.'/'.$site->ref);
				}
				else
				{
					$unwriteable[] = $root.'/'.$site->ref;
				}
			}
		}
		return (count($unwritable) > 0) ? $unwritable : true;
	}

	/**
	 * Count database and file system usage for a site
	 *
	 * @param	string	$id The site id
	 * @return	mixed	The site info
	 */
	public function get_stats($id)
	{
		// site specific table count
		$i = 0;

		$site = $this->get($id);

		$tables = $this->db->list_tables();

		foreach ($tables AS $table)
		{
			if (strpos($table, $site->ref.'_') === (int) 0)
			{
				$i++;
			}
		}

		$site->tables = $i;

		// now get file system usage
		$site->disk_usage = array();
		foreach ($this->locations AS $location => $sub)
		{
			$size = 0;

			$folder = get_dir_file_info($location.'/'.$site->ref, false);

			if (is_array($folder))
			{
				foreach ($folder AS $file)
				{
					$size = ($size + $file['size']);
				}
			}
			$site->disk_usage[$location.'/'.$site->ref] = $size;
		}
		ksort($site->disk_usage);

		// get number of users
		$this->db->set_dbprefix($site->ref.'_');
		$site->users = $this->db->count_all('users');

		// get last login of an admin
		$admin_login = $this->db->select_max('last_login')
			->get('users')
			->row();
		$site->admin_login = $admin_login->last_login;

		// and schema version
		$schema = $this->db->get('migrations')->row();
		$site->schema_version = $schema->version;

		return $site;
	}

	/**
	 * Create a new site's folder set
	 *
	 * return true on success or array of failed folders
	 *
	 * @param	string	$new_ref	The new site ref
	 * @return	boolean
	 */
	private function _make_folders($new_ref)
	{
		$unwritable = array();

		foreach ($this->locations AS $location => $sub_folders)
		{
			//check perms and log the failures
			if ( ! is_really_writable($location))
			{
				if (is_array($sub_folders))
				{
					foreach ($sub_folders AS $folder)
					{
						$unwritable[] = $location.'/'.$new_ref.'/'.$folder.'/index.html';
					}
				}
				else
				{
					$unwritable[] = $location.'/'.$new_ref.'/index.html';
				}
			}
			// it's writable, time to create
			else
			{
				if (count($sub_folders) > 0)
				{
					foreach ($sub_folders AS $folder)
					{
						if ( ! is_dir($location.'/'.$new_ref.'/'.$folder))
						{
							@mkdir($location.'/'.$new_ref.'/'.$folder, 0777, true);
							write_file($location.'/'.$new_ref.'/'.$folder.'/index.html', '');
							write_file($location.'/'.$new_ref.'/index.html', '');
						}
					}
				}
				else
				{
					if ( ! is_dir($location.'/'.$new_ref))
					{
						@mkdir($location.'/'.$new_ref, 0777, true);
						write_file($location.'/'.$new_ref.'/index.html', '');
					}
				}
			}
		}
		return (count($unwritable) > 0) ? $unwritable : true;
	}

	/**
	 * Rename an array of folders
	 *
	 * return true on success or array of failed folders
	 *
	 * @param	string	$new_ref	The new site ref
	 * @param	string	$old_ref	The old site's ref
	 * @return	boolean
	 */
	private function _rename_folders($new_ref, $old_ref)
	{
		$unwritable = array();

		foreach ($this->locations AS $location => $sub_folders)
		{
			if ( ! is_really_writable($location.'/'.$old_ref))
			{
				// log it so we can tell them to do it manually
				$unwritable[$location.'/'.$old_ref] = $location.'/'.$new_ref;
			}
			else
			{
				rename($location.'/'.$old_ref, $location.'/'.$new_ref);
			}
		}
		return (count($unwritable) > 0) ? $unwritable : true;
	}

	/**
	 * Remove the folders when a site is deleted
	 *
	 * @param string $ref The site ref to delete
	 * @return bool
	 */
	private function _remove_tree($root)
	{
		$status = array();

        if(is_file($root))
		{
           @unlink($root);
        }
        elseif(is_dir($root))
		{
            $scan = glob(rtrim($root,'/').'/*');
			foreach($scan AS $index => $path)
			{
				$this->_remove_tree($path);
            }
			@rmdir($root);
        }
	}
}