<?php

/**
 * RegistrationForm.inc.php
 *
 * Copyright (c) 2003-2004 The Public Knowledge Project
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @package user.form
 *
 * Form for user registration.
 *
 * $Id$
 */

class RegistrationForm extends Form {

	/** @var boolean user is already registered with another journal */
	var $existingUser;
	
	/**
	 * Constructor.
	 */
	function RegistrationForm() {
		parent::Form('user/register.tpl');
		
		$this->existingUser = Request::getUserVar('existingUser') ? 1 : 0;
		
		// Validation checks for this form
		$this->addCheck(new FormValidator(&$this, 'username', 'required', 'user.profile.form.usernameRequired'));
		$this->addCheck(new FormValidator(&$this, 'password', 'required', 'user.profile.form.passwordRequired'));

		if ($this->existingUser) {
			// Existing user -- check login
			$this->addCheck(new FormValidatorCustom(&$this, 'username', 'required', 'user.login.loginError', create_function('$username,$form', '$userDao = &DAORegistry::getDao(\'UserDAO\'); $user = &$userDao->getUserByCredentials($username, Validation::encryptPassword($form->getData(\'password\'))); return isset($user);'), array(&$this)));

		} else {
			// New user -- check required profile fields
			$this->addCheck(new FormValidatorCustom(&$this, 'username', 'required', 'user.register.form.usernameExists', array(DAORegistry::getDAO('UserDAO'), 'userExistsByUsername'), array(), true));
			$this->addCheck(new FormValidatorCustom(&$this, 'password', 'required', 'user.register.form.passwordsDoNotMatch', create_function('$password,$form', 'return $password == $form->getData(\'password2\');'), array(&$this)));
			$this->addCheck(new FormValidator(&$this, 'firstName', 'required', 'user.profile.form.firstNameRequired'));
			$this->addCheck(new FormValidator(&$this, 'lastName', 'required', 'user.profile.form.lastNameRequired'));
	$this->addCheck(new FormValidatorEmail(&$this, 'email', 'required', 'user.profile.form.emailRequired'));
			$this->addCheck(new FormValidatorCustom(&$this, 'email', 'required', 'user.register.form.emailExists', array(DAORegistry::getDAO('UserDAO'), 'userExistsByEmail'), array(), true));
		}
	}
	
	/**
	 * Display the form.
	 */
	function display() {
		$journal = &Request::getJournal();
		$journalSettingsDao = &DAORegistry::getDAO('JournalSettingsDAO');
		$templateMgr = &TemplateManager::getManager();
		$templateMgr->assign('privacyStatement', $journalSettingsDao->getSetting($journal->getJournalId(), 'privacyStatement'));
		$templateMgr->assign('allowRegReader', $journalSettingsDao->getSetting($journal->getJournalId(), 'allowRegReader'));
		$templateMgr->assign('allowRegAuthor', $journalSettingsDao->getSetting($journal->getJournalId(), 'allowRegAuthor'));
		$templateMgr->assign('allowRegReviewer', $journalSettingsDao->getSetting($journal->getJournalId(), 'allowRegReviewer'));
		
		parent::display();
	}
	
	/**
	 * Initialize default data.
	 */
	function initData() {
		$this->setData('registerAsReader', 1);
		$this->setData('existingUser', $this->existingUser);
	}
	
	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(
			array(
				'username', 'password', 'password2',
				'firstName', 'middleName', 'lastName',
				'affiliation', 'email', 'phone', 'fax',
				'mailingAddress', 'biography',
				'registerAsReader', 'registerAsAuthor', 'registerAsReviewer',
				'existingUser'
			)
		);
	}
	
	/**
	 * Register a new user.
	 */
	function execute() {
		if ($this->existingUser) {
			// Existing user in the system
			$userDao = &DAORegistry::getDAO('UserDAO');
			$user = &$userDao->getUserByCredentials($this->getData('username'), Validation::encryptPassword($this->getData('password')));
			if ($user == null) {
				return false;
			}
			
			$userId = $user->getUserId();
			
		} else {
			// New user
			$user = &new User();
			
			$user->setUsername($this->_data['username']);
			$user->setPassword(Validation::encryptPassword($this->_data['password']));
			$user->setFirstName($this->_data['firstName']);
			$user->setMiddleName($this->_data['middleName']);
			$user->setLastName($this->_data['lastName']);
			$user->setAffiliation($this->_data['affiliation']);
			$user->setEmail($this->_data['email']);
			$user->setPhone($this->_data['phone']);
			$user->setFax($this->_data['fax']);
			$user->setMailingAddress($this->_data['mailingAddress']);
			$user->setBiography($this->_data['biography']);
			$user->setDateRegistered(Core::getCurrentDate());
			
			$userDao = &DAORegistry::getDAO('UserDAO');
			$userDao->insertUser($user);
			$userId = $userDao->getInsertUserId();
			if (!$userId) {
				return false;
			}
			
			$sessionManager = &SessionManager::getManager();
			$session = &$sessionManager->getUserSession();
			$session->setSessionVar('username', $user->getUsername());
		}

		$journal = &Request::getJournal();
		$roleDao = &DAORegistry::getDAO('RoleDAO');
		
		// Roles users are allowed to register themselves in
		$allowedRoles = array('reader' => 'registerAsReader', 'author' => 'registerAsAuthor', 'reviewer' => 'registerAsReviewer');

		$journalSettingsDao = &DAORegistry::getDAO('JournalSettingsDAO');
		if (!$journalSettingsDao->getSetting($journal->getJournalId(), 'allowRegReader')) {
			unset($allowedRoles['reader']);
		}
		if (!$journalSettingsDao->getSetting($journal->getJournalId(), 'allowRegAuthor')) {
			unset($allowedRoles['author']);
		}
		if (!$journalSettingsDao->getSetting($journal->getJournalId(), 'allowRegReviewer')) {
			unset($allowedRoles['reader']);
		}
		
		foreach ($allowedRoles as $k => $v) {
			$roleId = $roleDao->getRoleIdFromPath($k);
			if ($this->getData($v) && !$roleDao->roleExists($journal->getJournalId(), $userId, $roleId)) {
				$role = new Role();
				$role->setJournalId($journal->getJournalId());
				$role->setUserId($userId);
				$role->setRoleId($roleId);
				$roleDao->insertRole($role);
			}
		}
		
	}
	
}

?>
