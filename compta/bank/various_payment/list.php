<?php
/* Copyright (C) 2017-2024	Alexandre Spangaro			<alexandre@inovea-conseil.com>
 * Copyright (C) 2017       Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2024  Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2020       Tobias Sekan				<tobias.sekan@startmail.com>
 * Copyright (C) 2024		MDW							<mdeweerd@users.noreply.github.com>
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
 *  \file       htdocs/compta/bank/various_payment/list.php
 *  \ingroup    bank
 *  \brief      List of various payments
 */

// Load Dolibarr environment
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formaccounting.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';
if (isModEnabled('project')) {
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("compta", "banks", "bills", "accountancy"));

$optioncss = GETPOST('optioncss', 'alpha');
$mode      = GETPOST('mode', 'alpha');
$massaction = GETPOST('massaction', 'aZ09');
$toselect = GETPOST('toselect', 'array'); // Array of ids of elements selected into a list
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'directdebitcredittransferlist'; // To manage different context of search

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$search_ref = GETPOST('search_ref', 'alpha');
$search_label = GETPOST('search_label', 'alpha');
$search_user = GETPOSTINT('search_user');
$search_category = GETPOST('search_category', 'alpha');
$search_service = GETPOST('search_service', 'alpha');
$search_ressource = GETPOSTINT('search_ressource');
$search_datep_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_startmonth'), GETPOSTINT('search_date_startday'), GETPOSTINT('search_date_startyear'));
$search_datep_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_endmonth'), GETPOSTINT('search_date_endday'), GETPOSTINT('search_date_endyear'));
$search_datev_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_value_startmonth'), GETPOSTINT('search_date_value_startday'), GETPOSTINT('search_date_value_startyear'));
$search_datev_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_value_endmonth'), GETPOSTINT('search_date_value_endday'), GETPOSTINT('search_date_value_endyear'));
$search_amount_deb = GETPOST('search_amount_deb', 'alpha');
$search_amount_cred = GETPOST('search_amount_cred', 'alpha');
$search_bank_account = GETPOST('search_account', "intcomma");
$search_bank_entry = GETPOST('search_bank_entry', 'alpha');
$search_accountancy_account = GETPOST("search_accountancy_account");
if ($search_accountancy_account == - 1) {
	$search_accountancy_account = '';
}
$search_accountancy_subledger = GETPOST("search_accountancy_subledger");
if ($search_accountancy_subledger == - 1) {
	$search_accountancy_subledger = '';
}
if (empty($search_datep_start)) {
	$search_datep_start = GETPOSTINT("search_datep_start");
}
if (empty($search_datep_end)) {
	$search_datep_end = GETPOSTINT("search_datep_end");
}
if (empty($search_datev_start)) {
	$search_datev_start = GETPOSTINT("search_datev_start");
}
if (empty($search_datev_end)) {
	$search_datev_end = GETPOSTINT("search_datev_end");
}
$search_type_id = GETPOST('search_type_id', 'int');

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	// If $page is not defined, or '' or -1 or if we click on clear filters
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Initialize a technical objects
$object = new PaymentVarious($db);
$extrafields = new ExtraFields($db);
//$diroutputmassaction = $conf->mymodule->dir_output.'/temp/massgeneration/'.$user->id;
$hookmanager->initHooks(array($contextpage)); 	// Note that conf->hooks_modules contains array of activated contexes

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Default sort order (if not yet defined by previous GETPOST)
if (!$sortfield) {
	$sortfield = "v.datep,v.rowid";
}
if (!$sortorder) {
	$sortorder = "DESC,DESC";
}

$filtre = GETPOST("filtre", 'alpha');

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All test are required to be compatible with all browsers
	$search_ref = '';
	$search_label = '';
	$search_datep_start = '';
	$search_datep_end = '';
	$search_datev_start = '';
	$search_datev_end = '';
	$search_amount_deb = '';
	$search_amount_cred = '';
	$search_bank_account = '';
	$search_bank_entry = '';
	$search_accountancy_account = '';
	$search_accountancy_subledger = '';
	$search_type_id = '';
	$search_user = 0;
	$search_category = '';
	$search_service = '';
	$search_ressource = 0;
}

$search_all = trim(GETPOST('search_all', 'alphanohtml'));

// List of fields to search into when doing a "search in all"
$fieldstosearchall = array(
	'v.rowid' => "Ref",
	'v.label' => "Label",
	'v.datep' => "DatePayment",
	'v.datev' => "DateValue",
	'v.amount' => $langs->trans("Debit").", ".$langs->trans("Credit"),
);

// Definition of fields for lists
$arrayfields = array(
	'ref'			=> array('label' => "Ref", 'checked' => 1, 'position' => 100),
	'label'			=> array('label' => "Label", 'checked' => 1, 'position' => 110),
	'datep'			=> array('label' => "DatePayment", 'checked' => 1, 'position' => 120),
	'datev'			=> array('label' => "DateValue", 'checked' => -1, 'position' => 130),
	'type'			=> array('label' => "PaymentMode", 'checked' => 1, 'position' => 140),
	'project'		=> array('label' => "Project", 'checked' => -1, 'position' => 200, "enabled" => isModEnabled('project')),
	'bank'			=> array('label' => "BankAccount", 'checked' => 1, 'position' => 300, "enabled" => isModEnabled("bank")),
	'entry'			=> array('label' => "BankTransactionLine", 'checked' => 1, 'position' => 310, "enabled" => isModEnabled("bank")),
	'account'		=> array('label' => "AccountAccountingShort", 'checked' => 1, 'position' => 400, "enabled" => isModEnabled('accounting')),
	'subledger'		=> array('label' => "SubledgerAccount", 'checked' => 1, 'position' => 410, "enabled" => isModEnabled('accounting')),
	'debit'			=> array('label' => "Debit", 'checked' => 1, 'position' => 500),
	'credit'		=> array('label' => "Credit", 'checked' => 1, 'position' => 510),
	'user'			=> array('label' => "User", 'checked' => 1, 'position' => 600),
	'category'		=> array('label' => "Category", 'checked' => 1, 'position' => 610),
	'service'		=> array('label' => "Service", 'checked' => 1, 'position' => 620),
	'ressource'		=> array('label' => "Ressource", 'checked' => 1, 'position' => 630),
);

$arrayfields = dol_sort_array($arrayfields, 'position');
'@phan-var-force array<string,array{label:string,checked?:int<0,1>,position?:int,help?:string}> $arrayfields';  // dol_sort_array looses type for Phan

// Security check
$socid = GETPOSTINT("socid");
if ($user->socid) {
	$socid = $user->socid;
}

$result = restrictedArea($user, 'banque', '', '', '');


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
	$action = 'list';
	$massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Selection of new fields
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

	// Purge search criteria
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
		foreach ($object->fields as $key => $val) {
			$search[$key] = '';
			if (preg_match('/^(date|timestamp|datetime)/', $val['type'])) {
				$search[$key.'_dtstart'] = '';
				$search[$key.'_dtend'] = '';
			}
		}
		$toselect = array();
		$search_array_options = array();
	}
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
		|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
		$massaction = ''; // Protection to avoid mass action if we force a new search during a mass action confirmation
	}
}

/*
 * View
 */

$form = new Form($db);
$proj = null;
$accountingaccount = new AccountingAccount($db);
$bankline = new AccountLine($db);
$variousstatic = new PaymentVarious($db);
$accountstatic = null;
$accountingjournal = null;
if ($arrayfields['account']['checked'] || $arrayfields['subledger']['checked']) {
	$formaccounting = new FormAccounting($db);
}
if ($arrayfields['bank']['checked'] && isModEnabled('accounting')) {
	$accountingjournal = new AccountingJournal($db);
}
if ($arrayfields['bank']['checked']) {
	$accountstatic = new Account($db);
}
if (isModEnabled('project') && $arrayfields['project']['checked']) {
	$proj = new Project($db);
}

$title = $langs->trans("VariousPayments");
$help_url = '';


// Build and execute select
// --------------------------------------------------------------------
// **FIX 1: Select the correct user column 'fk_user_author' and alias it as 'fk_user' for compatibility with the rest of the page.**
$sql = "SELECT v.rowid, v.sens, v.amount, v.label, v.datep as datep, v.datev as datev, v.fk_typepayment as type, v.num_payment, v.fk_bank, v.accountancy_code, v.subledger_account, v.fk_projet as fk_project, v.fk_user_author as fk_user, v.category, v.service, v.ressource,";
$sql .= " ba.rowid as bid, ba.ref as bref, ba.number as bnumber, ba.account_number as bank_account_number, ba.fk_accountancy_journal as accountancy_journal, ba.label as blabel,";
$sql .= " pst.code as payment_code";

$sqlfields = $sql;

$sql .= " FROM ".MAIN_DB_PREFIX."payment_various as v";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as pst ON v.fk_typepayment = pst.id";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON v.fk_bank = b.rowid";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account as ba ON b.fk_account = ba.rowid";
// **FIX 2: Join the user table on the correct column 'fk_user_author'.**
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON v.fk_user_author = u.rowid";
$sql .= " WHERE v.entity IN (".getEntity('payment_various').")";

// Search criteria
if ($search_ref) {
	$sql .= " AND v.rowid = ".((int) $search_ref);
}
if ($search_label) {
	$sql .= natural_search(array('v.label'), $search_label);
}
if ($search_datep_start) {
	$sql .= " AND v.datep >= '".$db->idate($search_datep_start)."'";
}
if ($search_datep_end) {
	$sql .= " AND v.datep <= '".$db->idate($search_datep_end)."'";
}
if ($search_datev_start) {
	$sql .= " AND v.datev >= '".$db->idate($search_datev_start)."'";
}
if ($search_datev_end) {
	$sql .= " AND v.datev <= '".$db->idate($search_datev_end)."'";
}
if ($search_amount_deb) {
	$sql .= natural_search("v.amount", $search_amount_deb, 1);
}
if ($search_amount_cred) {
	$sql .= natural_search("v.amount", $search_amount_cred, 1);
}
if ($search_bank_account > 0) {
	$sql .= " AND b.fk_account = ".((int) $search_bank_account);
}
if ($search_bank_entry > 0) {
	$sql .= " AND b.fk_account = ".((int) $search_bank_account);
}
if ($search_accountancy_account > 0) {
	$sql .= " AND v.accountancy_code = ".((int) $search_accountancy_account);
}
if (getDolGlobalString('ACCOUNTANCY_COMBO_FOR_AUX')) {
	$sql .= " AND v.subledger_account = '".$db->escape($search_accountancy_subledger)."'";
} else {
	if ($search_accountancy_subledger != '' && $search_accountancy_subledger != '-1') {
		$sql .= natural_search("v.subledger_account", $search_accountancy_subledger);
	}
}
if ($search_type_id > 0) {
	$sql .= " AND v.fk_typepayment=".((int) $search_type_id);
}

// **FIX 3: Filter on the correct user column and add checks for placeholder values.**
if ($search_user > 0) {
	$sql .= " AND v.fk_user_author = ".((int) $search_user);
}
if (isset($search_category) && $search_category != '' && $search_category != '-1') {
	$sql .= " AND v.category = '".$db->escape($search_category)."'";
}
if (isset($search_service) && $search_service != '' && $search_service != '-1') {
	$sql .= " AND v.service = '".$db->escape($search_service)."'";
}
if ($search_ressource > 0) {
	$sql .= " AND v.ressource = ".((int) $search_ressource);
}

if ($search_all) {
	$sql .= natural_search(array_keys($fieldstosearchall), $search_all);
}

include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_sql.tpl.php';

$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object, $action);
$sql .= $hookmanager->resPrint;

// Count total nb of records
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$sqlforcount = preg_replace('/^'.preg_quote($sqlfields, '/').'/', 'SELECT COUNT(*) as nbtotalofrecords', $sql);
	$sqlforcount = preg_replace('/GROUP BY .*$/', '', $sqlforcount);
	$resql = $db->query($sqlforcount);
	if ($resql) {
		$objforcount = $db->fetch_object($resql);
		$nbtotalofrecords = $objforcount->nbtotalofrecords;
	} else {
		dol_print_error($db);
	}

	if (($page * $limit) > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
	$db->free($resql);
}

$sql .= $db->order($sortfield, $sortorder);
if ($limit) {
	$sql .= $db->plimit($limit + 1, $offset);
}

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$num = $db->num_rows($resql);

if ($num == 1 && getDolGlobalInt('MAIN_SEARCH_DIRECT_OPEN_IF_ONLY_ONE') && $search_all && !$page) {
	$obj = $db->fetch_object($resql);
	$id = $obj->rowid;
	header("Location: ".DOL_URL_ROOT.'/compta/bank/various_payment/card.php?id='.$id);
	exit;
}

// Output page
llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'bodyforlist');

$arrayofselected = is_array($toselect) ? $toselect : array();

$param = '';
if (!empty($mode)) {
	$param .= '&mode='.urlencode($mode);
}
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($optioncss != '') {
	$param .= '&optioncss='.urlencode($optioncss);
}
if ($search_ref) {
	$param .= '&search_ref='.urlencode($search_ref);
}
if ($search_label) {
	$param .= '&search_label='.urlencode($search_label);
}
if ($search_datep_start) {
	$param .= '&search_datep_start='.urlencode($search_datep_start);
}
if ($search_datep_end) {
	$param .= '&search_datep_end='.urlencode($search_datep_end);
}
if ($search_datev_start) {
	$param .= '&search_datev_start='.urlencode($search_datev_start);
}
if ($search_datev_end) {
	$param .= '&search_datev_end='.urlencode($search_datev_end);
}
if ($search_type_id > 0) {
	$param .= '&search_type_id='.urlencode((string) ($search_type_id));
}
if ($search_amount_deb) {
	$param .= '&search_amount_deb='.urlencode($search_amount_deb);
}
if ($search_amount_cred) {
	$param .= '&search_amount_cred='.urlencode($search_amount_cred);
}
if ($search_bank_account > 0) {
	$param .= '&search_account='.urlencode((string) ($search_bank_account));
}
if ($search_accountancy_account > 0) {
	$param .= '&search_accountancy_account='.urlencode($search_accountancy_account);
}
if ($search_accountancy_subledger > 0) {
	$param .= '&search_accountancy_subledger='.urlencode($search_accountancy_subledger);
}
// Use the variables defined at the top
if ($search_user > 0) {
	$param .= '&search_user='.urlencode($search_user);
}
if (!empty($search_category) && $search_category != '-1') {
	$param .= '&search_category='.urlencode($search_category);
}
if (!empty($search_service) && $search_service != '-1') {
	$param .= '&search_service='.urlencode($search_service);
}
if ($search_ressource > 0) {
	$param .= '&search_ressource='.urlencode($search_ressource);
}

$url = DOL_URL_ROOT.'/compta/bank/various_payment/card.php?action=create';
if (!empty($socid)) {
	$url .= '&socid='.urlencode((string) ($socid));
}

$arrayofmassactions = array();
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
}
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';
print '<input type="hidden" name="page_y" value="">';
print '<input type="hidden" name="mode" value="'.$mode.'">';

$newcardbutton  = '';
$newcardbutton .= dolGetButtonTitle($langs->trans('ViewList'), '', 'fa fa-bars imgforviewmode', $_SERVER["PHP_SELF"].'?mode=common'.preg_replace('/(&|\?)*mode=[^&]+/', '', $param), '', ((empty($mode) || $mode == 'common') ? 2 : 1), array('morecss' => 'reposition'));
$newcardbutton .= dolGetButtonTitle($langs->trans('ViewKanban'), '', 'fa fa-th-list imgforviewmode', $_SERVER["PHP_SELF"].'?mode=kanban'.preg_replace('/(&|\?)*mode=[^&]+/', '', $param), '', ($mode == 'kanban' ? 2 : 1), array('morecss' => 'reposition'));
$newcardbutton .= dolGetButtonTitleSeparator();
$newcardbutton .= dolGetButtonTitle($langs->trans('MenuNewVariousPayment'), '', 'fa fa-plus-circle', $url, '', $user->hasRight('banque', 'modifier'));

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'object_payment', 0, $newcardbutton, '', $limit, 0, 0, 1);

if ($search_all) {
	$setupstring = '';
	foreach ($fieldstosearchall as $key => $val) {
		$fieldstosearchall[$key] = $langs->trans($val);
		$setupstring .= $key."=".$val.";";
	}
	print '<!-- Search done like if VARIOUSPAYMENT_QUICKSEARCH_ON_FIELDS = '.$setupstring.' -->'."\n";
	print '<div class="divsearchfieldfilter">'.$langs->trans("FilterOnInto", $search_all).implode(', ', $fieldstosearchall).'</div>';
}

$arrayofmassactions = array();
$moreforfilter = '';
$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
$htmlofselectarray = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN'));
$selectedfields = ($mode != 'kanban' ? $htmlofselectarray : '');
$selectedfields .= (count($arrayofmassactions) ? $form->showCheckAddButtons('checkforselect', 1) : '');

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal noborder liste'.($moreforfilter ? " listwithfilterbefore" : "").'">'."\n";

// Fields title search
print '<tr class="liste_titre liste_titre_filter">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print '<td class="liste_titre center maxwidthsearch">';
	$searchpicto = $form->showFilterButtons('left');
	print $searchpicto;
	print '</td>';
}

if (getDolGlobalString('MAIN_VIEW_LINE_NUMBER_IN_LIST')) {
	print '<td class="liste_titre"></td>';
}

// Search fields
if ($arrayfields['ref']['checked']) {
	print '<td class="liste_titre left"><input class="flat" type="text" size="3" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
}
if ($arrayfields['label']['checked']) {
	print '<td class="liste_titre"><input type="text" class="flat" size="10" name="search_label" value="'.dol_escape_htmltag($search_label).'"></td>';
}
if ($arrayfields['datep']['checked']) {
	print '<td class="liste_titre center"><div class="nowrapfordate">';
	print $form->selectDate($search_datep_start ? $search_datep_start : -1, 'search_date_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
	print '</div><div class="nowrapfordate">';
	print $form->selectDate($search_datep_end ? $search_datep_end : -1, 'search_date_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
	print '</div></td>';
}
if ($arrayfields['datev']['checked']) {
	print '<td class="liste_titre center"><div class="nowrapfordate">';
	print $form->selectDate($search_datev_start ? $search_datev_start : -1, 'search_date_value_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
	print '</div><div class="nowrapfordate">';
	print $form->selectDate($search_datev_end ? $search_datev_end : -1, 'search_date_value_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
	print '</div></td>';
}
if ($arrayfields['type']['checked']) {
	print '<td class="liste_titre center">';
	print $form->select_types_paiements($search_type_id, 'search_type_id', '', 0, 1, 1, 16, 1, 'maxwidth100', 1);
	print '</td>';
}
if (isModEnabled('project') && $arrayfields['project']['checked']) {
	print '<td class="liste_titre"></td>'; // TODO
}
if ($arrayfields['bank']['checked']) {
	print '<td class="liste_titre">';
	$form->select_comptes($search_bank_account, 'search_account', 0, '', 1, '', 0, 'maxwidth100');
	print '</td>';
}
if ($arrayfields['entry']['checked']) {
	print '<td class="liste_titre left"><input name="search_bank_entry" class="flat maxwidth50" type="text" value="'.dol_escape_htmltag($search_bank_entry).'"></td>';
}
if (!empty($arrayfields['account']['checked'])) {
	print '<td class="liste_titre"><div class="nowrap">';
	print $formaccounting->select_account($search_accountancy_account, 'search_accountancy_account', 1, array(), 1, 1, 'maxwidth200');
	print '</div></td>';
}
if (!empty($arrayfields['subledger']['checked'])) {
	print '<td class="liste_titre"><div class="nowrap">';
	if (getDolGlobalString('ACCOUNTANCY_COMBO_FOR_AUX')) {
		print $formaccounting->select_auxaccount($search_accountancy_subledger, 'search_accountancy_subledger', 1, 'maxwidth150');
	} else {
		print '<input type="text" class="maxwidth150 maxwidthonsmartphone" name="search_accountancy_subledger" value="'.$search_accountancy_subledger.'">';
	}
	print '</div></td>';
}
if (!empty($arrayfields['debit']['checked'])) {
	print '<td class="liste_titre right"><input name="search_amount_deb" class="flat maxwidth50" type="text" value="'.dol_escape_htmltag($search_amount_deb).'"></td>';
}
if ($arrayfields['credit']['checked']) {
	print '<td class="liste_titre right"><input name="search_amount_cred" class="flat maxwidth50" type="text" size="8" value="'.dol_escape_htmltag($search_amount_cred).'"></td>';
}
if ($arrayfields['user']['checked']) {
	print '<td class="liste_titre">';
	print $form->select_users($search_user, 'search_user', 1, '', 1);
	print '</td>';
}
if ($arrayfields['category']['checked']) {
	print '<td class="liste_titre">';
	$category_options = array(
		'' => $langs->trans("SelectAnOption"),
		'Payment Service' => $langs->trans("Payment Service"),
		'Moyenne generaux' => $langs->trans("Moyenne generaux"),
		'Les Pertes / Errors' => $langs->trans("Les Pertes / Errors"),
		'Consommation' => $langs->trans("Consommation"),
		'Prime Exceptionnel' => $langs->trans("Prime Exceptionnel"),
		'Pub' => $langs->trans("Pub"),
		'Yalidine' => $langs->trans("Yalidine"),
		'Socials' => $langs->trans("Socials"),
		'Autre' => $langs->trans("Autre")
	);
	print $form->selectarray('search_category', $category_options, $search_category, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1);
	print '</td>';
}
if ($arrayfields['service']['checked']) {
	print '<td class="liste_titre">';
	$service_options = array('' => $langs->trans("SelectAnOption"));
	$sql_service = "SELECT param FROM ".MAIN_DB_PREFIX."extrafields WHERE rowid = 70";
	$resql_service = $db->query($sql_service);
	if ($resql_service) {
		$obj_service = $db->fetch_object($resql_service);
		if ($obj_service && !empty($obj_service->param)) {
			$params_array = @unserialize($obj_service->param);
			if ($params_array !== false && !empty($params_array['options'])) {
				foreach ($params_array['options'] as $key => $value) {
					$service_options[$key] = $langs->trans($value);
				}
			}
		}
	}
	print $form->selectarray('search_service', $service_options, $search_service, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1);
	print '</td>';
}
if ($arrayfields['ressource']['checked']) {
	print '<td class="liste_titre">';
	$ressource_options = array();
	$sql_ressource = "SELECT rowid, ref FROM llx_resource ORDER BY ref ASC";
	$resql_ressource = $db->query($sql_ressource);
	if ($resql_ressource) {
		if ($db->num_rows($resql_ressource) > 0) {
			while ($obj_ressource = $db->fetch_object($resql_ressource)) {
				$ressource_options[$obj_ressource->rowid] = $obj_ressource->ref;
			}
		}
	}
	print $form->selectarray('search_ressource', $ressource_options, $search_ressource, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1);
	print '</td>';
}

if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print '<td class="liste_titre center maxwidthsearch">';
	$searchpicto = $form->showFilterButtons();
	print $searchpicto;
	print '</td>';
}
print '</tr>'."\n";

// Table headers
$totalarray = array();
$totalarray['nbfield'] = 0;

print '<tr class="liste_titre">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	$totalarray['nbfield']++;
}
if (getDolGlobalString('MAIN_VIEW_LINE_NUMBER_IN_LIST')) {
	print_liste_field_titre('#', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['ref']['checked']) {
	print_liste_field_titre($arrayfields['ref']['label'], $_SERVER["PHP_SELF"], 'v.rowid', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['label']['checked']) {
	print_liste_field_titre($arrayfields['label']['label'], $_SERVER["PHP_SELF"], 'v.label', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['datep']['checked']) {
	print_liste_field_titre($arrayfields['datep']['label'], $_SERVER["PHP_SELF"], 'v.datep,v.rowid', '', $param, '', $sortfield, $sortorder, 'center ');
	$totalarray['nbfield']++;
}
if ($arrayfields['datev']['checked']) {
	print_liste_field_titre($arrayfields['datev']['label'], $_SERVER["PHP_SELF"], 'v.datev,v.rowid', '', $param, '', $sortfield, $sortorder, 'center ');
	$totalarray['nbfield']++;
}
if ($arrayfields['type']['checked']) {
	print_liste_field_titre($arrayfields['type']['label'], $_SERVER["PHP_SELF"], 'type', '', $param, '', $sortfield, $sortorder, 'center ');
	$totalarray['nbfield']++;
}
if (isModEnabled('project') && $arrayfields['project']['checked']) {
	print_liste_field_titre($arrayfields['project']['label'], $_SERVER["PHP_SELF"], 'fk_project', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['bank']['checked']) {
	print_liste_field_titre($arrayfields['bank']['label'], $_SERVER["PHP_SELF"], 'ba.label', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['entry']['checked']) {
	print_liste_field_titre($arrayfields['entry']['label'], $_SERVER["PHP_SELF"], 'ba.label', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if (!empty($arrayfields['account']['checked'])) {
	print_liste_field_titre($arrayfields['account']['label'], $_SERVER["PHP_SELF"], 'v.accountancy_code', '', $param, '', $sortfield, $sortorder, 'left ');
	$totalarray['nbfield']++;
}
if (!empty($arrayfields['subledger']['checked'])) {
	print_liste_field_titre($arrayfields['subledger']['label'], $_SERVER["PHP_SELF"], 'v.subledger_account', '', $param, '', $sortfield, $sortorder, 'left ');
	$totalarray['nbfield']++;
}
if ($arrayfields['debit']['checked']) {
	print_liste_field_titre($arrayfields['debit']['label'], $_SERVER["PHP_SELF"], 'v.amount', '', $param, '', $sortfield, $sortorder, 'right ');
	$totalarray['nbfield']++;
}
if ($arrayfields['credit']['checked']) {
	print_liste_field_titre($arrayfields['credit']['label'], $_SERVER["PHP_SELF"], 'v.amount', '', $param, '', $sortfield, $sortorder, 'right ');
	$totalarray['nbfield']++;
}
if ($arrayfields['user']['checked']) {
	print_liste_field_titre($arrayfields['user']['label'], $_SERVER["PHP_SELF"], 'u.login', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['category']['checked']) {
	print_liste_field_titre($arrayfields['category']['label'], $_SERVER["PHP_SELF"], 'v.category', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['service']['checked']) {
	print_liste_field_titre($arrayfields['service']['label'], $_SERVER["PHP_SELF"], 'v.service', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
if ($arrayfields['ressource']['checked']) {
	print_liste_field_titre($arrayfields['ressource']['label'], $_SERVER["PHP_SELF"], 'v.ressource', '', $param, '', $sortfield, $sortorder);
	$totalarray['nbfield']++;
}
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_title.tpl.php';
$parameters = array('arrayfields' => $arrayfields, 'param' => $param, 'sortfield' => $sortfield, 'sortorder' => $sortorder, 'totalarray' => &$totalarray);
$reshook = $hookmanager->executeHooks('printFieldListTitle', 'parameters', $object, $action);
$arrayfields = dol_sort_array($arrayfields, 'position');
print $hookmanager->resPrint;
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	$totalarray['nbfield']++;
}
print '</tr>'."\n";

// Loop on records
$i = 0;
$savnbfield = $totalarray['nbfield'];
$totalarray = array();
$totalarray['nbfield'] = 0;
$totalarray['val']['total_cred'] = 0;
$totalarray['val']['total_deb'] = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break;
	}

	$variousstatic->id = $obj->rowid;
	$variousstatic->ref = $obj->rowid;
	$variousstatic->label = $obj->label;
	$variousstatic->datep = $obj->datep;
	$variousstatic->type_payment = $obj->payment_code;
	$variousstatic->accountancy_code = $obj->accountancy_code;
	$variousstatic->amount = $obj->amount;

	if ($mode == 'kanban') {
		if ($obj->fk_bank > 0) {
			$bankline->fetch($obj->fk_bank);
		} else {
			$bankline->id = 0;
		}
		$accountingaccount->fetch(0, $obj->accountancy_code, 1);
		if ($i == 0) {
			print '<tr class="trkanban"><td colspan="'.$savnbfield.'">';
			print '<div class="box-flex-container kanban">';
		}
		print $variousstatic->getKanbanView('', array('selected' => in_array($object->id, $arrayofselected), 'bankline' => $bankline, 'formatedaccountancycode' => $accountingaccount->getNomUrl(0, 0, 1, $obj->accountancy_code, 1)));
		if ($i == ($imaxinloop) - 1) {
			print '</div>';
			print '</td></tr>';
		}
	} else {
		print '<tr data-rowid="'.$obj->rowid.'" class="oddeven">';
		if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
			print '<td></td>'; if (!$i) $totalarray['nbfield']++;
		}
		if (getDolGlobalString('MAIN_VIEW_LINE_NUMBER_IN_LIST')) {
			print '<td>'.(($offset * $limit) + $i).'</td>';
		}
		if ($arrayfields['ref']['checked']) {
			print '<td>'.$variousstatic->getNomUrl(1)."</td>"; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['label']['checked']) {
			print '<td class="tdoverflowmax150" title="'.$variousstatic->label.'">'.$variousstatic->label."</td>"; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['datep']['checked']) {
			print '<td class="center">'.dol_print_date($db->jdate($obj->datep), 'day')."</td>"; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['datev']['checked']) {
			print '<td class="center">'.dol_print_date($db->jdate($obj->datev), 'day')."</td>"; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['type']['checked']) {
			$labeltoshow = '';
			if ($obj->payment_code) $labeltoshow = $langs->transnoentitiesnoconv("PaymentTypeShort".$obj->payment_code).' ';
			$labeltoshow .= $obj->num_payment;
			print '<td class="center tdoverflowmax150" title="'.dolPrintHTML($labeltoshow).'">'.$labeltoshow.'</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if (isModEnabled('project') && $arrayfields['project']['checked']) {
			print '<td class="nowraponall">';
			if ($obj->fk_project > 0 && is_object($proj)) {
				$proj->fetch($obj->fk_project);
				print $proj->getNomUrl(1);
			}
			print '</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['bank']['checked']) {
			print '<td class="nowraponall">';
			if (is_object($accountstatic) && $obj->bid > 0) {
				$accountstatic->id = $obj->bid;
				$accountstatic->ref = $obj->bref;
				$accountstatic->number = $obj->bnumber;
				if (isModEnabled('accounting') && is_object($accountingjournal)) {
					$accountstatic->account_number = $obj->bank_account_number;
					$accountingjournal->fetch($obj->accountancy_journal);
					$accountstatic->accountancy_journal = $accountingjournal->getNomUrl(0, 1, 1, '', 1);
				}
				$accountstatic->label = $obj->blabel;
				print $accountstatic->getNomUrl(1);
			}
			print '</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['entry']['checked']) {
			$bankline->fetch($obj->fk_bank);
			print '<td>'.$bankline->getNomUrl(1).'</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if (!empty($arrayfields['account']['checked'])) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
			$result = $accountingaccount->fetch(0, $obj->accountancy_code, 1);
			if ($result > 0) {
				print '<td class="tdoverflowmax150" title="'.dol_escape_htmltag($obj->accountancy_code.' '.$accountingaccount->label).'">'.$accountingaccount->getNomUrl(0, 1, 1, '', 1).'</td>';
			} else {
				print '<td></td>';
			}
			if (!$i) $totalarray['nbfield']++;
		}
		if (!empty($arrayfields['subledger']['checked'])) {
			print '<td class="tdoverflowmax150">'.length_accounta($obj->subledger_account).'</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['debit']['checked']) {
			print '<td class="nowrap right">';
			if ($obj->sens == 0) {
				print '<span class="amount">'.price($obj->amount).'</span>';
				$totalarray['val']['total_deb'] += $obj->amount;
			}
			if (!$i) {
				$totalarray['nbfield']++;
				$totalarray['pos'][$totalarray['nbfield']] = 'total_deb';
			}
			print '</td>';
		}
		if ($arrayfields['credit']['checked']) {
			print '<td class="nowrap right">';
			if ($obj->sens == 1) {
				print '<span class="amount">'.price($obj->amount).'</span>';
				$totalarray['val']['total_cred'] += $obj->amount;
			}
			if (!$i) {
				$totalarray['nbfield']++;
				$totalarray['pos'][$totalarray['nbfield']] = 'total_cred';
			}
			print '</td>';
		}
		if ($arrayfields['user']['checked']) {
			print '<td>';
			if ($obj->fk_user) {
				$userstatic = new User($db);
				$userstatic->fetch($obj->fk_user);
				print $userstatic->getNomUrl(1);
			} else {
				print ' ';
			}
			print '</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['category']['checked']) {
			print '<td>'.($obj->category ? $obj->category : ' ').'</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['service']['checked']) {
			print '<td>';
			if (!empty($obj->service)) {
				$sql_service_fetch = "SELECT param FROM ".MAIN_DB_PREFIX."extrafields WHERE rowid = 70";
				$resql_service_fetch = $db->query($sql_service_fetch);
				if ($resql_service_fetch) {
					$obj_service_param = $db->fetch_object($resql_service_fetch);
					if ($obj_service_param && !empty($obj_service_param->param)) {
						$params_array_service = unserialize($obj_service_param->param);
						if (!empty($params_array_service['options']) && isset($params_array_service['options'][$obj->service])) {
							print $langs->trans($params_array_service['options'][$obj->service]);
						} else {
							print $obj->service;
						}
					} else {
						print $obj->service;
					}
				} else {
					print $obj->service;
				}
			} else {
				print ' ';
			}
			print '</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if ($arrayfields['ressource']['checked']) {
			print '<td>';
			if (!empty($obj->ressource) && is_numeric($obj->ressource)) {
				$sql_ressource_fetch = "SELECT ref FROM ".MAIN_DB_PREFIX."resource WHERE rowid = ".((int) $obj->ressource);
				$resql_ressource_fetch = $db->query($sql_ressource_fetch);
				if ($resql_ressource_fetch) {
					$obj_ressource_ref = $db->fetch_object($resql_ressource_fetch);
					if ($obj_ressource_ref && !empty($obj_ressource_ref->ref)) {
						print $obj_ressource_ref->ref;
					} else {
						print $obj->ressource;
					}
				} else {
					print $obj->ressource;
				}
			} else {
				print ' ';
			}
			print '</td>'; if (!$i) $totalarray['nbfield']++;
		}
		if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
			print '<td></td>'; if (!$i) $totalarray['nbfield']++;
		}
		print '</tr>'."\n";
	}
	$i++;
}

// Show total line
include DOL_DOCUMENT_ROOT.'/core/tpl/list_print_total.tpl.php';

if ($num == 0) {
	$colspan = 1;
	foreach ($arrayfields as $key => $val) {
		if (!empty($val['checked'])) $colspan++;
	}
	print '<tr><td colspan="'.$colspan.'"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

$db->free($resql);

$parameters = array('arrayfields' => $arrayfields, 'sql' => $sql);
$reshook = $hookmanager->executeHooks('printFieldListFooter', $parameters, $object);
print $hookmanager->resPrint;

print '</table>'."\n";
print '</div>'."\n";
print '</form>'."\n";

llxFooter();
$db->close();
