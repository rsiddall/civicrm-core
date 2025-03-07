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

namespace Civi\WorkflowMessage;

use Civi\Schema\Traits\MagicGetterSetterTrait;
use Civi\WorkflowMessage\Traits\AddressingTrait;
use Civi\WorkflowMessage\Traits\FinalHelperTrait;
use Civi\WorkflowMessage\Traits\ReflectiveWorkflowTrait;

/**
 * Generic base-class for describing the inputs for a workflow email template.
 *
 * @method $this setContactId(int|null $contactId)
 * @method int|null getContactId()
 * @method $this setContact(array|null $contact)
 * @method array|null getContact()
 */
class GenericWorkflowMessage implements WorkflowMessageInterface {

  // Implement getFields(), import(), export(), validate() - All methods based on inspecting class properties (`ReflectionClass`).
  // Define stub methods exportExtraTokenContext(), exportExtraTplParams(), etc.
  use ReflectiveWorkflowTrait;

  // Implement __call() - Public and protected properties are automatically given a default getter/setter. These may be overridden/customized.
  use MagicGetterSetterTrait;

  // Implement assertValid(), renderTemplate(), sendTemplate() - Sugary stub methods that delegate to real APIs.
  use FinalHelperTrait;

  // Implement setTo(), setReplyTo(), etc
  use AddressingTrait;

  /**
   * WorkflowMessage constructor.
   *
   * @param array $imports
   *   List of values to import.
   *   Ex: ['tplParams' => [...tplValues...], 'tokenContext' => [...tokenData...]]
   *   Ex: ['modelProps' => [...classProperties...]]
   */
  public function __construct(array $imports = []) {
    WorkflowMessage::importAll($this, $imports);
  }

  /**
   * The contact receiving this message.
   *
   * @var int|null
   * @scope tokenContext
   * @fkEntity Contact
   */
  protected $contactId;

  /**
   * @var array|null
   * @scope tokenContext
   */
  protected $contact;

  /**
   * Must provide either 'int $contactId' or 'array $contact'
   *
   * @param array $errors
   * @see ReflectiveWorkflowTrait::validate()
   */
  protected function validateExtra_contact(array &$errors) {
    if (empty($this->contactId) && empty($this->contact['id'])) {
      $errors[] = [
        'severity' => 'error',
        'fields' => ['contactId', 'contact'],
        'name' => 'missingContact',
        'message' => ts('Message template requires one of these fields (%1)', ['contactId, contact']),
      ];
    }
    if (!empty($this->contactId) && !empty($this->contact)) {
      $errors[] = [
        'severity' => 'warning',
        'fields' => ['contactId', 'contact'],
        'name' => 'missingContact',
        'message' => ts('Passing both (%1) may lead to ambiguous behavior.', ['contactId, contact']),
      ];
    }
  }

}
