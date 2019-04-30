<?php
// $HeadURL: https://joomgallery.org/svn/joomgallery/JG-2.0/Plugins/JoomAdditionalCategoryFields/trunk/joomadditionalcategoryfields.php $
// $Id: joomadditionalcategoryfields.php 3751 2012-04-12 09:11:29Z chraneco $
/****************************************************************************************\
**   JoomAdditionalCategoryFields plugin 1.0                                            **
**   By: JoomGallery::ProjectTeam                                                       **
**   Copyright (C) 2012  JoomGallery::ProjectTeam                                       **
**   Based on: JoomGallery 1.0.0 by JoomGallery::ProjectTeam                            **
**   Released under GNU GPL Public License                                              **
**   License: http://www.gnu.org/copyleft/gpl.html or have a look                       **
**   at administrator/components/com_joomgallery/LICENSE.TXT                            **
\****************************************************************************************/

defined('_JEXEC') or die;

/**
 * Plugin for using custom additional fields for JoomGallery categories
 *
 * @package JoomGallery
 * @since   1.0
 */
class plgJoomGalleryJoomAdditionalCategoryFields extends JPlugin
{
  /**
   * Constructor
   *
   * @param   object  $subject  The object to observe
   * @param   array   $config   An array that holds the plugin configuration
   * @return  void
   * @since   1.0
   */
  public function __construct(& $subject, $config)
  {
    parent::__construct($subject, $config);
    $this->loadLanguage();
    $this->checkXmlFile();
  }

  /**
   * onJoomAfterDisplayCatThumb event
   * Method is called by the view
   *
   * @param   object  $category Category data of the category displayed
   * @return  string  HTML code which will be placed inside 'ul' tags
   * @since   1.0
   */
  public function onJoomAfterDisplayCatThumb($category)
  {
    if(!$this->params->get('display_after_thumb', 1))
    {
      return '';
    }

    $html = '';
    if(!$fields = $this->getAdditionalData($category))
    {
      return $html;
    }

    $html = '<li>'.implode('</li><li>', $fields).'</li>';

    return $html;
  }

  /**
   * onContentPrepareForm event
   * Method is called after the form was instantiated
   *
   * @param   object  $form The form to be altered
   * @param   array   $data The associated data for the form
   * @return  boolean True on success, false otherwise
   * @since   1.0
   */
  public function onContentPrepareForm($form, $data)
  {
    if(!($form instanceof JForm))
    {
      $this->_subject->setError('JERROR_NOT_A_FORM');

      return false;
    }

    // Check we are manipulating a valid form
    $name = $form->getName();
    if(!in_array($name, array(_JOOM_OPTION.'.category', _JOOM_OPTION.'.editcategory')))
    {
      return true;
    }

    // Add the registration fields to the form
    JForm::addFormPath(dirname(__FILE__).'/additionalfields');
    $form->loadFile('additional', false);

    return true;
  }

  /**
   * onContentPrepareData event
   * Method is called when data is retrieved for preparing a form
   *
   * @param   string  $context  The context for the data
   * @param   object  $data     The image data object
   * @return  void
   * @since   1.0
   */
  public function onContentPrepareData($context, $data)
  {
    // Check if we are manipulating a valid form
    if(!in_array($context, array(_JOOM_OPTION.'.category', _JOOM_OPTION.'.editcategory')))
    {
      return;
    }

    if(is_object($data) && !isset($data->additional) && isset($data->cid) && $data->cid)
    {
      // Load the profile data from the database.
      $db = JFactory::getDbo();
      $query = $db->getQuery(true)
            ->select('details_key, details_value')
            ->from(_JOOM_TABLE_CATEGORY_DETAILS)
            ->where('id = '.(int) $data->cid)
            ->where('details_key LIKE '.$db->q('additional.%'))
            ->order('ordering');
      $db->setQuery($query);
      $results = $db->loadRowList();

      // Check for a database error.
      if($db->getErrorNum())
      {
        $this->_subject->setError($db->getErrorMsg());

        return;
      }

      // Merge the profile data
      $data->additional = array();

      JForm::addFormPath(dirname(__FILE__).'/additionalfields');
      $form = JForm::getInstance('plg_joomadditionalcategoryfields.form', 'additional');
      foreach($results as $v)
      {
        $k = str_replace('additional.', '', $v[0]);
        if($form->getField($k, 'additional'))
        {
          $data->additional[$k] = $v[1];
        }
      }
    }
  }

  /**
   * onContentAfterSave event
   * Method is called after a category was stored successfully
   *
   * @param   string  $context  The context of the store action
   * @param   object  $table    The table object which was used for storing the category
   * @param   boolean $isNew    Determines wether it is a new category which was stored
   * @return  void
   * @since   1.0
   */
  public function onContentAfterSave($context, &$table, $isNew)
  {
    if(!isset($table->cid) || !$table->cid || $context != _JOOM_OPTION.'.category')
    {
      return;
    }

    try
    {
      $db = JFactory::getDbo();
      $query = $db->getQuery(true)
            ->delete(_JOOM_TABLE_CATEGORY_DETAILS)
            ->where('id = '.(int) $table->cid)
            ->where('details_key LIKE '.$db->q('additional.%'));
      $db->setQuery($query);

      if(!$db->query())
      {
        throw new Exception($db->getErrorMsg());
      }

      $tuples = array();
      $order  = 1;

      $data = JRequest::getVar('additional', array(), 'post', 'array');
      JForm::addFormPath(dirname(__FILE__).'/additionalfields');
      $form = JForm::getInstance('plg_joomadditionalcategoryfields.form', 'additional');
      foreach($data as $k => $v)
      {
        if($form->getField($k, 'additional'))
        {
          $tuples[] = (int) $table->cid.','.$db->q('additional.'.$k).','.$db->q($v).','.$order++;
        }
      }

      if(count($tuples))
      {
        $query->clear()
              ->insert(_JOOM_TABLE_CATEGORY_DETAILS)
              ->values($tuples);
        $db->setQuery($query);

        if(!$db->query())
        {
          throw new Exception($db->getErrorMsg());
        }
      }
    }
    catch(Exception $e)
    {echo $e->getMessage();die;
      $this->_subject->setError($e->getMessage());

      return;
    }
  }

  /**
   * Removes all additional category data for the given category ID
   *
   * Method is called after a category is deleted from the database
   *
   * @param   string  $context  The context of the delete action
   * @param   object  $table    The table object which was used for deleting the category
   * @return  void
   * @since   1.0
   */
  public function onContentAfterDelete($context, $table)
  {
    if(!isset($table->cid) || !$table->cid || $context != _JOOM_OPTION.'.category')
    {
      return;
    }

    try
    {
      $db = JFactory::getDbo();
      $query = $db->getQuery(true)
            ->delete(_JOOM_TABLE_CATEGORY_DETAILS)
            ->where('id = '.(int) $table->cid)
            ->where('details_key LIKE '.$db->q('additional.%'));
      $db->setQuery($query);

      if(!$db->query())
      {
        throw new Exception($db->getErrorMsg());
      }
    }
    catch(Exception $e)
    {
      $this->_subject->setError($e->getMessage());

      return;
    }
  }

  /**
   * onJoomSearch event
   * Event is triggered whenever a search in JoomGallery contents is done
   *
   * @param   string  $searchstring The escaped search string
   * @param   array   $aliase       An array containing database table aliases for used JoomGallery tables
   * @param   string  $context      The context in which the search is done
   * @return  array   An associative array containing additional query parts for the search query
   * @since   1.0
   */
  public function onJoomSearch($searchstring, $aliases, $context = '')
  {
    return array( 'categories.where.or' => 'LOWER(additionalcategory.details_value) LIKE \'%'.$searchstring.'%\'',
                  'categories.leftjoin' =>  _JOOM_TABLE_CATEGORY_DETAILS.' AS additionalcategory ON additionalcategory.id = '.$aliases['categories'].'.cid AND additionalcategory.details_key LIKE \'additional.%\'');
  }

  /**
   * onJoomGetCategoryDetailsPrefix event
   *
   * Not used yet. Could be called by maintenance functions for detecting
   * used prefixes in details database table in order to clean it up
   *
   * @return  array An array of used prefixes in details table
   * @since   1.0
   */
  public function onJoomGetCategoryDetailsPrefixes()
  {
    return array('additional');
  }

  /**
   * Internal method for retrieving and formatting additional data
   * for output in frontend
   *
   * @param   object  $category   Category data of the category for which the additional data shall be retieved
   * @param   string  $separator  String for formatting the output
   * @return  array   An array of formatted additional data fields
   * @since   1.0
   */
  protected function getAdditionalData($category, $separator = '%1$s: %2$s')
  {
    static $form = null;

    if(is_null($form))
    {
      JForm::addFormPath(dirname(__FILE__).'/additionalfields');
      $form = JForm::getInstance('plg_joomadditionalcategoryfields.form', 'additional');
    }

    if(is_object($category) && isset($category->cid))
    {
      $category = $category->cid;
    }

    // Load the additional data from the database
    $db = JFactory::getDbo();
    $query = $db->getQuery(true)
          ->select('details_key, details_value')
          ->from(_JOOM_TABLE_CATEGORY_DETAILS)
          ->where('id = '.(int) $category)
          ->where('details_key LIKE '.$db->q('additional.%'))
          ->order('ordering');
    $db->setQuery($query);
    $results = $db->loadRowList();

    // Check for a database error
    if($db->getErrorNum())
    {
      $this->_subject->setError($db->getErrorMsg());

      return false;
    }

    $fields = array();
    foreach($results as $result)
    {
      $k = str_replace('additional.', '', $result[0]);
      if($field = $form->getField($k, 'additional'))
      {
        switch($field->type)
        {

          // Create behavior for each field type

          case 'text':
          default:
            if(strlen($result[1]) > 0)
            {
              $key  = 'PLG_JOOMADDITIONALCATEGORYFIELDS_FIELD_'.strtoupper($k).'_VAR';
              if(JFactory::getLanguage()->hasKey($key))
              {
                $fields[] = JText::sprintf($key, $result[1]);
              }
              else
              {
                $fields[] = sprintf($separator, $field->title, $result[1]);
              }
            }
            break;
        }
      }
    }

    if(!count($fields))
    {
      return false;
    }

    return $fields;
  }

  /**
   * Checks existence of the XML file and outputs a notice if it was not found
   *
   * Additional this plugin is detached from all events, so it won't be called anymore
   *
   * @return  boolean True if file was found, false otherwise
   * @since   1.1
   */
  protected function checkXmlFile()
  {
    if(!is_file(dirname(__FILE__).'/additionalfields/additional.xml'))
    {
      JFactory::getApplication()->enqueueMessage(JText::_('PLG_JOOMGALLERY_JOOMADDITIONALCATEGORYFIELDS_MISSING_XML_FILE'), 'warning');

      $this->_subject->detach($this);
    }
  }
}