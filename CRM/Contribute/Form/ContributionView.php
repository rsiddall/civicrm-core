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
 * This class generates form components for Payment-Instrument.
 */
class CRM_Contribute_Form_ContributionView extends CRM_Core_Form {

  /**
   * Set variables up before form is built.
   */
  public function preProcess() {
    $id = $this->get('id');
    if (empty($id)) {
      throw new CRM_Core_Exception('Contribution ID is required');
    }
    $params = ['id' => $id];
    $context = CRM_Utils_Request::retrieve('context', 'Alphanumeric', $this);
    $this->assign('context', $context);

    $values = CRM_Contribute_BAO_Contribution::getValuesWithMappings($params);

    $force_create_template = CRM_Utils_Request::retrieve('force_create_template', 'Boolean', $this, FALSE, FALSE);
    if ($force_create_template && !empty($values['contribution_recur_id']) && empty($values['is_template'])) {
      // Create a template contribution.
      $templateContributionId = CRM_Contribute_BAO_ContributionRecur::ensureTemplateContributionExists($values['contribution_recur_id']);
      if (!empty($templateContributionId)) {
        $id = $templateContributionId;
        $params = ['id' => $id];
        $values = CRM_Contribute_BAO_Contribution::getValuesWithMappings($params);
      }
    }
    $this->assign('is_template', $values['is_template']);

    if (CRM_Financial_BAO_FinancialType::isACLFinancialTypeStatus() && $this->_action & CRM_Core_Action::VIEW) {
      $financialTypeID = CRM_Contribute_PseudoConstant::financialType($values['financial_type_id']);
      CRM_Financial_BAO_FinancialType::checkPermissionedLineItems($id, 'view');
      if (CRM_Financial_BAO_FinancialType::checkPermissionedLineItems($id, 'edit', FALSE)) {
        $this->assign('canEdit', TRUE);
      }
      if (CRM_Financial_BAO_FinancialType::checkPermissionedLineItems($id, 'delete', FALSE)) {
        $this->assign('canDelete', TRUE);
      }
      if (!CRM_Core_Permission::check('view contributions of type ' . $financialTypeID)) {
        CRM_Core_Error::statusBounce(ts('You do not have permission to access this page.'));
      }
    }
    elseif ($this->_action & CRM_Core_Action::VIEW) {
      $this->assign('noACL', TRUE);
    }
    CRM_Contribute_BAO_Contribution::resolveDefaults($values);

    if (!empty($values['contribution_page_id'])) {
      $contribPages = CRM_Contribute_PseudoConstant::contributionPage(NULL, TRUE);
      $values['contribution_page_title'] = CRM_Utils_Array::value(CRM_Utils_Array::value('contribution_page_id', $values), $contribPages);
    }

    // get received into i.e to_financial_account_id from last trxn
    $financialTrxnId = CRM_Core_BAO_FinancialTrxn::getFinancialTrxnId($values['contribution_id'], 'DESC');
    $values['to_financial_account'] = '';
    if (!empty($financialTrxnId['financialTrxnId'])) {
      $values['to_financial_account_id'] = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialTrxn', $financialTrxnId['financialTrxnId'], 'to_financial_account_id');
      if ($values['to_financial_account_id']) {
        $values['to_financial_account'] = CRM_Contribute_PseudoConstant::financialAccount($values['to_financial_account_id']);
      }
      $values['payment_processor_id'] = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialTrxn', $financialTrxnId['financialTrxnId'], 'payment_processor_id');
      if ($values['payment_processor_id']) {
        $values['payment_processor_name'] = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessor', $values['payment_processor_id'], 'name');
      }
    }

    if (!empty($values['contribution_recur_id'])) {
      $sql = "SELECT  installments, frequency_interval, frequency_unit FROM civicrm_contribution_recur WHERE id = %1";
      $params = [1 => [$values['contribution_recur_id'], 'Integer']];
      $dao = CRM_Core_DAO::executeQuery($sql, $params);
      if ($dao->fetch()) {
        $values['recur_installments'] = $dao->installments;
        $values['recur_frequency_unit'] = $dao->frequency_unit;
        $values['recur_frequency_interval'] = $dao->frequency_interval;
      }
    }

    $groupTree = CRM_Core_BAO_CustomGroup::getTree('Contribution', NULL, $id, 0, $values['financial_type_id'] ?? NULL,
      NULL, TRUE, NULL, FALSE, CRM_Core_Permission::VIEW);
    CRM_Core_BAO_CustomGroup::buildCustomDataView($this, $groupTree, FALSE, NULL, NULL, NULL, $id);

    $premiumId = NULL;
    $dao = new CRM_Contribute_DAO_ContributionProduct();
    $dao->contribution_id = $id;
    if ($dao->find(TRUE)) {
      $premiumId = $dao->id;
      $productID = $dao->product_id;
    }

    if ($premiumId) {
      $productDAO = new CRM_Contribute_DAO_Product();
      $productDAO->id = $productID;
      $productDAO->find(TRUE);

      $this->assign('premium', $productDAO->name);
      $this->assign('option', $dao->product_option);
      $this->assign('fulfilled', $dao->fulfilled_date);
    }

    // Get Note
    $noteValue = CRM_Core_BAO_Note::getNote(CRM_Utils_Array::value('id', $values), 'civicrm_contribution');
    $values['note'] = array_values($noteValue);

    // show billing address location details, if exists
    if (!empty($values['address_id'])) {
      $addressParams = ['id' => $values['address_id']];
      $addressDetails = CRM_Core_BAO_Address::getValues($addressParams, FALSE, 'id');
      $addressDetails = array_values($addressDetails);
      $values['billing_address'] = $addressDetails[0]['display'];
    }

    //assign soft credit record if exists.
    $SCRecords = CRM_Contribute_BAO_ContributionSoft::getSoftContribution($values['contribution_id'], TRUE);
    if (!empty($SCRecords['soft_credit'])) {
      $this->assign('softContributions', $SCRecords['soft_credit']);
      unset($SCRecords['soft_credit']);
    }

    //assign pcp record if exists
    foreach ($SCRecords as $name => $value) {
      $this->assign($name, $value);
    }

    $lineItems = [CRM_Price_BAO_LineItem::getLineItemsByContributionID(($id))];
    $firstLineItem = reset($lineItems[0]);
    if (empty($firstLineItem['price_set_id'])) {
      // CRM-20297 All we care is that it's not QuickConfig, so no price set
      // is no problem.
      $displayLineItems = TRUE;
    }
    else {
      try {
        $priceSet = civicrm_api3('PriceSet', 'getsingle', [
          'id' => $firstLineItem['price_set_id'],
          'return' => 'is_quick_config, id',
        ]);
        $displayLineItems = !$priceSet['is_quick_config'];
      }
      catch (CiviCRM_API3_Exception $e) {
        throw new CRM_Core_Exception('Cannot find price set by ID');
      }
    }
    $this->assign('lineItem', $lineItems);
    $this->assign('displayLineItems', $displayLineItems);
    $values['totalAmount'] = $values['total_amount'];
    $this->assign('displayLineItemFinancialType', TRUE);

    //do check for campaigns
    if ($campaignId = CRM_Utils_Array::value('campaign_id', $values)) {
      $campaigns = CRM_Campaign_BAO_Campaign::getCampaigns($campaignId);
      $values['campaign'] = $campaigns[$campaignId];
    }
    if ($values['contribution_status'] == 'Refunded') {
      $this->assign('refund_trxn_id', CRM_Core_BAO_FinancialTrxn::getRefundTransactionTrxnID($id));
    }

    // assign values to the template
    $this->assignVariables($values, array_keys($values));
    $invoicing = CRM_Invoicing_Utils::isInvoicingEnabled();
    $this->assign('invoicing', $invoicing);
    $this->assign('isDeferred', Civi::settings()->get('deferred_revenue_enabled'));
    if ($invoicing && isset($values['tax_amount'])) {
      $this->assign('totalTaxAmount', $values['tax_amount']);
    }

    // omitting contactImage from title for now since the summary overlay css doesn't work outside of our crm-container
    $displayName = CRM_Contact_BAO_Contact::displayName($values['contact_id']);
    $this->assign('displayName', $displayName);
    // Check if this is default domain contact CRM-10482
    if (CRM_Contact_BAO_Contact::checkDomainContact($values['contact_id'])) {
      $displayName .= ' (' . ts('default organization') . ')';
    }

    if (empty($values['is_template'])) {
      CRM_Utils_System::setTitle(ts('View Contribution from') . ' ' . $displayName);
    }
    else {
      CRM_Utils_System::setTitle(ts('View Template Contribution from') . ' ' . $displayName);
    }

    // add viewed contribution to recent items list
    $url = CRM_Utils_System::url('civicrm/contact/view/contribution',
      "action=view&reset=1&id={$values['id']}&cid={$values['contact_id']}&context=home"
    );

    $title = $displayName . ' - (' . CRM_Utils_Money::format($values['total_amount'], $values['currency']) . ' ' . ' - ' . $values['financial_type'] . ')';

    $recentOther = [];
    if (CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::UPDATE)) {
      $recentOther['editUrl'] = CRM_Utils_System::url('civicrm/contact/view/contribution',
        "action=update&reset=1&id={$values['id']}&cid={$values['contact_id']}&context=home"
      );
    }
    if (CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::DELETE)) {
      $recentOther['deleteUrl'] = CRM_Utils_System::url('civicrm/contact/view/contribution',
        "action=delete&reset=1&id={$values['id']}&cid={$values['contact_id']}&context=home"
      );
    }
    CRM_Utils_Recent::add($title,
      $url,
      $values['id'],
      'Contribution',
      $values['contact_id'],
      NULL,
      $recentOther
    );
    $statusOptionValueNames = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
    $contributionStatus = $statusOptionValueNames[$values['contribution_status_id']];
    if (in_array($contributionStatus, ['Partially paid', 'Pending refund'])
        || ($contributionStatus == 'Pending' && $values['is_pay_later'])
        ) {
      if ($contributionStatus == 'Pending refund') {
        $this->assign('paymentButtonName', ts('Record Refund'));
      }
      else {
        $this->assign('paymentButtonName', ts('Record Payment'));
      }
      $this->assign('addRecordPayment', TRUE);
      $this->assign('contactId', $values['contact_id']);
      $this->assign('componentId', $id);
      $this->assign('component', 'contribution');
    }
    $this->assignPaymentInfoBlock($id);
  }

  /**
   * Build the form object.
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

  /**
   * Assign the values to build the payment info block.
   *
   * @todo - this is a bit too much copy & paste from AbstractEditPayment
   * (justifying on the basis it's 'pretty short' and in a different inheritance
   * tree. I feel like traits are probably the longer term answer).
   *
   * @param int $id
   *
   * @return string
   *   Block title.
   */
  protected function assignPaymentInfoBlock($id) {
    // component is used in getPaymentInfo primarily to retrieve the contribution id, we
    // already have that.
    $paymentInfo = CRM_Contribute_BAO_Contribution::getPaymentInfo($id, 'contribution', TRUE);
    $title = ts('View Payment');
    $this->assign('transaction', TRUE);
    $this->assign('payments', $paymentInfo['transaction']);
    $this->assign('paymentLinks', $paymentInfo['payment_links']);
    return $title;
  }

}
