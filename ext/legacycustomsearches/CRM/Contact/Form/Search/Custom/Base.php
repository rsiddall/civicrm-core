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
class CRM_Contact_Form_Search_Custom_Base {

  protected $_formValues;

  protected $_columns;

  protected $_stateID;

  /**
   * Class constructor.
   *
   * @param array $formValues
   */
  public function __construct(&$formValues) {
    $this->_formValues = &$formValues;
  }

  /**
   * Builds the list of tasks or actions that a searcher can perform on a result set.
   *
   * The returned array completely replaces the task list, so a child class that
   * wants to modify the existing list should manipulate the result of this method.
   *
   * @param CRM_Core_Form_Search $form
   * @return array
   */
  public function buildTaskList(CRM_Core_Form_Search $form) {
    return $form->getVar('_taskList');
  }

  /**
   * @return null|string
   */
  public function count() {
    return CRM_Core_DAO::singleValueQuery($this->sql('count(distinct contact_a.id) as total'));
  }

  /**
   * @return null
   */
  public function summary() {
    return NULL;
  }

  /**
   * @param int $offset
   * @param int $rowcount
   * @param null $sort
   * @param bool $returnSQL
   *
   * @return string
   */
  public function contactIDs($offset = 0, $rowcount = 0, $sort = NULL, $returnSQL = FALSE) {
    $sql = $this->sql(
      'contact_a.id as contact_id',
      $offset,
      $rowcount,
      $sort
    );
    $this->validateUserSQL($sql);

    if ($returnSQL) {
      return $sql;
    }

    return CRM_Core_DAO::composeQuery($sql);
  }

  /**
   * @param $selectClause
   * @param int $offset
   * @param int $rowcount
   * @param null $sort
   * @param bool $includeContactIDs
   * @param null $groupBy
   *
   * @return string
   */
  public function sql(
    $selectClause,
    $offset = 0,
    $rowcount = 0,
    $sort = NULL,
    $includeContactIDs = FALSE,
    $groupBy = NULL
  ) {

    $sql = "SELECT $selectClause " . $this->from();
    $where = $this->where();
    if (!empty($where)) {
      $sql .= ' WHERE ' . $where;
    }

    if ($includeContactIDs) {
      $this->includeContactIDs($sql,
        $this->_formValues
      );
    }

    if ($groupBy) {
      $sql .= " $groupBy ";
    }

    $this->addSortOffset($sql, $offset, $rowcount, $sort);
    return $sql;
  }

  /**
   * @return null
   */
  public function templateFile() {
    return NULL;
  }

  public function &columns() {
    return $this->_columns;
  }

  /**
   * @param $sql
   * @param $formValues
   */
  public static function includeContactIDs(&$sql, &$formValues) {
    $contactIDs = [];
    foreach ($formValues as $id => $value) {
      if ($value &&
        substr($id, 0, CRM_Core_Form::CB_PREFIX_LEN) == CRM_Core_Form::CB_PREFIX
      ) {
        $contactIDs[] = substr($id, CRM_Core_Form::CB_PREFIX_LEN);
      }
    }

    if (!empty($contactIDs)) {
      $contactIDs = implode(', ', $contactIDs);
      $sql .= " AND contact_a.id IN ( $contactIDs )";
    }
  }

  /**
   * @param $sql
   * @param $offset
   * @param $rowcount
   * @param $sort
   */
  public function addSortOffset(&$sql, $offset, $rowcount, $sort) {
    if (!empty($sort)) {
      if (is_string($sort)) {
        $sort = CRM_Utils_Type::escape($sort, 'String');
        $sql .= " ORDER BY $sort ";
      }
      else {
        $sql .= " ORDER BY " . trim($sort->orderBy());
      }
    }

    if ($rowcount > 0 && $offset >= 0) {
      $offset = CRM_Utils_Type::escape($offset, 'Int');
      $rowcount = CRM_Utils_Type::escape($rowcount, 'Int');

      $sql .= " LIMIT $offset, $rowcount ";
    }
  }

  /**
   * @param $sql
   * @param bool $onlyWhere
   *
   * @throws Exception
   */
  public function validateUserSQL(&$sql, $onlyWhere = FALSE) {
    $includeStrings = ['contact_a'];
    $excludeStrings = ['insert', 'delete', 'update'];

    if (!$onlyWhere) {
      $includeStrings += ['select', 'from', 'where', 'civicrm_contact'];
    }

    foreach ($includeStrings as $string) {
      if (stripos($sql, $string) === FALSE) {
        CRM_Core_Error::statusBounce(ts('Could not find \'%1\' string in SQL clause.',
          [1 => $string]
        ));
      }
    }

    foreach ($excludeStrings as $string) {
      if (preg_match('/(\s' . $string . ')|(' . $string . '\s)/i', $sql)) {
        CRM_Core_Error::statusBounce(ts('Found illegal \'%1\' string in SQL clause.',
          [1 => $string]
        ));
      }
    }
  }

  /**
   * @param $where
   * @param array $params
   *
   * @return string
   */
  public function whereClause(&$where, &$params) {
    return CRM_Core_DAO::composeQuery($where, $params, TRUE);
  }

  /**
   * override this method to define the contact query object
   * used for creating $sql
   * @return null
   */
  public function getQueryObj() {
    return NULL;
  }

  /**
   * Set the title.
   *
   * @param string $title
   */
  public function setTitle($title) {
    if ($title) {
      CRM_Utils_System::setTitle($title);
    }
    else {
      CRM_Utils_System::setTitle(ts('Search'));
    }
  }

  /**
   * Validate form input.
   *
   * @param array $fields
   * @param array $files
   * @param CRM_Core_Form $self
   *
   * @return array
   *   Input errors from the form.
   */
  public function formRule($fields, $files, $self) {
    return [];
  }

}
