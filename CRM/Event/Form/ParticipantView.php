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
 * This class generates form components for Participant
 *
 */
class CRM_Event_Form_ParticipantView extends CRM_Core_Form {

  public $useLivePageJS = TRUE;

  /**
   * Set variables up before form is built.
   *
   * @return void
   */
  public function preProcess() {
    $values = $ids = [];
    $participantID = CRM_Utils_Request::retrieve('id', 'Positive', $this, TRUE);
    $contactID = CRM_Utils_Request::retrieve('cid', 'Positive', $this, TRUE);
    $params = ['id' => $participantID];

    CRM_Event_BAO_Participant::getValues($params,
      $values,
      $ids
    );

    if (empty($values)) {
      CRM_Core_Error::statusBounce(ts('The requested participant record does not exist (possibly the record was deleted).'));
    }

    CRM_Event_BAO_Participant::resolveDefaults($values[$participantID]);

    if (!empty($values[$participantID]['fee_level'])) {
      CRM_Event_BAO_Participant::fixEventLevel($values[$participantID]['fee_level']);
    }

    $this->assign('contactId', $contactID);
    $this->assign('participantId', $participantID);

    $paymentId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment',
      $participantID, 'id', 'participant_id'
    );
    $this->assign('hasPayment', $paymentId);
    $this->assign('componentId', $participantID);
    $this->assign('component', 'event');

    if ($parentParticipantId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant',
      $participantID, 'registered_by_id'
    )
    ) {
      $parentHasPayment = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment',
        $parentParticipantId, 'id', 'participant_id'
      );
      $this->assign('parentHasPayment', $parentHasPayment);
    }

    $statusId = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_Participant', $participantID, 'status_id', 'id');
    $status = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantStatusType', $statusId, 'name', 'id');
    if ($status == 'Transferred') {
      $transferId = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_Participant', $participantID, 'transferred_to_contact_id', 'id');
      $pid = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_Participant', $transferId, 'id', 'contact_id');
      $transferName = current(CRM_Contact_BAO_Contact::getContactDetails($transferId));
      $this->assign('pid', $pid);
      $this->assign('transferId', $transferId);
      $this->assign('transferName', $transferName);
    }

    // CRM-20879: Show 'Transfer or Cancel' option beside 'Change fee selection'
    //  only if logged in user have 'edit event participants' permission and
    //  participant status is not Cancelled or Transferred
    if (CRM_Core_Permission::check('edit event participants') && !in_array($status, ['Cancelled', 'Transferred'])) {
      $this->assign('transferOrCancelLink',
        CRM_Utils_System::url(
          'civicrm/event/selfsvcupdate',
          [
            'reset' => 1,
            'is_backoffice' => 1,
            'pid' => $participantID,
            'cs' => CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID, NULL, 'inf'),
          ]
        )
      );
    }

    if ($values[$participantID]['is_test']) {
      $values[$participantID]['status'] = CRM_Core_TestEntity::appendTestText($values[$participantID]['status']);
    }

    // Get Note
    $noteValue = CRM_Core_BAO_Note::getNote($participantID, 'civicrm_participant');

    $values[$participantID]['note'] = array_values($noteValue);

    // Get Line Items
    $lineItem = CRM_Price_BAO_LineItem::getLineItems($participantID);

    if (!CRM_Utils_System::isNull($lineItem)) {
      $values[$participantID]['lineItem'][] = $lineItem;
    }

    $values[$participantID]['totalAmount'] = $values[$participantID]['fee_amount'] ?? NULL;

    // Get registered_by contact ID and display_name if participant was registered by someone else (CRM-4859)
    if (!empty($values[$participantID]['participant_registered_by_id'])) {
      $values[$participantID]['registered_by_contact_id'] = CRM_Core_DAO::getFieldValue("CRM_Event_DAO_Participant",
        $values[$participantID]['participant_registered_by_id'],
        'contact_id', 'id'
      );
      $values[$participantID]['registered_by_display_name'] = CRM_Contact_BAO_Contact::displayName($values[$participantID]['registered_by_contact_id']);
    }

    // Check if this is a primaryParticipant (registered for others) and retrieve additional participants if true  (CRM-4859)
    if (CRM_Event_BAO_Participant::isPrimaryParticipant($participantID)) {
      $values[$participantID]['additionalParticipants'] = CRM_Event_BAO_Participant::getAdditionalParticipants($participantID);
    }

    // get the option value for custom data type
    $customDataType = CRM_Core_OptionGroup::values('custom_data_type', FALSE, FALSE, FALSE, NULL, 'name');
    $roleCustomDataTypeID = array_search('ParticipantRole', $customDataType);
    $eventNameCustomDataTypeID = array_search('ParticipantEventName', $customDataType);
    $eventTypeCustomDataTypeID = array_search('ParticipantEventType', $customDataType);
    $allRoleIDs = explode(CRM_Core_DAO::VALUE_SEPARATOR, $values[$participantID]['role_id']);
    $finalTree = [];

    foreach ($allRoleIDs as $k => $v) {
      $roleGroupTree = CRM_Core_BAO_CustomGroup::getTree('Participant', NULL, $participantID, NULL, $v, $roleCustomDataTypeID,
         TRUE, NULL, FALSE, CRM_Core_Permission::VIEW);
      $eventGroupTree = CRM_Core_BAO_CustomGroup::getTree('Participant', NULL, $participantID, NULL,
        $values[$participantID]['event_id'], $eventNameCustomDataTypeID,
        TRUE, NULL, FALSE, CRM_Core_Permission::VIEW
      );
      $eventTypeID = CRM_Core_DAO::getFieldValue("CRM_Event_DAO_Event", $values[$participantID]['event_id'], 'event_type_id', 'id');
      $eventTypeGroupTree = CRM_Core_BAO_CustomGroup::getTree('Participant', NULL, $participantID, NULL, $eventTypeID, $eventTypeCustomDataTypeID,
        TRUE, NULL, FALSE, CRM_Core_Permission::VIEW);
      $participantGroupTree = CRM_Core_BAO_CustomGroup::getTree('Participant', NULL, $participantID, NULL, [], NULL,
        TRUE, NULL, FALSE, CRM_Core_Permission::VIEW);
      $groupTree = CRM_Utils_Array::crmArrayMerge($roleGroupTree, $eventGroupTree);
      $groupTree = CRM_Utils_Array::crmArrayMerge($groupTree, $eventTypeGroupTree);
      $groupTree = CRM_Utils_Array::crmArrayMerge($groupTree, $participantGroupTree);
      foreach ($groupTree as $treeId => $trees) {
        $finalTree[$treeId] = $trees;
      }
    }
    CRM_Core_BAO_CustomGroup::buildCustomDataView($this, $finalTree, FALSE, NULL, NULL, NULL, $participantID);
    $eventTitle = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Event', $values[$participantID]['event_id'], 'title');
    //CRM-7150, show event name on participant view even if the event is disabled
    if (empty($values[$participantID]['event'])) {
      $values[$participantID]['event'] = $eventTitle;
    }

    //do check for campaigns
    if ($campaignId = CRM_Utils_Array::value('campaign_id', $values[$participantID])) {
      $campaigns = CRM_Campaign_BAO_Campaign::getCampaigns($campaignId);
      $values[$participantID]['campaign'] = $campaigns[$campaignId];
    }

    $this->assign($values[$participantID]);

    // add viewed participant to recent items list
    $url = CRM_Utils_System::url('civicrm/contact/view/participant',
      "action=view&reset=1&id={$values[$participantID]['id']}&cid={$values[$participantID]['contact_id']}&context=home"
    );

    $recentOther = [];
    if (CRM_Core_Permission::check('edit event participants')) {
      $recentOther['editUrl'] = CRM_Utils_System::url('civicrm/contact/view/participant',
        "action=update&reset=1&id={$values[$participantID]['id']}&cid={$values[$participantID]['contact_id']}&context=home"
      );
    }
    if (CRM_Core_Permission::check('delete in CiviEvent')) {
      $recentOther['deleteUrl'] = CRM_Utils_System::url('civicrm/contact/view/participant',
        "action=delete&reset=1&id={$values[$participantID]['id']}&cid={$values[$participantID]['contact_id']}&context=home"
      );
    }

    $participantRoles = CRM_Event_PseudoConstant::participantRole();
    $displayName = CRM_Contact_BAO_Contact::displayName($values[$participantID]['contact_id']);

    $participantCount = [];
    $totalTaxAmount = 0;
    foreach ($lineItem as $k => $v) {
      if (CRM_Utils_Array::value('participant_count', $lineItem[$k]) > 0) {
        $participantCount[] = $lineItem[$k]['participant_count'];
      }
      $totalTaxAmount = $v['tax_amount'] + $totalTaxAmount;
    }
    if (Civi::settings()->get('invoicing')) {
      $this->assign('totalTaxAmount', $totalTaxAmount);
    }
    if ($participantCount) {
      $this->assign('pricesetFieldsCount', $participantCount);
    }
    $this->assign('displayName', $displayName);
    // omitting contactImage from title for now since the summary overlay css doesn't work outside of our crm-container
    CRM_Utils_System::setTitle(ts('View Event Registration for') . ' ' . $displayName);

    $roleId = $values[$participantID]['role_id'] ?? NULL;
    $title = $displayName . ' (' . CRM_Utils_Array::value($roleId, $participantRoles) . ' - ' . $eventTitle . ')';

    $sep = CRM_Core_DAO::VALUE_SEPARATOR;
    $viewRoles = [];
    foreach (explode($sep, $values[$participantID]['role_id']) as $k => $v) {
      $viewRoles[] = $participantRoles[$v];
    }
    $values[$participantID]['role_id'] = implode(', ', $viewRoles);
    $this->assign('role', $values[$participantID]['role_id']);
    // add Participant to Recent Items
    CRM_Utils_Recent::add($title,
      $url,
      $values[$participantID]['id'],
      'Participant',
      $values[$participantID]['contact_id'],
      NULL,
      $recentOther
    );
  }

  /**
   * Build the form object.
   *
   * @return void
   */
  public function buildQuickForm() {
    $this->addButtons([
      [
        'type' => 'cancel',
        'name' => ts('Done'),
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
        'isDefault' => TRUE,
      ],
    ]);
  }

}
