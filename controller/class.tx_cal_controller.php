<?php
/***************************************************************
 * Copyright notice
 *
 * (c) 2005-2008 Mario Matzulla
 * (c) 2005-2008 Steffen Kamper
 * (c) 2005-2008 Christian Technology Ministries International Inc.
 * All rights reserved
 *
 * This file is part of the Web-Empowered Church (WEC)
 * (http://WebEmpoweredChurch.org) ministry of Christian Technology Ministries 
 * International (http://CTMIinc.org). The WEC is developing TYPO3-based
 * (http://typo3.org) free software for churches around the world. Our desire
 * is to use the Internet to help offer new life through Jesus Christ. Please
 * see http://WebEmpoweredChurch.org/Jesus.
 *
 * You can redistribute this file and/or modify it under the terms of the 
 * GNU General Public License as published by the Free Software Foundation;
 * either version 2 of the License, or (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This file is distributed in the hope that it will be useful for ministry,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the file!
 ***************************************************************/
#for BE calls
if (!defined('PATH_tslib')) define('PATH_tslib', t3lib_extMgm::extPath('cms').'tslib/');

require_once (PATH_tslib.'class.tslib_pibase.php');
require_once (t3lib_extMgm::extPath('cal').'controller/class.tx_cal_modelcontroller.php');
require_once (t3lib_extMgm::extPath('cal').'controller/class.tx_cal_viewcontroller.php');
require_once (t3lib_extMgm::extPath('cal').'controller/class.tx_cal_registry.php');
require_once(t3lib_extMgm::extPath('cal').'service/class.tx_cal_rights_service.php');
require_once (t3lib_extMgm::extPath('cal').'model/class.tx_cal_model.php');

/**
 * Main controller for the calendar base.  All requests come through this class
 * and are routed to the model and view layers for processing.
 *
 * @author Jeff Segars <jeff@webempoweredchurch.org>
 * @package TYPO3
 * @subpackage cal
 */
class tx_cal_controller extends tslib_pibase {

	var $prefixId = 'tx_cal_controller'; // Same as class name
	var $scriptRelPath = 'controller/class.tx_cal_controller.php'; // Path to this script relative to the extension dir.
	var $extKey = 'cal'; // The extension key.
	var $pi_checkCHash = FALSE;
	var $dayStart;
	var $ext_path;
	var $cObj; // The backReference to the mother cObj object set at call time
	var $local_cObj;
	var $link_vars;
	var $pointerName = 'offset';
	var $error = false;

	var $getDateTimeObject;


	/**
	 *  Main controller function that serves as the entry point from TYPO3.
	 *  @param		array		The content array.
	 *	@param		array		The conf array.
	 *	@return		string		HTML-representation of calendar data.
	 */
	function main($content, $conf) {

		#debug('Start:'.tx_cal_functions::getmicrotime());
		$this->conf = &$conf;

		// switch for more intelligent caching
		if($this->conf['isUserInt']) {
			#$this->pi_USER_INT_obj=1;
		} else {
			$this->pi_checkCHash = TRUE;
			if(count($this->piVars)) {
				$GLOBALS['TSFE']->reqCHash();
			}
			$this->pi_USER_INT_obj=0;
		}

		// Set the week start day, and then include tx_cal_date so that the week start day is already defined.
		$this->setWeekStartDay();
		require_once(t3lib_extMgm::extPath('cal').'model/class.tx_cal_date.php');
		require_once(t3lib_extMgm::extPath('cal').'res/pearLoader.php');

		$this->cleanPiVarParam($this->piVars);
		$this->clearPiVarParams();
		$this->getParamsFromSession();

		$return = $this->initConfigs();

		if(!$this->error){
			$return .= $this->getContent();
		}
		#debug('End:'.tx_cal_functions::getmicrotime());

		return $return;
	}
	
	/**
	 * Cleans all piVars for XSS vulnerabilities using external library and
	 * updates values within $this->piVars as it cleans.
	 *
	 * @param	mixed	Array of nested piVars or individual piVar value.
	 * @return	none
	 */
	function cleanPiVarParam(&$param) {
		if (is_array($param)) {
			$arrayKeys = array_keys($param);
			foreach ($arrayKeys as $key) {
				$this->cleanPiVarParam($param[$key]);
			}
		} else {
			// Don't use default replaceString of <x> because strip-tags will later remove it.
			$param = tx_cal_functions::removeXSS($param, '--xxx--');
		}
	}

	function getContent($notEmpty = true){
		$return = '';
		$count = 0;
		do {
			//category check:
			$catArray = t3lib_div::trimExplode(',',$this->conf['category'],1);
			$allowedCatArray = t3lib_div::trimExplode(',',$this->conf['view.']['allowedCategory'],1);
			$compareResult = array_diff($allowedCatArray,$catArray);
			if(empty($compareResult) && $this->conf['view'] != 'create_event' && $this->conf['view'] != 'edit_event'){
				unset($this->piVars['category']);
			}
			$count++; //Just to make sure we are not getting an endless loop				
			/* Convert view names (search_event) to function names (searchevent) */
			$viewFunction = str_replace('_', '', $this->conf['view']);
				
			/* @todo  Hack!  List is a reserved name so we have to change the function name. */
			if ($viewFunction == 'list') {
				$viewFunction = 'listView';
			}
				
			if(method_exists($this, $viewFunction)) {
				/* Call appropriate view function */
				$return .= $this->$viewFunction();
			} else {
				$customModel = t3lib_div::makeInstanceService('tx_cal_custom_model', $this->conf['view']);
				if(!is_object($customModel)){
					$return .= $this->conf['view.']['noViewFoundHelpText'].' '.$viewFunction;
				}else{
					$return .= $customModel->start();
				}
			}
		} while ($return == '' && $count<4 && $notEmpty);
		
		$return = $this->finish($return);
		
		if($this->conf['view']=='rss' || $this->conf['view']=='ics' || $this->conf['view']=='single_ics' || $this->conf['view']=='load_events' || $this->conf['view']=='load_todos' || $this->conf['view']=='load_rights'){
			return $return;
		}
		if($this->conf['view.'][$this->conf['view'].'.']['sendOutWithXMLHeader']){
			header('Content-Type: text/xml');
		}
		
		$additionalWrapperClasses = t3lib_div::trimExplode(',',$this->conf['additionalWrapperClasses'],1);

		if($this->conf['noWrapInBaseClass'] || $this->conf['view.']['enableAjax']){
			return $return;
		}
		return $this->pi_wrapInBaseClass($return, $additionalWrapperClasses);
	}

	function initConfigs(){
		// If an event record has been added through Insert Records, set some defaults.
		if($this->conf['displayCurrentRecord']) {
			$data = &$this->cObj->data;
			$this->conf['pidList'] = $data['pid'];
			$this->conf['view.']['allowedViews'] = 'event';
			$this->conf['getdate'] = $this->conf['_DEFAULT_PI_VARS.']['getdate'] = $data['start_date'];
			$this->conf['uid'] = $this->conf['_DEFAULT_PI_VARS.']['uid'] = $data['uid'];
			$this->conf['type'] = $this->conf['_DEFAULT_PI_VARS.']['type'] = 'tx_cal_phpicalendar';
			$this->conf['view'] = $this->conf['_DEFAULT_PI_VARS.']['view'] = 'event';
		}

		if (!$this->conf['dontListenToPiVars']) {
			$this->pi_setPiVarDefaults(); // Set default piVars from TS
		}

		//Jan 18032006 start
		if ($this->cObj->data['pi_flexform']) {
			$this->pi_initPIflexForm(); // Init and get the flexform data of the plugin
			$piFlexForm = $this->cObj->data['pi_flexform'];
			$this->updateConfWithFlexform($piFlexForm);
		}

		//apply stdWrap to pages and pidList
		$this->conf['pages'] = $this->cObj->stdWrap($this->conf['pages'],$this->conf['pages.']);
		$this->conf['pidList'] = $this->cObj->stdWrap($this->conf['pidList'],$this->conf['pidList.']);

		$this->updateIfNotEmpty($this->conf['pages'], $this->cObj->data['pages']);
		// don't use "updateIfNotEmpty" here, as the default value of "recursive" is 0 and thus not empty and will always override TS settings.
		if($this->cObj->data['recursive']) {
			$this->conf['recursive'] = $this->cObj->data['recursive'];
		}

		$this->conf['pidList'] = $this->pi_getPidList($this->conf['pages'].','.$this->conf['pidList'], $this->conf['recursive']);

		if(!$this->conf['pidList'] || $this->conf['pidList']==''){
			$this->error = true;
			return '<b>Calendar error: please configure the pidList (calendar plugin -> startingpoints or plugin.tx_cal_controller.pidList or for ics in constants)</b>';
		}

		if ($this->conf['language'])
		$this->LLkey = $this->conf['language'];
		$this->pi_loadLL();

		$this->conf['cache']=1;
		if (t3lib_div::int_from_ver(TYPO3_version) >= 4003000) {
			$GLOBALS['TSFE']->addCacheTags(array('cal'));
		} else {
			$GLOBALS['TSFE']->page_cache_reg1 = 77;
		}

		$location = $this->convertLinkVarArrayToList($this->piVars['location_ids']);

		if($this->piVars['view'] == $this->piVars['lastview']){
			unset($this->piVars['lastview']);
		}

		if ($this->piVars['getdate'] == '') {
			$this->conf['getdate'] = date('Ymd');
		}else{
			$this->conf['getdate'] = intval($this->piVars['getdate']);
		}

		if ($this->piVars['jumpto']) {
			require_once(t3lib_extMgm::extPath('cal').'controller/class.tx_cal_dateParser.php');
			$dp = new tx_cal_dateParser();
			$dp->parse($this->piVars['jumpto'],$this->conf['dateParserConf.']);
			$newGetdate = $dp->getDateObjectFromStack();
			$this->conf['getdate'] = $newGetdate->format('%Y%m%d');
			unset($this->piVars['getdate']);
			unset($this->piVars['jumpto']);
		}

		// date and strtotime should be ok here
		if($this->conf['getdate'] <= date('Ymd',strtotime($this->conf['view.']['startLinkRange'])) || $this->conf['getdate'] >= date('Ymd',strtotime($this->conf['view.']['endLinkRange']))){
			// Set additional META-Tag for google et al
			$GLOBALS['TSFE']->additionalHeaderData['cal'] = '<meta name="robots" content="index,nofollow" />';
				
			// Set / override no_search for current page-object
			$GLOBALS['TSFE']->page['no_search'] = 0;
		}
		
		if(!$this->conf['dontListenToPiVars']) {
			$this->conf['view'] = htmlspecialchars(strip_tags($this->piVars['view']));
			$this->conf['lastview'] = htmlspecialchars(strip_tags($this->piVars['lastview']));
			$this->conf['uid'] = intval($this->piVars['uid']);
			$this->conf['type'] = htmlspecialchars(strip_tags($this->piVars['type']));
			$this->conf['monitor'] = htmlspecialchars(strip_tags($this->piVars['monitor']));
			$this->conf['gettime'] = intval($this->piVars['gettime']);
			$this->conf['postview'] = intval($this->piVars['postview']);
			$this->conf['page_id'] = intval($this->piVars['page_id']);
			$this->conf['option'] = htmlspecialchars(strip_tags($this->piVars['option']));
			$this->conf['switch_calendar'] = intval($this->piVars['switch_calendar']);
			$this->conf['location'] = $location;
			$this->conf['preview'] = intval($this->piVars['preview']);
		}

		if(!is_array($this->conf['view.']['allowedViews'])){
			$this->conf['view.']['allowedViews'] = array_unique(t3lib_div::trimExplode(',',str_replace('~',',',$this->conf['view.']['allowedViews'])));
		}
		
		// only merge customViews if not empty. Otherwhise the array with allowedViews will have empty entries which will end up in wrong behavior in the rightsServies, which is checking for the number of allowed views.
		if(!empty($this->conf['view.']['customViews'])) {
			$this->conf['view.']['allowedViews'] = array_unique(array_merge($this->conf['view.']['allowedViews'],t3lib_div::trimExplode(',',$this->conf['view.']['customViews'],1)));
		}
		
		$allowedViewsByViewPid = $this->getAllowedViewsByViewPid();
		$this->conf['view.']['allowedViewsToLinkTo'] = array_unique(array_merge($this->conf['view.']['allowedViews'],$allowedViewsByViewPid));

		// change by Franz: if there is no view parameter given (empty), fall back to the first allowed view
		// This is necessary when you're not passing the viewParameter within the URL and like to handle the correct views based on seperate pages for each view.
		if(!$this->conf['view'] && $this->conf['view.']['allowedViews'][0]) {
			$this->conf['view'] = $this->conf['view.']['allowedViews'][0];
		}

		$this->getDateTimeObject = new tx_cal_date($this->conf['getdate'].'000000');
		$this->getDateTimeObject->setTZbyId('UTC');
		$this->conf['day'] = $this->getDateTimeObject->getDay();
		$this->conf['month'] = $this->getDateTimeObject->getMonth();
		$this->conf['year'] = $this->getDateTimeObject->getYear();

		tx_cal_controller::initRegistry($this);
		$rightsObj = &tx_cal_registry::Registry('basic','rightscontroller');
		$rightsObj = t3lib_div::makeInstanceService('cal_rights_model', 'rights');
		$rightsObj->setDefaultSaveToPage();

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$modelObj = new tx_cal_modelcontroller();

		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$viewObj = new tx_cal_viewcontroller();

		$this->checkCalendarAndCategory();

		$this->conf['view'] = $rightsObj->checkView($this->conf['view']);

		$this->pointerName = $this->conf['view.']['list.']['pageBrowser.']['pointer'] ? $this->conf['view.']['list.']['pageBrowser.']['pointer'] : $this->pointerName;

		//links to files will be rendered with an absolute path
		if(in_array($this->conf['view'], array('ics','rss','singl_ics'))){
			$GLOBALS['TSFE']->absRefPrefix = t3lib_div::getIndpEnv('TYPO3_SITE_URL');
		}

		$hookObjectsArr = $this->getHookObjectsArray('controllerClass');

		// Hook: configuration
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'configuration')) {
				$hookObj->configuration($this);
			}
		}
	}
	
	function checkCalendarAndCategory(){
		//new Mode - category can be configurred
		$category = '';
		$calendar = '';

		$this->confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);

		$allCategoryByParentId = array();
		$allCategoryById = array();
		$catIDs = array();
		$category = '';
		
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$rightsObj = &tx_cal_registry::Registry('basic','rightscontroller');

		#get all categories
		$categoryArray = $modelObj->findAllCategories('tx_cal_category','',$this->conf['pidList']);

		foreach((Array)$categoryArray['tx_cal_category'][0][0] as $category){
			$row = $category->row;
			$allCategoryByParentId[$row['parent_category']][] = $row;
			$allCategoryById[$row['uid']] = $row;
			$catIDs[]=$row['uid'];
		}
		
		if($this->piVars['categorySelection']==1 && empty($this->piVars['category'])){
			$catIDs = Array();
		} else {
			unset($this->piVars['categorySelection']);
		}

		$this->conf['view.']['category'] = implode(',',$catIDs);
		if(!$this->conf['view.']['category']){
			$this->conf['view.']['category'] = '0';
		}
		$category = $this->conf['view.']['category'];
		$this->conf['view.']['allowedCategory'] = $this->conf['view.']['category'];


		$piVarCategory = $this->convertLinkVarArrayToList($this->piVars['category']);

		if($piVarCategory){
			if($this->conf['view.']['category']) {
				$categoryArray = explode(',',$category);
				$piVarCategoryArray = explode(',',$piVarCategory);
				$sameValues = array_intersect($categoryArray,$piVarCategoryArray);
				if(empty($sameValues)){
					$category = $this->conf['view.']['category'];
				} else {
					$category = $this->convertLinkVarArrayToList($sameValues);
				}
			} else {
				$category = $piVarCategory;
			}
			$category = is_array($category)?implode(',',$category):$category;
		}

		#Select calendars
		#get all first
		$allCalendars = Array();
		$calendarArray = $modelObj->findAllCalendar('tx_cal_calendar',$this->conf['pidList']);
		foreach((Array)$calendarArray['tx_cal_calendar'] as $calendarObject){
			$allCalendars[]=$calendarObject->getUid();
		}

		#compile calendar array
		switch($this->conf['view.']['calendarMode']) {
			case 0: # show all
				$calendar = $this->conf['view.']['calendar'] = $this->conf['view.']['allowedCalendar'] = implode(',',$allCalendars);
				break;
			case 1: #show selected
				if($this->conf['view.']['calendar']){
					$calendar = $this->conf['view.']['calendar'];
					$this->conf['view.']['allowedCalendar'] = $this->conf['view.']['calendar'];
				}
				break;
			case 2: #exclude selected
				if($this->conf['view.']['calendar']){
					$calendar = $this->conf['view.']['calendar'] = implode(',',array_diff($allCalendars,explode(',',$this->conf['view.']['calendar'])));
					$this->conf['view.']['allowedCalendar'] = $this->conf['view.']['calendar'];
				} else {
					$calendar = $this->conf['view.']['calendar'] = implode(',',$allCalendars);
					$this->conf['view.']['allowedCalendar'] = $this->conf['view.']['calendar'];
				}
				break;
		}
		
		if($rightsObj->isLoggedIn()){
			$select = 'tx_cal_calendar_subscription';
			$table = 'fe_users';
			$where = 'uid = '.$rightsObj->getUserId();
			$result = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where);
			if($result) {
				while($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)){
					$this->conf['view.']['calendar.']['subscription'] = $row['tx_cal_calendar_subscription'];
				}
				$GLOBALS['TYPO3_DB']->sql_free_result($result);
			}
		}
		
		if($this->conf['view.']['calendar.']['subscription']!=''){
			$calendar = $this->conf['view.']['allowedCalendar'] = $this->conf['view.']['calendar'] = implode(',',array_diff(explode(',',$calendar),explode(',',$this->conf['view.']['calendar.']['subscription'])));
		}


		$piVarCalendar = $this->convertLinkVarArrayToList($this->piVars['calendar']);
		if($piVarCalendar){
			if($this->conf['view.']['calendar']) {
				$calendarArray = explode(',',$calendar);
				$piVarCalendarArray = explode(',',$piVarCalendar);
				$sameValues = array_intersect($calendarArray,$piVarCalendarArray);
				$calendar = $this->convertLinkVarArrayToList($sameValues);
			} else {
				$calendar=$piVarCalendar;
			}
			$calendar = is_array($calendar)?implode(',',$calendar):$calendar;
		}
		
		if($this->conf['view.']['freeAndBusy.']['enable']){
			$this->conf['option'] = 'freeandbusy';
			$this->conf['view.']['calendarMode'] = 1;
			$calendar = intval($this->piVars['calendar'])?intval($this->piVars['calendar']):$this->conf['view.']['freeAndBusy.']['defaultCalendarUid'];
			$this->conf['view.']['calendar'] = $calendar;
		}

		$this->conf['category'] = $category;
		$this->conf['calendar'] = $calendar;
		$this->conf['view.']['allowedCategories'] = $category;
		$this->conf['view.']['allowedCalendar'] = $calendar;
	}

	/*
	 * Sets up a hook in the controller's PHP file with the specified name.
	 * @param	string	The name of the hook.
	 * @return	array	The array of objects implementing this hoook.
	 */
	function getHookObjectsArray($hookName) {
		return tx_cal_functions::getHookObjectsArray('tx_cal_controller',$hookName);
	}

	/*
	 * Executes the specified function for each item in the array of hook objects.
	 * @param	array	The array of hook objects.
	 * @param	string	The name of the function to execute.
	 * @return	none
	 */
	function executeHookObjectsFunction($hookObjectsArray, $function) {
		foreach ($hookObjectsArray as $hookObj) {
			if (method_exists($hookObj, $function)) {
				$hookObj->$function($this);
			}
		}
	}

	/*
	 * Clears $this-conf vars related to view and lastview.  Useful when calling save and remove functions.
	 * @return		none
	 */
	function clearConfVars() {
		$this->initConfigs();
		$viewParams = $this->shortenLastViewAndGetTargetViewParameters(true);
		$this->conf['view'] = $viewParams['view'];
		$this->conf['lastview'] = '';
		$rightsObj = &tx_cal_registry::Registry('basic','rightscontroller');
		$this->conf['view'] = $rightsObj->checkView($this->conf['view']);
		$this->conf['uid'] = $viewParams['uid'];
		$this->conf['type'] = $viewParams['type'];
	}

	function getAllowedViewsByViewPid() {
		//for now, ownly check basic views.
		$allowedViews = array();
		$regularViews = array('day','week','month','year','list','event','location','organizer');
		$feEditingViews = array('event','location','organizer','calendar','category');
		$editingTypes = array('create','edit','delete');

		foreach ($regularViews as $view) {
			if($this->conf['view.'][$view.'.'][$view.'ViewPid']) {
				$allowedViews[] = $view;
			}
		}
		
		foreach ($feEditingViews as $view) {
			foreach($editingTypes as $type) {
				if($this->conf['view.'][$view.'.'][$type.ucfirst($view).'ViewPid']) {
					$allowedViews[] = $type.'_'.$view;
				}
			}
		}
		
		return $allowedViews;
	}


	function saveEvent() {
		$hookObjectsArr = $this->getHookObjectsArray('saveEventClass');
		// Hook: postListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'preSaveEvent');

		$pid = $this->conf['rights.']['create.']['event.']['saveEventToPid'];
		if (!is_numeric($pid)) {
			$pid = $GLOBALS['TSFE']->id;
		}
		$eventType = intval($this->piVars['event_type']);
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');

		$event = null;
		if($eventType==tx_cal_model::EVENT_TYPE_TODO){
			$event = $modelObj->saveTodo($this->conf['uid'], $this->conf['type'], $pid);
		} else {
			$event = $modelObj->saveEvent($this->conf['uid'], $this->conf['type'], $pid);
		}
	
		// Hook: preListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'postSaveEvent');

		if($this->conf['view.']['enableAjax']){
			if(is_object($event)){
				if(in_array($event->getFreq(),Array('year','month','week','day')) || ($event->getRdate() && in_array($event->getRdateType(),Array('date','datetime','period')))){
					$this->conf['view.'][$this->conf['view'].'.']['minDate'] = $event->start->format('%Y%m%d');
					$this->conf['view.'][$this->conf['view'].'.']['maxDate'] = $this->piVars['maxDate'];
					
					$eventArray = $modelObj->findEvent($event->getUid(), $this->conf['type'], $this->conf['pidList'], false, false, true, false, false, '0,1,2,3,4');

					$dateKeys = array_keys($eventArray);
					foreach ($dateKeys as $dateKey) {
						$timeKeys = array_keys($eventArray[$dateKey]);
						foreach ($timeKeys as $timeKey) {
							$eventKeys = array_keys($eventArray[$dateKey][$timeKey]);
							foreach ($eventKeys as $eventKey) {
								$eventX = &$eventArray[$dateKey][$timeKey][$eventKey];
								$ajaxStringArray[] = '{'.$this->getEventAjaxString($eventX).'}';
							}
						}
					}
					$ajaxString = implode(',',$ajaxStringArray);
					echo '['.$ajaxString.']';
				} else {
					$ajaxString = $this->getEventAjaxString($event);
					$ajaxString = str_replace(Array(chr(13),"\n"), Array("",""),$ajaxString);
					echo '[{'.$ajaxString.'}]';
				}
			}else{
				echo '{"success": false,"errors": {text:"event was not saved"}}';
			}
		}

		unset($this->piVars['type']);
		unset($this->conf['type']);
		$this->conf['type'] = '';
		$this->clearConfVars();
		$this->checkRedirect($this->piVars['uid']?'edit':'create', 'event');
	}

	function removeEvent() {
		$eventType = intval($this->piVars['event_type']);
		$hookObjectsArr = $this->getHookObjectsArray('removeEventClass');
		// Hook: postRemoveEvent
		$this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveEvent');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		if($eventType==tx_cal_model::EVENT_TYPE_TODO){
			$ok = $modelObj->removeTodo($this->conf['uid'], $this->conf['type']);
		} else {
			$ok = $modelObj->removeEvent($this->conf['uid'], $this->conf['type']);
		}

		// Hook: preRemoveEvent
		$this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveEvent');
		
		if($this->conf['view.']['enableAjax']){
			return 'true';
		}

		$this->clearConfVars();
		$this->checkRedirect('delete', 'event');
	}

	function createExceptionEvent() {
		$getdate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];
		$hookObjectsArr = $this->getHookObjectsArray('createExceptionEventClass');
		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preCreateExceptionEventRendering')) {
				$hookObj->preCreateExceptionEventRendering($this, $getdate, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnCreateExceptionEvent = $viewObj->drawCreateExceptionEvent($getdate, $pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postCreateExceptionEventRendering')) {
				$hookObj->postCreateExceptionEventRendering($drawnCreateExceptionEvent, $this);
			}
		}

		return $drawnCreateExceptionEvent;
	}

	function saveExceptionEvent() {
		$hookObjectsArr = $this->getHookObjectsArray('saveExceptionEventClass');

		// Hook: postListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'preSaveExceptionEvent');

		$pid = $this->conf['rights.']['create.']['exceptionEvent.']['saveExceptionEventToPid'];
		if (!is_numeric($pid)) {
			$pid = $GLOBALS['TSFE']->id;
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ok = $modelObj->saveExceptionEvent($this->conf['uid'], $this->conf['type'], $pid);

		// Hook: preListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'postSaveExceptionEvent');

		$this->clearConfVars();
		$this->checkRedirect($this->piVars['uid']?'edit':'create', 'exceptionEvent');
	}

	function removeCalendar() {
		$hookObjectsArr = $this->getHookObjectsArray('removeCalendarClass');
		// Hook: postRemoveCalendar
		$this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveCalendar');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ok = $modelObj->removeCalendar($this->conf['uid'], $this->conf['type']);

		// Hook: preRemoveCalendar
		$this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveCalendar');

		$this->clearConfVars();
		$this->checkRedirect('delete', 'calendar');
	}

	function removeCategory() {
		$hookObjectsArr = $this->getHookObjectsArray('removeCategoryClass');
		// Hook: postRemoveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveCategory');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ok = $modelObj->removeCategory($this->conf['uid'], $this->conf['type']);

		// Hook: preRemoveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveCategory');

		$this->clearConfVars();
		$this->checkRedirect('delete', 'category');
	}
	
	function removeLocation() {
		$hookObjectsArr = $this->getHookObjectsArray('removeLocationClass');
		// Hook: postRemoveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveLocation');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ok = $modelObj->removeLocation($this->conf['uid'], $this->conf['type']);
		
		// Hook: preRemoveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveLocation');
	
		$this->clearConfVars();
		$this->checkRedirect('delete', 'location');
	}

	function removeOrganizer() {
		$hookObjectsArr = $this->getHookObjectsArray('removeOrganizerClass');
		// Hook: postRemoveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveOrganizer');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ok = $modelObj->removeOrganizer($this->conf['uid'], $this->conf['type']);
	
		// Hook: preRemoveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveOrganizer');
	
		$this->clearConfVars();
		$this->checkRedirect('delete', 'organizer');
	}

	function saveLocation() {
		$hookObjectsArr = $this->getHookObjectsArray('saveLocationClass');

		// Hook: postListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'preSaveLocation');

		$pid = $this->conf['rights.']['create.']['location.']['saveLocationToPid'];
		if (!is_numeric($pid)) {
			$pid = $GLOBALS['TSFE']->id;
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$location = $modelObj->saveLocation($this->conf['uid'], $this->conf['type'], $pid);
		
		if($this->conf['view.']['enableAjax']){
			return '{'.$this->getEventAjaxString($location).'}';
		}

		// Hook: preListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'postSaveLocation');

		$this->clearConfVars();
		$this->checkRedirect($this->piVars['uid']?'edit':'create', 'location');
	}

	function saveOrganizer() {
		$hookObjectsArr = $this->getHookObjectsArray('saveOrganizerClass');
		// Hook: postListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'preSaveOrganizer');

		$pid = $this->conf['rights.']['create.']['organizer.']['saveOrganizerToPid'];
		if (!is_numeric($pid)) {
			$pid = $GLOBALS['TSFE']->id;
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$organizer = $modelObj->saveOrganizer($this->conf['uid'], $this->conf['type'], $pid);
		
		if($this->conf['view.']['enableAjax']){
			return '{'.$this->getEventAjaxString($organizer).'}';
		}

		// Hook: preListRendering
		$this->executeHookObjectsFunction($hookObjectsArr, 'postSaveOrganizer');

		$this->clearConfVars();
		$this->checkRedirect($this->piVars['uid']?'edit':'create', 'organizer');
	}

	function saveCalendar() {
		$hookObjectsArr = $this->getHookObjectsArray('saveCalendarClass');
		// Hook: postSaveCalendar
		$this->executeHookObjectsFunction($hookObjectsArr, 'preSaveCalendar');

		$pid = $this->conf['rights.']['create.']['calendar.']['saveCalendarToPid'];
		if (!is_numeric($pid)) {
			$pid = $GLOBALS['TSFE']->id;
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$calendar = $modelObj->saveCalendar($this->conf['uid'], $this->conf['type'], $pid);
		
		if($this->conf['view.']['enableAjax']){
			
			if(is_object($calendar)){
				$calendar = $modelObj->findCalendar($calendar->getUid(), $this->conf['type'], $pid);
				$ajaxString = $this->getEventAjaxString($calendar);
				$ajaxString = str_replace(Array(chr(13),"\n"), Array("",""),$ajaxString);
				return '{'.$ajaxString.'}';
			}else{
				return '{"success": false,"errors": {text:"calendar was not saved"}}';
			}
		}

		// Hook: preSaveCalendar
		$this->executeHookObjectsFunction($hookObjectsArr, 'postSaveCalendar');

		$this->clearConfVars();
		$this->checkRedirect($this->piVars['uid']?'edit':'create', 'calendar');
	}

	function saveCategory() {
		$hookObjectsArr = $this->getHookObjectsArray('saveCategoryClass');

		// Hook: postSaveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'preSaveCategory');

		$pid = $this->conf['rights.']['create.']['category.']['saveCategoryToPid'];
		if (!is_numeric($pid)) {
			$pid = $GLOBALS['TSFE']->id;
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$category = $modelObj->saveCategory($this->conf['uid'], $this->conf['type'], $pid);
		
		if($this->conf['view.']['enableAjax']){
			return '{'.$this->getEventAjaxString($category).'}';
		}

		// Hook: preSaveCategory
		$this->executeHookObjectsFunction($hookObjectsArr, 'postSaveCategory');

		$this->clearConfVars();
		$this->checkRedirect($this->piVars['uid']?'edit':'create', 'category');
	}

	function event() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];

		/* @todo drawEventClass? */
		$hookObjectsArr = $this->getHookObjectsArray('draweventClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$event = $modelObj->findEvent($uid, $type, $pidList);

		// Hook: postEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preEventRendering')) {
				$hookObj->preEventRendering($event, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnEvent = $viewObj->drawEvent($event, $getdate);

		// Hook: preEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postEventRendering')) {
				$hookObj->postEventRendering($drawnEvent, $event, $this);
			}
		}

		return $drawnEvent;
	}

	function day() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];

		/* @todo drawDayClass? */
		$hookObjectsArr = $this->getHookObjectsArray('drawdayClass');

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}
		$timeObj = new tx_cal_date($this->conf['getdate'].'000000');
		$timeObj->setTZbyId('UTC');
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		$master_array = $modelObj->findEventsForDay($timeObj, $type, $pidList);
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		// Hook: postDayRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preDayRendering')) {
				$hookObj->preDayRendering($master_array, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		#debug('$drawnDay:'.tx_cal_functions::getmicrotime());
		$drawnDay = $viewObj->drawDay($master_array, $getdate);
		#debug('$drawnDay:'.tx_cal_functions::getmicrotime());
		// Hook: preDayRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postDayRendering')) {
				$hookObj->postDayRendering($drawnDay, $master_array, $this);
			}
		}

		return $drawnDay;
	}

	function week() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];

		$hookObjectsArr = $this->getHookObjectsArray('drawweekClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}
		$timeObj = new tx_cal_date($this->conf['getdate'].'000000');
		$timeObj->setTZbyId('UTC');
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		$master_array = $modelObj->findEventsForWeek($timeObj, $type, $pidList);
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		// Hook: postWeekRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preWeekRendering')) {
				$hookObj->preWeekRendering($master_array, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		#debug('$drawnWeek:'.tx_cal_functions::getmicrotime());
		$drawnWeek = $viewObj->drawWeek($master_array, $getdate);
		#debug('$drawnWeek:'.tx_cal_functions::getmicrotime());
		// Hook: preWeekRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postWeekRendering')) {
				$hookObj->postWeekRendering($drawnWeek, $master_array, $this);
			}
		}

		return $drawnWeek;
	}

	function month() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];


		$hookObjectsArr = $this->getHookObjectsArray('drawmonthClass');
		
		if($this->conf['view.']['enableAjax']){
			$master_array = Array();
		} else {
			
			$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
			$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
			if(!in_array($type,$availableTypes)){
				$type = '';
			}
	
			$timeObj = new tx_cal_date($this->conf['getdate'].'000000');
			$timeObj->setTZbyId('UTC');
			#debug('$master_array:'.tx_cal_functions::getmicrotime());
			$master_array = $modelObj->findEventsForMonth($timeObj, $type, $pidList);
		}
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		// Hook: postMonthRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preMonthRendering')) {
				$hookObj->preMonthRendering($master_array, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		#debug('$drawnMonth:'.tx_cal_functions::getmicrotime());
		$drawnMonth = $viewObj->drawMonth($master_array, $getdate);
		#debug('$drawnMonth:'.tx_cal_functions::getmicrotime());
		// Hook: preMonthRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postMonthRendering')) {
				$hookObj->postMonthRendering($drawnMonth, $master_array, $this);
			}
		}
		return $drawnMonth;
	}

	function year() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];

		$hookObjectsArr = $this->getHookObjectsArray('drawyearClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}
		$timeObj = new tx_cal_date($this->conf['getdate'].'000000');
		$timeObj->setTZbyId('UTC');
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		$master_array = $modelObj->findEventsForYear($timeObj, $type, $pidList);
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		// Hook: postYearRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preYearRendering')) {
				$hookObj->preYearRendering($master_array, $this);
			}
		}

		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		#debug('$drawnYear:'.tx_cal_functions::getmicrotime());
		$drawnYear = $viewObj->drawYear($master_array, $getdate);
		#debug('$drawnYear:'.tx_cal_functions::getmicrotime());
		// Hook: preYearRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postYearRendering')) {
				$hookObj->postYearRendering($drawnYear, $master_array, $this);
			}
		}

		return $drawnYear;
	}

	function ics() {
		$type = $this->conf['type'];
		$getdata = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];

		/* @todo duplicated? drawICSClass? */
		$hookObjectsArr = $this->getHookObjectsArray('drawicsClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}

		$master_array = $modelObj->findEventsForIcs($type, $pidList); //$this->conf['pid_list']);

		// Hook: postIcsRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preIcsRendering')) {
				$hookObj->preIcsRendering($master_array, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnIcs = $viewObj->drawIcs($master_array, $this->conf['getdate']);

		// Hook: preIcsRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postIcsRendering')) {
				$hookObj->postIcsRendering($drawnIcs, $master_array, $this);
			}
		}

		return $drawnIcs;
	}

	function singleIcs() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$getdate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];

		/* duplicated?  drawICSClass? */
		$hookObjectsArr = $this->getHookObjectsArray('drawicsClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$master_array = array ($modelObj->findEvent($uid, $type, $pidList)); //$this->conf['pid_list']));

		// Hook: postIcsRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preIcsRendering')) {
				$hookObj->preIcsRendering($master_array, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnIcs = $viewObj->drawIcs($master_array, $getdate);

		// Hook: preIcsRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postIcsRendering')) {
				$hookObj->postIcsRendering($drawnIcs, $master_array, $this);
			}
		}

		return $drawnIcs;
	}

	function rss() {
		$type = $this->conf['type'];
		$getdate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];
		if($pidList==0){
			return 'Please define plugin.tx_cal_controller.pidList in constants';
		}
		/* @todo duplicated? drawICSClass? */
		$hookObjectsArr = $this->getHookObjectsArray('drawrssClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}


		$starttime = tx_cal_calendar::calculateStartDayTime($this->getDateTimeObject);
		$endtime = new tx_cal_date();
		$endtime->copy($starttime);
		$endtime->addSeconds($this->conf['view.']['rss.']['range']*86400);
		$master_array = $modelObj->findEventsForRss($starttime, $endtime, $type, $pidList); //$this->conf['pid_list']);

		// Hook: postRssRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preRssRendering')) {
				$hookObj->preRssRendering($master_array, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnIcs = $viewObj->drawRss($master_array, $getdate);

		// Hook: preRssRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postRssRendering')) {
				$hookObj->postRssRendering($drawnIcs, $master_array, $this);
			}
		}

		return $drawnIcs;
	}

	function location() {

		$uid = $this->conf['uid'];
		$type = $this->conf['type'];

		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('drawLocationClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_location_model', 'location');

		if(!in_array($type,$availableTypes)){
			$type = '';
		}

		$location = $modelObj->findLocation($uid, $type, $pidList);
		if($this->conf['view.']['enableAjax']){
			return '{'.$this->getEventAjaxString($location).'}';
		}
		$relatedEvents = &$this->findRelatedEvents('location',$uid);

		// Hook: postLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preLocationRendering')) {
				$hookObj->preLocationRendering($location, $relatedEvents, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnLocation = $viewObj->drawLocation($location, $relatedEvents);

		// Hook: preLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postLocationRendering')) {
				$hookObj->postLocationRendering($drawnLocation, $location, $this);
			}
		}

		return $drawnLocation;
	}

	function organizer() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo drawOrganizerClass? */
		$hookObjectsArr = $this->getHookObjectsArray('draworganizerClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_organizer_model', 'organizer');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}

		$organizer = $modelObj->findOrganizer($uid, $type, $pidList);
		$relatedEvents = &$this->findRelatedEvents('organizer',$uid);

		// Hook: postOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preOrganizerRendering')) {
				$hookObj->preOrganizerRendering($organizer, $relatedEvents, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnOrganizer = $viewObj->drawOrganizer($organizer, $relatedEvents);

		// Hook: preOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postOrganizerRendering')) {
				$hookObj->postOrganizerRendering($drawnOrganizer, $organizer, $this);
			}
		}
		return $drawnOrganizer;
	}

	/**
	 * Calculates the time for list view start and end times.
	 * @param		string		The string representing the relative time.
	 * @param		integer		The starting point that timeString is relative to.
	 * @return		integer		Timestamp for list view start or end time.
	 */
	function getListViewTime($timeString, $timeObj='') {
		require_once(t3lib_extMgm::extPath('cal').'controller/class.tx_cal_dateParser.php');
		$dp = new tx_cal_dateParser();
		$dp->parse($timeString,$this->conf['dateParserConf.'],$timeObj);
		$newTime = $dp->getDateObjectFromStack();

		return $newTime;
	}

	function listview() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo drawListClass? duplicated?*/
		$hookObjectsArr = $this->getHookObjectsArray('drawlistClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}

		$starttime = $this->cObj->stdWrap($this->conf['view.']['list.']['starttime'], $this->conf['view.']['list.']['starttime.']);
		$starttime = $this->getListViewTime($starttime);
		$endtime = $this->cObj->stdWrap($this->conf['view.']['list.']['endtime'], $this->conf['view.']['list.']['endtime.']);
		$endtime = $this->getListViewTime($endtime);
		
		if (!$this->conf['view.']['list.']['useGetdate']) {
			// do nothing - removed "continue" at this point, due to #543
		} else if ($this->conf['view'] == 'list' && !$this->conf['view.']['list.']['doNotUseGetdateTheFirstTime'] && $this->conf['getdate'] ) {
			if ($this->conf['view.']['list.']['useCustomStarttime']) {
				if ($this->conf['view.']['list.']['customStarttimeRelativeToGetdate']) {
					$starttime = $this->getListViewTime($this->conf['view.']['list.']['starttime'],$this->getDateTimeObject);
				} else {
					$starttime = $this->getListViewTime($this->conf['view.']['list.']['starttime']);
				}
			} else {
				$starttime = tx_cal_calendar::calculateStartDayTime($this->getDateTimeObject);
			}
				
			if ($this->conf['view.']['list.']['useCustomEndtime']) {
				if ($this->conf['view.']['list.']['customEndtimeRelativeToGetdate']) {
					$endtime = $this->getListViewTime($this->conf['view.']['list.']['endtime'],$this->getDateTimeObject);
				} else {
					$endtime = $this->getListViewTime($this->conf['view.']['list.']['endtime']);
				}
			} else {
				if ($this->conf['view.']['list.']['useCustomStarttime']) {
					// if we have a custom starttime but use getdate, calculate the endtime based on the getdate and not on the changed startdate
					$endtime = tx_cal_calendar::calculateStartDayTime($this->getDateTimeObject);
				} else {
					$endtime = new tx_cal_date();
					$endtime->copy($starttime);
				}
				$endtime->addSeconds(86340);
			}
		}

		$list = $modelObj->findEventsForList($starttime,$endtime, $type, $pidList);

		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preListRendering')) {
				$hookObj->preListRendering($list, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnList = $viewObj->drawList($list,$starttime,$endtime);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postListRendering')) {
				$hookObj->postListRendering($drawnList, $list, $this);
			}
		}

		return $drawnList;
	}

	function icslist() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];

		/* @todo drawListClass? duplicated? */
		$hookObjectsArr = $this->getHookObjectsArray('drawlistClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$list = $modelObj->findCategoriesForList($type, $pidList);

		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preListRendering')) {
				$hookObj->preListRendering($list, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnList = $viewObj->drawIcsList($list, $getdate);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postListRendering')) {
				$hookObj->postListRendering($drawnList, $list, $this);
			}
		}

		return $drawnList;
	}

	function admin() {
		/* drawAdminClass?  duplicated? */
		$hookObjectsArr = $this->getHookObjectsArray('drawlistClass');

		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnPage = $viewObj->drawAdminPage();

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postListRendering')) {
				$hookObj->postListRendering($drawnPage, $this);
			}
		}

		return $drawnPage;
	}

	function searchEvent() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo drawSearchClass */
		$hookObjectsArr = $this->getHookObjectsArray('drawsearchClass');

		$start_day = $this->piVars['start_day'];
		$end_day = $this->piVars['end_day'];
		$searchword = strip_tags($this->piVars['query']);
		
		include_once (t3lib_extMgm::extPath('cal').'controller/class.tx_cal_functions.php');

		if(!$start_day){
			$start_day = $this->getListViewTime($this->conf['view.']['search.']['defaultValues.']['start_day']);
			$start_day = tx_cal_calendar::calculateStartDayTime($start_day);
		}else{
			$start_day = new tx_cal_date(tx_cal_functions::getYmdFromDateString($this->conf, $start_day).'000000');
			$start_day->setHour(0);
			$start_day->setMinute(0);
			$start_day->setSecond(0);
			$start_day->setTZbyId('UTC');
		}
		if(!$end_day){
			$end_day = $this->getListViewTime($this->conf['view.']['search.']['defaultValues.']['end_day']);
			$end_day = tx_cal_calendar::calculateEndDayTime($end_day);
		}else{
			$end_day = new tx_cal_date(tx_cal_functions::getYmdFromDateString($this->conf, $end_day).'000000');
			$end_day->setHour(23);
			$end_day->setMinute(59);
			$end_day->setSecond(59);
			$end_day->setTZbyId('UTC');
		}
	 	if($this->piVars['single_date']){
			$start_day = new tx_cal_date(tx_cal_functions::getYmdFromDateString($this->conf, $this->piVars['single_date']));
			$start_day->setHour(0);
			$start_day->setMinute(0);
			$start_day->setSecond(0);
			$start_day->setTZbyId('UTC');
			$end_day = new tx_cal_date();
			$end_day->copy($start_day);
			$end_day->addSeconds(86399);
		}
			
		$minStarttime=new tx_cal_date($this->conf['view.']['search.']['startRange'].'000000');
		$maxEndtime = new tx_cal_date($this->conf['view.']['search.']['endRange'].'000000');

		if($start_day->before($minStarttime)) {
			$start_day->copy($minStarttime);
		}
		if($start_day->after($maxEndtime)) {
			$start_day->copy($maxEndtime);
		}

		if($end_day->before($minStarttime)) {
			$end_day->copy($minStarttime);
		}
		if($end_day->after($maxEndtime)) {
			$end_day->copy($maxEndtime);
		}
		if($end_day->before($start_day)) {
			$end_day->copy($start_day);
		}



		$locationIds = strip_tags($this->convertLinkVarArrayToList($this->piVars['location_ids']));
		$organizerIds = strip_tags($this->convertLinkVarArrayToList($this->piVars['organizer_ids']));

		$this->getDateTimeObject->copy($start_day);

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');

		$list = Array();
		if ($this->piVars['submit'] || !$this->conf['view.']['search.']['startSearchAfterSubmit']) {
			$list = $modelObj->searchEvents($type, $pidList, $start_day, $end_day, $searchword, $locationIds, $organizerIds);
		}

		// Hook: postSearchEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preSearchEventRendering')) {
				$hookObj->preSearchEventRendering($list, $this);
			}
		}
		
		if($this->conf['view.']['enableAjax']){
			$ajaxStringArray = Array();
			foreach($list as $event){
				$ajaxStringArray[] = '{'.$this->getEventAjaxString($event).'}';
			}
			return '['.implode(',',$ajaxStringArray).']';
		}

		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnList = $viewObj->drawSearchEventResult($list, $start_day, $end_day, $searchword, $locationIds, $organizerIds);

		// Hook: preSearchEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postSearchEventRendering')) {
				$hookObj->postSearchEventRendering($drawnList, $list, $this);
			}
		}

		return $drawnList;
	}

	function createEvent() {

		$getDate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('createEventClass');


		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preCreateEventRendering')) {
				$hookObj->preCreateEventRendering($this, $getDate, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnCreateEvent = $viewObj->drawCreateEvent($getDate, $pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postCreateEventRendering')) {
				$hookObj->postCreateEventRendering($drawnCreateEvent, $this);
			}
		}

		return $drawnCreateEvent;
	}

	function confirmEvent() {
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('confirmEventClass');

		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preConfirmEventRendering')) {
				$hookObj->preConfirmEventRendering($this, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnConfirmEvent = $viewObj->drawConfirmEvent($pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postConfirmEventRendering')) {
				$hookObj->postConfirmEventRendering($drawnConfirmEvent, $this);
			}
		}

		return $drawnConfirmEvent;
	}

	function editEvent() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('editEventClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$event = $modelObj->findEvent($uid, $type, $pidList);

		// Hook: preEditEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preEditEventRendering')) {
				$hookObj->preEditEventRendering($this, $event, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnEditEvent = $viewObj->drawEditEvent($event, $pidList);

		// Hook: preEditEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postEditEventRendering')) {
				$hookObj->postEditEventRendering($drawnEditEvent, $this);
			}
		}

		return $drawnEditEvent;
	}

	function deleteEvent() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('deleteEventClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$event = $modelObj->findEvent($uid, $type, $pidList);

		// Hook: postDeleteEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preDeleteEventRendering')) {
				$hookObj->preDeleteEventRendering($this, $event, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnDeleteEvent = $viewObj->drawDeleteEvent($event, $pidList);

		// Hook: preDeleteEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postDeleteEventRendering')) {
				$hookObj->postDeleteEventRendering($drawnDeleteEvent, $this);
			}
		}

		return $drawnDeleteEvent;
	}

	function createLocation() {
		$getdate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('createLocationClass');

		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preCreateLocationRendering')) {
				$hookObj->preCreateLocationRendering($this, $getdate, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnCreateLocation = $viewObj->drawCreateLocation($pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postCreateLocationRendering')) {
				$hookObj->postCreateLocationRendering($drawnCreateLocation, $this);
			}
		}

		return $drawnCreateLocation;
	}

	function confirmLocation() {

		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('confirmLocationClass');

		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preConfirmLocationRendering')) {
				$hookObj->preConfirmLocationRendering($this, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnConfirmLocation = $viewObj->drawConfirmLocation($pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postConfirmLocationRendering')) {
				$hookObj->postConfirmLocationRendering($drawnConfirmLocation, $this);
			}
		}

		return $drawnConfirmLocation;
	}

	function editLocation() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('editLocationClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$location = $modelObj->findLocation($uid, $type, $pidList);

		// Hook: postEditLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preEditLocationRendering')) {
				$hookObj->preEditLocationRendering($this, $location, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnEditLocation = $viewObj->drawEditLocation($location, $pidList);

		// Hook: preEditLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postEditLocationRendering')) {
				$hookObj->postEditLocationRendering($drawnEditLocation, $this);
			}
		}

		return $drawnEditLocation;
	}

	function deleteLocation() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('deleteLocationClass');

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$location = $modelObj->findLocation($uid, $type, $pidList);

		// Hook: postDeleteLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preDeleteLocationRendering')) {
				$hookObj->preDeleteLocationRendering($this, $location, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnDeleteLocation = $viewObj->drawDeleteLocation($location, $pidList);

		// Hook: preDeleteLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postDeleteLocationRendering')) {
				$hookObj->postDeleteLocationRendering($drawnDeleteLocation, $this);
			}
		}

		return $drawnDeleteLocation;
	}

	function createOrganizer() {
		$getdate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('createOrganizerClass');

		// Hook: postCreateOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preCreateOrganizerRendering')) {
				$hookObj->preCreateOrganizerRendering($this, $getdate, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnCreateOrganizer = $viewObj->drawCreateOrganizer($pidList);

		// Hook: preCreateOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postCreateOrganizerRendering')) {
				$hookObj->postCreateOrganizerRendering($drawnCreateOrganizer, $this);
			}
		}

		return $drawnCreateOrganizer;
	}

	function confirmOrganizer() {
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('confirmOrganizerClass');

		// Hook: postConfirmOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preConfirmOrganizerRendering')) {
				$hookObj->preConfirmOrganizerRendering($this, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnConfirmOrganizer = $viewObj->drawConfirmOrganizer($pidList);

		// Hook: preConfirmOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postConfirmOrganizerRendering')) {
				$hookObj->postConfirmOrganizerRendering($drawnConfirmOrganizer, $this);
			}
		}

		return $drawnConfirmOrganizer;
	}

	function editOrganizer() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('editOrganizerClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$organizer = $modelObj->findOrganizer($uid, $type, $pidList);

		// Hook: postEditOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preEditOrganizerRendering')) {
				$hookObj->preEditOrganizerRendering($this, $organizer, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnEditOrganizer = $viewObj->drawEditOrganizer($organizer, $pidList);

		// Hook: preEditOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postEditOrganizerRendering')) {
				$hookObj->postEditOrganizerRendering($drawnEditOrganizer, $this);
			}
		}

		return $drawnEditOrganizer;
	}

	function deleteOrganizer() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('deleteOrganizerClass');

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$organizer = $modelObj->findOrganizer($uid, $type, $pidList);

		// Hook: postDeleteOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preDeleteOrganizerRendering')) {
				$hookObj->preDeleteOrganizerRendering($this, $organizer, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnDeleteOrganizer = $viewObj->drawDeleteOrganizer($organizer, $pidList);

		// Hook: preDeleteOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postDeleteOrganizerRendering')) {
				$hookObj->postDeleteOrganizerRendering($drawnDeleteOrganizer, $this);
			}
		}

		return $drawnDeleteOrganizer;
	}

	function createCalendar() {
		$getdate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('createCalendarClass');


		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preCreateCalendarRendering')) {
				$hookObj->preCreateCalendarRendering($this, $getdate, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnCreateCalendar = $viewObj->drawCreateCalendar($pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postCreateCalendarRendering')) {
				$hookObj->postCreateCalendarRendering($drawnCreateCalendar, $this);
			}
		}

		return $drawnCreateCalendar;
	}

	function confirmCalendar() {
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('confirmCalendarClass');


		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preConfirmCalendarRendering')) {
				$hookObj->preConfirmCalendarRendering($this, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnConfirmCalendar = $viewObj->drawConfirmCalendar($pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postConfirmCalendarRendering')) {
				$hookObj->postConfirmCalendarRendering($drawnConfirmCalendar, $this);
			}
		}

		return $drawnConfirmCalendar;
	}

	function editCalendar() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('editCalendadrClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$calendar = $modelObj->findCalendar($uid, $type, $pidList);

		// Hook: postEditCalendarRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preEditCalendarRendering')) {
				$hookObj->preEditCalendarRendering($this, $calendar, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnEditCalendar = $viewObj->drawEditCalendar($calendar, $pidList);

		// Hook: preEditCalendarRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postEditCalendarRendering')) {
				$hookObj->postEditCalendarRendering($drawnEditCalendar, $this);
			}
		}

		return $drawnEditCalendar;
	}

	function deleteCalendar() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('deleteCalendarClass');

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$calendar = $modelObj->findCalendar($uid, $type, $pidList);

		// Hook: postDeleteCalendarRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preDeleteCalendarRendering')) {
				$hookObj->preDeleteCalendarRendering($this, $calendar, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnDeleteCalendar = $viewObj->drawDeleteCalendar($calendar, $pidList);

		// Hook: preDeleteCalendarRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postDeleteCalendarRendering')) {
				$hookObj->postDeleteCalendarRendering($drawnDeleteCalendar, $this);
			}
		}

		return $drawnDeleteCalendar;
	}

	function createCategory() {
		$getdate = $this->conf['getdate'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('createCategoryClass');


		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preCreateCategoryRendering')) {
				$hookObj->preCreateCategoryRendering($this, $getdate, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnCreateCategory = $viewObj->drawCreateCategory($pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postCreateCategoryRendering')) {
				$hookObj->postCreateCategoryRendering($drawnCreateCategory, $this);
			}
		}

		return $drawnCreateCategory;
	}

	function confirmCategory() {
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('confirmCategoryClass');


		// Hook: postListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preConfirmCategoryRendering')) {
				$hookObj->preConfirmCategoryRendering($this, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnConfirmCategory = $viewObj->drawConfirmCategory($pidList);

		// Hook: preListRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postConfirmCategoryRendering')) {
				$hookObj->postConfirmCategoryRendering($drawnConfirmCategory, $this);
			}
		}

		return $drawnConfirmCategory;
	}

	function editCategory() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('editCategoryClass');

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$category = $modelObj->findCategory($uid, $type, $pidList);
		// Hook: postEditCategoryRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preEditCategoryRendering')) {
				$hookObj->preEditCategoryRendering($this, $category, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnEditCategory = $viewObj->drawEditCategory($category, $pidList);

		// Hook: preEditCategoryRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postEditCategoryRendering')) {
				$hookObj->postEditCategoryRendering($drawnEditCategory, $this);
			}
		}

		return $drawnEditCategory;
	}

	function deleteCategory() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('deleteCategoryClass');

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$category = $modelObj->findCategory($uid, $type, $pidList);
		// Hook: postDeleteCategoryRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preDeleteCategoryRendering')) {
				$hookObj->preDeleteCategoryRendering($this, $category, $pidList);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnDeleteCategory = $viewObj->drawDeleteCategory($category, $pidList);

		// Hook: preDeleteCategoryRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postDeleteCategoryRendering')) {
				$hookObj->postDeleteCategoryRendering($drawnDeleteCategory, $this);
			}
		}

		return $drawnDeleteCategory;
	}

	function searchAll() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		$hookObjectsArr = $this->getHookObjectsArray('drawsearchClass');
		
		if(intval($this->piVars['start_day']) == 0) {
			$starttime = $this->getListViewTime($this->conf['view.']['search.']['defaultValues.']['start_day']);
		} else {
			$starttime = new tx_cal_date(intval($this->piVars['start_day']).'000000');
		}
		if(intval($this->piVars['end_day']) == 0) {
			$endtime = $this->getListViewTime($this->conf['view.']['search.']['defaultValues.']['end_day']);
		} else {
			$endtime = new tx_cal_date(intval($this->piVars['end_day']).'000000');
		}
		$searchword = strip_tags($this->piVars['query']);
		if($searchword == '') {
			$searchword = $this->cObj->stdWrap($this->conf['view.']['search.']['defaultValues.']['query'],$this->conf['view.']['search.']['event.']['defaultValues.']['query.']);
		}
		$endtime->addSeconds(86399);

		/* Get the boundaries for allowed search dates */
		$minStarttime = new tx_cal_date(intval($this->conf['view.']['search.']['startRange']).'000000');
		$maxEndtime = new tx_cal_date(intval($this->conf['view.']['search.']['endRange']).'000000');
		
		/* Check starttime against boundaries */
		if($starttime->before($minStarttime)) {
			$starttime->copy($minStarttime);
		} 
		if($starttime->after($maxEndtime)) {
			$starttime->copy($maxEndtime);
		}
		
		/* Check endtime against boundaries */
		if($endtime->before($minStarttime)) {
			$endtime->copy($minStarttime);
		} 
		if($endtime->after($maxEndtime)) {
			$endtime->copy($maxEndtime);	
		}
		
		/* Check endtime against starttime */
		if($endtime->before($starttime)) {
			$endtime->copy($starttime);
		} 

		$locationIds = strip_tags($this->convertLinkVarArrayToList($this->piVars['location_ids']));
		$organizerIds = strip_tags($this->convertLinkVarArrayToList($this->piVars['organizer_ids']));
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$list = array();
		if ($this->piVars['query'] && ($this->piVars['submit'] || !$this->conf['view.']['search.']['startSearchAfterSubmit'])) {
			$list['phpicalendar_event'] = $modelObj->searchEvents($type, $pidList, $starttime, $endtime, $searchword, $locationIds, $organizerIds);
			$list['location'] = $modelObj->searchLocation($type, $pidList, $searchword);
			$list['organizer'] = $modelObj->searchOrganizer($type, $pidList, $searchword);
		}
		
		// Hook: postSearchAllRendering
		if(is_array($hookObjectsArr)) {
			foreach ($hookObjectsArr as $hookObj) {
				if (method_exists($hookObj, 'preSearchAllRendering')) {
					$hookObj->preSearchAllRendering($list, $this);
				}
			}
		}
		
		if($this->conf['view.']['enableAjax']){
			$ajaxStringArray = Array();
			foreach($list as $location){
				$ajaxStringArray[] = '{'.$this->getEventAjaxString($location).'}';
			}
			return '['.implode(',',$ajaxStringArray).']';
		}
		
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnList = $viewObj->drawSearchAllResult($list, $starttime, $endtime, $searchword, $locationIds, $organizerIds);

		// Hook: preSearchAllRendering
		if(is_array($hookObjectsArr)) {
			foreach ($hookObjectsArr as $hookObj) {
				if (method_exists($hookObj, 'postSearchAllRendering')) {
					$hookObj->postSearchAllRendering($drawnList, $list, $this);
				}
			}
		}
		return $drawnList;
	}

	function searchLocation() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo duplicated? */
		$hookObjectsArr = $this->getHookObjectsArray('drawsearchClass');

		$searchword = strip_tags($this->piVars['query']);
		if($searchword==''){
			$searchword = $this->cObj->stdWrap($this->conf['view.']['search.']['location.']['defaultValues.']['query'],$this->conf['view.']['search.']['location.']['defaultValues.']['query.']);
			if($searchword==''){
				//
			}
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$list = $modelObj->searchLocation($type, $pidList,$searchword);

		// Hook: postSearchLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preSearchLocationRendering')) {
				$hookObj->preSearchLocationRendering($list, $this);
			}
		}
		
		if($this->conf['view.']['enableAjax']){
			$ajaxStringArray = Array();
			foreach($list as $location){
				$ajaxStringArray[] = '{'.$this->getEventAjaxString($location).'}';
			}
			return '['.implode(',',$ajaxStringArray).']';
		}
		
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnList = $viewObj->drawSearchLocationResult($list,$searchword);

		// Hook: preSearchLocationRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postSearchLocationRendering')) {
				$hookObj->postSearchLocationRendering($drawnList, $list, $this);
			}
		}

		return $drawnList;
	}

	function searchOrganizer() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo duplicated? */
		$hookObjectsArr = $this->getHookObjectsArray('drawsearchClass');

		$searchword = strip_tags($this->piVars['query']);
		if($searchword==''){
			$searchword = $this->cObj->stdWrap($this->conf['view.']['search.']['organizer.']['defaultValues.']['query'],$this->conf['view.']['search.']['organizer.']['defaultValues.']['query.']);
			if($searchword==''){
				//
			}
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$list = $modelObj->searchOrganizer($type, $pidList, $searchword);

		// Hook: postSearchOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preSearchOrganizerRendering')) {
				$hookObj->preSearchOrganizerRendering($list, $this);
			}
		}
		
		if($this->conf['view.']['enableAjax']){
			$ajaxStringArray = Array();
			foreach($list as $organizer){
				$ajaxStringArray[] = '{'.$this->getEventAjaxString($organizer).'}';
			}
			return '['.implode(',',$ajaxStringArray).']';
		}
		
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnList = $viewObj->drawSearchOrganizerResult($list, $searchword);

		// Hook: preSearchOrganizerRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postSearchOrganizerRendering')) {
				$hookObj->postSearchOrganizerRendering($drawnList, $list, $this);
			}
		}

		return $drawnList;
	}
	
	function searchUserAndGroup() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo duplicated? */
		$hookObjectsArr = $this->getHookObjectsArray('drawsearchClass');

		$searchword = strip_tags($this->piVars['query']);
		$allowedUsers = t3lib_div::trimExplode(',',$this->conf['rights.']['allowedUsers'],1);
		
		$additionalWhere = '';
		if(count($allowedUsers) > 0){
			$additionalWhere = ' AND uid in ('.implode(',',$allowedUsers).')';
		}
		
		if($searchword!=''){
			$additionalWhere .= $this->cObj->searchWhere($sw, $this->conf['view.']['search.']['searchUserFieldList'], 'fe_users');
		}
		
		// Hook: preSearchUser
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preSearchUser')) {
				$hookObj->preSearchUser($additionalWhere, $this);
			}
		}
		
		$userList = Array();
		
		$result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','fe_users','pid in ('.$this->conf['pidList'].')'.$additionalWhere);
		if($result) {
			while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
				unset($row['username']);
				unset($row['password']);
				$userList[] = $row;
			}
			$GLOBALS['TYPO3_DB']->sql_free_result($result);
		}
		
		// Hook: postSearchUser
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postSearchUser')) {
				$hookObj->postSearchUser($userList, $this);
			}
		}
		
		$additionalWhere = '';
		
		$allowedGroups = t3lib_div::trimExplode(',',$this->conf['rights.']['allowedGroups'],1);
		if(count($allowedUsers) > 0){
			$additionalWhere = ' AND uid in ('.implode(',',$allowedGroups).')';
		}
		
		if($searchword!=''){
			$additionalWhere .= $this->cObj->searchWhere($sw, $this->conf['view.']['search.']['searchGroupFieldList'], 'fe_groups');
		}
		
		// Hook: preSearchGroup
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preSearchGroup')) {
				$hookObj->preSearchGroup($additionalWhere, $this);
			}
		}
		
		$groupList = Array();
		
		$result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','fe_groups','pid in ('.$this->conf['pidList'].')'.$additionalWhere);
		if($result) {
			while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
				$groupList[] = $row;
			}
			$GLOBALS['TYPO3_DB']->sql_free_result($result);
		}
		
		// Hook: postSearchGroup
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postSearchGroup')) {
				$hookObj->postSearchGroup($groupList, $this);
			}
		}
		
		$ajaxUserStringArray = Array();
		foreach($userList as $user){
			$ajaxUserStringArray[] = '{'.$this->getEventAjaxString($user).'}';
		}
		$ajaxGroupStringArray = Array();
		foreach($groupList as $group){
			$ajaxGroupStringArray[] = '{'.$this->getEventAjaxString($group).'}';
		}
		return '{"fe_users":['.implode(',',$ajaxUserStringArray).'],"fe_groups":['.implode(',',$ajaxGroupStringArray).']}';
	}
	
	function subscription() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo drawSubscriptionClass */
		$hookObjectsArr = $this->getHookObjectsArray('drawSubscriptionClass');

		// Hook: preSubscriptionRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preSubscriptionRendering')) {
				$hookObj->preSubscriptionRendering($this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnSubscriptionManager = $viewObj->drawSubscriptionManager();

		// Hook: preSubscriptionRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postSubscriptionRendering')) {
				$hookObj->postSubscriptionRendering($drawnSubscriptionManager, $this);
			}
		}

		return $drawnSubscriptionManager;
	}

	function meeting() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];

		/* @todo drawMeetingClass */
		$hookObjectsArr = $this->getHookObjectsArray('drawMeetingClass');

		// Hook: preMeetingRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preMeetingRendering')) {
				$hookObj->preMeetingRendering($this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnMeetingManager = $viewObj->drawMeetingManager();

		// Hook: preMeetingRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postMeetingRendering')) {
				$hookObj->postMeetingRendering($drawnMeetingManager, $this);
			}
		}

		return $drawnMeetingManager;
	}

	function translation() {
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$overlay = intval($this->piVars['overlay']);
		$uid = $this->conf['uid'];
		$servicename = $this->piVars['servicename'];
		$subtype = $this->piVars['subtype'];
		if($overlay > 0 && $uid > 0){
			$hookObjectsArr = $this->getHookObjectsArray('createTranslationClass');

			// Hook: preCreateTranslation
			foreach ($hookObjectsArr as $hookObj) {
				if (method_exists($hookObj, 'preCreateTranslation')) {
					$hookObj->preCreateTranslation($this);
				}
			}
			$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
			$modelObj->createTranslation($uid,$overlay,$servicename,$type,$subtype);

			// Hook: postCreateTranslation
			foreach ($hookObjectsArr as $hookObj) {
				if (method_exists($hookObj, 'postCreateTranslation')) {
					$hookObj->postCreateTranslation($this);
				}
			}
		}
		unset($this->piVars['overlay']);
		unset($this->piVars['servicename']);
		unset($this->piVars['subtype']);
		$viewParams = $this->shortenLastViewAndGetTargetViewParameters(false);
		$this->conf['view'] = $viewParams['view'];
		$this->conf['lastview'] = $viewParams['lastview'];
		$rightsObj = &tx_cal_registry::Registry('basic','rightscontroller');
		$this->conf['view'] = $rightsObj->checkView($this->conf['view']);
		$this->conf['uid'] = $viewParams['uid'];
		$this->conf['type'] = $viewParams['type'];
		return '';
	}
	
	function todo() {
		$uid = $this->conf['uid'];
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];

		/* @todo drawTodoClass? */
		$hookObjectsArr = $this->getHookObjectsArray('drawTodoClass');
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);
		$todoSubtype = $confArr['todoSubtype'];
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', $todoSubtype);

		if(!in_array($type,$availableTypes)){
			$type = '';
		}
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$todo = $modelObj->findTodo($uid, $type, $pidList);

		// Hook: postEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preTodoRendering')) {
				$hookObj->preEventRendering($todo, $this);
			}
		}
		$viewObj = &tx_cal_registry::Registry('basic','viewcontroller');
		$drawnTodo = $viewObj->drawEvent($todo, $getdate);

		// Hook: preEventRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postTodoRendering')) {
				$hookObj->postTodoRendering($drawnTodo, $todo, $this);
			}
		}

		return $drawnTodo;
	}
	
	function loadEvents(){
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];
		
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}
		$startObj = new tx_cal_date($this->piVars['start'].'000000');
		$startObj->setTZbyId('UTC');
		$endObj = new tx_cal_date($this->piVars['end'].'000000');
		$endObj->setTZbyId('UTC');
		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		$eventTypes = '0,1,2,3';
		$confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);
		if($confArr['todoSubtype']=='event'){
			$eventTypes = '0,1,2,3,4';
		}
		$master_array = $modelObj->findEventsForList($startObj, $endObj, $type, $pidList, $eventTypes);

		$this->conf['view'] = $this->piVars['targetView'];
		if (!empty($master_array)) {
			// use array keys for the loop in order to be able to use referenced events instead of copies and save some memory
			$masterArrayKeys = array_keys($master_array);
			$ajaxStringArray = Array();

			foreach ($masterArrayKeys as $dateKey) {
				$dateArray = &$master_array[$dateKey];
				$dateArrayKeys = array_keys($dateArray);
				foreach ($dateArrayKeys as $timeKey) {
					$arrayOfEvents = &$dateArray[$timeKey];
					$eventKeys = array_keys($arrayOfEvents);
					foreach ($eventKeys as $eventKey) {
						$event = &$arrayOfEvents[$eventKey];
						$ajaxStringArray[] = '{'.$this->getEventAjaxString($event).'}';
					}
				}
			}
			$ajaxString = implode(',',$ajaxStringArray);
			//$ajaxString .= $ajaxEdit;
		}
		$this->conf['view'] = 'load_events';
		
		$sims = Array();
		$rems = Array();
		$wrapped = Array();
		$sims['###IMG_PATH###'] = tx_cal_functions::expandPath($this->conf['view.']['imagePath']);
		$page = tx_cal_functions::substituteMarkerArrayNotCached('['.$ajaxString.']', $sims, $rems, $wrapped);
		
		return $page;
	}
	
	function loadEvent($uid, $eventType=''){
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];
		
		$eventType = intval($this->piVars['event_type']);

		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		if($eventType==tx_cal_model::EVENT_TYPE_TODO){
			$confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);
			$todoSubtype = $confArr['todoSubtype'];
			$availableTypes = $modelObj->getServiceTypes('cal_event_model', $todoSubtype);
			if(!in_array($type,$availableTypes)){
				$type = '';
			}
			$event = $modelObj->findTodo($uid, $type, $pidList);
		} else {
			$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
			if(!in_array($type,$availableTypes)){
				$type = '';
			}
			$event = $modelObj->findEvent($uid, $type, $pidList);
		}
		
		$ajaxString = '';
		
		if (is_object($event)) {
			$ajaxString = $this->getEventAjaxString($event);
			$ajaxString .= 'events.push(tmp'.$event->getUid().');'."\n";
			$ajaxString .= 'addEvents();';
		}else{
			$ajaxString = 'error, can not find the event'; 
		}
				
		return $ajaxString;
	}
	
	function getEventAjaxString(&$event){
		if(is_object($event)){
			$eventValues = $event->getValuesAsArray();
		} else if(is_array($event)){
			$eventValues = $event;
		}
		$ajaxStringArray = Array();
		
		$badchr        = array(
	        "\xc2", // prefix 1
	        "\x80", // prefix 2
	        "\x98", // single quote opening
	        "\x99", // single quote closing
	        "\x8c", // double quote opening
	        "\x9d"  // double quote closing
	    );
	       
	    $goodchr    = array('', '', '\'', '\'', '"', '"');
	       
	    foreach($eventValues as $key => $value){
			if(is_array($value)){
				if(count($value)>0){
					$ajaxStringArray[] = '"'.$key.'":'.'{'.$this->getEventAjaxString($eventValues[$key]).'}';
				} else {
					$ajaxStringArray[] = '"'.$key.'":'.'[]';
				}
			} else if(is_object($value)){
				$ajaxStringArray[] = '"'.$key.'":'.'{'.$this->getEventAjaxString($eventValues[$key]).'}';
			}else {
				if($key!=='l18n_diffsource'){
					$ajaxStringArray[] = '"'.$key.'":'.json_encode($value);
				}
			}
		}
		
		return implode(',',$ajaxStringArray);
	}
	
	function loadTodos(){
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$getdate = $this->conf['getdate'];
		
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
		if(!in_array($type,$availableTypes)){
			$type = '';
		}

		#debug('$master_array:'.tx_cal_functions::getmicrotime());
		$result_array = $modelObj->findCurrentTodos($type, $pidList);
		
		$ajaxStringArray = Array();
		
		$this->conf['view'] = $this->piVars['targetView'];
		if (!empty($result_array)) {
			// use array keys for the loop in order to be able to use referenced events instead of copies and save some memory
			$resultArrayKeys = array_keys($result_array);
			foreach ($resultArrayKeys as $resultArrayKey) {
				$masterArrayKeys = array_keys($result_array[$resultArrayKey]);
				foreach ($masterArrayKeys as $dateKey) {
					$dateArray = &$result_array[$resultArrayKey][$dateKey];
					$dateArrayKeys = array_keys($dateArray);
					foreach ($dateArrayKeys as $timeKey) {
						$arrayOfEvents = &$dateArray[$timeKey];
						$eventKeys = array_keys($arrayOfEvents);
						foreach ($eventKeys as $eventKey) {
							$event = &$arrayOfEvents[$eventKey];
							$ajaxStringArray[] = '{'.$this->getEventAjaxString($event).'}';
						}
					}
				}
			}
		}
		$this->conf['view'] = 'load_todos';
		
		$sims = Array();
		$rems = Array();
		$wrapped = Array();
		$sims['###IMG_PATH###'] = tx_cal_functions::expandPath($this->conf['view.']['imagePath']);
		$page = tx_cal_functions::substituteMarkerArrayNotCached('['.implode(',',$ajaxStringArray).']', $sims, $rems, $wrapped);
		
		return $page;
	}
	
	function loadCalendars(){
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ajaxStringArray = Array();
		$deselectedCalendarIds = t3lib_div::trimExplode(',',$this->conf['view.']['calendar.']['subscription'],1);
		$calendarIds = Array();
		foreach($deselectedCalendarIds as $calendarUid){
			$calendarIds[] = $calendarUid;
			$calendar = $modelObj->findCalendar($calendarUid, 'tx_cal_calendar', $this->conf['pidList']);
			$ajaxStringArray[] = '{'.$this->getEventAjaxString($calendar).'}';
		}
		$calendarArray = $modelObj->findAllCalendar('tx_cal_calendar');
		
		foreach($calendarArray['tx_cal_calendar'] as $calendar){
			if(!in_array($calendar->getUid(),$calendarIds)){
				$ajaxStringArray[] = '{'.$this->getEventAjaxString($calendar).'}';
			}
		}
		return '['.implode(',',$ajaxStringArray).']';
	}
	
	function loadLocations(){
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ajaxStringArray = Array();
		
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$locationArray = $modelObj->findAllLocations( $type, $pidList);
		
		foreach($locationArray as $location){
			$ajaxStringArray[] = '{'.$this->getEventAjaxString($location).'}';
		}
		return '['.implode(',',$ajaxStringArray).']';
	}
	
	function loadOrganizers(){
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		$ajaxStringArray = Array();
		
		$type = $this->conf['type'];
		$pidList = $this->conf['pidList'];
		$organizerArray = $modelObj->findAllOrganizer( $type, $pidList);
		
		foreach($organizerArray as $organizer){
			$ajaxStringArray[] = '{'.$this->getEventAjaxString($organizer).'}';
		}
		return '['.implode(',',$ajaxStringArray).']';
	}
	
	function loadRights(){
		$rightsObj = &tx_cal_registry::Registry('basic','rightscontroller');
		$options = Array('create','edit','delete');
		$rights = Array();
		foreach($options as $option){
			$isAllowedToOptionCalendar = $rightsObj->isAllowedTo($option,'calendar')?'true':'false';
			$isAllowedToOptionCategory = $rightsObj->isAllowedTo($option,'category')?'true':'false';
			$isAllowedToOptionEvent = $rightsObj->isAllowedTo($option,'event')?'true':'false';
			$isAllowedToOptionLocation = $rightsObj->isAllowedTo($option,'location')?'true':'false';
			$isAllowedToOptionOrganizer = $rightsObj->isAllowedTo($option,'organizer')?'true':'false';
			$rights[] = ($option=='delete'?'del':$option) .':{calendar:'.$isAllowedToOptionCalendar.',category:'.$isAllowedToOptionCategory.',event:'.
				$isAllowedToOptionEvent.',location:'.$isAllowedToOptionLocation.',organizer:'.$isAllowedToOptionOrganizer.'}';
		}
		$rights[] = 'admin:'.($rightsObj->isCalAdmin()?'true':'false');
		$rights[] = 'userId:'.$rightsObj->getUserId();
		$rights[] = 'userGroups:['.implode(',',$rightsObj->getUserGroups()).']';
		return '{'.implode(',',$rights).'}';
	}

	function updateConfWithFlexform(&$piFlexForm){
		//		$this->updateIfNotEmpty($this->conf['pages'], $this->pi_getFFvalue($piFlexForm, 'pages'));
		//		$this->updateIfNotEmpty($this->conf['recursive'], $this->pi_getFFvalue($piFlexForm, 'recursive'));
		if($this->conf['dontListenToFlexForm'] == 1) {
			return;
		}
		if($this->conf['dontListenToFlexForm.']['general.']['calendarName'] != 1) {
			$this->updateIfNotEmpty($this->conf['calendarName'], $this->pi_getFFvalue($piFlexForm, 'calendarName'));
		}
		if($this->conf['dontListenToFlexForm.']['general.']['allowSubscribe'] != 1) {
			$this->updateIfNotEmpty($this->conf['allowSubscribe'] , $this->pi_getFFvalue($piFlexForm, 'subscription')==1?1:-1);
		}
		if($this->conf['dontListenToFlexForm.']['general.']['subscribeFeUser'] != 1) {
			$this->updateIfNotEmpty($this->conf['subscribeFeUser'] , $this->pi_getFFvalue($piFlexForm, 'subscription')==2?1:-1);
		}
		if($this->conf['dontListenToFlexForm.']['general.']['subscribeWithCaptcha'] != 1) {
			$this->updateIfNotEmpty($this->conf['subscribeWithCaptcha'] , $this->pi_getFFvalue($piFlexForm, 'subscribeWithCaptcha'));
		}
		if($this->conf['dontListenToFlexForm.']['general.']['allowedViews'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['allowedViews'] , $this->pi_getFFvalue($piFlexForm, 'allowedViews'));
		}
		if($this->conf['dontListenToFlexForm.']['general.']['calendarDistance'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['calendar.']['nearbyDistance'] , intval($this->pi_getFFvalue($piFlexForm, 'calendarDistance')));
		}

		if($this->conf['dontListenToFlexForm.']['day.']['dayViewPid'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['day.']['dayViewPid'] , $this->pi_getFFvalue($piFlexForm, 'dayViewPid','s_Day_View'));
		}
		if($this->conf['dontListenToFlexForm.']['day.']['dayStart'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['day.']['dayStart'] , $this->pi_getFFvalue($piFlexForm, 'dayStart','s_Day_View'));
		}
		if($this->conf['dontListenToFlexForm.']['day.']['dayEnd'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['day.']['dayEnd'] , $this->pi_getFFvalue($piFlexForm, 'dayEnd','s_Day_View'));
		}
		if($this->conf['dontListenToFlexForm.']['day.']['gridLength'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['day.']['gridLength'] , $this->pi_getFFvalue($piFlexForm, 'gridLength','s_Day_View'));
		}
		if($this->conf['dontListenToFlexForm.']['day.']['weekViewPid'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['week.']['weekViewPid'] , $this->pi_getFFvalue($piFlexForm, 'weekViewPid','s_Week_View'));
		}

		if($this->conf['dontListenToFlexForm.']['month.']['monthViewPid'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['month.']['monthViewPid'] , $this->pi_getFFvalue($piFlexForm, 'monthViewPid','s_Month_View'));
		}
		if($this->conf['dontListenToFlexForm.']['month.']['monthMakeMiniCal'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['month.']['monthMakeMiniCal'] , $this->pi_getFFvalue($piFlexForm, 'monthMakeMiniCal','s_Month_View'));
		}
		if($this->conf['dontListenToFlexForm.']['month.']['monthShowListView'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['month.']['showListInMonthView'], $this->pi_getFFvalue($piFlexForm, 'monthShowListView', 's_Month_View'));
		}
		
		if($this->conf['dontListenToFlexForm.']['year.']['yearViewPid'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['year.']['yearViewPid'] , $this->pi_getFFvalue($piFlexForm, 'yearViewPid','s_Year_View'));
		}
		
		if($this->conf['dontListenToFlexForm.']['event.']['eventViewPid'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['event.']['eventViewPid'] , $this->pi_getFFvalue($piFlexForm, 'eventViewPid','s_Event_View'));
		}
		if($this->conf['dontListenToFlexForm.']['event.']['isPreview'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['event.']['isPreview'] , $this->pi_getFFvalue($piFlexForm, 'isPreview','s_Event_View'));
		}
		
		if($this->conf['dontListenToFlexForm.']['list.']['listViewPid'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['listViewPid'] , $this->pi_getFFvalue($piFlexForm, 'listViewPid','s_List_View'));
		}
		if($this->conf['dontListenToFlexForm.']['list.']['starttime'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['starttime'] , $this->pi_getFFvalue($piFlexForm, 'starttime','s_List_View'));
		}
		if($this->conf['dontListenToFlexForm.']['list.']['endtime'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['endtime'] , $this->pi_getFFvalue($piFlexForm, 'endtime','s_List_View'));
		}
		if($this->conf['dontListenToFlexForm.']['list.']['maxEvents'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['maxEvents'] , $this->pi_getFFvalue($piFlexForm, 'maxEvents','s_List_View'));
		}
		if($this->conf['dontListenToFlexForm.']['list.']['maxRecurringEvents'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['maxRecurringEvents'] , $this->pi_getFFvalue($piFlexForm, 'maxRecurringEvents','s_List_View'));
		}
		if($this->conf['dontListenToFlexForm.']['list.']['usePageBrowser'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['pageBrowser.']['usePageBrowser'] , $this->pi_getFFvalue($piFlexForm, 'usePageBrowser','s_List_View'));
		}
		if($this->conf['dontListenToFlexForm.']['list.']['recordsPerPage'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['pageBrowser.']['recordsPerPage'] , $this->pi_getFFvalue($piFlexForm, 'recordsPerPage','s_List_View'));
		}
		if($this->conf['dontListenToFlexForm.']['list.']['pagesCount'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['list.']['pageBrowser.']['pagesCount'] , $this->pi_getFFvalue($piFlexForm, 'pagesCount','s_List_View'));
		}
		
		if($this->conf['dontListenToFlexForm.']['ics.']['showIcsLinks'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['ics.']['showIcsLinks'] , $this->pi_getFFvalue($piFlexForm, 'showIcsLinks','s_Ics_View'));
		}
		
		if($this->conf['dontListenToFlexForm.']['other.']['showLogin'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['other.']['showLogin'] , $this->pi_getFFvalue($piFlexForm, 'showLogin','s_Other_View'));
		}
		if($this->conf['dontListenToFlexForm.']['other.']['showSearch'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['other.']['showSearch'] , $this->pi_getFFvalue($piFlexForm, 'showSearch','s_Other_View'));
		}
		if($this->conf['dontListenToFlexForm.']['other.']['showJumps'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['other.']['showJumps'] , $this->pi_getFFvalue($piFlexForm, 'showJumps','s_Other_View'));
		}
		if($this->conf['dontListenToFlexForm.']['other.']['showGoto'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['other.']['showGoto'] , $this->pi_getFFvalue($piFlexForm, 'showGoto','s_Other_View'));
		}
		if($this->conf['dontListenToFlexForm.']['other.']['showCalendarSelection'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['other.']['showCalendarSelection'] , $this->pi_getFFvalue($piFlexForm, 'showCalendarSelection','s_Other_View'));
		}
		if($this->conf['dontListenToFlexForm.']['other.']['showCategorySelection'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['other.']['showCategorySelection'] , $this->pi_getFFvalue($piFlexForm, 'showCategorySelection','s_Other_View'));
		}
		if($this->conf['dontListenToFlexForm.']['other.']['showTomorrowEvents'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['other.']['showTomorrowEvents'] , $this->pi_getFFvalue($piFlexForm, 'showTomorrowEvents','s_Other_View'));
		}

		if($this->conf['dontListenToFlexForm.']['filters.']['categorySelection'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['category'] , $this->pi_getFFvalue($piFlexForm, 'categorySelection','s_Cat'));
		}
		if($this->conf['dontListenToFlexForm.']['filters.']['categoryMode'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['categoryMode'] , $this->pi_getFFvalue($piFlexForm, 'categoryMode','s_Cat'));
		}
		if($this->conf['dontListenToFlexForm.']['filters.']['calendarSelection'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['calendar'] , $this->pi_getFFvalue($piFlexForm, 'calendarSelection','s_Cat'));
		}
		if($this->conf['dontListenToFlexForm.']['filters.']['calendarMode'] != 1) {
			$this->updateIfNotEmpty($this->conf['view.']['calendarMode'] , $this->pi_getFFvalue($piFlexForm, 'calendarMode','s_Cat'));
		}
		
		$flexformTyposcript = $this->pi_getFFvalue($piFlexForm, 'myTS','s_TS_View'); 
		if($flexformTyposcript) {
			require_once(PATH_t3lib.'class.t3lib_tsparser.php'); 
			$tsparser = t3lib_div::makeInstance('t3lib_tsparser'); 
			// Copy conf into existing setup 
			$tsparser->setup = $this->conf; 
			// Parse the new Typoscript 
			$tsparser->parse($flexformTyposcript); 
			// Copy the resulting setup back into conf 
			$this->conf = $tsparser->setup; 
		}
	}

	function updateIfNotEmpty(&$confVar, $newConfVar){
		if($newConfVar!=''){
			$confVar = $newConfVar;
		}
	}

	function convertLinkVarArrayToList($linkVar){

		if(is_array($linkVar)){
			$first = true;
			foreach($linkVar as $key => $value){
				if($first){
					if($value=='on'){
						$value = intval($key);
					}
					$new .= intval($value);
					$first = false;
				}else{
					if($value=='on'){
						$value = intval($key);
					}
					$new .= ','.intval($value);
				}
			}
			return $new;
		}else{
			return implode(',',t3lib_div::intExplode(',',$linkVar));
		}
	}

	function replace_tags($tags = array(), $page)
	{
		if (sizeof($tags) > 0)
		{
			$sims = array();
			foreach ($tags as $tag => $data)
			{
				// This replaces any tags
				$sims['###' . strtoupper($tag) . '###'] = tx_cal_functions::substituteMarkerArrayNotCached($data,'###' . strtoupper($tag) . '###', array(),array());
			}

			$page = tx_cal_functions::substituteMarkerArrayNotCached($page, $sims, array(), array());

		}
		else
		{
			//die('No tags designated for replacement.');
		}
		return $page;

	}

	function shortenLastViewAndGetTargetViewParameters($takeFirstInsteadOfLast=false){
		$returnParams = array();
		if(count($this->conf['view.']['allowedViews'])==1 && count($this->conf['view.']['allowedViewsToLinkTo'])==1){
			$returnParams['lastview'] = null;
			$returnParams['view'] = $this->conf['view.']['allowedViews'][0];
				
		}else{
			$views = explode('||',$this->conf['lastview']);
			if($takeFirstInsteadOfLast){
				$target = array_shift($views);
				$views = array();
			}else{
				$target = array_pop($views);
			}
			$lastview = implode('||',$views);

			$viewParams = $this->convertLastViewParamsToArray($target);
			$returnParams = $viewParams[0];
			
			switch(trim($returnParams['view'])){
				case 'event':
				case 'organizer':
				case 'location':
				case 'edit_calendar':
				case 'edit_category':
				case 'edit_location':
				case 'edit_organizer':
				case 'edit_event':
					break;
				case 'rss':
					$returnParams['uid']=null;
					$returnParams['type']=null;
					$returnParams['gettime']=null;
					$returnParams['getdate']=$this->conf['getdate'];
					$returnParams['page_id'] = $returnParams['page_id'].',151';
					break;
				default:
					$returnParams['uid']=null;
					$returnParams['type']=null;
					$returnParams['gettime']=null;
					$returnParams['getdate']= empty($returnParams['getdate']) ? $this->conf['getdate'] : $returnParams['getdate'];
					break;
			}

			switch($this->conf['view']){
				case 'search_event':
					$returnParams['start_day']=null;
					$returnParams['end_day']=null;
					$returnParams['category']=null;
					$returnParams['query']=null;
					break;
				case 'event':
					$returnParams['ts_table']=null;
					break;
			}
			$returnParams['lastview'] = $lastview;
		}
		return $returnParams;
	}

	function extendLastView($overrideParams = false){
		if(count($this->conf['view.']['allowedViews'])==1 && count($this->conf['view.']['allowedViewsToLinkTo'])==1){
			$lastview = NULL;
			$view = $this->conf['view.']['allowedViews'][0];
			return NULL;
		}
		$views = $this->convertLastViewParamsToArray($this->conf['lastview']);

		/* [Franz] what is this code good for? 
		/* Should it prevent that the same view get's added twice? If so - it has to be changed to work propperly
		/***************************
		foreach($views as $view) {
			if($view['view'] == $this->conf['view'] && $view['page_id'] == $GLOBALS['TSFE']->id) {
				return 'view-'.$this->conf['view'].'|'.'page_id-'.$GLOBALS['TSFE']->id;
			}
		}
		*/

		$params = array('view' => $this->conf['view'],'page_id' => $GLOBALS['TSFE']->id);
		if($overrideParams && is_array($overrideParams)) {
			$params = array_merge($params,$overrideParams);
		}
		switch($this->conf['view']){
			case 'event':
			case 'organizer':
			case 'location':
			case 'edit_calendar':
			case 'edit_category':
			case 'edit_location':
			case 'edit_organizer':
			case 'edit_event':
				$params['uid']=$this->conf['uid'];
				$params['type']=$this->conf['type'];
				break;
			default:
				break;
		}

		$paramsForUrl = array();
		foreach($params as $key => $val) {
			$paramsForUrl[] = $key.'-'.$val;
		}

		return ($this->conf['lastview']!=null?$this->conf['lastview'].'||':'').implode('|',$paramsForUrl);
	}

	function convertLastViewParamsToArray($config) {
		$views = explode('||',$config);
		$result = array();
		foreach($views as $viewNr => $viewConf) {
			$paramArray = explode('|',$viewConf);
			foreach($paramArray as $paramString) {
				$param = explode('-',$paramString);
				$result[$viewNr][$param[0]] = $param[1];
			}
		}
		return $result;
	}

	function initRegistry(&$controller){
		$myCobj = &tx_cal_registry::Registry('basic','cobj');
		$myCobj = $controller->cObj;
		$controller->cObj = &$myCobj;
		$myConf = &tx_cal_registry::Registry('basic','conf');
		$myConf = $controller->conf;
		$controller->conf = &$myConf;
		$myController = &tx_cal_registry::Registry('basic','controller');
		$myController = $controller;
		$controller = &$myController;
		// besides of the regular cObj we provide a localCobj, whos data can be overridden with custom data for a more flexible rendering of TSObjects
		$local_cObj = &tx_cal_registry::Registry('basic','local_cobj');
		$local_cObj = t3lib_div :: makeInstance('tslib_cObj');
		$local_cObj->start(array());
		$controller->local_cObj = &$local_cObj;
	}

	function __toString(){
		return get_class($this);
	}

	function pi_wrapInBaseClass($str, $additionalClasses=array())	{
		$content = '<div class="'.str_replace('_','-',$this->prefixId).' '.implode(' ',$additionalClasses).'">
		'.$str.'</div>';

		if(!$GLOBALS['TSFE']->config['config']['disablePrefixComment'])	{
			$content = '
			<!--

			BEGIN: Content of extension "'.$this->extKey.'", plugin "'.$this->prefixId.'"

			-->
			'.$content.'
		
			<!-- END: Content of extension "'.$this->extKey.'", plugin "'.$this->prefixId.'" -->

			';
		}

		return $content;
	}
	
	function moveParamsIntoSession(&$params){
		if(empty($params)){
			$params = $this->piVars;
		}
		$sessionPiVars = t3lib_div::trimExplode(',',$this->conf['sessionPiVars'],1);

		foreach((Array)$params[$this->prefixId] as $key => $value){
			if(in_array($key,$sessionPiVars)){
				$_SESSION[$this->prefixId][$key] = $value;
				unset($params[$this->prefixId][$key]);
			}
		}
	}
	
	function getParamsFromSession(){
		if(!$this->piVars['view']){
			if($this->piVars['week']){
				$this->piVars['view'] = 'week';
			} else if($this->piVars['day']){
				$this->piVars['view'] = 'day';
			} else if($this->piVars['month']){
				$this->piVars['view'] = 'month';
			} else if($this->piVars['year']){
				$this->piVars['view'] = 'year';
			}
		}

		if ( $this->conf['dontListenToPiVars']) {
			$this->piVars = array();
		} else {
			foreach((Array)$_SESSION[$this->prefixId] as $key => $value){
				if(!array_key_exists($key, $this->piVars)){
					$this->piVars[$key] = $value;
				}
			}
		}
		if(!$this->piVars['getdate']){
			if($this->piVars['week']){
				$date = new tx_cal_date($this->piVars['year'].'0101');
	
				$oldYearWeek = ($date->format('%U') > 1)?'0':'1';
				$offset = $date->format('%w') - $this->piVars['weekday'];
				$days = Date_Calc::dateToDays($date->getDay(),$date->getMonth(),$date->getYear());
				$daysTotal = ($this->piVars['week'] - $oldYearWeek) * 7 - $offset + $days;
				
				$this->piVars['getdate'] = Date_Calc::daysToDate($daysTotal,'%Y%m%d');
				
				unset($this->piVars['year']);
				unset($this->piVars['week']);
				unset($this->piVars['weekday']);
			} else {
				$date = new tx_cal_date();
				if(!$this->piVars['year']){
					$this->piVars['year'] = $date->format('%Y');
				}
				if(!$this->piVars['month']){
					$this->piVars['month'] = $date->format('%m');
				}
				if(!$this->piVars['day']){
					$this->piVars['day'] = $date->format('%d');
				}
				$this->piVars['getdate'] = $this->piVars['year'].$this->piVars['month'].$this->piVars['day'];
				unset($this->piVars['year']);
				unset($this->piVars['month']);
				unset($this->piVars['day']);
			} 
		}
		unset($_SESSION[$this->prefixId]);
	}
	
	function clearPiVarParams(){
		if ( $this->conf['dontListenToPiVars'] || $this->conf['clearPiVars'] == 'all') {
			$this->piVars = array();
		} else {
			$clearPiVars = t3lib_div::trimExplode(',',$this->conf['clearPiVars'],1);
			foreach((Array)$this->piVars as $key => $value){
				if(in_array($key,$clearPiVars)){
					unset($this->piVars[$key]);
				}
			}
		}
	}

	/**
	 * Returns a array with fields/parameters that can be used for link rendering in typoscript. It's based on the link functions from tslib_pibase.
	 *
	 * @param	array			Referenced array in which the parameters get merged into
	 * @param	array			Array with parameter=>value pairs of piVars that should override present piVars
	 * @param	boolean		Flag that indicates if the linktarget is allowed to be cached (takes care of cacheHash and no_cache parameter)
	 * @param	boolean		Flag that's clearing all present piVars, thus only piVars defined in $overrulePIvars are kept
	 * @param	integer		Alternative ID of a page that should be used as link target. If empty or 0, current page is used
	 *	@return	nothing
	 *
	 */
	function getParametersForTyposcriptLink(&$parameterArray, $overrulePIvars=array(), $cache=false, $clearAnyway=false, $altPageId=0) {
		
		// copied from function 'pi_linkTP_keepPIvars'
		if (is_array($this->piVars) && is_array($overrulePIvars) && !$clearAnyway)	{
			$piVars = $this->piVars;
			unset($piVars['DATA']);
			$overrulePIvars = t3lib_div::array_merge_recursive_overrule($piVars, $overrulePIvars);
			if ($this->pi_autoCacheEn)	{
				$cache = $this->pi_autoCache($overrulePIvars);
			}
		}
		
		$piVars = array($this->prefixId => $overrulePIvars);

		/* TEST */
		if($piVars['tx_cal_controller']['getdate']){
			$date = new tx_cal_date($piVars['tx_cal_controller']['getdate']);

			$sessionVars = Array();
			switch($piVars['tx_cal_controller']['view']){
				case 'week':
					$piVars['tx_cal_controller']['year'] = $date->format('%Y');
					$piVars['tx_cal_controller']['week'] = $date->format('%U');
					$piVars['tx_cal_controller']['weekday'] = $date->format('%w');
					$sessionVars['month'] = substr($piVars['tx_cal_controller']['getdate'],4,2);
					$sessionVars['day'] = substr($piVars['tx_cal_controller']['getdate'],6,2);

					unset($piVars['tx_cal_controller']['view']);
					unset($piVars['tx_cal_controller']['getdate']);
					break;
				case 'event':
				case 'todo':
					$piVars['tx_cal_controller']['year'] = substr($piVars['tx_cal_controller']['getdate'],0,4);
					$piVars['tx_cal_controller']['month'] = substr($piVars['tx_cal_controller']['getdate'],4,2);
					$piVars['tx_cal_controller']['day'] = substr($piVars['tx_cal_controller']['getdate'],6,2);
					unset($piVars['tx_cal_controller']['getdate']);
					break;
				case 'day':
					$piVars['tx_cal_controller']['year'] = substr($piVars['tx_cal_controller']['getdate'],0,4);
					$piVars['tx_cal_controller']['month'] = substr($piVars['tx_cal_controller']['getdate'],4,2);
					$piVars['tx_cal_controller']['day'] = substr($piVars['tx_cal_controller']['getdate'],6,2);
				case 'month':
					$piVars['tx_cal_controller']['year'] = substr($piVars['tx_cal_controller']['getdate'],0,4);
					$piVars['tx_cal_controller']['month'] = substr($piVars['tx_cal_controller']['getdate'],4,2);
					$sessionVars['day'] = substr($piVars['tx_cal_controller']['getdate'],6,2);
				case 'year':
					$piVars['tx_cal_controller']['year'] = substr($piVars['tx_cal_controller']['getdate'],0,4);
					$sessionVars['month'] = substr($piVars['tx_cal_controller']['getdate'],4,2);
					$sessionVars['day'] = substr($piVars['tx_cal_controller']['getdate'],6,2);
					unset($piVars['tx_cal_controller']['view']);
					unset($piVars['tx_cal_controller']['getdate']);
			}
			
			foreach($sessionVars as $key => $value){
				$_SESSION[$this->prefixId][$key] = $value;
			}

		}
		/* TEST */

		// use internal method for cleaning up piVars
		$this->cleanupUrlParameter($piVars);

		// copied and modified logic of function 'pi_linkTP'
		# once useCacheHash property in typolinks has stdWrap, we can use this flag - until then it's unfortunately useless :(
		#$parameterArray['link_useCacheHash'] = $this->pi_USER_INT_obj ? 0 : $cache;
		$parameterArray['link_no_cache'] = $this->pi_USER_INT_obj ? 0 : !$cache;
		$parameterArray['link_parameter'] = $altPageId ? $altPageId : ($this->pi_tmpPageId ? $this->pi_tmpPageId : $GLOBALS['TSFE']->id);
		$parameterArray['link_additionalParams'] = $this->conf['parent.']['addParams'] . t3lib_div::implodeArrayForUrl('', $piVars, '', true) . $this->pi_moreParams;

		# add time/date related parameters to all link objects, so that they can use them e.g. to display the monthname etc.
		$parameterArray['getdate'] = $this->conf['getdate'];
		if ($overrulePIvars['getdate'] && is_object($date)) {
			$parameterArray['link_timestamp'] = $date->getTime();
			$parameterArray['link_getdate'] = $overrulePIvars['getdate'];
		}
	}


	/**
	 * Modified function pi_linkTP. It calls a function for cleaning up the piVars right before calling the original function.
	 * Returns the $str wrapped in <a>-tags with a link to the CURRENT page, but with $urlParameters set as extra parameters for the page.
	 *
	 * @param	string		The content string to wrap in <a> tags
	 * @param	array			Array with URL parameters as key/value pairs. They will be "imploded" and added to the list of parameters defined in the plugins TypoScript property "parent.addParams" plus $this->pi_moreParams.
	 * @param	boolean		If $cache is set (0/1), the page is asked to be cached by a &cHash value (unless the current plugin using this class is a USER_INT). Otherwise the no_cache-parameter will be a part of the link.
	 * @param	integer		Alternative page ID for the link. (By default this function links to the SAME page!)
	 * @return	string		The input string wrapped in <a> tags
	 * @see pi_linkTP_keepPIvars(), tslib_cObj::typoLink()
	 */
	function pi_linkTP($str,$urlParameters=array(),$cache=0,$altPageId=0){
		$this->cleanupUrlParameter($urlParameters);
		$link = parent::pi_linkTP($str,$urlParameters,$cache,$altPageId);
		$this->pi_USER_INT_obj=0;
		return $link;
	}


	function cleanupUrlParameter(&$urlParameters) {
		/* [Franz] this little construct should be a first step into a centralized url-parameter handler for intelligent and nice looking urls.
		/* But it's merely experimental. A better, more flexible solution/concept need's to be found. 
		/* To save some parsing time, I've removed the calls to $controller->extendLastView() on calls to the pi-link functions,
		/* because all these use internally this function and the lastView parameter is added by this function now.
		*/
		$params = &$urlParameters[$this->prefixId];
		$removeParams = array();
		$lastViewParams = array();
		$useLastView = true;
		// temporary fix for BACK_LINK urls
		$dontExtendLastView = $params['dontExtendLastView'];
		unset($params['dontExtendLastView']);
		
		switch(trim($params['view'])) {
			case 'search_all':
			case 'search_event':
			case 'search_location':
			case 'search_organizer':
				$this->pi_USER_INT_obj=1;
				$useLastView = false;
				break;
			default:
				if($params['type'] || t3lib_div::inList('week,day,year',trim($params['view']))) {
					$removeParams = array($this->getPointerName(),'submit','query');
				}
				if($params[$this->getPointerName] || ($params['category'] && $params['view']!='event')) {
					$useLastView = false;
				}
				break;
		}
		if(count($removeParams)) {
			foreach($removeParams as $name) {
				if(isset($params[$name])) {
					$lastViewParams[$name] = $params[$name];
					unset($params[$name]);
				}
			}
			if($useLastView && !$dontExtendLastView) {
				$params['lastview'] = $this->extendLastView($lastViewParams);
			} else if(!$useLastView) {
				$params['lastview'] = NULL;
			}
		}

		$this->moveParamsIntoSession($urlParameters);
	}


	function checkRedirect($action, $object){
		if($this->conf['view.']['enableAjax']){
			die();
		}
		if($this->conf['view.'][$action.'_'.$object.'.']['redirectAfter'.ucwords($action).'ToPid'] || $this->conf['view.'][$action.'_'.$object.'.']['redirectAfter'.ucwords($action).'ToView']){
			$linkParams = Array();
			if($object=='event'){
				$linkParams[$this->prefixId.'[getdate]'] = $this->conf['getdate'];
			}
			if($this->conf['view.'][$action.'_'.$object.'.']['redirectAfter'.ucwords($action).'ToView']){
				$linkParams[$this->prefixId.'[view]'] = $this->conf['view.'][$action.'_'.$object.'.']['redirectAfter'.ucwords($action).'ToView'];
			}
			$this->pi_linkTP('|',$linkParams, $this->conf['cache'], $this->conf['view.'][$action.'_'.$object.'.']['redirectAfter'.ucwords($action).'ToPid']);
			$rURL = $this->cObj->lastTypoLinkUrl;
			Header('Location: '.t3lib_div::locationHeaderUrl($rURL));
			exit;
		}
	}
	
	/**
	* Method for post processing the rendered event
	* @return processed content/output
	*/
	function finish(&$content) {
		$hookObjectsArr = $this->getHookObjectsArray('finishViewRendering');
		// Hook: preFinishViewRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'preFinishViewRendering')) {
				$hookObj->preFinishViewRendering($this, $content);
			}
		}

		// translate output
		$this->translateLanguageMarker($content);

		// Hook: postFinishViewRendering
		foreach ($hookObjectsArr as $hookObj) {
			if (method_exists($hookObj, 'postFinishViewRendering')) {
				$hookObj->postFinishViewRendering($this, $content);
			}
		}
		return $content;
	}

	function translateLanguageMarker(&$content) {
		// translate leftover markers
		
		preg_match_all('!(###|%%%)([A-Z0-9_-|]*)\1!is', $content, $match);
		$allLanguageMarkers = array_unique($match[2]);

		if (count($allLanguageMarkers)) {
			$sims = array();
			foreach ($allLanguageMarkers as $key => $marker) {
				$wrapper = $match[1][$key];
				if(preg_match('/.*_LABEL$/',$marker)){
					$value = $this->pi_getLL('l_'.strtolower(substr($marker,0,strlen($marker)-6)));
				} else if(preg_match('/^L_.*/',$marker)){
					$value = $this->pi_getLL(strtolower($marker));
				} else if($wrapper == '%%%') {
					$value = $this->pi_getLL('l_'.strtolower($marker));
				} else {
					$value = '';
				}
				$sims[$wrapper.$marker.$wrapper] = $value;
			}
			if (count($sims)) {
				$content = $this->cObj->substituteMarkerArray($content, $sims);
			}
		}
		return $content;
	}
	
	function getPointerName() {
		return $this->pointerName;
	}
	
	function findRelatedEvents($objectType, $uid){
		$relatedEvents = Array();
		$modelObj = &tx_cal_registry::Registry('basic','modelcontroller');
		if($this->conf['view.'][$this->conf['view'].'.'][$objectType.'.']['includeEventsInResult']==1){
			$calendarService = &$modelObj->getServiceObjByKey('cal_calendar_model', 'calendar', 'tx_cal_calendar');
			$calendarSearchString = $calendarService->getCalendarSearchString($pidList, true, $this->conf['calendar']?$this->conf['calendar']:'');
			$relatedEventsFromService = $modelObj->findAllObjects('event', 'tx_cal_phpicalendar', $pidList, 'findAllWithAdditionalWhere', ' AND location_id = '.$uid.$calendarSearchString);
			if(!empty($relatedEventsFromService)){
				foreach($relatedEventsFromService as $eventServiceKey => $eventArray){
					foreach ($eventArray as $eventdaykey => $eventday) {
						if(array_key_exists($eventdaykey,$relatedEvents)==1){
							foreach($eventday as $eventtimekey => $eventtime) {
								if(array_key_exists($eventtimekey,$relatedEvents[$eventdaykey])){
									$relatedEvents[$eventdaykey][$eventtimekey] = $relatedEvents[$eventdaykey][$eventtimekey] + $eventtime;
								} else {
									$relatedEvents[$eventdaykey][$eventtimekey] = $eventtime;
								}
							}
						} else {
							$relatedEvents[$eventdaykey] = $eventday;
						}
					}
				}
			}
			/* Flattens the array returned by the current model into the top level array */
		}
		return $relatedEvents;
	}

	/**
	 * Sets the PHP constant for the week start day. This must be called as
	 * early as possible to avoid PEAR Date defining its default instead.
	 *
	 * @return	void
	 */
	function setWeekStartDay() {
		if ($this->cObj->data['pi_flexform']) {
			$this->pi_initPIflexForm(); // Init and get the flexform data of the plugin
			$piFlexForm = $this->cObj->data['pi_flexform'];

			if($this->conf['dontListenToFlexForm.']['day.']['weekStartDay'] != 1) {
				$this->updateIfNotEmpty($this->conf['view.']['weekStartDay'] , $this->pi_getFFvalue($piFlexForm, 'weekStartDay'));
			}
		}

		define('DATE_CALC_BEGIN_WEEKDAY', $this->conf['view.']['weekStartDay'] == 'Sunday' ? 0 : 1);
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/cal/controller/class.tx_cal_controller.php']) {
	include_once ($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/cal/controller/class.tx_cal_controller.php']);
}
?>