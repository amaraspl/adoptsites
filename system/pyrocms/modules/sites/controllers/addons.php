<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * This is the multi-site management module
 *
 * @author 		Jerel Unruh - PyroCMS Dev Team
 * @website		http://unruhdesigns.com
 * @package 	PyroCMS Premium
 * @subpackage 	Site Manager Module
 */
class Addons extends Sites_Controller
{
	public function __construct()
	{
		parent::__construct();
		
		$this->ref		= $this->uri->segment(4);
		$this->type		= $this->uri->segment(5);
		$this->slug		= $this->uri->segment(6);
		$this->shared	= (bool) $this->uri->segment(7);

		$this->db->set_dbprefix($this->ref.'_');
		
		$this->load->model('addons_m');
	}
	
	/**
	 * Index method
	 * @access public
	 * @return void
	 */
	public function index()
	{
		// set it so we can access the core_sites table
		$this->db->set_dbprefix('core_');
		$data->site = $this->sites_m->get_by('ref', $this->ref);
		
		// now we set it back
		$this->db->set_dbprefix($this->ref.'_');
		
		if ($data->site)
		{
			$data->modules 	= $this->addons_m->index_modules($data->site->ref);
			$data->widgets 	= $this->addons_m->index_widgets($data->site->ref);
			$data->themes 	= $this->addons_m->index_themes($data->site->ref);
			$data->plugins 	= $this->addons_m->index_plugins($data->site->ref);
		}

		$this->template->title(lang('site.sites'))
						->set('description', lang('site.manage_addons_desc'))
						->build('addons', $data);
	}
	
	/**
	 * Display the upload modal
	 *
	 * @return	void
	 */
	public function upload()
	{
		$this->template
			->set_layout('modal')
			->build('upload');
	}

	/**
	 * Upload
	 *
	 * Uploads an addon module
	 *
	 * @access	public
	 * @return	void
	 */
	public function do_upload()
	{
		$path = ($this->shared) ? SHARED_ADDONPATH : ADDON_FOLDER.$this->ref.'/';
		
		if ($this->input->post('btnAction') == 'upload')
		{
			
			$config['upload_path'] 		= UPLOAD_PATH;
			$config['allowed_types'] 	= 'zip';
			$config['max_size']			= '2048';
			$config['overwrite'] 		= TRUE;

			$this->load->library('upload', $config);

			if ($this->upload->do_upload())
			{
				$upload_data = $this->upload->data();

				// Check if we already have an addon with same name
				if ($this->addons_m->exists($upload_data['raw_name']))
				{
					$this->session->set_flashdata('error', sprintf(lang('modules.already_exists_error'), $upload_data['raw_name']));
				}

				else
				{
					// Now try to unzip
					$this->load->library('unzip');
					$this->unzip->allow(array('xml', 'html', 'css', 'js', 'png', 'gif', 'jpeg', 'jpg', 'swf', 'ico', 'php'));

					// Try and extract
					if ( ! is_string($this->slug = $this->unzip->extract($upload_data['full_path'], $path.$this->type.'s', TRUE, TRUE)) )
					{
						$this->session->set_flashdata('error', $this->unzip->error_string());
					}
				}

				// Delete uploaded file
				@unlink($upload_data['full_path']);

			}

			else
			{
				$this->session->set_flashdata('error', $this->upload->display_errors());
			}

			redirect('sites/addons/index/'.$this->ref);
		}
	}
	
	/**
	 * Uninstall
	 *
	 * Uninstalls an addon
	 *
	 * @access	public
	 * @return	void
	 */
	public function uninstall()
	{
		if ($this->addons_m->uninstall())
		{
			$this->session->set_flashdata('success', sprintf(lang('modules.uninstall_success'), $this->slug));

			redirect('sites/addons/index/'.$this->ref);
		}

		$this->session->set_flashdata('error', sprintf(lang('modules.uninstall_error'), $this->slug));
		redirect('sites/addons/index/'.$this->ref);
	}

	/**
	 * Delete
	 *
	 * Completely deletes an addon
	 *
	 * @access	public
	 * @return	void
	 */
	public function delete()
	{
		$status = array();
		$slug	= $this->slug;
		
		// Don't allow user to delete the entire module folder
		if ($this->slug == '/' OR $this->slug == '*' OR empty($this->slug))
		{
			show_error(lang('modules.module_not_specified'));
		}
		
		// only modules and widgets need attention at the database level
		switch ($this->type)
		{
			case 'module':
				$status[] = $this->addons_m->uninstall();
				$status[] = $this->addons_m->delete();
				break;
			case 'widget':
				$status[] = $this->addons_m->delete();
				break;
			case 'plugin':
				$slug = $this->slug.'.php';
		}

		// delete the files
		if ( ! in_array(FALSE, $status))
		{
			$this->session->set_flashdata('success', sprintf(lang('modules.delete_success'), $slug));

			if ($this->shared == '1')
			{
				$path = SHARED_ADDONPATH . $this->type . 's/' . $slug;
			}
			else
			{
				$path = ADDON_FOLDER . $this->ref . '/' . $this->type . 's/' . $slug;
			}
			
			// and... delete
			if (!$this->_delete_recursive($path))
			{
				$this->session->set_flashdata('notice', sprintf(lang('modules.manually_remove'), $path));
			}

			redirect('sites/addons/index/'.$this->ref);
		}

		$this->session->set_flashdata('error', sprintf(lang('modules.delete_error'), $slug));
		redirect('sites/addons/index/'.$this->ref);
	}

	/**
	 * Install
	 *
	 * Installs an addon module
	 *
	 * @access	public
	 * @return	void
	 */
	public function install()
	{
		if ($this->addons_m->install())
		{
			$this->session->set_flashdata('success', sprintf(lang('modules.install_success'), $this->slug));
		}
		else
		{
			$this->session->set_flashdata('error', sprintf(lang('modules.install_error'), $this->slug));
		}

		redirect('sites/addons/index/'.$this->ref);
	}

	/**
	 * Enable
	 *
	 * Enables an addon
	 *
	 * @access	public
	 * @return	void
	 */
	public function enable()
	{
		if ($this->addons_m->enable())
		{
			$this->session->set_flashdata('success', sprintf(lang('modules.enable_success'), $this->slug));
		}
		else
		{
			$this->session->set_flashdata('error', sprintf(lang('modules.enable_error'), $this->slug));
		}

		redirect('sites/addons/index/'.$this->ref);
	}

	/**
	 * Disable
	 *
	 * Disables an addon
	 *
	 * @access	public
	 * @return	void
	 */
	public function disable()
	{
		if ($this->addons_m->disable())
		{
			$this->session->set_flashdata('success', sprintf(lang('modules.disable_success'), $this->slug));
		}
		else
		{
			$this->session->set_flashdata('error', sprintf(lang('modules.disable_error'), $this->slug));
		}

		redirect('sites/addons/index/'.$this->ref);
	}
	
	/**
	 * Upgrade
	 *
	 * Upgrade an addon module
	 *
	 * @access	public
	 * @return	void
	 */
	public function upgrade()
	{		
		// If upgrade succeeded
		if ($this->addons_m->upgrade())
		{
			$this->session->set_flashdata('success', sprintf(lang('modules.upgrade_success'), $this->slug));
		}
		// If upgrade failed
		else
		{
			$this->session->set_flashdata('error', sprintf(lang('modules.upgrade_error'), $this->slug));
		}
		
		redirect('sites/addons/index/'.$this->ref);
	}

	/**
	 * Delete Recursive
	 *
	 * Recursively delete a folder
	 *
	 * @param	string	$str	The path to delete
	 * @return	bool
	 */
	private function _delete_recursive($str)
	{
        if (is_file($str))
		{
            return @unlink($str);
        }
		elseif (is_dir($str))
		{
            $scan = glob(rtrim($str,'/').'/*');

			foreach($scan as $index => $path)
			{
                $this->_delete_recursive($path);
            }

            return @rmdir($str);
        }
    }
}
