<?php
/**
 * @version    SVN: <svn_id>
 * @package    Com_Tjucm
 * @author     Techjoomla <extensions@techjoomla.com>
 * @copyright  Copyright (c) 2009-2017 TechJoomla. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

// No direct access
defined('_JEXEC') or die;

jimport('joomla.application.component.view');
jimport('joomla.application.component.controller');
jimport('joomla.filesystem.file');
jimport('joomla.database.table');

/**
 * View to edit
 *
 * @since  1.6
 */
class TjucmViewItemform extends JViewLegacy
{
	protected $state;

	protected $item;

	protected $form;

	protected $params;

	protected $canSave;

	/**
	 * Display the view
	 *
	 * @param   string  $tpl  Template name
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	public function display($tpl = null)
	{
		$app  = JFactory::getApplication();
		$input = $app->input;
		$user = JFactory::getUser();

		$this->state   = $this->get('State');
		$this->item    = $this->get('Data');
		$this->params  = $app->getParams('com_tjucm');
		$this->canSave = $this->get('CanSave');
		$this->form = $this->get('Form');
		$this->client = $input->get('client');
		$this->id = $input->get('id', '', 'INT');

		// If did not get the client from url then get if from menu param
		if (empty($this->client))
		{
			// Get the active item
			$menuItem = $app->getMenu()->getActive();

			// Get the params
			$this->menuparams = $menuItem->params;

			if (!empty($this->menuparams))
			{
				$this->ucm_type   = $this->menuparams->get('ucm_type');

				if (!empty($this->ucm_type))
				{
					$this->client     = 'com_tjucm.' . $this->ucm_type;
				}
			}
		}

		if (empty($this->client))
		{
			return JError::raiseError(404, JText::_('COM_TJUCM_ITEM_DOESNT_EXIST'));
		}

		// Check the view access to the itemform (the model has already computed the values).
		if ($this->item->params->get('access-view') == false)
		{
			$app->enqueueMessage(JText::_('JERROR_ALERTNOAUTHOR'), 'error');
			$app->setHeader('status', 403, true);

			return;
		}

		// Include models
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_tjucm/models');

		/* Get model instance here */
		$model = $this->getModel();

		// Get if user is allowed to save the content
		$tjUcmModelType = JModelLegacy::getInstance('Type', 'TjucmModel');
		$typeId = $tjUcmModelType->getTypeId($this->client);

		$typeData = $tjUcmModelType->getItem($typeId);

		// Check if the UCM type is unpublished
		if ($typeData->state == "0")
		{
			return JError::raiseError(404, JText::_('COM_TJUCM_ITEM_DOESNT_EXIST'));
		}

		// Set Layout to type view
		$layout = isset($typeData->params['layout']) ? $typeData->params['layout'] : '';
		$this->setLayout($layout);

		$allowedCount = $typeData->allowed_count;
		$user   = JFactory::getUser();
		$userId = $user->id;

		if (empty($this->id))
		{
			$this->allowedToAdd = $model->allowedToAddTypeData($userId, $this->client, $allowedCount);

			if (!$this->allowedToAdd)
			{
				JLoader::import('controllers.itemform', JPATH_SITE . '/components/com_tjucm');
				$itemFormController = new TjucmControllerItemForm;
				$itemFormController->redirectToListView($typeId, $allowedCount);
			}
		}

		$view = explode('.', $this->client);

		// Call to extra fields
		$this->form_extra = $model->getFormExtra(
		array(
			"clientComponent" => 'com_tjucm',
			"client" => $this->client,
			"view" => $view[1],
			"layout" => 'edit',
			"content_id" => $this->id)
			);

		// Check if draft save is enabled for the form
		JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_tjucm/tables');
		$tjUcmTypeTable = JTable::getInstance('Type', 'TjucmTable');
		$tjUcmTypeTable->load(array('unique_identifier' => $this->client));
		$typeParams = json_decode($tjUcmTypeTable->params);

		$this->allow_auto_save = (isset($typeParams->allow_auto_save) && empty($typeParams->allow_auto_save)) ? 0 : 1;
		$this->allow_draft_save = (isset($typeParams->allow_draft_save) && !empty($typeParams->allow_draft_save)) ? 1 : 0;

		// Check for errors.
		if (count($errors = $this->get('Errors')))
		{
			throw new Exception(implode("\n", $errors));
		}

		// Ucm triggger before item form display
		JPluginHelper::importPlugin('tjucm');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('tjucmOnBeforeItemFormDisplay', array(&$this->item, &$this->form_extra));

		$this->_prepareDocument();

		parent::display($tpl);
	}

	/**
	 * Prepares the document
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	protected function _prepareDocument()
	{
		$app   = JFactory::getApplication();
		$menus = $app->getMenu();
		$title = null;

		// Because the application sets a default page title,
		// we need to get it from the menu item itself
		$menu = $menus->getActive();

		if ($menu)
		{
			$this->params->def('page_heading', $this->params->get('page_title', $menu->title));
		}
		else
		{
			$this->params->def('page_heading', JText::_('COM_TJUCM_DEFAULT_PAGE_TITLE'));
		}

		$title = $this->params->get('page_title', '');

		if (empty($title))
		{
			$title = $app->get('sitename');
		}
		elseif ($app->get('sitename_pagetitles', 0) == 1)
		{
			$title = JText::sprintf('JPAGETITLE', $app->get('sitename'), $title);
		}
		elseif ($app->get('sitename_pagetitles', 0) == 2)
		{
			$title = JText::sprintf('JPAGETITLE', $title, $app->get('sitename'));
		}

		$this->document->setTitle($title);

		if ($this->params->get('menu-meta_description'))
		{
			$this->document->setDescription($this->params->get('menu-meta_description'));
		}

		if ($this->params->get('menu-meta_keywords'))
		{
			$this->document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
		}

		if ($this->params->get('robots'))
		{
			$this->document->setMetadata('robots', $this->params->get('robots'));
		}
	}
}
