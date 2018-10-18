<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Sepa_Form_Import_New extends CRM_Core_Form {
  
  private $fields = array();

  private $delimiter = ',';
  private $enclosure = '"';

  // todo print values below the form
  private $readonlyFields = array(
    'interval' => array(),
    'mandate_type' => array(),
  );

  private $defaultsMap = array(
    'creditor_id' => 'batching_default_creditor',
    'financial_type_id' => 'import_financial_type_id',
    'campaign_id' => 'import_campaign_id',
    'collection_day' => 'import_collection_day',
  );

  private $creditors = array();
  private $financialTypes = array();
  private $campaigns = array();
  private $settings = array();

  function __construct($state, $action, $method, $name) {
    $this->fields = array(
      'creditor_id' => array(
        'type' => 'Select',
        'label' => ts("Creditor", array('domain' => 'org.project60.sepa')),
        'options' => array(),
        'required' => true,
      ),
      'financial_type_id' => array(
        'type' => 'Select',
        'label' => ts("Financial Type", array('domain' => 'org.project60.sepa')),
        'options' => array(),
        'required' => true,
      ),
      'campaign_id' => array(
        'type' => 'Select',
        'label' => ts("Campaign", array('domain' => 'org.project60.sepa')),
        'options' => array(),
        'required' => true, // fixme seems not to be required when CiviCampaign is not installed
      ),
      'collection_day' => array(
        'type' => 'text',
        'label' => ts("Collection day", array('domain' => 'org.project60.sepa')),
        'options' => array(),
        'required' => true,
      ),
      'start_date' => array(
        'type' => 'text',
        'label' => ts("Start date", array('domain' => 'org.project60.sepa')),
        'options' => array(),
        'required' => true,
      ),
      'importFile' => array(
        'type' => 'File',
        'label' => ts("Import file", array('domain' => 'org.project60.sepa')),
        'options' => array(),
        'required' => true,
      ),
    );
    parent::__construct($state, $action, $method, $name);
  }

  function preProcess() {
    $this->creditors = $this->getCreditors();
    $this->fields['creditor_id']['options'] = $this->creditors;
    $this->financialTypes = $this->getFinancialTypes();
    $this->fields['financial_type_id']['options'] = $this->financialTypes;
    $this->campaigns = $this->getCampaigns();
    $this->fields['campaign_id']['options'] = $this->campaigns;
    $this->settings = CRM_Sepa_Logic_Import::getSettings();
    parent::preProcess();
  }

  function buildQuickForm() {
    foreach ($this->fields as $key => $field) {
      if ($key == 'importFile') {
        $this->addElement('file', $key, $field['label'], 'size=30 maxlength=60');
        $this->addUploadElement($key);
      } else {
        $this->add($field['type'], $key, $field['label'], array('' => '- select -') + $field['options'], $field['required']);
      }
    }

    $config = CRM_Core_Config::singleton();
    if (!empty($config->maxImportFileSize)) {
      $uploadFileSize = $config->maxImportFileSize;
    }
    else {
      $uploadFileSize = CRM_Utils_Number::formatUnitSize($config->maxFileSize . 'm', TRUE);
    }
    if ($uploadFileSize >= 8388608) {
      $uploadFileSize = 8388608;
    }
    $uploadSize = round(($uploadFileSize / (1024 * 1024)), 2);
    $this->addRule('importFile', ts('A valid file must be uploaded.'), 'uploadedfile');
    $this->addRule('importFile', ts('File size should be less than %1 MBytes (%2 bytes)', array(1 => $uploadSize, 2 => $uploadFileSize)), 'maxfilesize', $uploadFileSize);
    $this->setMaxFileSize($uploadFileSize);
    $this->addRule('importFile', ts('Input file must be in CSV format'), 'utf8File');

    $this->addButtons(array(array('type' => 'upload', 'name' => ts('Submit'), 'isDefault' => TRUE)));
    $this->assign('elementNames', $this->getRenderableElementNames());
  }

  function postProcess() {
    $importFile = $this->controller->exportValue($this->_name, 'importFile');
    $params = array(
      'creditor_id' => $this->controller->exportValue($this->_name, 'creditor_id'),
      'financial_type_id' => $this->controller->exportValue($this->_name, 'financial_type_id'),
      'campaign_id' => $this->controller->exportValue($this->_name, 'campaign_id'),
      'campaign_title' => $this->campaigns[$this->controller->exportValue($this->_name, 'campaign_id')],
      'collection_day' => $this->controller->exportValue($this->_name, 'collection_day'),
      'start_date' => $this->controller->exportValue($this->_name, 'start_date'),
    );

    $data = array();
    $content = file($importFile['name']);
    foreach ($content as $line) {
      $data[] = array_map('trim', str_getcsv($line, $this->delimiter, $this->enclosure));
    }
    // remove first row which contains header
    unset($data[0]);

    $session = new CRM_Core_Session();
    if (CRM_Sepa_Logic_Import::validateImportFile($data, $this->settings)) {
      $session->set('data', $data, 'sepa-import');
      $session->set('params', $params, 'sepa-import');
      CRM_Core_Session::singleton()->pushUserContext(CRM_Utils_System::url('civicrm/sepa/import-ready'));
    } else {
      $session->set('errors', CRM_Sepa_Logic_Import::$errors, 'sepa-import');
      CRM_Core_Session::singleton()->pushUserContext(CRM_Utils_System::url('civicrm/sepa/import-notvalid'));
    }
  }


  function setDefaultValues() {
    $defaults = array();
    foreach ($this->defaultsMap as $key => $setting) {
      $defaults[$key] = $this->settings[$setting];
    }
    $defaults['start_date'] = date('Y-m-d');
    return $defaults;
  }


  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  function getRenderableElementNames() {
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }


  private function getCreditors() {
    $result = civicrm_api3('SepaCreditor', 'get');
    $arr = array();
    if (array_key_exists('values', $result) && count($result['values']) > 0) {
      foreach ($result['values'] as $item) {
        $arr[$item['id']] = $item['name'];
      }
    }
    return $arr;
  }


  private function getFinancialTypes() {
    $result = civicrm_api3('FinancialType', 'get', array('is_active' => 1, 'options' => array('limit' => 0)));
    $arr = array();
    if (array_key_exists('values', $result) && count($result['values']) > 0) {
      foreach ($result['values'] as $item) {
        $arr[$item['id']] = $item['name'];
      }
    }
    return $arr;
  }


  private function getCampaigns() {
    $result = civicrm_api3('Campaign', 'get', array('is_active' => 1, 'options' => array('limit' => 0)));
    $arr = array();
    if (array_key_exists('values', $result) && count($result['values']) > 0) {
      foreach ($result['values'] as $item) {
        $arr[$item['id']] = $item['title'];
      }
    }
    return $arr;
  }
}
