<?php
/*-------------------------------------------------------+
| SYSTOPIA Donation Receipts Extension                   |
| Copyright (C) 2013-2016 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| License: AGPLv3, see LICENSE file                      |
+--------------------------------------------------------*/

require_once 'CRM/Core/Form.php';

use CRM_Donrec_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Donrec_Form_Task_ContributeTask extends CRM_Contribute_Form_Task {

  private $availableCurrencies;

  function buildQuickForm() {
    CRM_Utils_System::setTitle(E::ts('Issue Donation Receipts'));

    $this->addElement('hidden', 'rsid');
    $options = array(
       'current_year'      => E::ts('This Year'),
       'last_year'         => E::ts('last year'),
       'customized_period' => E::ts('Choose Date Range')
    );
    $this->addElement('select', 'time_period', 'Time Period:', $options, array('class' => 'crm-select2'));
    $this->addDateRange('donrec_contribution_horizon', '_from', '_to', E::ts('From:'), 'searchDate', TRUE, FALSE);

    // add profile selector
    $this->addElement('select', 
                      'profile', 
                      E::ts('Profile'),
                      CRM_Donrec_Logic_Profile::getAllActiveNames('is_default', 'DESC'),
                      array('class' => 'crm-select2'));

    // add currency selector
    $this->availableCurrencies = array_keys(CRM_Core_OptionGroup::values('currencies_enabled'));
    $this->addElement('select', 'donrec_contribution_currency', E::ts('Currency'), $this->availableCurrencies);

    // call the (overwritten) Form's method, so the continue button is on the right...
    CRM_Core_Form::addDefaultButtons(E::ts('Continue'));
  }

  function setDefaultValues() {
    // do a cleanup here (ticket #1616)
    CRM_Donrec_Logic_Snapshot::cleanup();

    $uid = CRM_Donrec_Logic_Settings::getLoggedInContactID();
    $remaining_snapshots = CRM_Donrec_Logic_Snapshot::getUserSnapshots($uid);
    if (!empty($remaining_snapshots)) {
      $remaining_snapshot = array_pop($remaining_snapshots);
      $this->getElement('rsid')->setValue($remaining_snapshot);
      $this->assign('statistic', CRM_Donrec_Logic_Snapshot::getStatistic($remaining_snapshot));
      $this->assign('remaining_snapshot', TRUE);
    }
  }

  function postProcess() {
    // CAUTION: changes to this function should also be done in CRM_Donrec_Form_Task_Create:postProcess()

    // process remaining snapshots if exsisting
    $rsid = empty($_REQUEST['rsid']) ? NULL : $_REQUEST['rsid'];
    if (!empty($rsid)) {

      //work on with a remaining snapshot...
      $use_remaining_snapshot = CRM_Utils_Array::value('use_remaining_snapshot', $_REQUEST, NULL);
      if (!empty($use_remaining_snapshot)) {
        CRM_Core_Session::singleton()->pushUserContext(
          CRM_Utils_System::url('civicrm/donrec/task', 'sid=' . $rsid)
        );
        return;

      // or delete all remaining snapshots of this user
      } else {
        $uid = CRM_Donrec_Logic_Settings::getLoggedInContactID();
        CRM_Donrec_Logic_Snapshot::deleteUserSnapshots($uid);
      }
    }

    // process form values and try to build a snapshot with all contributions
    // that match the specified criteria (i.e. contributions which have been
    // created between two specific dates)
    $values = $this->exportValues();
    $values['contribution_ids'] = $this->_contributionIds;

    // get the currency ISO code
    $currencyId = $values['donrec_contribution_currency'];
    $values['donrec_contribution_currency'] = $this->availableCurrencies[ $currencyId ];

    //set url_back as session-variable
    $session = CRM_Core_Session::singleton();
    $session->set('url_back', CRM_Utils_System::url('civicrm/contact/search', "reset=1"));

    // generate the snapshot
    $result = CRM_Donrec_Logic_Selector::createSnapshot($values);
    $sid = empty($result['snapshot'])?NULL:$result['snapshot']->getId();

    if (!empty($result['intersection_error'])) {
      CRM_Core_Session::singleton()->pushUserContext(
        CRM_Utils_System::url('civicrm/donrec/task', 'conflict=1' . '&sid=' . $result['snapshot']->getId() . '&ccount=' . count($this->_contactIds)));
    }elseif (empty($result['snapshot'])) {
      CRM_Core_Session::setStatus(E::ts('There are no selectable contributions for these contacts in the selected time period.'), E::ts('Warning'), 'warning');
      $qfKey = $values['qfKey'];
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/contact/search', "_qf_DonrecTask_display=true&qfKey=$qfKey"));
    }else{
 if (is_countable($this->_contactIds))$count=count($this->_contactIds); else $count=0;
      CRM_Core_Session::singleton()->pushUserContext(
        CRM_Utils_System::url('civicrm/donrec/task', 'sid=' . $result['snapshot']->getId() . '&ccount=' . $count)
      );
    }
  }
}
