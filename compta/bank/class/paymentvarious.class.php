<?php
/* Copyright (C) 2017-2021  Alexandre Spangaro      <aspangaro@open-dsi.fr>
 * Copyright (C) 2018-2024  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2024		MDW						<mdeweerd@users.noreply.github.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file		htdocs/compta/bank/class/paymentvarious.class.php
 *  \ingroup	bank
 *  \brief		Class for various payment
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';


/**
 *  Class to manage various payments
 */
class PaymentVarious extends CommonObject
{
	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'payment_various';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'payment_various';

	/**
	 * @var string String with name of icon for myobject. Must be the part after the 'object_' into object_myobject.png
	 */
	public $picto = 'payment';

	/**
	 * @var int ID
	 */
	public $id;

	/**
	 * @var string Ref
	 */
	public $ref;

	/**
	 * @var int timestamp
	 */
	public $datep;

	/**
	 * @var int timestamp
	 */
	public $datev;

	/**
	 * @var int<0,1> Payment direction (debit or credit)
	 */
	public $sens;

	/**
	 * @var float
	 */
	public $amount;

	/**
	 * @var int Payment type (fk_typepayment)
	 */
	public $type_payment;

	/**
	 * @var string      Payment reference
	 *                  (Cheque or bank transfer reference. Can be "ABC123")
	 */
	public $num_payment;

	/**
	 * @var string Name of cheque writer
	 */
	public $chqemetteur;

	/**
	 * @var string Bank of cheque writer
	 */
	public $chqbank;

	/**
	 * @var int Category id
	 */
	public $category_transaction;

	/**
	 * @var string various payments label
	 */
	public $label;

	/**
	 * @var string accountancy code
	 */
	public $accountancy_code;

	/**
	 * @var string subledger account
	 */
	public $subledger_account;

	/**
	 * @var int ID
	 */
	public $fk_project;

	/**
	 * @var int Bank account ID
	 */
	public $fk_account;

	/**
	 * @var int Bank account ID
	 * @deprecated See fk_account
	 */
	public $accountid;

	/**
	 * @var int ID record into llx_bank
	 */
	public $fk_bank;

	/**
	 * @var int transaction category
	 */
	public $categorie_transaction;

	/**
	 * @var int ID
	 */
	public $fk_user_author;

	/**
	 * @var int ID
	 */
	public $fk_user_modif;

	/** @var string Category */
	public $category;
	/** @var string Service */
	public $service;
	/** @var string Ressource */
	public $ressource;

	/**
	 * @var int User ID
	 */
	public $fk_user;

	/**
	 * @var	int		Type of bank account if the payment is on a bank account
	 */
	public $fk_type;

	/**
	 * @var int		1 if the payment is on a bank account line that is conciliated
	 */
	public $rappro;

	/**
	 * @var	string	ID of bank receipt
	 */
	public $bank_num_releve;


	/**
	 *  'type' if the field format ('integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter]]', 'varchar(x)', 'double(24,8)', 'real', 'price', 'text', 'html', 'date', 'datetime', 'timestamp', 'duration', 'mail', 'phone', 'url', 'password')
	 *         Note: Filter can be a string like "(t.ref:like:'SO-%') or (t.date_creation:<:'20160101') or (t.nature:is:NULL)"
	 *  'label' the translation key.
	 *  'enabled' is a condition when the field must be managed (Example: 1 or 'getDolGlobalString("MY_SETUP_PARAM")'
	 *  'position' is the sort order of field.
	 *  'notnull' is set to 1 if not null in database. Set to -1 if we must set data to null if empty ('' or 0).
	 *  'visible' says if field is visible in list (Examples: 0=Not visible, 1=Visible on list and create/update/view forms, 2=Visible on list only, 3=Visible on create/update/view form only (not list), 4=Visible on list and update/view form only (not create). 5=Visible on list and view only (not create/not update). Using a negative value means field is not shown by default on list but can be selected for viewing)
	 *  'noteditable' says if field is not editable (1 or 0)
	 *  'default' is a default value for creation (can still be overwrote by the Setup of Default Values if field is editable in creation form). Note: If default is set to '(PROV)' and field is 'ref', the default value will be set to '(PROVid)' where id is rowid when a new record is created.
	 *  'index' if we want an index in database.
	 *  'foreignkey'=>'tablename.field' if the field is a foreign key (it is recommended to name the field fk_...).
	 *  'searchall' is 1 if we want to search in this field when making a search from the quick search button.
	 *  'isameasure' must be set to 1 if you want to have a total on list for this field. Field type must be summable like integer or double(24,8).
	 *  'css' is the CSS style to use on field. For example: 'maxwidth200'
	 *  'help' is a string visible as a tooltip on field
	 *  'showoncombobox' if value of the field must be visible into the label of the combobox that list record
	 *  'disabled' is 1 if we want to have the field locked by a 'disabled' attribute. In most cases, this is never set into the definition of $fields into class, but is set dynamically by some part of code.
	 *  'arrayofkeyval' to set list of value if type is a list of predefined values. For example: array("0"=>"Draft","1"=>"Active","-1"=>"Cancel")
	 *  'autofocusoncreate' to have field having the focus on a create form. Only 1 field should have this property set to 1.
	 *  'comment' is not used. You can store here any text of your choice. It is not used by application.
	 *
	 *  Note: To have value dynamic, you can set value to 0 in definition and edit the value on the fly into the constructor.
	 */

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @var array<string,array{type:string,label:string,enabled:int<0,2>|string,position:int,notnull?:int,visible:int<-5,5>|string,alwayseditable?:int<0,1>,noteditable?:int<0,1>,default?:string,index?:int,foreignkey?:string,searchall?:int<0,1>,isameasure?:int<0,1>,css?:string,csslist?:string,help?:string,showoncombobox?:int<0,4>,disabled?:int<0,1>,arrayofkeyval?:array<int|string,string>,autofocusoncreate?:int<0,1>,comment?:string,copytoclipboard?:int<1,2>,validate?:int<0,1>,showonheader?:int<0,1>}>  Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		// TODO: fill this array
	);
	// END MODULEBUILDER PROPERTIES

	/**
	 * Draft status
	 */
	const STATUS_DRAFT = 0;


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Update database
	 *
	 * @param   User	$user        	User that modify
	 * @param   int		$notrigger      0=no, 1=yes (no update trigger)
	 * @return  int         			Return integer <0 if KO, >0 if OK
	 */
	public function update($user = null, $notrigger = 0)
	{
		global $conf, $langs;

		$error = 0;

		// Clean parameters
		$this->amount = (float) price2num($this->amount);
		$this->label = trim($this->label);
		$this->note = trim($this->note);
		$this->fk_bank = (int) $this->fk_bank;
		$this->fk_user_author = (int) $this->fk_user_author;
		$this->fk_user_modif = (int) $this->fk_user_modif;
		$this->category = trim($this->category);
		$this->service = trim($this->service);
		$this->ressource = trim($this->ressource);

		$this->db->begin();

		// Update request
		$sql = "UPDATE ".MAIN_DB_PREFIX."payment_various SET";
		if ($this->tms) {
			$sql .= " tms='".$this->db->idate($this->tms)."',";
		}
		$sql .= " datep='".$this->db->idate($this->datep)."',";
		$sql .= " datev='".$this->db->idate($this->datev)."',";
		$sql .= " sens=".(int) $this->sens.",";
		$sql .= " amount=".price2num($this->amount).",";
		$sql .= " fk_typepayment=".(int) $this->type_payment.",";
		$sql .= " num_payment='".$this->db->escape($this->num_payment)."',";
		$sql .= " label='".$this->db->escape($this->label)."',";
		$sql .= " note='".$this->db->escape($this->note)."',";
		$sql .= " accountancy_code='".$this->db->escape($this->accountancy_code)."',";
		$sql .= " subledger_account='".$this->db->escape($this->subledger_account)."',";
		$sql .= " fk_projet='".$this->db->escape($this->fk_project)."',";
		$sql .= " fk_bank=".($this->fk_bank > 0 ? $this->fk_bank : "null").",";
		$sql .= " fk_user_author=".(int) $this->fk_user_author.",";
		$sql .= " fk_user_modif=".(int) $this->fk_user_modif.",";
		$sql .= " category='".$this->db->escape($this->category)."',";
		$sql .= " service='".$this->db->escape($this->service)."',";
		$sql .= " ressource='".$this->db->escape($this->ressource)."',";
		$sql .= " fk_user=".($this->fk_user > 0 ? ((int) $this->fk_user) : "null");
		$sql .= " WHERE rowid=".((int) $this->id);

		dol_syslog(get_class($this)."::update", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = "Error ".$this->db->lasterror();
			return -1;
		}

		if (!$notrigger) {
			// Call trigger
			$result = $this->call_trigger('PAYMENT_VARIOUS_MODIFY', $user);
			if ($result < 0) {
				$error++;
			}
			// End call triggers
		}

		if (!$error) {
			$this->db->commit();
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}


	/**
	 *  Load object in memory from database
	 *
	 *  @param	int		$id         id object
	 *  @param  User	$user       User that load
	 *  @return int         		Return integer <0 if KO, >0 if OK
	 */
	public function fetch($id, $user = null)
	{
		$sql = "SELECT";
		$sql .= " v.rowid,";
		$sql .= " v.tms,";
		$sql .= " v.datep,";
		$sql .= " v.datev,";
		$sql .= " v.sens,";
		$sql .= " v.amount,";
		$sql .= " v.fk_typepayment,";
		$sql .= " v.num_payment,";
		$sql .= " v.label,";
		$sql .= " v.note as note_private,";
		$sql .= " v.accountancy_code,";
		$sql .= " v.subledger_account,";
		$sql .= " v.fk_projet as fk_project,";
		$sql .= " v.fk_bank,";
		$sql .= " v.fk_user_author,";
		$sql .= " v.fk_user_modif,";
		$sql .= " v.category,";
		$sql .= " v.service,";
		$sql .= " v.ressource,";
		$sql .= " v.fk_user,";
		$sql .= " b.fk_account,";
		$sql .= " b.fk_type,";
		$sql .= " b.rappro,";
		$sql .= " b.num_releve as bank_num_releve";
		$sql .= " FROM ".MAIN_DB_PREFIX."payment_various as v";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON v.fk_bank = b.rowid";
		$sql .= " WHERE v.rowid = ".((int) $id);

		dol_syslog(get_class($this)."::fetch", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);

				$this->id                   = $obj->rowid;
				$this->ref                  = $obj->rowid;
				$this->tms                  = $this->db->jdate($obj->tms);
				$this->datep                = $this->db->jdate($obj->datep);
				$this->datev                = $this->db->jdate($obj->datev);
				$this->sens                 = $obj->sens;
				$this->amount               = $obj->amount;
				$this->type_payment         = $obj->fk_typepayment;
				$this->num_payment          = $obj->num_payment;
				$this->label                = $obj->label;
				$this->note                 = $obj->note_private;	// For backward compatibility
				$this->note_private         = $obj->note_private;
				$this->subledger_account    = $obj->subledger_account;
				$this->accountancy_code     = $obj->accountancy_code;
				$this->fk_project           = $obj->fk_project;
				$this->fk_bank              = $obj->fk_bank;
				$this->fk_user_author       = $obj->fk_user_author;
				$this->fk_user_modif        = $obj->fk_user_modif;
				$this->category             = $obj->category;
				$this->service              = $obj->service;
				$this->ressource            = $obj->ressource;
				$this->fk_user              = $obj->fk_user;
				$this->fk_account           = $obj->fk_account;
				$this->fk_type              = $obj->fk_type;
				$this->rappro               = $obj->rappro;
				$this->bank_num_releve      = $obj->bank_num_releve;
			}
			$this->db->free($resql);

			return 1;
		} else {
			$this->error = "Error ".$this->db->lasterror();
			return -1;
		}
	}


	/**
	 *  Delete object in database
	 *
	 *	@param	User	$user       User that delete
	 *	@return	int					Return integer <0 if KO, >0 if OK
	 */
	public function delete($user)
	{
		global $conf, $langs;

		$error = 0;

		// Call trigger
		$result = $this->call_trigger('PAYMENT_VARIOUS_DELETE', $user);
		if ($result < 0) {
			return -1;
		}
		// End call triggers


		$sql = "DELETE FROM ".MAIN_DB_PREFIX."payment_various";
		$sql .= " WHERE rowid=".((int) $this->id);

		dol_syslog(get_class($this)."::delete", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = "Error ".$this->db->lasterror();
			return -1;
		}

		return 1;
	}


	/**
	 *  Initialise an instance with random values.
	 *  Used to build previews or test instances.
	 *	id must be 0 if object instance is a specimen.
	 *
	 *  @return	int
	 */
	public function initAsSpecimen()
	{
		$this->id = 0;
		$this->tms = dol_now();
		$this->datep = dol_now();
		$this->datev = dol_now();
		$this->sens = 0;
		$this->amount = 100;
		$this->label = 'Specimen payment';
		$this->accountancy_code = '';
		$this->subledger_account = '';
		$this->note = '';
		$this->fk_bank = 0;
		$this->fk_user_author = 0;
		$this->fk_user_modif = 0;

		return 1;
	}

	/**
	 * Check if a miscellaneous payment can be created into database
	 *
	 * @return	boolean		True or false
	 */
	public function check()
	{
		$newamount = price2num($this->amount, 'MT');

		// Validation of parameters
		if (!($newamount) > 0 || empty($this->datep)) {
			return false;
		}

		return true;
	}

	/**
	 *  Create in database
	 *
	 *  @param   User   $user   User that create
	 *  @return  int            Return integer <0 if KO, >0 if OK
	 */
	public function create($user)
	{
		global $conf, $langs;

		$error = 0;
		$now = dol_now();

		// Clean parameters
		$this->amount = (float) price2num($this->amount);
		$this->label = trim($this->label);
		$this->note = trim($this->note);
		$this->fk_bank = (int) $this->fk_bank;
		$this->fk_user_author = (int) $this->fk_user_author;
		$this->fk_user_modif = (int) $this->fk_user_modif;
		$this->fk_account = (int) $this->fk_account;
		if (empty($this->fk_account) && isset($this->accountid)) {	// For compatibility
			$this->fk_account = $this->accountid;
		}
		$this->category = trim($this->category);
		$this->service = trim($this->service);
		$this->ressource = trim($this->ressource);

		// Check parameters
		if (!$this->label) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentities("Label"));
			return -3;
		}
		if ($this->amount < 0 || $this->amount == '') {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentities("Amount"));
			return -5;
		}
		if (isModEnabled("bank") && (empty($this->fk_account) || $this->fk_account <= 0)) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentities("BankAccount"));
			return -6;
		}
		if (isModEnabled("bank") && (empty($this->type_payment) || $this->type_payment <= 0)) {
			$this->error = $langs->trans("ErrorFieldRequired", $langs->transnoentities("PaymentMode"));
			return -7;
		}

		$this->db->begin();

		// Insert into llx_payment_various
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."payment_various (";
		$sql .= " datep";
		$sql .= ", datev";
		$sql .= ", sens";
		$sql .= ", amount";
		$sql .= ", fk_typepayment";
		$sql .= ", num_payment";
		if ($this->note) {
			$sql .= ", note";
		}
		$sql .= ", label";
		$sql .= ", accountancy_code";
		$sql .= ", subledger_account";
		$sql .= ", fk_projet";
		$sql .= ", fk_user_author";
		$sql .= ", datec";
		$sql .= ", fk_bank";
		$sql .= ", entity";
		$sql .= ", category";
		$sql .= ", service";
		$sql .= ", ressource";
		$sql .= ", fk_user";
		$sql .= ")";
		$sql .= " VALUES (";
		$sql .= "'".$this->db->idate($this->datep)."'";
		$sql .= ", '".$this->db->idate($this->datev)."'";
		$sql .= ", '".$this->db->escape($this->sens)."'";
		$sql .= ", ".price2num($this->amount);
		$sql .= ", '".$this->db->escape($this->type_payment)."'";
		$sql .= ", '".$this->db->escape($this->num_payment)."'";
		if ($this->note) {
			$sql .= ", '".$this->db->escape($this->note)."'";
		}
		$sql .= ", '".$this->db->escape($this->label)."'";
		$sql .= ", '".$this->db->escape($this->accountancy_code)."'";
		$sql .= ", '".$this->db->escape($this->subledger_account)."'";
		$sql .= ", ".($this->fk_project > 0 ? ((int) $this->fk_project) : 0);
		$sql .= ", ".((int) $user->id);
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ", NULL";	// Filled later
		$sql .= ", ".((int) $conf->entity);
		$sql .= ", '".$this->db->escape($this->category)."'";
		$sql .= ", '".$this->db->escape($this->service)."'";
		$sql .= ", '".$this->db->escape($this->ressource)."'";
		$sql .= ", ".($this->fk_user > 0 ? ((int) $this->fk_user) : "null");
		$sql .= ")";

		dol_syslog(get_class($this)."::create", LOG_DEBUG);
		$result = $this->db->query($sql);
		if ($result) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."payment_various");
			$this->ref = (string) $this->id;

			if ($this->id > 0) {
				if (isModEnabled("bank") && !empty($this->amount)) {
					// Insert into llx_bank
					require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

					$acc = new Account($this->db);
					$result = $acc->fetch($this->fk_account);
					if ($result <= 0) {
						dol_print_error($this->db);
					}

					// Insert payment into llx_bank
					// Add link 'payment_various' in bank_url between payment and bank transaction
					$sign = 1;
					if ($this->sens == '0') {
						$sign = -1;
					}

					$bank_line_id = $acc->addline(
						$this->datep,
						$this->type_payment,
						$this->label,
						$sign * abs($this->amount),
						$this->num_payment,
						($this->category_transaction > 0 ? $this->category_transaction : 0),
						$user,
						$this->chqemetteur,
						$this->chqbank,
						'',
						$this->datev
					);

					// Update fk_bank into llx_payment_various
					// So we know the payment which has generate the banking ecriture
					if ($bank_line_id > 0) {
						$this->update_fk_bank($bank_line_id);
					} else {
						$this->error = $acc->error;
						$error++;
					}

					if (!$error) {
						// Add link 'payment_various' in bank_url between payment and bank transaction
						$url = DOL_URL_ROOT.'/compta/bank/various_payment/card.php?id=';

						$result = $acc->add_url_line($bank_line_id, $this->id, $url, "(VariousPayment)", "payment_various");
						if ($result <= 0) {
							$this->error = $acc->error;
							$error++;
						}
					}

					if ($result <= 0) {
						$this->error = $acc->error;
						$error++;
					}
				}

				// Call trigger
				$result = $this->call_trigger('PAYMENT_VARIOUS_CREATE', $user);
				if ($result < 0) {
					$error++;
				}
				// End call triggers
			} else {
				$error++;
			}

			if (!$error) {
				$this->db->commit();
				return $this->id;
			} else {
				$this->db->rollback();
				return -2;
			}
		} else {
			$this->error = $this->db->error();
			$this->db->rollback();
			return -1;
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Update link between payment various and line generate into llx_bank
	 *
	 *  @param  int     $id_bank    Id bank account
	 *	@return int                 Return integer <0 if KO, >0 if OK
	 */
	public function update_fk_bank($id_bank)
	{
		// phpcs:enable
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'payment_various SET fk_bank = '.((int) $id_bank);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$result = $this->db->query($sql);
		if ($result) {
			return 1;
		} else {
			dol_print_error($this->db);
			return -1;
		}
	}


	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->statut, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return the label of a given status
	 *
	 *  @param	int		$status        Id status
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return string 			       Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		global $langs;

		/*
		if (empty($status)) {
			$status = 0;
		}

		if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
			$this->labelStatus[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			//$this->labelStatus[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Enabled');
			//$this->labelStatus[self::STATUS_CANCELED] = $langs->transnoentitiesnoconv('Disabled');
			$this->labelStatusShort[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			//$this->labelStatusShort[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Enabled');
			//$this->labelStatusShort[self::STATUS_CANCELED] = $langs->transnoentitiesnoconv('Disabled');
		}

		$statusType = 'status'.$status;

		return dolGetStatus($this->labelStatus[$status], $this->labelStatusShort[$status], '', $statusType, $mode);
		*/
		return '';
	}


	/**
	 *	Send name clickable (with possibly the picto)
	 *
	 *	@param  int		$withpicto					0=No picto, 1=Include picto into link, 2=Only picto
	 *	@param  string	$option						link option
	 *  @param  int     $save_lastsearch_value	 	-1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
	 *  @param	int  	$notooltip		 			1=Disable tooltip
	 *  @param	string  $morecss                    morecss string
	 *	@return string								String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $save_lastsearch_value = -1, $notooltip = 0, $morecss = '')
	{
		global $db, $conf, $langs, $hookmanager;
		global $langs;

		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1; // Force disable tooltips
		}

		$result = '';

		$label = '<u>'.$langs->trans("ShowVariousPayment").'</u>';
		$label .= '<br>';
		$label .= '<b>'.$langs->trans('Ref').':</b> '.$this->ref;

		$url = DOL_URL_ROOT.'/compta/bank/various_payment/card.php?id='.$this->id;

		if ($option != 'nolink') {
			// Add param to save lastsearch_values or not
			$add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
			if ($save_lastsearch_value == -1 && isset($_SERVER["PHP_SELF"]) && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
				$add_save_lastsearch_values = 1;
			}
			if ($add_save_lastsearch_values) {
				$url .= '&save_lastsearch_values=1';
			}
		}

		$linkclose = '';
		if (empty($notooltip)) {
			if (getDolGlobalString('MAIN_OPTIMIZEFORTEXTBROWSER')) {
				$label = $langs->trans("ShowMyObject");
				$linkclose .= ' alt="'.dolPrintHTMLForAttribute($label).'"';
			}
			$linkclose .= ' title="'.dolPrintHTMLForAttribute($label).'"';
			$linkclose .= ' class="classfortooltip'.($morecss ? ' '.$morecss : '').'"';
		} else {
			$linkclose = ($morecss ? ' class="'.$morecss.'"' : '');
		}

		$linkstart = '<a href="'.$url.'"';
		$linkstart .= $linkclose.'>';
		$linkend = '</a>';

		$result .= $linkstart;
		if ($withpicto) {
			$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="'.(($withpicto != 2) ? 'paddingright ' : '').'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
		}
		if ($withpicto != 2) {
			$result .= $this->ref;
		}
		$result .= $linkend;
		//if ($withpicto != 2) $result.=(($addlabel && $this->label) ? $sep . dol_trunc($this->label, ($addlabel > 1 ? $addlabel : 0)) : '');

		global $action;
		$hookmanager->initHooks(array('variouspayment'));
		$parameters = array('id' => $this->id, 'getnomurl' => &$result);
		$reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook > 0) {
			$result = $hookmanager->resPrint;
		} else {
			$result .= $hookmanager->resPrint;
		}

		return $result;
	}

	/**
	 * Information on record
	 *
	 * @param  int      $id      Id of record
	 * @return void
	 */
	public function info($id)
	{
		$sql = 'SELECT v.rowid, v.datec, v.fk_user_author, fk_user_modif, tms';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'payment_various as v';
		$sql .= ' WHERE v.rowid = '.((int) $id);

		dol_syslog(get_class($this).'::info', LOG_DEBUG);
		$result = $this->db->query($sql);

		if ($result) {
			if ($this->db->num_rows($result)) {
				$obj = $this->db->fetch_object($result);

				$this->id = $obj->rowid;
				$this->user_creation = $obj->fk_user_author;
				$this->user_creation_id = $obj->fk_user_author;
				$this->user_modification_id = $obj->fk_user_modif;
				$this->date_creation = $this->db->jdate($obj->datec);
				$this->date_modification = $this->db->jdate($obj->tms);
			}
			$this->db->free($result);
		} else {
			dol_print_error($this->db);
		}
	}

	/**
	 *	Return if a various payment linked to a bank line id was dispatched into bookkeeping
	 *
	 *	@return     int         Return integer <0 if KO, 0=no, 1=yes
	 */
	public function getVentilExportCompta()
	{
		$banklineid = $this->fk_bank;

		$alreadydispatched = 0;

		$type = 'bank';

		$sql = " SELECT COUNT(ab.rowid) as nb FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as ab WHERE ab.doc_type='".$this->db->escape($type)."' AND ab.fk_doc = ".((int) $banklineid);
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$alreadydispatched = $obj->nb;
			}
		} else {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if ($alreadydispatched) {
			return 1;
		}
		return 0;
	}

	/**
	 *	Return clickable link of object (with eventually picto)
	 *
	 *	@param      string	    			$option                 Where point the link (0=> main card, 1,2 => shipment, 'nolink'=>No link)
	 *  @param		array{string,mixed}		$arraydata				Array of data
	 *  @return		string											HTML Code for Kanban thumb.
	 */
	public function getKanbanView($option = '', $arraydata = null)
	{
		global $langs;

		$selected = (empty($arraydata['selected']) ? 0 : $arraydata['selected']);
		$bankline = ((empty($arraydata['bankline']) || empty($arraydata['bankline']->id)) ? 0 : $arraydata['bankline']);
		$formatedaccountancycode = (empty($arraydata['formatedaccountancycode']) ? '' : $arraydata['formatedaccountancycode']);

		$return = '<div class="box-flex-item box-flex-grow-zero">';
		$return .= '<div class="info-box info-box-sm">';
		$return .= '<span class="info-box-icon bg-infobox-action">';
		$return .= img_picto('', $this->picto);
		$return .= '</span>';
		$return .= '<div class="info-box-content">';
		$return .= '<span class="info-box-ref inline-block tdoverflowmax150 valignmiddle">'.(method_exists($this, 'getNomUrl') ? $this->getNomUrl(1) : $this->ref).'</span>';
		if ($selected >= 0) {
			$return .= '<input id="cb'.$this->id.'" class="flat checkforselect fright" type="checkbox" name="toselect[]" value="'.$this->id.'"'.($selected ? ' checked="checked"' : '').'>';
		}
		if (!empty($bankline) && $bankline instanceof AccountLine) {
			$return .= ' | <span class="info-box-status ">'.$bankline->getNomUrl(1).'</span>';
		}
		if (property_exists($this, 'datep')) {
			$return .= '<br><span class="opacitymedium">'.$langs->trans("Date").'</span> : <span class="info-box-label">'.dol_print_date($this->db->jdate($this->datep), 'day').'</span>';
			if ($this->type_payment) {
				$return .= ' - <span class="info-box-label">'.$this->type_payment.'</span>';
			}
		}
		if (!empty($formatedaccountancycode)) {
			$return .= '<br><span class="opacitymedium">'.$langs->trans("Account").'</span> : <span class="info-box-label" title="'.$this->accountancy_code.'">';
			$return .= $formatedaccountancycode;
			$return .= '</span>';
		} elseif (property_exists($this, 'accountancy_code')) {
			$return .= '<br><span class="opacitymedium">'.$langs->trans("Account").'</span> : <span class="info-box-label" title="'.$this->accountancy_code.'">'.$this->accountancy_code.'</span>';
		}

		if (property_exists($this, 'amount')) {
			$return .= '<br><span class="opacitymedium">'.$langs->trans("Debit").'</span> : <span class="info-box-label amount">'.price($this->amount).'</span>';
		}
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</div>';
		return $return;
	}

	/**
	 * Return General accounting account with defined length (used for product and miscellaneous)
	 *
	 * @param 	string	$account		General accounting account
	 * @return	string          		String with defined length
	 */
	public function lengthAccountg($account)
	{
		include_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';

		/*
		if (isModEnabled('accounting')) {
			$accountingaccount = new AccountingAccount($db);
			$accountingaccount->fetch(0, $valuetoshow, 1);
		}*/

		return length_accountg($account);
	}

	/**
	 * Return Auxiliary accounting account of thirdparties with defined length
	 *
	 * @param 	string	$account		Auxiliary accounting account
	 * @return	string          		String with defined length
	 */
	public function lengthAccounta($account)
	{
		include_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';

		return length_accounta($account);
	}
}


