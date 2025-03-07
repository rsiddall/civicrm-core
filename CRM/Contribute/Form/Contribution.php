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

use Civi\Payment\Exception\PaymentProcessorException;

/**
 * This class generates form components for processing a contribution.
 */
class CRM_Contribute_Form_Contribution extends CRM_Contribute_Form_AbstractEditPayment {
  /**
   * The id of the contribution that we are processing.
   *
   * @var int
   */
  public $_id;

  /**
   * The id of the premium that we are processing.
   *
   * @var int
   */
  public $_premiumID = NULL;

  /**
   * @var CRM_Contribute_DAO_ContributionProduct
   */
  public $_productDAO = NULL;

  /**
   * The id of the note.
   *
   * @var int
   */
  public $_noteID;

  /**
   * The id of the contact associated with this contribution.
   *
   * @var int
   */
  public $_contactID;

  /**
   * The id of the pledge payment that we are processing.
   *
   * @var int
   */
  public $_ppID;

  /**
   * Is this contribution associated with an online.
   * financial transaction
   *
   * @var bool
   */
  public $_online = FALSE;

  /**
   * Stores all product options.
   *
   * @var array
   */
  public $_options;

  /**
   * Storage of parameters from form
   *
   * @var array
   */
  public $_params;

  /**
   * The contribution values if an existing contribution
   * @var array
   */
  public $_values;

  /**
   * The pledge values if this contribution is associated with pledge
   * @var array
   */
  public $_pledgeValues;

  public $_contributeMode = 'direct';

  public $_context;

  /**
   * Parameter with confusing name.
   * @var string
   * @todo what is it?
   */
  public $_compContext;

  public $_compId;

  /**
   * Possible From email addresses
   * @var array
   */
  public $_fromEmails;

  /**
   * ID of from email.
   *
   * @var int
   */
  public $fromEmailId;

  /**
   * Store the line items if price set used.
   * @var array
   */
  public $_lineItems;

  /**
   * Line item
   * @var array
   * @todo explain why we use lineItem & lineItems
   */
  public $_lineItem;

  /**
   * Soft credit info.
   *
   * @var array
   */
  public $_softCreditInfo;

  protected $_formType;

  /**
   * Array of the payment fields to be displayed in the payment fieldset (pane) in billingBlock.tpl
   * this contains all the information to describe these fields from quickform. See CRM_Core_Form_Payment getPaymentFormFieldsMetadata
   *
   * @var array
   */
  public $_paymentFields = [];
  /**
   * Logged in user's email.
   * @var string
   */
  public $userEmail;

  /**
   * Price set ID.
   *
   * @var int
   */
  public $_priceSetId;

  /**
   * Price set as an array
   * @var array
   */
  public $_priceSet;

  /**
   * User display name
   *
   * @var string
   */
  public $userDisplayName;

  /**
   * Status message to be shown to the user.
   *
   * @var array
   */
  protected $statusMessage = [];

  /**
   * Status message title to be shown to the user.
   *
   * Generally the payment processor message title is 'Complete' and offline is 'Saved'
   * although this might not be a good fit with the broad range of processors.
   *
   * @var string
   */
  protected $statusMessageTitle;

  /**
   * @var int
   *
   * Max row count for soft credits. The value here is +1 the actual number of
   * rows displayed.
   */
  public $_softCreditItemCount = 11;

  /**
   * @var bool
   */
  public $submitOnce = TRUE;

  /**
   * Explicitly declare the form context.
   */
  public function getDefaultContext() {
    return 'create';
  }

  /**
   * Set variables up before form is built.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function preProcess() {
    // Check permission for action.
    if (!CRM_Core_Permission::checkActionPermission('CiviContribute', $this->_action)) {
      CRM_Core_Error::statusBounce(ts('You do not have permission to access this page.'));
    }

    parent::preProcess();

    $this->_formType = $_GET['formType'] ?? NULL;

    // Get price set id.
    $this->_priceSetId = $_GET['priceSetId'] ?? NULL;
    $this->set('priceSetId', $this->_priceSetId);
    $this->assign('priceSetId', $this->_priceSetId);

    // Get the pledge payment id
    $this->_ppID = CRM_Utils_Request::retrieve('ppid', 'Positive', $this);

    $this->assign('action', $this->_action);

    // Get the contribution id if update
    $this->_id = CRM_Utils_Request::retrieve('id', 'Positive');
    if (!empty($this->_id)) {
      $this->assignPaymentInfoBlock();
      $this->assign('contribID', $this->_id);
      $this->assign('isUsePaymentBlock', TRUE);
    }

    $this->_context = CRM_Utils_Request::retrieve('context', 'Alphanumeric', $this);
    $this->assign('context', $this->_context);

    $this->_compId = CRM_Utils_Request::retrieve('compId', 'Positive', $this);

    $this->_compContext = CRM_Utils_Request::retrieve('compContext', 'String', $this);

    //set the contribution mode.
    $this->_mode = CRM_Utils_Request::retrieve('mode', 'Alphanumeric', $this);

    $this->assign('contributionMode', $this->_mode);
    if ($this->_action & CRM_Core_Action::DELETE) {
      return;
    }

    $this->_fromEmails = CRM_Core_BAO_Email::getFromEmail();

    if (in_array('CiviPledge', CRM_Core_Config::singleton()->enableComponents) && !$this->_formType) {
      $this->preProcessPledge();
    }

    if ($this->_id) {
      $this->showRecordLinkMesssage($this->_id);
    }
    $this->_values = [];

    // Current contribution id.
    if ($this->_id) {
      $this->assignPremiumProduct($this->_id);
      $this->buildValuesAndAssignOnline_Note_Type($this->_id, $this->_values);
    }

    // when custom data is included in this page
    if (!empty($_POST['hidden_custom'])) {
      $this->applyCustomData('Contribution', $this->getFinancialTypeID(), $this->_id);
    }

    if (!empty($this->_values['is_template'])) {
      $this->assign('is_template', TRUE);
    }

    $this->_lineItems = [];
    if ($this->_id) {
      if (!empty($this->_compId) && $this->_compContext === 'participant') {
        $this->assign('compId', $this->_compId);
        $lineItem = CRM_Price_BAO_LineItem::getLineItems($this->_compId);
      }
      else {
        $lineItem = CRM_Price_BAO_LineItem::getLineItems($this->_id, 'contribution', 1, TRUE, TRUE);
      }
      // wtf?
      empty($lineItem) ? NULL : $this->_lineItems[] = $lineItem;
    }

    $this->assign('lineItem', empty($lineItem) ? FALSE : [$lineItem]);

    // Set title
    if ($this->_mode && $this->_id) {
      $this->_payNow = TRUE;
      $this->assign('payNow', $this->_payNow);
      CRM_Utils_System::setTitle(ts('Pay with Credit Card'));
    }
    elseif (!empty($this->_values['is_template'])) {
      $this->setPageTitle(ts('Template Contribution'));
    }
    elseif ($this->_mode) {
      $this->setPageTitle($this->_ppID ? ts('Credit Card Pledge Payment') : ts('Credit Card Contribution'));
    }
    else {
      $this->setPageTitle($this->_ppID ? ts('Pledge Payment') : ts('Contribution'));
    }
  }

  /**
   * Set default values.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function setDefaultValues() {
    $defaults = $this->_values;

    // Set defaults for pledge payment.
    if ($this->_ppID) {
      $defaults['total_amount'] = $this->_pledgeValues['pledgePayment']['scheduled_amount'] ?? NULL;
      $defaults['financial_type_id'] = $this->_pledgeValues['financial_type_id'] ?? NULL;
      $defaults['currency'] = $this->_pledgeValues['currency'] ?? NULL;
      $defaults['option_type'] = 1;
    }

    if ($this->_action & CRM_Core_Action::DELETE) {
      return $defaults;
    }

    $defaults['frequency_interval'] = 1;
    $defaults['frequency_unit'] = 'month';

    // Set soft credit defaults.
    CRM_Contribute_Form_SoftCredit::setDefaultValues($defaults, $this);

    if ($this->_mode) {
      // @todo - remove this function as the parent does it too.
      $config = CRM_Core_Config::singleton();
      // Set default country from config if no country set.
      if (empty($defaults["billing_country_id-{$this->_bltID}"])) {
        $defaults["billing_country_id-{$this->_bltID}"] = $config->defaultContactCountry;
      }

      if (empty($defaults["billing_state_province_id-{$this->_bltID}"])) {
        $defaults["billing_state_province_id-{$this->_bltID}"] = $config->defaultContactStateProvince;
      }

      $billingDefaults = $this->getProfileDefaults('Billing', $this->_contactID);
      $defaults = array_merge($defaults, $billingDefaults);
    }

    if ($this->_id) {
      $this->_contactID = $defaults['contact_id'];
    }
    elseif ($this->_contactID) {
      $defaults['contact_id'] = $this->_contactID;
    }

    // Set $newCredit variable in template to control whether link to credit card mode is included.
    $this->assign('newCredit', CRM_Core_Config::isEnabledBackOfficeCreditCardPayments());

    // Fix the display of the monetary value, CRM-4038.
    if (isset($defaults['total_amount'])) {
      $total_value = $defaults['total_amount'];
      $defaults['total_amount'] = CRM_Utils_Money::formatLocaleNumericRoundedForDefaultCurrency($total_value);
      if (!empty($defaults['tax_amount'])) {
        $componentDetails = CRM_Contribute_BAO_Contribution::getComponentDetails($this->_id);
        if (empty($componentDetails['membership']) && empty($componentDetails['participant'])) {
          $defaults['total_amount'] = CRM_Utils_Money::formatLocaleNumericRoundedForDefaultCurrency($total_value - $defaults['tax_amount']);
        }
      }
    }

    $amountFields = ['non_deductible_amount', 'fee_amount'];
    foreach ($amountFields as $amt) {
      if (isset($defaults[$amt])) {
        $defaults[$amt] = CRM_Utils_Money::formatLocaleNumericRoundedForDefaultCurrency($defaults[$amt]);
      }
    }

    if (empty($defaults['payment_instrument_id'])) {
      $defaults['payment_instrument_id'] = $this->getDefaultPaymentInstrumentId();
    }

    if (!empty($defaults['is_test'])) {
      $this->assign('is_test', TRUE);
    }

    $this->assign('showOption', TRUE);
    // For Premium section.
    if ($this->_premiumID) {
      $this->assign('showOption', FALSE);
      $options = $this->_options[$this->_productDAO->product_id] ?? "";
      if (!$options) {
        $this->assign('showOption', TRUE);
      }
      $options_key = CRM_Utils_Array::key($this->_productDAO->product_option, $options);
      if ($options_key) {
        $defaults['product_name'] = [$this->_productDAO->product_id, trim($options_key)];
      }
      else {
        $defaults['product_name'] = [$this->_productDAO->product_id];
      }
      if ($this->_productDAO->fulfilled_date) {
        $defaults['fulfilled_date'] = $this->_productDAO->fulfilled_date;
      }
    }

    if (isset($this->userEmail)) {
      $this->assign('email', $this->userEmail);
    }

    if (!empty($defaults['is_pay_later'])) {
      $this->assign('is_pay_later', TRUE);
    }
    $this->assign('contribution_status_id', CRM_Utils_Array::value('contribution_status_id', $defaults));
    if (!empty($defaults['contribution_status_id']) && in_array(
        CRM_Contribute_PseudoConstant::contributionStatus($defaults['contribution_status_id'], 'name'),
        // Historically not 'Cancelled' hence not using CRM_Contribute_BAO_Contribution::isContributionStatusNegative.
        ['Refunded', 'Chargeback']
      )) {
      $defaults['refund_trxn_id'] = CRM_Core_BAO_FinancialTrxn::getRefundTransactionTrxnID($this->_id);
    }
    else {
      $defaults['refund_trxn_id'] = $defaults['trxn_id'] ?? NULL;
    }

    if (!$this->_id && empty($defaults['receive_date'])) {
      $defaults['receive_date'] = date('Y-m-d H:i:s');
    }

    $currency = $defaults['currency'] ?? NULL;
    $this->assign('currency', $currency);
    // Hack to get currency info to the js layer. CRM-11440.
    CRM_Utils_Money::format(1);
    $this->assign('currencySymbol', CRM_Utils_Array::value($currency, CRM_Utils_Money::$_currencySymbols));
    $this->assign('totalAmount', CRM_Utils_Array::value('total_amount', $defaults));

    // Inherit campaign from pledge.
    if ($this->_ppID && !empty($this->_pledgeValues['campaign_id'])) {
      $defaults['campaign_id'] = $this->_pledgeValues['campaign_id'];
    }

    $this->_defaults = $defaults;
    return $defaults;
  }

  /**
   * Build the form object.
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm() {
    if ($this->_id) {
      $this->add('hidden', 'id', $this->_id);
    }

    if ($this->_action & CRM_Core_Action::DELETE) {
      $this->addButtons([
        [
          'type' => 'next',
          'name' => ts('Delete'),
          'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
          'isDefault' => TRUE,
        ],
        [
          'type' => 'cancel',
          'name' => ts('Cancel'),
        ],
      ]);
      return;
    }

    // FIXME: This probably needs to be done in preprocess
    if (CRM_Financial_BAO_FinancialType::isACLFinancialTypeStatus()
      && $this->_action & CRM_Core_Action::UPDATE
      && !empty($this->_values['financial_type_id'])
    ) {
      $financialTypeID = CRM_Contribute_PseudoConstant::financialType($this->_values['financial_type_id']);
      CRM_Financial_BAO_FinancialType::checkPermissionedLineItems($this->_id, 'edit');
      if (!CRM_Core_Permission::check('edit contributions of type ' . $financialTypeID)) {
        CRM_Core_Error::statusBounce(ts('You do not have permission to access this page.'));
      }
    }
    $allPanes = [];

    //tax rate from financialType
    $this->assign('taxRates', json_encode(CRM_Core_PseudoConstant::getTaxRates()));
    $this->assign('currencies', json_encode(CRM_Core_OptionGroup::values('currencies_enabled')));

    // build price set form.
    $buildPriceSet = FALSE;
    $invoicing = CRM_Invoicing_Utils::isInvoicingEnabled();
    $this->assign('invoicing', $invoicing);

    $buildRecurBlock = FALSE;

    // display tax amount on edit contribution page
    if ($invoicing && $this->_action & CRM_Core_Action::UPDATE && isset($this->_values['tax_amount'])) {
      $this->assign('totalTaxAmount', $this->_values['tax_amount']);
    }

    if (empty($this->_lineItems) &&
      ($this->_priceSetId || !empty($_POST['price_set_id']))
    ) {
      $buildPriceSet = TRUE;
      $getOnlyPriceSetElements = TRUE;
      if (!$this->_priceSetId) {
        $this->_priceSetId = $_POST['price_set_id'];
        $getOnlyPriceSetElements = FALSE;
      }

      $this->set('priceSetId', $this->_priceSetId);
      CRM_Price_BAO_PriceSet::buildPriceSet($this);

      // get only price set form elements.
      if ($getOnlyPriceSetElements) {
        return;
      }
    }
    // use to build form during form rule.
    $this->assign('buildPriceSet', $buildPriceSet);

    $defaults = $this->_values;
    $additionalDetailFields = [
      'note',
      'thankyou_date',
      'invoice_id',
      'non_deductible_amount',
      'fee_amount',
    ];
    foreach ($additionalDetailFields as $key) {
      if (!empty($defaults[$key])) {
        $defaults['hidden_AdditionalDetail'] = 1;
        break;
      }
    }

    if ($this->_productDAO) {
      if ($this->_productDAO->product_id) {
        $defaults['hidden_Premium'] = 1;
      }
    }

    if ($this->_noteID &&
      !CRM_Utils_System::isNull($this->_values['note'])
    ) {
      $defaults['hidden_AdditionalDetail'] = 1;
    }

    $paneNames = [];
    if (empty($this->_payNow)) {
      $paneNames[ts('Additional Details')] = 'AdditionalDetail';
    }

    //Add Premium pane only if Premium is exists.
    $dao = new CRM_Contribute_DAO_Product();
    $dao->is_active = 1;

    if ($dao->find(TRUE) && empty($this->_payNow)) {
      $paneNames[ts('Premium Information')] = 'Premium';
    }

    $this->payment_instrument_id = CRM_Utils_Array::value('payment_instrument_id', $defaults, $this->getDefaultPaymentInstrumentId());
    if (CRM_Core_Payment_Form::buildPaymentForm($this, $this->_paymentProcessor, FALSE, TRUE, $this->payment_instrument_id) == TRUE) {
      if (!empty($this->_recurPaymentProcessors)) {
        $buildRecurBlock = TRUE;
        if ($this->_ppID) {
          // ppID denotes a pledge payment.
          foreach ($this->_paymentProcessors as $processor) {
            if (!empty($processor['is_recur']) && !empty($processor['object']) && $processor['object']->supports('recurContributionsForPledges')) {
              $buildRecurBlock = TRUE;
              break;
            }
            $buildRecurBlock = FALSE;
          }
        }
        if ($buildRecurBlock) {
          CRM_Contribute_Form_Contribution_Main::buildRecur($this);
          $this->setDefaults(['is_recur' => 0]);
          $this->assign('buildRecurBlock', TRUE);
        }
      }
    }
    $this->addPaymentProcessorSelect(FALSE, $buildRecurBlock);

    foreach ($paneNames as $name => $type) {
      $allPanes[$name] = $this->generatePane($type, $defaults);
    }

    $qfKey = $this->controller->_key;
    $this->assign('qfKey', $qfKey);
    $this->assign('allPanes', $allPanes);

    $this->addFormRule(['CRM_Contribute_Form_Contribution', 'formRule'], $this);

    if ($this->_formType) {
      $this->assign('formType', $this->_formType);
      return;
    }

    $this->applyFilter('__ALL__', 'trim');

    //need to assign custom data type and subtype to the template
    $this->assign('customDataType', 'Contribution');
    $this->assign('customDataSubType', $this->getFinancialTypeID());
    $this->assign('entityID', $this->_id);

    $contactField = $this->addEntityRef('contact_id', ts('Contributor'), ['create' => TRUE, 'api' => ['extra' => ['email']]], TRUE);
    if ($this->_context !== 'standalone') {
      $contactField->freeze();
    }

    $attributes = CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_Contribution');

    // Check permissions for financial type first
    CRM_Financial_BAO_FinancialType::getAvailableFinancialTypes($financialTypes, $this->_action);
    if (empty($financialTypes)) {
      CRM_Core_Error::statusBounce(ts('You do not have all the permissions needed for this page.'));
    }
    $financialType = $this->add('select', 'financial_type_id',
      ts('Financial Type'),
      ['' => ts('- select -')] + $financialTypes,
      TRUE,
      ['onChange' => "CRM.buildCustomData( 'Contribution', this.value );"]
    );

    $paymentInstrument = FALSE;
    if (!$this->_mode) {
      // payment_instrument isn't required in edit and will not be present when payment block is enabled.
      $required = !$this->_id;
      $checkPaymentID = array_search('Check', CRM_Contribute_BAO_Contribution::buildOptions('payment_instrument_id', 'validate'));
      $paymentInstrument = $this->add('select', 'payment_instrument_id',
        ts('Payment Method'),
        ['' => ts('- select -')] + CRM_Contribute_BAO_Contribution::buildOptions('payment_instrument_id', 'create'),
        $required, ['onChange' => "return showHideByValue('payment_instrument_id','{$checkPaymentID}','checkNumber','table-row','select',false);"]
      );
    }

    $trxnId = $this->add('text', 'trxn_id', ts('Transaction ID'), ['class' => 'twelve'] + $attributes['trxn_id']);

    //add receipt for offline contribution
    $this->addElement('checkbox', 'is_email_receipt', ts('Send Receipt?'));

    $this->add('select', 'from_email_address', ts('Receipt From'), $this->_fromEmails);

    $component = 'contribution';
    $componentDetails = [];
    if ($this->_id) {
      $componentDetails = CRM_Contribute_BAO_Contribution::getComponentDetails($this->_id);
      if (!empty($componentDetails['membership'])) {
        $component = 'membership';
      }
      elseif (!empty($componentDetails['participant'])) {
        $component = 'participant';
      }
    }
    if ($this->_ppID) {
      $component = 'pledge';
    }
    $status = CRM_Contribute_BAO_Contribution_Utils::getContributionStatuses($component, $this->_id);

    // define the status IDs that show the cancellation info, see CRM-17589
    $cancelInfo_show_ids = [];
    foreach (array_keys($status) as $status_id) {
      if (CRM_Contribute_BAO_Contribution::isContributionStatusNegative($status_id)) {
        $cancelInfo_show_ids[] = "'$status_id'";
      }
    }
    $this->assign('cancelInfo_show_ids', implode(',', $cancelInfo_show_ids));

    $statusElement = $this->add('select', 'contribution_status_id',
      ts('Contribution Status'),
      $status,
      FALSE
    );

    $currencyFreeze = FALSE;
    if (!empty($this->_payNow) && ($this->_action & CRM_Core_Action::UPDATE)) {
      $statusElement->freeze();
      $currencyFreeze = TRUE;
      $attributes['total_amount']['readonly'] = TRUE;
    }

    // CRM-16189, add Revenue Recognition Date
    if (Civi::settings()->get('deferred_revenue_enabled')) {
      $revenueDate = $this->add('datepicker', 'revenue_recognition_date', ts('Revenue Recognition Date'), [], FALSE, ['time' => FALSE]);
      if ($this->_id && !CRM_Contribute_BAO_Contribution::allowUpdateRevenueRecognitionDate($this->_id)) {
        $revenueDate->freeze();
      }
    }

    // add various dates
    $this->addField('receive_date', ['entity' => 'contribution'], !$this->_mode, FALSE);
    $this->addField('receipt_date', ['entity' => 'contribution'], FALSE, FALSE);
    $this->addField('cancel_date', ['entity' => 'contribution', 'label' => ts('Cancelled / Refunded Date')], FALSE, FALSE);

    if ($this->_online) {
      $this->assign('hideCalender', TRUE);
    }

    $this->add('textarea', 'cancel_reason', ts('Cancellation / Refund Reason'), $attributes['cancel_reason']);

    $totalAmount = NULL;
    if (empty($this->_lineItems)) {
      $buildPriceSet = FALSE;
      $priceSets = CRM_Price_BAO_PriceSet::getAssoc(FALSE, 'CiviContribute');
      if (!empty($priceSets) && !$this->_ppID) {
        $buildPriceSet = TRUE;
      }

      // don't allow price set for contribution if it is related to participant, or if it is a pledge payment
      // and if we already have line items for that participant. CRM-5095
      if ($buildPriceSet && $this->_id) {
        $pledgePaymentId = CRM_Core_DAO::getFieldValue('CRM_Pledge_DAO_PledgePayment',
          $this->_id,
          'id',
          'contribution_id'
        );
        if ($pledgePaymentId) {
          $buildPriceSet = FALSE;
        }
        if ($participantID = CRM_Utils_Array::value('participant', $componentDetails)) {
          $participantLI = CRM_Price_BAO_LineItem::getLineItems($participantID);
          if (!CRM_Utils_System::isNull($participantLI)) {
            $buildPriceSet = FALSE;
          }
        }
      }

      $hasPriceSets = FALSE;
      if ($buildPriceSet) {
        $hasPriceSets = TRUE;
        // CRM-16451: set financial type of 'Price Set' in back office contribution
        // instead of selecting manually
        $financialTypeIds = CRM_Price_BAO_PriceSet::getAssoc(FALSE, 'CiviContribute', 'financial_type_id');
        $element = $this->add('select', 'price_set_id', ts('Choose price set'),
          [
            '' => ts('Choose price set'),
          ] + $priceSets,
          NULL, ['onchange' => "buildAmount( this.value, " . json_encode($financialTypeIds) . ");"]
        );
        if ($this->_online && !($this->_action & CRM_Core_Action::UPDATE)) {
          $element->freeze();
        }
      }
      $this->assign('hasPriceSets', $hasPriceSets);
      if (!($this->_action & CRM_Core_Action::UPDATE)) {
        if ($this->_online || $this->_ppID) {
          $attributes['total_amount'] = array_merge($attributes['total_amount'], [
            'READONLY' => TRUE,
            'style' => "background-color:#EBECE4",
          ]);
          $optionTypes = [
            '1' => ts('Adjust Pledge Payment Schedule?'),
            '2' => ts('Adjust Total Pledge Amount?'),
          ];
          $this->addRadio('option_type',
            NULL,
            $optionTypes,
            [], '<br/>'
          );

          $currencyFreeze = TRUE;
        }
      }

      $totalAmount = $this->addMoney('total_amount',
        ts('Total Amount'),
        !$hasPriceSets,
        $attributes['total_amount'],
        TRUE, 'currency', NULL, $currencyFreeze
      );
    }

    $this->add('text', 'source', ts('Source'), CRM_Utils_Array::value('source', $attributes));

    // CRM-7362 --add campaigns.
    CRM_Campaign_BAO_Campaign::addCampaign($this, CRM_Utils_Array::value('campaign_id', $this->_values));

    if (empty($this->_payNow)) {
      CRM_Contribute_Form_SoftCredit::buildQuickForm($this);
    }

    $js = NULL;
    if (!$this->_mode) {
      $js = ['onclick' => "return verify( );"];
    }

    $mailingInfo = Civi::settings()->get('mailing_backend');
    $this->assign('outBound_option', $mailingInfo['outBound_option']);

    $this->addButtons([
      [
        'type' => 'upload',
        'name' => ts('Save'),
        'js' => $js,
        'isDefault' => TRUE,
      ],
      [
        'type' => 'upload',
        'name' => ts('Save and New'),
        'js' => $js,
        'subName' => 'new',
      ],
      [
        'type' => 'cancel',
        'name' => ts('Cancel'),
      ],
    ]);

    // if contribution is related to membership or participant freeze Financial Type, Amount
    if ($this->_id) {
      $componentDetails = CRM_Contribute_BAO_Contribution::getComponentDetails($this->_id);
      $isCancelledStatus = ($this->_values['contribution_status_id'] == CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Cancelled'));

      if (!empty($componentDetails['membership']) ||
        !empty($componentDetails['participant']) ||
        // if status is Cancelled freeze Amount, Payment Instrument, Check #, Financial Type,
        // Net and Fee Amounts are frozen in AdditionalInfo::buildAdditionalDetail
        $isCancelledStatus
      ) {
        if ($totalAmount) {
          $totalAmount->freeze();
          $this->getElement('currency')->freeze();
        }
        if ($isCancelledStatus) {
          $paymentInstrument->freeze();
          $trxnId->freeze();
        }
        $financialType->freeze();
        $this->assign('freezeFinancialType', TRUE);
      }
    }

    if ($this->_action & CRM_Core_Action::VIEW) {
      $this->freeze();
    }
  }

  /**
   * Global form rule.
   *
   * @param array $fields
   *   The input form values.
   * @param array $files
   *   The uploaded files if any.
   * @param $self
   *
   * @return bool|array
   *   true if no errors, else array of errors
   */
  public static function formRule($fields, $files, $self) {
    $errors = [];
    // Check for Credit Card Contribution.
    if ($self->_mode) {
      if (empty($fields['payment_processor_id'])) {
        $errors['payment_processor_id'] = ts('Payment Processor is a required field.');
      }
      else {
        // validate payment instrument (e.g. credit card number)
        CRM_Core_Payment_Form::validatePaymentInstrument($fields['payment_processor_id'], $fields, $errors, NULL);
      }
    }

    // Do the amount validations.
    if (empty($fields['total_amount']) && empty($self->_lineItems)) {
      if ($priceSetId = CRM_Utils_Array::value('price_set_id', $fields)) {
        CRM_Price_BAO_PriceField::priceSetValidation($priceSetId, $fields, $errors);
      }
    }

    $softErrors = CRM_Contribute_Form_SoftCredit::formRule($fields, $errors, $self);

    //CRM-16285 - Function to handle validation errors on form, for recurring contribution field.
    CRM_Contribute_BAO_ContributionRecur::validateRecurContribution($fields, $files, $self, $errors);

    // Form rule for status http://wiki.civicrm.org/confluence/display/CRM/CiviAccounts+4.3+Data+Flow
    if (($self->_action & CRM_Core_Action::UPDATE)
      && $self->_id
      && $self->_values['contribution_status_id'] != $fields['contribution_status_id']
    ) {
      CRM_Contribute_BAO_Contribution::checkStatusValidation($self->_values, $fields, $errors);
    }
    // CRM-16015, add form-rule to restrict change of financial type if using price field of different financial type
    if (($self->_action & CRM_Core_Action::UPDATE)
      && $self->_id
      && $self->_values['financial_type_id'] != $fields['financial_type_id']
    ) {
      CRM_Contribute_BAO_Contribution::checkFinancialTypeChange(NULL, $self->_id, $errors);
    }
    //FIXME FOR NEW DATA FLOW http://wiki.civicrm.org/confluence/display/CRM/CiviAccounts+4.3+Data+Flow
    if (!empty($fields['fee_amount']) && !empty($fields['financial_type_id']) && $financialType = CRM_Contribute_BAO_Contribution::validateFinancialType($fields['financial_type_id'])) {
      $errors['financial_type_id'] = ts("Financial Account of account relationship of 'Expense Account is' is not configured for Financial Type : ") . $financialType;
    }

    // $trxn_id must be unique CRM-13919
    if (!empty($fields['trxn_id'])) {
      $queryParams = [1 => [$fields['trxn_id'], 'String']];
      $query = 'select count(*) from civicrm_contribution where trxn_id = %1';
      if ($self->_id) {
        $queryParams[2] = [(int) $self->_id, 'Integer'];
        $query .= ' and id !=%2';
      }
      $tCnt = CRM_Core_DAO::singleValueQuery($query, $queryParams);
      if ($tCnt) {
        $errors['trxn_id'] = ts('Transaction ID\'s must be unique. Transaction \'%1\' already exists in your database.', [1 => $fields['trxn_id']]);
      }
    }
    // CRM-16189
    $order = new CRM_Financial_BAO_Order();
    $order->setPriceSelectionFromUnfilteredInput($fields);
    if (isset($fields['total_amount'])) {
      $order->setOverrideTotalAmount((float) CRM_Utils_Rule::cleanMoney($fields['total_amount']));
    }
    $lineItems = $order->getLineItems();
    try {
      CRM_Financial_BAO_FinancialAccount::checkFinancialTypeHasDeferred($fields, $self->_id, $lineItems);
    }
    catch (CRM_Core_Exception $e) {
      $errors['financial_type_id'] = ' ';
      $errors['_qf_default'] = $e->getMessage();
    }
    $errors = array_merge($errors, $softErrors);
    return $errors;
  }

  /**
   * Process the form submission.
   */
  public function postProcess() {
    if ($this->_action & CRM_Core_Action::DELETE) {
      CRM_Contribute_BAO_Contribution::deleteContribution($this->_id);
      CRM_Core_Session::singleton()->replaceUserContext(CRM_Utils_System::url('civicrm/contact/view',
        "reset=1&cid={$this->_contactID}&selectedChild=contribute"
      ));
      return;
    }
    // Get the submitted form values.
    $submittedValues = $this->controller->exportValues($this->_name);

    try {
      $contribution = $this->submit($submittedValues, $this->_action, $this->_ppID);
    }
    catch (PaymentProcessorException $e) {
      // Set the contribution mode.
      $urlParams = "action=add&cid={$this->_contactID}";
      if ($this->_mode) {
        $urlParams .= "&mode={$this->_mode}";
      }
      if (!empty($this->_ppID)) {
        $urlParams .= "&context=pledge&ppid={$this->_ppID}";
      }

      CRM_Core_Error::statusBounce($e->getMessage(), $urlParams, ts('Payment Processor Error'));
    }
    $this->setUserContext();

    //store contribution ID if not yet set (on create)
    if (empty($this->_id) && !empty($contribution->id)) {
      $this->_id = $contribution->id;
    }
    if (!empty($this->_id) && CRM_Core_Permission::access('CiviMember')) {
      $membershipPaymentCount = civicrm_api3('MembershipPayment', 'getCount', ['contribution_id' => $this->_id]);
      if ($membershipPaymentCount) {
        $this->ajaxResponse['updateTabs']['#tab_member'] = CRM_Contact_BAO_Contact::getCountComponent('membership', $this->_contactID);
      }
    }
    if (!empty($this->_id) && CRM_Core_Permission::access('CiviEvent')) {
      $participantPaymentCount = civicrm_api3('ParticipantPayment', 'getCount', ['contribution_id' => $this->_id]);
      if ($participantPaymentCount) {
        $this->ajaxResponse['updateTabs']['#tab_participant'] = CRM_Contact_BAO_Contact::getCountComponent('participant', $this->_contactID);
      }
    }
  }

  /**
   * Process credit card payment.
   *
   * @param array $submittedValues
   * @param array $lineItem
   *
   * @param int $contactID
   *   Contact ID
   *
   * @return bool|\CRM_Contribute_DAO_Contribution
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \CiviCRM_API3_Exception
   */
  protected function processCreditCard($submittedValues, $lineItem, $contactID) {
    $isTest = ($this->_mode == 'test') ? 1 : 0;
    // CRM-12680 set $_lineItem if its not set
    // @todo - I don't believe this would ever BE set. I can't find anywhere in the code.
    // It would be better to pass line item out to functions than $this->_lineItem as
    // we don't know what is being changed where.
    if (empty($this->_lineItem) && !empty($lineItem)) {
      $this->_lineItem = $lineItem;
    }

    $this->_paymentObject = Civi\Payment\System::singleton()->getById($submittedValues['payment_processor_id']);
    $this->_paymentProcessor = $this->_paymentObject->getPaymentProcessor();

    // Set source if not set
    if (empty($submittedValues['source'])) {
      $userID = CRM_Core_Session::singleton()->get('userID');
      $userSortName = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $userID,
        'sort_name'
      );
      $submittedValues['source'] = ts('Submit Credit Card Payment by: %1', [1 => $userSortName]);
    }

    $params = $submittedValues;
    $this->_params = array_merge($this->_params, $submittedValues);

    // Mapping requiring documentation.
    $this->_params['payment_processor'] = $submittedValues['payment_processor_id'];

    $now = date('YmdHis');

    $this->_contributorEmail = $this->userEmail;
    $this->_contributorContactID = $contactID;
    $this->processBillingAddress();
    if (!empty($params['source'])) {
      unset($params['source']);
    }

    $this->_params['amount'] = $this->_params['total_amount'];
    // @todo - stop setting amount level in this function & call the CRM_Price_BAO_PriceSet::getAmountLevel
    // function to get correct amount level consistently. Remove setting of the amount level in
    // CRM_Price_BAO_PriceSet::processAmount. Extend the unit tests in CRM_Price_BAO_PriceSetTest
    // to cover all variants.
    $this->_params['amount_level'] = 0;
    $this->_params['description'] = ts("Contribution submitted by a staff person using contributor's credit card");
    $this->_params['currencyID'] = CRM_Utils_Array::value('currency',
      $this->_params,
      CRM_Core_Config::singleton()->defaultCurrency
    );

    $this->_params['pcp_display_in_roll'] = $params['pcp_display_in_roll'] ?? NULL;
    $this->_params['pcp_roll_nickname'] = $params['pcp_roll_nickname'] ?? NULL;
    $this->_params['pcp_personal_note'] = $params['pcp_personal_note'] ?? NULL;

    //Add common data to formatted params
    CRM_Contribute_Form_AdditionalInfo::postProcessCommon($params, $this->_params, $this);

    if (empty($this->_params['invoice_id'])) {
      $this->_params['invoiceID'] = md5(uniqid(rand(), TRUE));
    }
    else {
      $this->_params['invoiceID'] = $this->_params['invoice_id'];
    }

    // At this point we've created a contact and stored its address etc
    // all the payment processors expect the name and address to be in the
    // so we copy stuff over to first_name etc.
    $paymentParams = $this->_params;
    $paymentParams['contactID'] = $contactID;
    CRM_Core_Payment_Form::mapParams($this->_bltID, $this->_params, $paymentParams, TRUE);

    $financialType = new CRM_Financial_DAO_FinancialType();
    $financialType->id = $params['financial_type_id'];
    $financialType->find(TRUE);

    // Add some financial type details to the params list
    // if folks need to use it.
    $paymentParams['contributionType_name'] = $this->_params['contributionType_name'] = $financialType->name;
    $paymentParams['contributionPageID'] = NULL;

    if (!empty($this->_params['is_email_receipt'])) {
      $paymentParams['email'] = $this->userEmail;
      $paymentParams['is_email_receipt'] = 1;
    }
    else {
      $paymentParams['is_email_receipt'] = 0;
      $this->_params['is_email_receipt'] = 0;
    }
    if (!empty($this->_params['receive_date'])) {
      $paymentParams['receive_date'] = $this->_params['receive_date'];
    }

    if (!empty($this->_params['is_email_receipt'])) {
      $this->_params['receipt_date'] = $now;
    }

    $this->set('params', $this->_params);

    $this->assign('receive_date', $this->_params['receive_date']);

    // Result has all the stuff we need
    // lets archive it to a financial transaction
    if ($financialType->is_deductible) {
      $this->assign('is_deductible', TRUE);
      $this->set('is_deductible', TRUE);
    }
    $contributionParams = [
      'id' => $this->_params['contribution_id'] ?? NULL,
      'contact_id' => $contactID,
      'line_item' => $lineItem,
      'is_test' => $isTest,
      'campaign_id' => $this->_params['campaign_id'] ?? NULL,
      'contribution_page_id' => $this->_params['contribution_page_id'] ?? NULL,
      'source' => CRM_Utils_Array::value('source', $paymentParams, CRM_Utils_Array::value('description', $paymentParams)),
      'thankyou_date' => $this->_params['thankyou_date'] ?? NULL,
    ];
    $contributionParams['payment_instrument_id'] = $this->_paymentProcessor['payment_instrument_id'];

    $contribution = CRM_Contribute_Form_Contribution_Confirm::processFormContribution($this,
      $this->_params,
      NULL,
      $contributionParams,
      $financialType,
      FALSE,
      $this->_bltID,
      CRM_Utils_Array::value('is_recur', $this->_params)
    );

    $paymentParams['contributionID'] = $contribution->id;
    $paymentParams['contributionPageID'] = $contribution->contribution_page_id;
    $paymentParams['contributionRecurID'] = $contribution->contribution_recur_id;

    if ($paymentParams['amount'] > 0.0) {
      // force a re-get of the payment processor in case the form changed it, CRM-7179
      // NOTE - I expect this is obsolete.
      $payment = Civi\Payment\System::singleton()->getByProcessor($this->_paymentProcessor);
      try {
        $completeStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
        $result = $payment->doPayment($paymentParams, 'contribute');
        $this->assign('trxn_id', $result['trxn_id']);
        $contribution->trxn_id = $result['trxn_id'];
        /* Our scenarios here are
         *  1) the payment failed & an Exception should have been thrown
         *  2) the payment succeeded but the payment is not immediate (for example a recurring payment
         *     with a delayed start)
         *  3) the payment succeeded with an immediate payment.
         *
         * The doPayment function ensures that payment_status_id is always set
         * as historically we have had to guess from the context - ie doDirectPayment
         * = error or success, unless it is a recurring contribution in which case it is pending.
         */
        if ($result['payment_status_id'] == $completeStatusId) {
          try {
            civicrm_api3('contribution', 'completetransaction', [
              'id' => $contribution->id,
              'trxn_id' => $result['trxn_id'],
              'payment_processor_id' => $this->_paymentProcessor['id'],
              'is_transactional' => FALSE,
              'fee_amount' => $result['fee_amount'] ?? NULL,
              'card_type_id' => $paymentParams['card_type_id'] ?? NULL,
              'pan_truncation' => $paymentParams['pan_truncation'] ?? NULL,
              'is_email_receipt' => FALSE,
            ]);
            // This has now been set to 1 in the DB - declare it here also
            $contribution->contribution_status_id = 1;
          }
          catch (CiviCRM_API3_Exception $e) {
            if ($e->getErrorCode() != 'contribution_completed') {
              \Civi::log()->error('CRM_Contribute_Form_Contribution::processCreditCard CiviCRM_API3_Exception: ' . $e->getMessage());
              throw new CRM_Core_Exception('Failed to update contribution in database');
            }
          }
        }
        else {
          // Save the trxn_id.
          $contribution->save();
        }
      }
      catch (PaymentProcessorException $e) {
        CRM_Contribute_BAO_Contribution::failPayment($contribution->id, $paymentParams['contactID'], $e->getMessage());
        throw new PaymentProcessorException($e->getMessage());
      }
    }
    // Send receipt mail.
    array_unshift($this->statusMessage, ts('The contribution record has been saved.'));
    if ($contribution->id && !empty($this->_params['is_email_receipt'])) {
      $this->_params['trxn_id'] = $result['trxn_id'] ?? NULL;
      $this->_params['contact_id'] = $contactID;
      $this->_params['contribution_id'] = $contribution->id;
      if (CRM_Contribute_Form_AdditionalInfo::emailReceipt($this, $this->_params, TRUE)) {
        $this->statusMessage[] = ts('A receipt has been emailed to the contributor.');
      }
    }

    return $contribution;
  }

  /**
   * Generate the data to construct a snippet based pane.
   *
   * This form also assigns the showAdditionalInfo var based on historical code.
   * This appears to mean 'there is a pane to show'.
   *
   * @param string $type
   *   Type of Pane - this is generally used to determine the function name used to build it
   *   - e.g CreditCard, AdditionalDetail
   * @param array $defaults
   *
   * @return array
   *   We aim to further refactor & simplify this but currently
   *   - the panes array
   *   - should additional info be shown?
   */
  protected function generatePane($type, $defaults) {
    $urlParams = "snippet=4&formType={$type}";
    if ($this->_mode) {
      $urlParams .= "&mode={$this->_mode}";
    }

    $open = 'false';
    if ($type == 'CreditCard' ||
      $type == 'DirectDebit'
    ) {
      $open = 'true';
    }

    $pane = [
      'url' => CRM_Utils_System::url('civicrm/contact/view/contribution', $urlParams),
      'open' => $open,
      'id' => $type,
    ];

    // See if we need to include this paneName in the current form.
    if ($this->_formType == $type || !empty($_POST["hidden_{$type}"]) ||
      !empty($defaults["hidden_{$type}"])
    ) {
      $this->assign('showAdditionalInfo', TRUE);
      $pane['open'] = 'true';
    }

    if ($type == 'CreditCard' || $type == 'DirectDebit') {
      // @todo would be good to align tpl name with form name...
      // @todo document why this hidden variable is required.
      $this->add('hidden', 'hidden_' . $type, 1);
      return $pane;
    }
    else {
      $additionalInfoFormFunction = 'build' . $type;
      CRM_Contribute_Form_AdditionalInfo::$additionalInfoFormFunction($this);
      return $pane;
    }
  }

  /**
   * Wrapper for unit testing the post process submit function.
   *
   * (If we expose through api we can get default additions 'for free').
   *
   * @param array $params
   * @param int $action
   * @param string|null $creditCardMode
   *
   * @return CRM_Contribute_BAO_Contribution
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function testSubmit($params, $action, $creditCardMode = NULL) {
    $defaults = [
      'soft_credit_contact_id' => [],
      'receive_date' => date('Y-m-d H:i:s'),
      'receipt_date' => '',
      'cancel_date' => '',
      'hidden_Premium' => 1,
    ];
    $this->_bltID = 5;
    if (!empty($params['id'])) {
      $existingContribution = civicrm_api3('contribution', 'getsingle', [
        'id' => $params['id'],
      ]);
      $this->_id = $params['id'];
      $this->_values = $existingContribution;
      if (CRM_Invoicing_Utils::isInvoicingEnabled()) {
        $this->_values['tax_amount'] = civicrm_api3('contribution', 'getvalue', [
          'id' => $params['id'],
          'return' => 'tax_amount',
        ]);
      }
    }
    else {
      $existingContribution = [];
    }

    $this->_defaults['contribution_status_id'] = CRM_Utils_Array::value('contribution_status_id',
      $existingContribution
    );

    $this->_defaults['total_amount'] = CRM_Utils_Array::value('total_amount',
      $existingContribution
    );

    if ($creditCardMode) {
      $this->_mode = $creditCardMode;
    }

    // Required because processCreditCard calls set method on this.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $this->controller = new CRM_Core_Controller();

    CRM_Contribute_Form_AdditionalInfo::buildPremium($this);

    $this->_fields = [];
    return $this->submit(array_merge($defaults, $params), $action, CRM_Utils_Array::value('pledge_payment_id', $params));

  }

  /**
   * @param array $submittedValues
   *
   * @param int $action
   *   Action constant
   *    - CRM_Core_Action::UPDATE
   *
   * @param $pledgePaymentID
   *
   * @return \CRM_Contribute_BAO_Contribution
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  protected function submit($submittedValues, $action, $pledgePaymentID) {
    $pId = $contribution = $isRelatedId = FALSE;
    $this->_params = $submittedValues;
    $this->beginPostProcess();
    // reassign submitted form values if the any information is formatted via beginPostProcess
    $submittedValues = $this->_params;

    if (!empty($submittedValues['price_set_id']) && $action & CRM_Core_Action::UPDATE) {
      $line = CRM_Price_BAO_LineItem::getLineItems($this->_id, 'contribution');
      $lineID = key($line);
      $priceSetId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceField', CRM_Utils_Array::value('price_field_id', $line[$lineID]), 'price_set_id');
      $quickConfig = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', $priceSetId, 'is_quick_config');
      // Why do we do this? Seems like a like a wrapper for old functionality - but single line price sets & quick
      // config should be treated the same.
      if ($quickConfig) {
        CRM_Price_BAO_LineItem::deleteLineItems($this->_id, 'civicrm_contribution');
      }
    }

    // Process price set and get total amount and line items.
    $lineItem = [];
    $priceSetId = $submittedValues['price_set_id'] ?? NULL;
    if (empty($priceSetId) && !$this->_id) {
      $this->_priceSetId = $priceSetId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', 'default_contribution_amount', 'id', 'name');
      $this->_priceSet = current(CRM_Price_BAO_PriceSet::getSetDetail($priceSetId));
      $fieldID = key($this->_priceSet['fields']);
      $fieldValueId = key($this->_priceSet['fields'][$fieldID]['options']);
      $this->_priceSet['fields'][$fieldID]['options'][$fieldValueId]['amount'] = $submittedValues['total_amount'];
      $submittedValues['price_' . $fieldID] = 1;
    }

    // Every contribution has a price-set - the only reason it shouldn't be set is if we are dealing with
    // quick config (very very arguably) & yet we see that this could still be quick config so this should be understood
    // as a point of fragility rather than a logical 'if' clause.
    if ($priceSetId) {
      CRM_Price_BAO_PriceSet::processAmount($this->_priceSet['fields'],
        $submittedValues, $lineItem[$priceSetId], $priceSetId);
      // Unset tax amount for offline 'is_quick_config' contribution.
      // @todo WHY  - quick config was conceived as a quick way to configure contribution forms.
      // this is an example of 'other' functionality being hung off it.
      if ($this->_priceSet['is_quick_config'] &&
        !array_key_exists($submittedValues['financial_type_id'], CRM_Core_PseudoConstant::getTaxRates())
      ) {
        unset($submittedValues['tax_amount']);
      }
      $submittedValues['total_amount'] = $submittedValues['amount'] ?? NULL;
    }

    if ($this->_id) {
      if ($this->_compId) {
        if ($this->_context == 'participant') {
          $pId = $this->_compId;
        }
        elseif ($this->_context == 'membership') {
          $isRelatedId = TRUE;
        }
        else {
          $pId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment', $this->_id, 'participant_id', 'contribution_id');
        }
      }
      else {
        $contributionDetails = CRM_Contribute_BAO_Contribution::getComponentDetails($this->_id);
        if (array_key_exists('membership', $contributionDetails)) {
          $isRelatedId = TRUE;
        }
        elseif (array_key_exists('participant', $contributionDetails)) {
          $pId = $contributionDetails['participant'];
        }
      }
      if (!empty($this->_payNow)) {
        $this->_params['contribution_id'] = $this->_id;
      }
    }

    if (!$priceSetId && !empty($submittedValues['total_amount']) && $this->_id) {
      // CRM-10117 update the line items for participants.
      // @todo - if we are completing a contribution then the api call
      // civicrm_api3('Contribution', 'completetransaction') should take care of
      // all associated updates rather than replicating them on the form layer.
      if ($pId) {
        $entityTable = 'participant';
        $entityID = $pId;
        $isRelatedId = FALSE;
        $participantParams = [
          'fee_amount' => $submittedValues['total_amount'],
          'id' => $entityID,
        ];
        CRM_Event_BAO_Participant::add($participantParams);
        if (empty($this->_lineItems)) {
          $this->_lineItems[] = CRM_Price_BAO_LineItem::getLineItems($entityID, 'participant', TRUE);
        }
      }
      else {
        $entityTable = 'contribution';
        $entityID = $this->_id;
      }

      $lineItems = CRM_Price_BAO_LineItem::getLineItems($entityID, $entityTable, FALSE, TRUE, $isRelatedId);
      foreach (array_keys($lineItems) as $id) {
        $lineItems[$id]['id'] = $id;
      }
      $itemId = key($lineItems);
      if ($itemId && !empty($lineItems[$itemId]['price_field_id'])) {
        $this->_priceSetId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceField', $lineItems[$itemId]['price_field_id'], 'price_set_id');
      }

      // @todo see above - new functionality has been inappropriately added to the quick config concept
      // and new functionality has been added onto the form layer rather than the BAO :-(
      if ($this->_priceSetId && CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', $this->_priceSetId, 'is_quick_config')) {
        //CRM-16833: Ensure tax is applied only once for membership conribution, when status changed.(e.g Pending to Completed).
        $componentDetails = CRM_Contribute_BAO_Contribution::getComponentDetails($this->_id);
        if (empty($componentDetails['membership']) && empty($componentDetails['participant'])) {
          if (!($this->_action & CRM_Core_Action::UPDATE && (($this->_defaults['contribution_status_id'] != $submittedValues['contribution_status_id'])))) {
            $lineItems[$itemId]['unit_price'] = $lineItems[$itemId]['line_total'] = CRM_Utils_Rule::cleanMoney(CRM_Utils_Array::value('total_amount', $submittedValues));
          }
        }

        // Update line total and total amount with tax on edit.
        $financialItemsId = CRM_Core_PseudoConstant::getTaxRates();
        if (array_key_exists($submittedValues['financial_type_id'], $financialItemsId)) {
          $lineItems[$itemId]['tax_rate'] = $financialItemsId[$submittedValues['financial_type_id']];
        }
        else {
          $lineItems[$itemId]['tax_rate'] = $lineItems[$itemId]['tax_amount'] = "";
          $submittedValues['tax_amount'] = 0;
        }
        if ($lineItems[$itemId]['tax_rate']) {
          $lineItems[$itemId]['tax_amount'] = ($lineItems[$itemId]['tax_rate'] / 100) * $lineItems[$itemId]['line_total'];
          $submittedValues['total_amount'] = $lineItems[$itemId]['line_total'] + $lineItems[$itemId]['tax_amount'];
          $submittedValues['tax_amount'] = $lineItems[$itemId]['tax_amount'];
        }
      }
      // CRM-10117 update the line items for participants.
      if (!empty($lineItems[$itemId]['price_field_id'])) {
        $lineItem[$this->_priceSetId] = $lineItems;
      }
    }

    $isQuickConfig = 0;
    if ($this->_priceSetId && CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', $this->_priceSetId, 'is_quick_config')) {
      $isQuickConfig = 1;
    }
    //CRM-11529 for quick config back office transactions
    //when financial_type_id is passed in form, update the
    //line items with the financial type selected in form
    // NOTE that this IS still a legitimate use of 'quick-config' for contributions under the current DB but
    // we should look at having a price field per contribution type & then there would be little reason
    // for the back-office contribution form postProcess to know if it is a quick-config form.
    if ($isQuickConfig && !empty($submittedValues['financial_type_id']) && !empty($lineItem[$this->_priceSetId])
    ) {
      foreach ($lineItem[$this->_priceSetId] as &$values) {
        $values['financial_type_id'] = $submittedValues['financial_type_id'];
      }
    }

    if (!isset($submittedValues['total_amount'])) {
      $submittedValues['total_amount'] = $this->_values['total_amount'] ?? NULL;
      // Avoid tax amount deduction on edit form and keep it original, because this will lead to error described in CRM-20676
      if (!$this->_id) {
        $submittedValues['total_amount'] -= CRM_Utils_Array::value('tax_amount', $this->_values, 0);
      }
    }
    $this->assign('lineItem', !empty($lineItem) && !$isQuickConfig ? $lineItem : FALSE);

    $isEmpty = array_keys(array_flip($submittedValues['soft_credit_contact_id']));
    if ($this->_id && count($isEmpty) == 1 && key($isEmpty) == NULL) {
      civicrm_api3('ContributionSoft', 'get', ['contribution_id' => $this->_id, 'pcp_id' => ['IS NULL' => 1], 'api.ContributionSoft.delete' => 1]);
    }

    // set the contact, when contact is selected
    if (!empty($submittedValues['contact_id'])) {
      $this->_contactID = $submittedValues['contact_id'];
    }

    $formValues = $submittedValues;

    // Credit Card Contribution.
    if ($this->_mode) {
      $paramsSetByPaymentProcessingSubsystem = [
        'trxn_id',
        'payment_instrument_id',
        'contribution_status_id',
        'cancel_date',
        'cancel_reason',
      ];
      foreach ($paramsSetByPaymentProcessingSubsystem as $key) {
        if (isset($formValues[$key])) {
          unset($formValues[$key]);
        }
      }
      $contribution = $this->processCreditCard($formValues, $lineItem, $this->_contactID);
      foreach ($paramsSetByPaymentProcessingSubsystem as $key) {
        $formValues[$key] = $contribution->$key;
      }
    }
    else {
      // Offline Contribution.
      $submittedValues = $this->unsetCreditCardFields($submittedValues);

      // get the required field value only.

      $params = [
        'contact_id' => $this->_contactID,
        'currency' => $this->getCurrency($submittedValues),
        'skipCleanMoney' => TRUE,
        'id' => $this->_id,
      ];

      //format soft-credit/pcp param first
      CRM_Contribute_BAO_ContributionSoft::formatSoftCreditParams($submittedValues, $this);
      $params = array_merge($params, $submittedValues);

      $fields = [
        'financial_type_id',
        'contribution_status_id',
        'payment_instrument_id',
        'cancel_reason',
        'source',
        'check_number',
        'card_type_id',
        'pan_truncation',
      ];
      foreach ($fields as $f) {
        $params[$f] = $formValues[$f] ?? NULL;
      }

      $params['revenue_recognition_date'] = NULL;
      if (!empty($formValues['revenue_recognition_date'])) {
        $params['revenue_recognition_date'] = $formValues['revenue_recognition_date'];
      }

      if (!empty($formValues['is_email_receipt'])) {
        $params['receipt_date'] = date("Y-m-d");
      }

      if (CRM_Contribute_BAO_Contribution::isContributionStatusNegative($params['contribution_status_id'])
      ) {
        if (CRM_Utils_System::isNull(CRM_Utils_Array::value('cancel_date', $params))) {
          $params['cancel_date'] = date('YmdHis');
        }
      }
      else {
        $params['cancel_date'] = $params['cancel_reason'] = 'null';
      }

      // Set is_pay_later flag for back-office offline Pending status contributions CRM-8996
      // else if contribution_status is changed to Completed is_pay_later flag is changed to 0, CRM-15041
      if ($params['contribution_status_id'] == CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending')) {
        $params['is_pay_later'] = 1;
      }
      elseif ($params['contribution_status_id'] == CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed')) {
        // @todo - if the contribution is new then it should be Pending status & then we use
        // Payment.create to update to Completed.
        $params['is_pay_later'] = 0;
      }

      // Add Additional common information to formatted params.
      CRM_Contribute_Form_AdditionalInfo::postProcessCommon($formValues, $params, $this);
      if ($pId) {
        $params['contribution_mode'] = 'participant';
        $params['participant_id'] = $pId;
        $params['skipLineItem'] = 1;
      }
      $params['line_item'] = $lineItem;
      $params['payment_processor_id'] = $params['payment_processor'] = $this->_paymentProcessor['id'] ?? NULL;
      $params['tax_amount'] = CRM_Utils_Array::value('tax_amount', $submittedValues, CRM_Utils_Array::value('tax_amount', $this->_values));
      //create contribution.
      if ($isQuickConfig) {
        $params['is_quick_config'] = 1;
      }
      $params['non_deductible_amount'] = $this->calculateNonDeductibleAmount($params, $formValues);

      // we are already handling note below, so to avoid duplicate notes against $contribution
      if (!empty($params['note']) && !empty($submittedValues['note'])) {
        unset($params['note']);
      }
      $contribution = CRM_Contribute_BAO_Contribution::create($params);

      // process associated membership / participant, CRM-4395
      if ($contribution->id && $action & CRM_Core_Action::UPDATE) {
        // @todo use Payment.create to do this, remove transitioncomponents function
        // if contribution is being created with a completed status it should be
        // created pending & then Payment.create adds the payment
        CRM_Contribute_BAO_Contribution::transitionComponents([
          'contribution_id' => $contribution->id,
          'contribution_status_id' => $contribution->contribution_status_id,
          'previous_contribution_status_id' => $this->_values['contribution_status_id'] ?? NULL,
          'receive_date' => $contribution->receive_date,
        ]);
      }

      array_unshift($this->statusMessage, ts('The contribution record has been saved.'));

      $this->invoicingPostProcessHook($submittedValues, $action, $lineItem);

      //send receipt mail.
      if ($contribution->id && !empty($formValues['is_email_receipt'])) {
        $formValues['contact_id'] = $this->_contactID;
        $formValues['contribution_id'] = $contribution->id;

        $formValues += CRM_Contribute_BAO_ContributionSoft::getSoftContribution($contribution->id);

        // to get 'from email id' for send receipt
        $this->fromEmailId = $formValues['from_email_address'] ?? NULL;
        if (CRM_Contribute_Form_AdditionalInfo::emailReceipt($this, $formValues)) {
          $this->statusMessage[] = ts('A receipt has been emailed to the contributor.');
        }
      }

      $this->statusMessageTitle = ts('Saved');

    }

    if ($contribution->id && isset($formValues['product_name'][0])) {
      CRM_Contribute_Form_AdditionalInfo::processPremium($submittedValues, $contribution->id,
        $this->_premiumID, $this->_options
      );
    }

    if ($contribution->id && array_key_exists('note', $submittedValues)) {
      CRM_Contribute_Form_AdditionalInfo::processNote($submittedValues, $this->_contactID, $contribution->id, $this->_noteID);
    }

    CRM_Core_Session::setStatus(implode(' ', $this->statusMessage), $this->statusMessageTitle, 'success');

    CRM_Contribute_BAO_Contribution::updateRelatedPledge(
      $action,
      $pledgePaymentID,
      $contribution->id,
      ($formValues['option_type'] ?? 0) == 2,
      $formValues['total_amount'],
      CRM_Utils_Array::value('total_amount', $this->_defaults),
      $formValues['contribution_status_id'],
      CRM_Utils_Array::value('contribution_status_id', $this->_defaults)
    );
    return $contribution;
  }

  /**
   * Assign tax calculations to contribution receipts.
   *
   * @param array $submittedValues
   * @param int $action
   * @param array $lineItem
   */
  protected function invoicingPostProcessHook($submittedValues, $action, $lineItem) {
    if (!Civi::settings()->get('invoicing')) {
      return;
    }
    $taxRate = [];
    $getTaxDetails = FALSE;

    foreach ($lineItem as $key => $value) {
      foreach ($value as $v) {
        if (isset($taxRate[(string) CRM_Utils_Array::value('tax_rate', $v)])) {
          $taxRate[(string) $v['tax_rate']] = $taxRate[(string) $v['tax_rate']] + CRM_Utils_Array::value('tax_amount', $v);
        }
        else {
          if (isset($v['tax_rate'])) {
            $taxRate[(string) $v['tax_rate']] = $v['tax_amount'] ?? NULL;
            $getTaxDetails = TRUE;
          }
        }
      }
    }

    if ($action & CRM_Core_Action::UPDATE) {
      if (isset($submittedValues['tax_amount'])) {
        $totalTaxAmount = $submittedValues['tax_amount'];
      }
      else {
        $totalTaxAmount = $this->_values['tax_amount'];
      }
      $this->assign('totalTaxAmount', $totalTaxAmount);
      $this->assign('dataArray', $taxRate);
    }
    else {
      if (!empty($submittedValues['price_set_id'])) {
        $this->assign('totalTaxAmount', $submittedValues['tax_amount']);
        $this->assign('getTaxDetails', $getTaxDetails);
        $this->assign('dataArray', $taxRate);
        $this->assign('taxTerm', Civi::settings()->get('tax_term'));
      }
      else {
        $this->assign('totalTaxAmount', CRM_Utils_Array::value('tax_amount', $submittedValues));
      }
    }
  }

  /**
   * Calculate non deductible amount.
   *
   * @see https://issues.civicrm.org/jira/browse/CRM-11956
   * if non_deductible_amount exists i.e. Additional Details field set was opened [and staff typed something] -
   * if non_deductible_amount does NOT exist - then calculate it depending on:
   * $financialType->is_deductible and whether there is a product (premium).
   *
   * @param $params
   * @param $formValues
   *
   * @return array
   */
  protected function calculateNonDeductibleAmount($params, $formValues) {
    if (!empty($params['non_deductible_amount'])) {
      return $params['non_deductible_amount'];
    }

    $priceSetId = $params['price_set_id'] ?? NULL;
    // return non-deductible amount if it is set at the price field option level
    if ($priceSetId && !empty($params['line_item'])) {
      $nonDeductibleAmount = CRM_Price_BAO_PriceSet::getNonDeductibleAmountFromPriceSet($priceSetId, $params['line_item']);
      if (!empty($nonDeductibleAmount)) {
        return $nonDeductibleAmount;
      }
    }

    $financialType = new CRM_Financial_DAO_FinancialType();
    $financialType->id = $params['financial_type_id'];
    $financialType->find(TRUE);

    if ($financialType->is_deductible) {

      if (isset($formValues['product_name'][0])) {
        $selectProduct = $formValues['product_name'][0];
      }
      // if there is a product - compare the value to the contribution amount
      if (isset($selectProduct)) {
        $productDAO = new CRM_Contribute_DAO_Product();
        $productDAO->id = $selectProduct;
        $productDAO->find(TRUE);
        // product value exceeds contribution amount
        if ($params['total_amount'] < $productDAO->price) {
          return $params['total_amount'];
        }
        // product value does NOT exceed contribution amount
        else {
          return $productDAO->price;
        }
      }
      // contribution is deductible - but there is no product
      else {
        return '0.00';
      }
    }
    // contribution is NOT deductible
    else {
      return $params['total_amount'];
    }

    return 0;
  }

  /**
   * Get the financial Type ID for the contribution either from the submitted values or from the contribution values if possible.
   *
   * This is important for dev/core#1728 - ie ensure that if we are returned to the form for a form
   * error that any custom fields based on the selected financial type are loaded.
   *
   * @return int
   */
  protected function getFinancialTypeID() {
    if (!empty($this->_submitValues['financial_type_id'])) {
      return $this->_submitValues['financial_type_id'];
    }
    if (!empty($this->_values['financial_type_id'])) {
      return $this->_values['financial_type_id'];
    }
  }

  /**
   * Set context in session
   */
  public function setUserContext(): void {
    $session = CRM_Core_Session::singleton();
    $buttonName = $this->controller->getButtonName();
    if ($this->_context == 'standalone') {
      if ($buttonName == $this->getButtonName('upload', 'new')) {
        $session->replaceUserContext(CRM_Utils_System::url('civicrm/contribute/add',
          'reset=1&action=add&context=standalone'
        ));
      }
      else {
        $session->replaceUserContext(CRM_Utils_System::url('civicrm/contact/view',
          "reset=1&cid={$this->_contactID}&selectedChild=contribute"
        ));
      }
    }
    elseif ($this->_context == 'contribution' && $this->_mode && $buttonName == $this->getButtonName('upload', 'new')) {
      $session->replaceUserContext(CRM_Utils_System::url('civicrm/contact/view/contribution',
        "reset=1&action=add&context={$this->_context}&cid={$this->_contactID}&mode={$this->_mode}"
      ));
    }
    elseif ($buttonName == $this->getButtonName('upload', 'new')) {
      $session->replaceUserContext(CRM_Utils_System::url('civicrm/contact/view/contribution',
        "reset=1&action=add&context={$this->_context}&cid={$this->_contactID}"
      ));
    }
  }

}
