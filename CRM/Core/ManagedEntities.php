<?php

/**
 * The ManagedEntities system allows modules to add records to the database
 * declaratively.  Those records will be automatically inserted, updated,
 * deactivated, and deleted in tandem with their modules.
 */
class CRM_Core_ManagedEntities {

  /**
   * Get clean up options.
   *
   * @return array
   */
  public static function getCleanupOptions() {
    return [
      'always' => ts('Always'),
      'never' => ts('Never'),
      'unused' => ts('If Unused'),
    ];
  }

  /**
   * @var array
   *   Array($status => array($name => CRM_Core_Module)).
   */
  protected $moduleIndex;

  /**
   * @var array
   *   List of all entity declarations.
   * @see CRM_Utils_Hook::managed()
   */
  protected $declarations;

  /**
   * Get an instance.
   * @param bool $fresh
   * @return \CRM_Core_ManagedEntities
   */
  public static function singleton($fresh = FALSE) {
    static $singleton;
    if ($fresh || !$singleton) {
      $singleton = new CRM_Core_ManagedEntities(CRM_Core_Module::getAll(), NULL);
    }
    return $singleton;
  }

  /**
   * Perform an asynchronous reconciliation when the transaction ends.
   */
  public static function scheduleReconciliation() {
    CRM_Core_Transaction::addCallback(
      CRM_Core_Transaction::PHASE_POST_COMMIT,
      function () {
        CRM_Core_ManagedEntities::singleton(TRUE)->reconcile();
      },
      [],
      'ManagedEntities::reconcile'
    );
  }

  /**
   * @param array $modules
   *   CRM_Core_Module.
   * @param array $declarations
   *   Per hook_civicrm_managed.
   */
  public function __construct($modules, $declarations) {
    $this->moduleIndex = self::createModuleIndex($modules);

    if ($declarations !== NULL) {
      $this->declarations = self::cleanDeclarations($declarations);
    }
    else {
      $this->declarations = NULL;
    }
  }

  /**
   * Read a managed entity using APIv3.
   *
   * @param string $moduleName
   *   The name of the module which declared entity.
   * @param string $name
   *   The symbolic name of the entity.
   * @return array|NULL
   *   API representation, or NULL if the entity does not exist
   */
  public function get($moduleName, $name) {
    $dao = new CRM_Core_DAO_Managed();
    $dao->module = $moduleName;
    $dao->name = $name;
    if ($dao->find(TRUE)) {
      $params = [
        'id' => $dao->entity_id,
      ];
      $result = NULL;
      try {
        $result = civicrm_api3($dao->entity_type, 'getsingle', $params);
      }
      catch (Exception $e) {
        $this->onApiError($dao->entity_type, 'getsingle', $params, $result);
      }
      return $result;
    }
    else {
      return NULL;
    }
  }

  /**
   * Identify any enabled/disabled modules. Add new entities, update
   * existing entities, and remove orphaned (stale) entities.
   * @param bool $ignoreUpgradeMode
   *
   * @throws Exception
   */
  public function reconcile($ignoreUpgradeMode = FALSE) {
    // Do not reconcile whilst we are in upgrade mode
    if (CRM_Core_Config::singleton()->isUpgradeMode() && !$ignoreUpgradeMode) {
      return;
    }
    if ($error = $this->validate($this->getDeclarations())) {
      throw new Exception($error);
    }
    $this->reconcileEnabledModules();
    $this->reconcileDisabledModules();
    $this->reconcileUnknownModules();
  }

  /**
   * For all enabled modules, add new entities, update
   * existing entities, and remove orphaned (stale) entities.
   *
   * @throws Exception
   */
  public function reconcileEnabledModules() {
    // Note: any thing currently declared is necessarily from
    // an active module -- because we got it from a hook!

    // index by moduleName,name
    $decls = self::createDeclarationIndex($this->moduleIndex, $this->getDeclarations());
    foreach ($decls as $moduleName => $todos) {
      if (isset($this->moduleIndex[TRUE][$moduleName])) {
        $this->reconcileEnabledModule($this->moduleIndex[TRUE][$moduleName], $todos);
      }
      elseif (isset($this->moduleIndex[FALSE][$moduleName])) {
        // do nothing -- module should get swept up later
      }
      else {
        throw new Exception("Entity declaration references invalid or inactive module name [$moduleName]");
      }
    }
  }

  /**
   * For one enabled module, add new entities, update existing entities,
   * and remove orphaned (stale) entities.
   *
   * @param \CRM_Core_Module $module
   * @param array $todos
   *   List of entities currently declared by this module.
   *   array(string $name => array $entityDef).
   */
  public function reconcileEnabledModule(CRM_Core_Module $module, $todos) {
    $dao = new CRM_Core_DAO_Managed();
    $dao->module = $module->name;
    $dao->find();
    while ($dao->fetch()) {
      if (isset($todos[$dao->name]) && $todos[$dao->name]) {
        // update existing entity; remove from $todos
        $this->updateExistingEntity($dao, $todos[$dao->name]);
        unset($todos[$dao->name]);
      }
      else {
        // remove stale entity; not in $todos
        $this->removeStaleEntity($dao);
      }
    }

    // create new entities from leftover $todos
    foreach ($todos as $name => $todo) {
      $this->insertNewEntity($todo);
    }
  }

  /**
   * For all disabled modules, disable any managed entities.
   */
  public function reconcileDisabledModules() {
    if (empty($this->moduleIndex[FALSE])) {
      return;
    }

    $in = CRM_Core_DAO::escapeStrings(array_keys($this->moduleIndex[FALSE]));
    $dao = new CRM_Core_DAO_Managed();
    $dao->whereAdd("module in ($in)");
    $dao->orderBy('id DESC');
    $dao->find();
    while ($dao->fetch()) {
      $this->disableEntity($dao);

    }
  }

  /**
   * Remove any orphaned (stale) entities that are linked to
   * unknown modules.
   */
  public function reconcileUnknownModules() {
    $knownModules = [];
    if (array_key_exists(0, $this->moduleIndex) && is_array($this->moduleIndex[0])) {
      $knownModules = array_merge($knownModules, array_keys($this->moduleIndex[0]));
    }
    if (array_key_exists(1, $this->moduleIndex) && is_array($this->moduleIndex[1])) {
      $knownModules = array_merge($knownModules, array_keys($this->moduleIndex[1]));
    }

    $dao = new CRM_Core_DAO_Managed();
    if (!empty($knownModules)) {
      $in = CRM_Core_DAO::escapeStrings($knownModules);
      $dao->whereAdd("module NOT IN ($in)");
      $dao->orderBy('id DESC');
    }
    $dao->find();
    while ($dao->fetch()) {
      $this->removeStaleEntity($dao);
    }
  }

  /**
   * Create a new entity.
   *
   * @param array $todo
   *   Entity specification (per hook_civicrm_managedEntities).
   */
  public function insertNewEntity($todo) {
    $result = civicrm_api($todo['entity'], 'create', $todo['params']);
    if (!empty($result['is_error'])) {
      $this->onApiError($todo['entity'], 'create', $todo['params'], $result);
    }

    $dao = new CRM_Core_DAO_Managed();
    $dao->module = $todo['module'];
    $dao->name = $todo['name'];
    $dao->entity_type = $todo['entity'];
    // A fatal error will result if there is no valid id but if
    // this is v4 api we might need to access it via ->first().
    $dao->entity_id = $result['id'] ?? $result->first()['id'];
    $dao->cleanup = $todo['cleanup'] ?? NULL;
    $dao->save();
  }

  /**
   * Update an entity which is believed to exist.
   *
   * @param CRM_Core_DAO_Managed $dao
   * @param array $todo
   *   Entity specification (per hook_civicrm_managedEntities).
   */
  public function updateExistingEntity($dao, $todo) {
    $policy = CRM_Utils_Array::value('update', $todo, 'always');
    $doUpdate = ($policy === 'always');

    if ($doUpdate) {
      $defaults = ['id' => $dao->entity_id];
      if ($this->isActivationSupported($dao->entity_type)) {
        $defaults['is_active'] = 1;
      }
      $params = array_merge($defaults, $todo['params']);

      $manager = CRM_Extension_System::singleton()->getManager();
      if ($dao->entity_type === 'Job' && !$manager->extensionIsBeingInstalledOrEnabled($dao->module)) {
        // Special treatment for scheduled jobs:
        //
        // If we're being called as part of enabling/installing a module then
        // we want the default behaviour of setting is_active = 1.
        //
        // However, if we're just being called by a normal cache flush then we
        // should not re-enable a job that an administrator has decided to disable.
        //
        // Without this logic there was a problem: site admin might disable
        // a job, but then when there was a flush op, the job was re-enabled
        // which can cause significant embarrassment, depending on the job
        // ("Don't worry, sending mailings is disabled right now...").
        unset($params['is_active']);
      }

      $result = civicrm_api($dao->entity_type, 'create', $params);
      if ($result['is_error']) {
        $this->onApiError($dao->entity_type, 'create', $params, $result);
      }
    }

    if (isset($todo['cleanup'])) {
      $dao->cleanup = $todo['cleanup'];
      $dao->update();
    }
  }

  /**
   * Update an entity which (a) is believed to exist and which (b) ought to be
   * inactive.
   *
   * @param CRM_Core_DAO_Managed $dao
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function disableEntity($dao): void {
    $entity_type = $dao->entity_type;
    if ($this->isActivationSupported($entity_type)) {
      // FIXME cascading for payproc types?
      $params = [
        'version' => 3,
        'id' => $dao->entity_id,
        'is_active' => 0,
      ];
      $result = civicrm_api($dao->entity_type, 'create', $params);
      if ($result['is_error']) {
        $this->onApiError($dao->entity_type, 'create', $params, $result);
      }
    }
  }

  /**
   * Remove a stale entity (if policy allows).
   *
   * @param CRM_Core_DAO_Managed $dao
   * @throws Exception
   */
  public function removeStaleEntity($dao) {
    $policy = empty($dao->cleanup) ? 'always' : $dao->cleanup;
    switch ($policy) {
      case 'always':
        $doDelete = TRUE;
        break;

      case 'never':
        $doDelete = FALSE;
        break;

      case 'unused':
        $getRefCount = civicrm_api3($dao->entity_type, 'getrefcount', [
          'debug' => 1,
          'id' => $dao->entity_id,
        ]);

        $total = 0;
        foreach ($getRefCount['values'] as $refCount) {
          $total += $refCount['count'];
        }

        $doDelete = ($total == 0);
        break;

      default:
        throw new \Exception('Unrecognized cleanup policy: ' . $policy);
    }

    if ($doDelete) {
      $params = [
        'version' => 3,
        'id' => $dao->entity_id,
      ];
      $check = civicrm_api3($dao->entity_type, 'get', $params);
      if ((bool) $check['count']) {
        $result = civicrm_api($dao->entity_type, 'delete', $params);
        if ($result['is_error']) {
          if (isset($dao->name)) {
            $params['name'] = $dao->name;
          }
          $this->onApiError($dao->entity_type, 'delete', $params, $result);
        }
      }
      CRM_Core_DAO::executeQuery('DELETE FROM civicrm_managed WHERE id = %1', [
        1 => [$dao->id, 'Integer'],
      ]);
    }
  }

  /**
   * Get declarations.
   *
   * @return array|null
   */
  public function getDeclarations() {
    if ($this->declarations === NULL) {
      $this->declarations = [];
      foreach (CRM_Core_Component::getEnabledComponents() as $component) {
        /** @var CRM_Core_Component_Info $component */
        $this->declarations = array_merge($this->declarations, $component->getManagedEntities());
      }
      CRM_Utils_Hook::managed($this->declarations);
      $this->declarations = self::cleanDeclarations($this->declarations);
    }
    return $this->declarations;
  }

  /**
   * @param array $modules
   *   Array<CRM_Core_Module>.
   *
   * @return array
   *   indexed by is_active,name
   */
  protected static function createModuleIndex($modules) {
    $result = [];
    foreach ($modules as $module) {
      $result[$module->is_active][$module->name] = $module;
    }
    return $result;
  }

  /**
   * @param array $moduleIndex
   * @param array $declarations
   *
   * @return array
   *   indexed by module,name
   */
  protected static function createDeclarationIndex($moduleIndex, $declarations) {
    $result = [];
    if (!isset($moduleIndex[TRUE])) {
      return $result;
    }
    foreach ($moduleIndex[TRUE] as $moduleName => $module) {
      if ($module->is_active) {
        // need an empty array() for all active modules, even if there are no current $declarations
        $result[$moduleName] = [];
      }
    }
    foreach ($declarations as $declaration) {
      $result[$declaration['module']][$declaration['name']] = $declaration;
    }
    return $result;
  }

  /**
   * @param $declarations
   *
   * @return string|bool
   *   string on error, or FALSE
   */
  protected static function validate($declarations) {
    foreach ($declarations as $declare) {
      foreach (['name', 'module', 'entity', 'params'] as $key) {
        if (empty($declare[$key])) {
          $str = print_r($declare, TRUE);
          return ("Managed Entity is missing field \"$key\": $str");
        }
      }
      // FIXME: validate that each 'module' is known
    }
    return FALSE;
  }

  /**
   * @param array $declarations
   *
   * @return array
   */
  protected static function cleanDeclarations($declarations) {
    foreach ($declarations as $name => &$declare) {
      if (!array_key_exists('name', $declare)) {
        $declare['name'] = $name;
      }
    }
    return $declarations;
  }

  /**
   * @param string $entity
   * @param string $action
   * @param array $params
   * @param array $result
   *
   * @throws Exception
   */
  protected function onApiError($entity, $action, $params, $result) {
    CRM_Core_Error::debug_var('ManagedEntities_failed', [
      'entity' => $entity,
      'action' => $action,
      'params' => $params,
      'result' => $result,
    ]);
    throw new Exception('API error: ' . $result['error_message'] . ' on ' . $entity . '.' . $action
      . (!empty($params['name']) ? '( entity name ' . $params['name'] . ')' : '')
    );
  }

  /**
   * Determine if an entity supports APIv3-based activation/de-activation.
   * @param string $entity_type
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  private function isActivationSupported(string $entity_type): bool {
    if (!isset(Civi::$statics[__CLASS__][__FUNCTION__][$entity_type])) {
      $actions = civicrm_api3($entity_type, 'getactions', [])['values'];
      Civi::$statics[__CLASS__][__FUNCTION__][$entity_type] = FALSE;
      if (in_array('create', $actions, TRUE) && in_array('getfields', $actions)) {
        $fields = civicrm_api3($entity_type, 'getfields', ['action' => 'create'])['values'];
        Civi::$statics[__CLASS__][__FUNCTION__][$entity_type] = array_key_exists('is_active', $fields);
      }
    }
    return Civi::$statics[__CLASS__][__FUNCTION__][$entity_type];
  }

}
