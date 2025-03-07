<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class generates view mode for CiviCase.
 */
class CRM_Case_Form_CaseView extends CRM_Core_Form {
  /**
   * Check for merge cases.
   * @var bool
   */
  private $_mergeCases = FALSE;

  /**
   * Set variables up before form is built.
   */
  public function preProcess() {
    $this->_showRelatedCases = $_GET['relatedCases'] ?? NULL;

    $xmlProcessorProcess = new CRM_Case_XMLProcessor_Process();
    $isMultiClient = $xmlProcessorProcess->getAllowMultipleCaseClients();
    $this->assign('multiClient', $isMultiClient);

    //pull the related cases.
    $this->assign('showRelatedCases', FALSE);
    if ($this->_showRelatedCases) {
      $relatedCases = $this->get('relatedCases');
      if (!isset($relatedCases)) {
        $cId = CRM_Utils_Request::retrieve('cid', 'Integer');
        $caseId = CRM_Utils_Request::retrieve('id', 'Integer');
        $relatedCases = CRM_Case_BAO_Case::getRelatedCases($caseId);
      }
      $this->assign('relatedCases', $relatedCases);
      $this->assign('showRelatedCases', TRUE);
      CRM_Utils_System::setTitle(ts('Related Cases'));
      return;
    }

    $this->_hasAccessToAllCases = CRM_Core_Permission::check('access all cases and activities');
    $this->assign('hasAccessToAllCases', $this->_hasAccessToAllCases);

    $this->assign('contactID', $this->_contactID = (int) $this->get('cid'));
    $this->assign('caseID', $this->_caseID = (int) $this->get('id'));

    // Access check.
    if (!CRM_Case_BAO_Case::accessCase($this->_caseID, FALSE)) {
      CRM_Core_Error::statusBounce(ts('You do not have permission to access this case.'));
    }

    $fulltext = CRM_Utils_Request::retrieve('context', 'Alphanumeric');
    if ($fulltext == 'fulltext') {
      $this->assign('fulltext', $fulltext);
    }

    $this->assign('contactType', CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $this->_contactID, 'contact_type'));
    $this->assign('userID', CRM_Core_Session::getLoggedInContactID());

    //retrieve details about case
    $params = ['id' => $this->_caseID];

    $returnProperties = ['case_type_id', 'subject', 'status_id', 'start_date'];
    CRM_Core_DAO::commonRetrieve('CRM_Case_BAO_Case', $params, $values, $returnProperties);

    $statuses = CRM_Case_PseudoConstant::caseStatus('label', FALSE);
    $caseTypeName = CRM_Case_BAO_Case::getCaseType($this->_caseID, 'name');
    $caseType = CRM_Case_BAO_Case::getCaseType($this->_caseID);
    $statusClass = civicrm_api3('OptionValue', 'getsingle', [
      'option_group_id' => "case_status",
      'value' => $values['case_status_id'],
      'return' => 'grouping',
    ]);

    $this->_caseDetails = [
      'case_type' => $caseType,
      'case_status' => $statuses[$values['case_status_id']] ?? NULL,
      'case_subject' => $values['subject'] ?? NULL,
      'case_start_date' => $values['case_start_date'],
      'status_class' => $statusClass['grouping'],
    ];
    $this->_caseType = $caseTypeName;
    $this->assign('caseDetails', $this->_caseDetails);

    // add to recently viewed

    $url = CRM_Utils_System::url('civicrm/contact/view/case',
      "action=view&reset=1&id={$this->_caseID}&cid={$this->_contactID}&context=home"
    );

    $displayName = CRM_Contact_BAO_Contact::displayName($this->_contactID);
    $this->assign('displayName', $displayName);

    CRM_Utils_System::setTitle($displayName . ' - ' . $caseType);

    $recentOther = [];
    if (CRM_Core_Permission::checkActionPermission('CiviCase', CRM_Core_Action::DELETE)) {
      $recentOther['deleteUrl'] = CRM_Utils_System::url('civicrm/contact/view/case',
        "action=delete&reset=1&id={$this->_caseID}&cid={$this->_contactID}&context=home"
      );
    }

    // Add the recently viewed case
    CRM_Utils_Recent::add($displayName . ' - ' . $caseType,
      $url,
      $this->_caseID,
      'Case',
      $this->_contactID,
      NULL,
      $recentOther
    );

    //get the related cases for given case.
    $relatedCases = $this->get('relatedCases');
    if (!isset($relatedCases)) {
      $relatedCases = CRM_Case_BAO_Case::getRelatedCases($this->_caseID);
      $relatedCases = empty($relatedCases) ? FALSE : $relatedCases;
      $this->set('relatedCases', $relatedCases);
    }
    $this->assign('hasRelatedCases', (bool) $relatedCases);
    if ($relatedCases) {
      $this->assign('relatedCaseLabel', ts('%1 Related Case', [
        'count' => count($relatedCases),
        'plural' => '%1 Related Cases',
      ]));
      $this->assign('relatedCaseUrl', CRM_Utils_System::url('civicrm/contact/view/case', [
        'id' => $this->_caseID,
        'cid' => $this->_contactID,
        'relatedCases' => 1,
        'action' => 'view',
      ]));
    }

    $entitySubType = !empty($values['case_type_id']) ? $values['case_type_id'] : NULL;
    $this->assign('caseTypeID', $entitySubType);
    $groupTree = CRM_Core_BAO_CustomGroup::getTree('Case',
      NULL,
      $this->_caseID,
      NULL,
      $entitySubType,
      NULL,
      TRUE,
      NULL,
      FALSE,
      CRM_Core_Permission::VIEW
    );
    CRM_Core_BAO_CustomGroup::buildCustomDataView($this, $groupTree, FALSE, NULL, NULL, NULL, $this->_caseID);
  }

  /**
   * Set default values for the form.
   *
   * @return array;
   */
  public function setDefaultValues() {
    $defaults = [];
    return $defaults;
  }

  /**
   * Build the form object.
   */
  public function buildQuickForm() {
    //this call is for show related cases.
    if ($this->_showRelatedCases) {
      return;
    }

    $allowedRelationshipTypes = CRM_Contact_BAO_Relationship::getContactRelationshipType($this->_contactID);
    $relationshipTypeMetadata = CRM_Contact_Form_Relationship::getRelationshipTypeMetadata($allowedRelationshipTypes);

    $caseTypeDefinition = civicrm_api3('CaseType', 'getsingle', ['name' => $this->_caseType])['definition'];

    foreach ($caseTypeDefinition['caseRoles'] as $role) {
      if (!empty($role['groups'])) {
        $relationshipType = civicrm_api3('RelationshipType', 'get', [
          'sequential' => 1,
          'name_a_b' => $role['name'],
          'name_b_a' => $role['name'],
          'options' => ['limit' => 1, 'or' => [["name_a_b", "name_b_a"]]],
        ]);
        if (($relationshipType['values'][0]['name_a_b'] ?? NULL) === $role['name']) {
          $relationshipTypeMetadata[$relationshipType['id']]['group_a'] = $role['groups'];
        }
        if (($relationshipType['values'][0]['name_b_a'] ?? NULL) === $role['name']) {
          $relationshipTypeMetadata[$relationshipType['id']]['group_b'] = $role['groups'];
        }
      }
    }

    CRM_Core_Resources::singleton()
      ->addScriptFile('civicrm', 'js/crm.livePage.js', 1, 'html-header')
      ->addScriptFile('civicrm', 'templates/CRM/Case/Form/CaseView.js', 2, 'html-header')
      ->addVars('relationshipTypes', $relationshipTypeMetadata);

    $xmlProcessor = new CRM_Case_XMLProcessor_Process();
    $caseRoles = $xmlProcessor->get($this->_caseType, 'CaseRoles');
    $reports = $xmlProcessor->get($this->_caseType, 'ActivitySets');

    //adding case manager.CRM-4510.
    $managerRoleId = $xmlProcessor->getCaseManagerRoleId($this->_caseType);
    if (!empty($managerRoleId)) {
      $caseRoles[$managerRoleId] = $caseRoles[$managerRoleId] . '<br />' . '(' . ts('Case Manager') . ')';
    }

    $aTypes = $xmlProcessor->get($this->_caseType, 'ActivityTypes', TRUE);

    $allActTypes = CRM_Activity_BAO_Activity::buildOptions('activity_type_id', 'validate');
    $emailActivityType = array_search('Email', $allActTypes);
    $pdfActivityType = array_search('Print PDF Letter', $allActTypes);

    if ($pdfActivityType) {
      $this->assign('exportDoc', CRM_Utils_System::url('civicrm/activity/pdf/add',
        "action=add&context=standalone&reset=1&cid={$this->_contactID}&caseid={$this->_caseID}&atype=$pdfActivityType"));
    }

    // remove Open Case activity type since we're inside an existing case
    if ($openActTypeId = array_search('Open Case', $allActTypes)) {
      unset($aTypes[$openActTypeId]);
    }

    // Only show "link cases" activity if other cases exist.
    $linkActTypeId = array_search('Link Cases', $allActTypes);
    if ($linkActTypeId) {
      $count = civicrm_api3('Case', 'getcount', [
        'check_permissions' => TRUE,
        'id' => ['!=' => $this->_caseID],
        'is_deleted' => 0,
      ]);
      if (!$count) {
        unset($aTypes[$linkActTypeId]);
      }
    }

    if (!$xmlProcessor->getNaturalActivityTypeSort()) {
      asort($aTypes);
    }

    $activityLinks = ['' => ts('Add Activity')];
    foreach ($aTypes as $type => $label) {
      if ($type == $emailActivityType) {
        $url = CRM_Utils_System::url('civicrm/activity/email/add',
          "action=add&context=standalone&reset=1&caseid={$this->_caseID}&atype=$type",
          FALSE, NULL, FALSE
        );
      }
      elseif ($type == $pdfActivityType) {
        $url = CRM_Utils_System::url('civicrm/activity/pdf/add',
          "action=add&context=standalone&reset=1&cid={$this->_contactID}&caseid={$this->_caseID}&atype=$type",
          FALSE, NULL, FALSE);
      }
      else {
        $url = CRM_Utils_System::url('civicrm/case/activity',
          "action=add&reset=1&cid={$this->_contactID}&caseid={$this->_caseID}&atype=$type",
          FALSE, NULL, FALSE
        );
      }
      $activityLinks[$url] = $label;
    }

    $this->add('select', 'add_activity_type_id', '', $activityLinks, FALSE, ['class' => 'crm-select2 crm-action-menu fa-calendar-check-o twenty']);
    if ($this->_hasAccessToAllCases) {
      $this->add('select', 'report_id', '',
        ['' => ts('Activity Audit')] + $reports,
        FALSE,
        ['class' => 'crm-select2 crm-action-menu fa-list-alt']
      );
      $this->add('select', 'timeline_id', '',
        ['' => ts('Add Timeline')] + $reports,
        FALSE,
        ['class' => 'crm-select2 crm-action-menu fa-list-ol']
      );
    }
    // This button is hidden but gets clicked by javascript at
    // https://github.com/civicrm/civicrm-core/blob/bd28ecf8121a85bc069cad3ab912a0c3dff8fdc5/templates/CRM/Case/Form/CaseView.js#L194
    // by the onChange handler for the above timeline_id select.
    $this->addElement('xbutton', $this->getButtonName('next'), ' ', [
      'class' => 'hiddenElement',
      'type' => 'submit',
    ]);

    $this->buildMergeCaseForm();

    //call activity form
    self::activityForm($this, $aTypes);

    //get case related relationships (Case Role)
    $caseRelationships = CRM_Case_BAO_Case::getCaseRoles($this->_contactID, $this->_caseID, NULL, FALSE);

    //save special label because we unset it in the loop
    $managerLabel = empty($managerRoleId) ? '' : $caseRoles[$managerRoleId];

    foreach ($caseRelationships as $key => & $value) {
      if (!empty($managerRoleId)) {
        if (substr($managerRoleId, 0, -4) == $value['relation_type'] && substr($managerRoleId, -3) == $value['relationship_direction']) {
          $value['relation'] = $managerLabel;
        }
      }

      //calculate roles that don't have relationships
      if (!empty($caseRoles[$value['relation_type']])) {
        unset($caseRoles[$value['relation_type']]);
      }
    }

    $this->assign('caseRelationships', $caseRelationships);

    //also add client as role. CRM-4438
    $caseRoles['client'] = CRM_Case_BAO_Case::getContactNames($this->_caseID);

    $this->assign('caseRoles', $caseRoles);

    // Retrieve ALL client relationships
    $relClient = CRM_Contact_BAO_Relationship::getRelationship($this->_contactID,
      CRM_Contact_BAO_Relationship::CURRENT,
      0, 0, 0, NULL, NULL, FALSE
    );

    // Now build 'Other Relationships' array by removing relationships that are already listed under Case Roles
    // so they don't show up twice.
    $clientRelationships = [];
    foreach ($relClient as $r) {
      if (!array_key_exists($r['id'], $caseRelationships)) {
        $clientRelationships[] = $r;
      }
    }
    $this->assign('clientRelationships', $clientRelationships);

    // Now global contact list that appears on all cases.
    $globalGroupInfo = [];
    CRM_Case_BAO_Case::getGlobalContacts($globalGroupInfo);
    $this->assign('globalGroupInfo', $globalGroupInfo);

    // List relationship types for adding an arbitrary new role to the case
    $this->add('select',
      'role_type',
      ts('Relationship Type'),
      ['' => ts('- select type -')] + $allowedRelationshipTypes,
      FALSE,
      ['class' => 'crm-select2 twenty', 'data-select-params' => '{"allowClear": false}']
    );

    $hookCaseSummary = CRM_Utils_Hook::caseSummary($this->_caseID);
    $this->assign('hookCaseSummary', is_array($hookCaseSummary) ? $hookCaseSummary : NULL);

    $allTags = CRM_Core_BAO_Tag::getColorTags('civicrm_case');

    if (!empty($allTags)) {
      $this->add('select2', 'case_tag', ts('Tags'), $allTags, FALSE,
        ['id' => 'tags', 'multiple' => 'multiple']
      );

      $tags = CRM_Core_BAO_EntityTag::getTag($this->_caseID, 'civicrm_case');

      foreach ($tags as $tid) {
        $tagInfo = CRM_Utils_Array::findInTree($tid, $allTags);
        if ($tagInfo) {
          $tags[$tid] = $tagInfo;
        }
        else {
          unset($tags[$tid]);
        }
      }

      $this->setDefaults(['case_tag' => implode(',', array_keys($tags))]);

      $this->assign('tags', $tags);
      $this->assign('showTags', TRUE);
    }
    else {
      $this->assign('showTags', FALSE);
    }

    // build tagset widget

    // see if we have any tagsets which can be assigned to cases
    $parentNames = CRM_Core_BAO_Tag::getTagSet('civicrm_case');
    $tagSetTags = [];
    if ($parentNames) {
      $this->assign('showTags', TRUE);
      $tagSetItems = civicrm_api3('entityTag', 'get', [
        'entity_id' => $this->_caseID,
        'entity_table' => 'civicrm_case',
        'tag_id.parent_id.is_tagset' => 1,
        'options' => ['limit' => 0],
        'return' => ["tag_id.parent_id", "tag_id.parent_id.name", "tag_id.name"],
      ]);
      foreach ($tagSetItems['values'] as $tag) {
        $tagSetTags += [
          $tag['tag_id.parent_id'] => [
            'name' => $tag['tag_id.parent_id.name'],
            'items' => [],
          ],
        ];
        $tagSetTags[$tag['tag_id.parent_id']]['items'][] = $tag['tag_id.name'];
      }
    }
    $this->assign('tagSetTags', $tagSetTags);
    CRM_Core_Form_Tag::buildQuickForm($this, $parentNames, 'civicrm_case', $this->_caseID, FALSE, TRUE);

    $this->addButtons([
      [
        'type' => 'cancel',
        'name' => ts('Done'),
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
        'isDefault' => TRUE,
      ],
    ]);
  }

  /**
   * Process the form.
   */
  public function postProcess() {
    $params = $this->controller->exportValues($this->_name);
    $buttonName = $this->controller->getButtonName();

    // user context
    $url = CRM_Utils_System::url('civicrm/contact/view/case',
      "reset=1&action=view&cid={$this->_contactID}&id={$this->_caseID}&show=1"
    );
    $session = CRM_Core_Session::singleton();
    $session->pushUserContext($url);

    if (!empty($params['timeline_id']) && $buttonName == '_qf_CaseView_next') {
      civicrm_api3('Case', 'addtimeline', [
        'case_id' => $this->_caseID,
        'timeline' => $params['timeline_id'],
      ]);

      $xmlProcessor = new CRM_Case_XMLProcessor_Process();
      $reports = $xmlProcessor->get($this->_caseType, 'ActivitySets');
      CRM_Core_Session::setStatus(ts('Activities from the %1 activity set have been added to this case.',
        [1 => $reports[$params['timeline_id']]]
      ), ts('Done'), 'success');
    }
    elseif ($this->_mergeCases &&
      $buttonName == '_qf_CaseView_next_merge_case'
    ) {

      $mainCaseId = $params['merge_case_id'];
      $otherCaseId = $this->_caseID;

      //merge two cases.
      CRM_Case_BAO_Case::mergeCases($this->_contactID, $mainCaseId, NULL, $otherCaseId);

      //redirect user to main case view.
      $url = CRM_Utils_System::url('civicrm/contact/view/case',
        "reset=1&action=view&cid={$this->_contactID}&id={$mainCaseId}&show=1"
      );
      $session = CRM_Core_Session::singleton();
      $session->pushUserContext($url);
    }
  }

  /**
   * Build the activity selector/datatable
   * @param CRM_Core_Form $form
   * @param array $aTypes
   *   To include acivities related to current case id $form->_caseID.
   */
  public static function activityForm($form, $aTypes = []) {
    $caseRelationships = CRM_Case_BAO_Case::getCaseRoles($form->_contactID, $form->_caseID);
    //build reporter select
    $reporters = ["" => ts(' - any reporter - ')];
    foreach ($caseRelationships as $key => & $value) {
      $reporters[$value['cid']] = $value['sort_name'] . " ( {$value['relation']} )";
    }
    $form->add('select', 'reporter_id', ts('Reporter/Role'), $reporters, FALSE, ['id' => 'reporter_id_' . $form->_caseID]);

    // take all case activity types for search filter, CRM-7187
    $aTypesFilter = [];
    $allCaseActTypes = CRM_Case_PseudoConstant::caseActivityType();
    foreach ($allCaseActTypes as $typeDetails) {
      if (!in_array($typeDetails['name'], ['Open Case'])) {
        $aTypesFilter[$typeDetails['id']] = $typeDetails['label'] ?? NULL;
      }
    }
    $aTypesFilter = $aTypesFilter + $aTypes;
    asort($aTypesFilter);
    $form->add('select', 'activity_type_filter_id', ts('Activity Type'), ['' => ts('- select activity type -')] + $aTypesFilter, FALSE, ['id' => 'activity_type_filter_id_' . $form->_caseID]);

    $activityStatus = CRM_Core_PseudoConstant::activityStatus();
    $form->add('select', 'status_id', ts('Status'), ["" => ts(' - any status - ')] + $activityStatus, FALSE, ['id' => 'status_id_' . $form->_caseID]);

    // activity date search filters
    $form->add('datepicker', 'activity_date_low_' . $form->_caseID, ts('Activity Dates - From'), [], FALSE, ['time' => FALSE]);
    $form->add('datepicker', 'activity_date_high_' . $form->_caseID, ts('To'), [], FALSE, ['time' => FALSE]);

    if (CRM_Core_Permission::check('administer CiviCRM')) {
      $form->add('checkbox', 'activity_deleted', ts('Deleted Activities'), '', FALSE, ['id' => 'activity_deleted_' . $form->_caseID]);
    }
  }

  /**
   * Form elements for merging cases
   */
  public function buildMergeCaseForm() {
    $otherCases = [];
    $result = civicrm_api3('Case', 'get', [
      'check_permissions' => TRUE,
      'contact_id' => $this->_contactID,
      'is_deleted' => 0,
      'option.limit' => 0,
      'id' => ['!=' => $this->_caseID],
      'return' => ['id', 'start_date', 'case_type_id.title'],
    ]);
    foreach ($result['values'] as $id => $case) {
      $otherCases[$id] = "#$id: {$case['case_type_id.title']} " . ts('(opened %1)', [1 => $case['start_date']]);
    }

    $this->assign('mergeCases', $this->_mergeCases = (bool) $otherCases);

    if ($otherCases) {
      $this->add('select', 'merge_case_id',
        ts('Select Case for Merge'),
        [
          '' => ts('- select case -'),
        ] + $otherCases,
        FALSE,
        ['class' => 'crm-select2 huge']
      );
      // This button is hidden but gets clicked by javascript at
      // https://github.com/civicrm/civicrm-core/blob/bd28ecf8121a85bc069cad3ab912a0c3dff8fdc5/templates/CRM/Case/Form/CaseView.js#L55
      // when the mergeCasesDialog is saved.
      $this->addElement('xbutton',
        $this->getButtonName('next', 'merge_case'),
        ts('Merge'),
        [
          'class' => 'hiddenElement',
          'type' => 'submit',
        ]
      );
    }
  }

}
