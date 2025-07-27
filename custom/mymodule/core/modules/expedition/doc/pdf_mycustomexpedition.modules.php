<?php
/*
 * This file is an adaptation of pdf_bon_garantie.modules.php to work with the Expedition module.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php'; // Added from bon_garantie
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php'; // Added for MO display logic

/**
 * Class to generate a Warranty Slip PDF from an Expedition, mimicking pdf_bon_garantie.
 */
class pdf_custom_shipping_slip extends ModelePDFExpedition
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var int The environment ID when using a multicompany module
	 */
	public $entity;

	/**
	 * @var string model name
	 */
	public $name;

	/**
	 * @var string model description (short text)
	 */
	public $description;

	/**
	 * @var int 	Save the name of generated file as the main doc when generating a doc with this template
	 */
	public $update_main_doc_field;

	/**
	 * @var string document type
	 */
	public $type;

	/**
	 * Dolibarr version of the loaded document
	 * @var string Version, possible values are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'''|'development'|'dolibarr'|'experimental'
	 */
	public $version = 'dolibarr'; // Copied from bon_garantie

	/**
	 * @var array<string,array{rank:int,width:float|int,status:bool,title:array{textkey:string,label:string,align:string,padding:array{0:float,1:float,2:float,3:float}},content:array{align:string,padding:array{0:float,1:float,2:float,3:float}}}>	Array of document table columns
	 */
	public $cols = array(); // Will be defined in defineColumnField

	// Properties from bon_garantie constructor
	public $page_largeur;
	public $page_hauteur;
	public $format;
	public $marge_gauche;
	public $marge_droite;
	public $marge_haute;
	public $marge_basse;
	public $corner_radius;
	public $option_logo;
	public $option_multilang;
	public $option_freetext;
	public $option_draft_watermark;
	public $watermark;
	public $emetteur; // Already present in ModelePDFExpedition, but ensure it's used like in bon_garantie
	public $posxdesc;
	public $tabTitleHeight;
	public $atleastonephoto; // Will be set in write_file
	public $atleastonediscount; // Likely not used for shipping slip, but for consistency

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        global $conf, $langs, $mysoc;

        $langs->loadLangs(array("main", "bills", "products", "sendings", "orders", "companies", "mrp")); // Added mrp

        parent::__construct($db); // Call parent constructor from ModelePDFExpedition

        $this->db = $db; // Already done by parent, but explicit
        $this->name = "custom_shipping_slip"; // Keep specific name
        $this->description = $langs->trans('WarrantySlipFromExpedition'); // Keep specific description
        $this->update_main_doc_field = 1; // From bon_garantie

        $this->type = 'pdf';
        $formatarray = pdf_getFormat();
        $this->page_largeur = $formatarray['width'];
        $this->page_hauteur = $formatarray['height'];
        $this->format = array($this->page_largeur, $this->page_hauteur);
        $this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
        $this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
        $this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
        $this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
        $this->corner_radius = getDolGlobalInt('MAIN_PDF_FRAME_CORNER_RADIUS', 0);

        // Options from bon_garantie (some may not be relevant but included for structural similarity)
        $this->option_logo = 0; // Display logo (already handled by parent, but for clarity)
        $this->option_multilang = 1;
        $this->option_freetext = 1;
        $this->option_draft_watermark = 1; // Might apply if shipments can be drafts
        $this->watermark = '';

        if ($mysoc === null) {
			dol_syslog(get_class($this).'::__construct() Global $mysoc should not be null.'. getCallerInfoString(), LOG_ERR);
			// return; // Cannot return from constructor directly in PHP < 7
		}

        $this->emetteur = $mysoc; // Ensure emetteur is set
		if (empty($this->emetteur->country_code) && !empty($langs->defaultlang)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2);
		}

        $this->posxdesc = $this->marge_gauche + 1;
        $this->tabTitleHeight = 5; // default height from bon_garantie

        // Vars for totals - likely not used for shipping slip warranty, but for structure
        $this->tva = array();
        $this->tva_array = array();
        $this->localtax1 = array();
        $this->localtax2 = array();
        $this->atleastoneratenotnull = 0; // For VAT, not relevant here
        $this->atleastonediscount = 0; // For discounts, not relevant here
    }

/**
 * Function to build PDF onto disk.
 * Heavily adapted from pdf_bon_garantie::write_file()
 *
 * @param Expedition $object Object to generate (Expedition type)
 * @param Translate $outputlangs Lang output object
 * @param string $srctemplatepath Full path of source filename for generator using a template file
 * @param int $hidedetails Do not show line details
 * @param int $hidedesc Do not show desc
 * @param int $hideref Do not show ref
 * @return int 1 if OK, <=0 if KO
 */
public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
{
    global $user, $langs, $conf, $mysoc, $db, $hookmanager;

    dol_syslog(get_class($this)."::write_file outputlangs->defaultlang=" . (is_object($outputlangs) ? $outputlangs->defaultlang : 'null'));

    if (!is_object($outputlangs)) {
        $outputlangs = $langs;
    }
    if (getDolGlobalInt('MAIN_USE_FPDF')) { // For backward compatibility with FPDF
        $outputlangs->charset_output = 'ISO-8859-1';
    }

    $langs->loadLangs(array("main", "dict", "companies", "sendings", "products", "orders", "mrp")); // Adapted for sendings

    // Ensure $this->emetteur is set (already done in constructor, but defensive)
    if (empty($this->emetteur)) $this->emetteur = $mysoc;


    // Draft watermark (if expeditions can have a draft status)
    // Example: Check $object->statut if Expedition object has a status field for drafts
    // if (isset($object->statut) && $object->statut == Expedition::STATUS_DRAFT && getDolGlobalString('EXPEDITION_DRAFT_WATERMARK')) {
    //    $this->watermark = getDolGlobalString('EXPEDITION_DRAFT_WATERMARK');
    // }


    global $outputlangsbis; // For dual language output
    $outputlangsbis = null;
    if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE')) {
        $outputlangsbis = new Translate('', $conf);
        $outputlangsbis->setDefaultLang(getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE'));
        $outputlangsbis->loadLangs(array("main", "dict", "companies", "sendings", "products", "orders", "mrp"));
    }

    // Fetch thirdparty if not already an object (Expedition object should have it via socid)
    if (empty($object->thirdparty) && !empty($object->socid) && $object->socid > 0) {
        $object->fetch_thirdparty();
    }
    // Fetch lines if not already populated (Expedition object method to fetch lines is typically fetch_lines())
    if (empty($object->lines) && method_exists($object, 'fetch_lines')) {
         $object->fetch_lines(); // This populates $object->lines as an array of line objects
    }
    $nblines = (is_array($object->lines) ? count($object->lines) : 0);


    $hidetop = getDolGlobalInt('MAIN_PDF_DISABLE_COL_HEAD_TITLE');

    // Loop to detect if there is at least one image (copied from bon_garantie)
    $realpatharray = array();
    $this->atleastonephoto = false;
    if (getDolGlobalInt('MAIN_GENERATE_SHIPMENTS_WITH_PICTURE')) { // Config for shipments
        $objphoto = new Product($this->db);
        for ($i = 0; $i < $nblines; $i++) {
            // Ensure $object->lines[$i] is an object and has fk_product
            if (empty($object->lines[$i]) || !is_object($object->lines[$i]) || empty($object->lines[$i]->fk_product)) continue;

            $pdir = array();
            $objphoto->fetch($object->lines[$i]->fk_product);
            if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
                $pdir[0] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product') . $objphoto->id . "/photos/";
                $pdir[1] = get_exdir(0, 0, 0, 0, $objphoto, 'product') . dol_sanitizeFileName($objphoto->ref) . '/';
            } else {
                $pdir[0] = get_exdir(0, 0, 0, 0, $objphoto, 'product');
                $pdir[1] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product') . $objphoto->id . "/photos/";
            }

            $arephoto = false;
            $current_realpath = null;
            foreach ($pdir as $midir) {
                if (!$arephoto) {
                    $dir_photo = ($conf->entity != $objphoto->entity && !empty($conf->product->multidir_output[$objphoto->entity])) ? $conf->product->multidir_output[$objphoto->entity] . '/' . $midir : $conf->product->dir_output . '/' . $midir;
                    foreach ($objphoto->liste_photos($dir_photo, 1) as $key => $obj_photo_file) {
                        $filename = (!getDolGlobalInt('CAT_HIGH_QUALITY_IMAGES') && !empty($obj_photo_file['photo_vignette'])) ? $obj_photo_file['photo_vignette'] : $obj_photo_file['photo'];
                        $current_realpath = $dir_photo . $filename;
                        $arephoto = true;
                        $this->atleastonephoto = true;
                        break; 
                    }
                }
                 if ($arephoto) break;
            }
            if ($current_realpath && $arephoto) {
                $realpatharray[$i] = $current_realpath;
            }
        }
    }
    // Set photo column status based on atleastonephoto & global config
    if (isset($this->cols['photo'])) {
        $this->cols['photo']['status'] = $this->atleastonephoto && getDolGlobalInt('MAIN_GENERATE_SHIPMENTS_WITH_PICTURE');
        // $this->buildColumnField(); // Rebuild if status changed - careful with recursion if called inside defineColumnField
    }


    // Definition of $dir and $file (adapted for Expedition)
    $objectref = dol_sanitizeFileName($object->ref);
    if (!empty($object->specimen)) { 
        $dir = getMultidirOutput($object); 
        $file = $dir . "/SPECIMEN_garantie.pdf";
    } else {
        $dir = getMultidirOutput($object) . "/" . $objectref;
        $file = $dir . "/" . $objectref . "_garantie.pdf"; 
    }

    if (!file_exists($dir)) {
        if (dol_mkdir($dir) < 0) {
            $this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
            return 0;
        }
    }

    if (file_exists($dir)) {
        if (!is_object($hookmanager)) {
            include_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
            $hookmanager = new HookManager($this->db);
        }
        $hookmanager->initHooks(array('pdfgeneration'));
        $parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
        global $action; 
        $reshook = $hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);
        $nblines = (is_array($object->lines) ? count($object->lines) : 0); 


        $pdf = pdf_getInstance($this->format);
        $default_font_size = pdf_getPDFFontSize($outputlangs);
        $pdf->SetAutoPageBreak(1, 0); 

        $heightforfreetext = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5);
        $heightforfooter = $this->marge_basse + 8;
        if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS')) $heightforfooter += 6;


        if (class_exists('TCPDF')) {
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
        }
        $pdf->SetFont(pdf_getPDFFont($outputlangs));

        $tplidx = null; 
        if (getDolGlobalString('MAIN_ADD_PDF_BACKGROUND')) {
            $logodir_bg = $conf->mycompany->dir_output;
            if (!empty($conf->mycompany->multidir_output[$object->entity])) $logodir_bg = $conf->mycompany->multidir_output[$object->entity];
            $pagecount = $pdf->setSourceFile($logodir_bg . '/' . getDolGlobalString('MAIN_ADD_PDF_BACKGROUND'));
            $tplidx = $pdf->importPage(1);
        }

        $pdf->Open();
        $pagenb = 0;
        $pdf->SetDrawColor(128, 128, 128);

        $pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
        $pdf->SetSubject($outputlangs->transnoentities("WarrantySlip")); 
        $pdf->SetCreator("Dolibarr " . DOL_VERSION);
        $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
        $pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref) . " " . $outputlangs->transnoentities("WarrantySlip") . " " . (is_object($object->thirdparty)?$outputlangs->convToOutputCharset($object->thirdparty->name):''));
        if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) $pdf->SetCompression(false);

        $pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);


        $pdf->AddPage();
        if (!empty($tplidx)) $pdf->useTemplate($tplidx);
        $pagenb++;
        $pagehead_data = $this->_pagehead($pdf, $object, 1, $outputlangs, $outputlangsbis);
        $top_shift_from_header = $pagehead_data['top_shift'] ?? 0;
        $pagehead_end_y = $pagehead_data['pagehead_end_y'] ?? ($this->marge_haute + 80); 

        $pdf->SetFont('', '', $default_font_size - 1);
        $pdf->MultiCell(0, 3, ''); 
        $pdf->SetTextColor(0, 0, 0);
        
        $tab_top = $pagehead_end_y + 5; // Position table after header elements + small gap
        if ($tab_top < $this->marge_haute + 80) $tab_top = $this->marge_haute + 80; // Minimum top for table

        $tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? ($top_shift_from_header > 0 ? $top_shift_from_header : $this->marge_haute) : $this->marge_haute) +5; 
        if (!$hidetop && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
            $tab_top_newpage += $this->tabTitleHeight;
        }
        
        $tab_height = $this->page_hauteur - $tab_top - $heightforfooter - $heightforfreetext;

        $notetoshow = empty($object->note_public) ? '' : $object->note_public;
        $extranote = $this->getExtrafieldsInHtml($object, $outputlangs);
        if (!empty($extranote)) $notetoshow = dol_concatdesc($notetoshow, $extranote);

        if ($notetoshow) {
            $current_page_for_note = $pdf->getPage();
            $pdf->SetFont('', '', $default_font_size - 1);
            $posy_current_for_note = $pdf->GetY();
            // Ensure note starts close to where table would, or after header.
            $posy_before_note = max($posy_current_for_note, $pagehead_end_y + 2); 
            if ($posy_before_note < $tab_top - 10) $posy_before_note = $tab_top -10;


            $substitutionarray = pdf_getSubstitutionArray($outputlangs, null, $object);
            complete_substitutions_array($substitutionarray, $outputlangs, $object); 
            $notetoshow_substituted = make_substitutions($notetoshow, $substitutionarray, $outputlangs);
            $notetoshow_substituted = convertBackOfficeMediasLinksToPublicLinks($notetoshow_substituted);

            $height_note = pdf_write_html_free_text($pdf, $posy_before_note, 0, $this->posxdesc-1, $outputlangs, $notetoshow_substituted, false, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $this->corner_radius);
            
            if ($pdf->getPage() > $current_page_for_note) { 
                if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
                     $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
                }
                $tab_top = $tab_top_newpage; 
            } else {
                 $tab_top = $posy_before_note + $height_note + 3; 
            }
            $tab_height = $this->page_hauteur - $tab_top - $heightforfooter - $heightforfreetext; 
        }

        $this->defineColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);
        // Re-set photo column status after defineColumnField might have reset it
        if (isset($this->cols['photo'])) {
            $this->cols['photo']['status'] = $this->atleastonephoto && getDolGlobalInt('MAIN_GENERATE_SHIPMENTS_WITH_PICTURE');
            $this->buildColumnField(); 
        }


        $stamp_path = $conf->mycompany->dir_output . '/logos/stamp.png';
        if (is_readable($stamp_path)) {
            $stamp_width = 70; $stamp_height = 60;
            $stamp_x = $this->page_largeur - $this->marge_droite - $stamp_width - 5; // Adjusted X
            $stamp_y = $tab_top > $pagehead_end_y + 5 ? $tab_top -5 : $pagehead_end_y + 5 ; // Position it carefully
            if ($stamp_y + $stamp_height > $this->page_hauteur - $heightforfooter) { // Avoid overlap with footer
                 $stamp_y = $this->page_hauteur - $heightforfooter - $stamp_height -2;
            }
            $pdf->setAlpha(0.6); // Adjusted alpha
            $pdf->Image($stamp_path, $stamp_x, $stamp_y, $stamp_width, $stamp_height, 'PNG');
            $pdf->setAlpha(1);
        }

        $pdf->startTransaction();
        $this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);
        $pdf->rollbackTransaction(true); 
        $nexY = $tab_top + $this->tabTitleHeight;

        $pageposbeforeprintlines = $pdf->getPage();
        for ($i = 0; $i < $nblines; $i++) {
            $curY = $nexY;
            $line = $object->lines[$i]; 

            if (empty($line->product_label) && !empty($line->fk_product)) {
                $main_line_product = new Product($this->db);
                if ($main_line_product->fetch($line->fk_product) > 0) {
                    $line->product_label = $main_line_product->label;
                    if(isset($object->lines[$i]) && is_object($object->lines[$i])) $object->lines[$i]->product_label = $line->product_label; 
                }
            }
            
            $extracted_mo_ref = null;
            $target_bom_id = null; 
            $current_line_mo_ref_for_serial = '';

            if (isset($line->fk_product) && $line->fk_product == 483) { 
                $line_description = isset($line->description) ? $line->description : (isset($line->product_desc) ? $line->product_desc : '');
                if (preg_match('/([A-Za-z0-9-]+-?\d+)(?: \(Fabrication\))?/', $line_description, $matches)) {
                    $extracted_mo_ref = $matches[1];
                    $current_line_mo_ref_for_serial = $extracted_mo_ref;
                    $mo = new Mo($this->db);
                    if ($mo->fetch(0, $extracted_mo_ref) > 0) {
                        $target_bom_id = $mo->fk_bom; 
                    } else {
                        dol_syslog(get_class($this).": Failed to fetch MO with ref: " . $extracted_mo_ref, LOG_ERR);
                    }
                } else {
                    dol_syslog(get_class($this).": Could not extract MO ref from desc: '" . $line_description . "' for product line " . $i, LOG_WARNING);
                }
            }

            $lineheight_pdf = 4; 
            $estimated_line_plus_bom_height = $lineheight_pdf;
            if (isset($line->fk_product) && $line->fk_product == 483 && !empty($target_bom_id)) {
                 $estimated_line_plus_bom_height += 5; 
                 $sql_count_bom = "SELECT COUNT(*) as num_comp FROM ".MAIN_DB_PREFIX."bom_bomline WHERE fk_bom = ".(int)$target_bom_id;
                 $res_count = $this->db->query($sql_count_bom); // Changed to $this->db->query
                 if ($res_count) {
                     $obj_count = $this->db->fetch_object($res_count);
                     if($obj_count) $estimated_line_plus_bom_height += ($obj_count->num_comp * $lineheight_pdf);
                     $this->db->free($res_count);
                 }
            }
            
            if (($curY + $estimated_line_plus_bom_height) > ($this->page_hauteur - $heightforfooter - 5)) {
                 $pdf->AddPage();
                 if (!empty($tplidx)) $pdf->useTemplate($tplidx);
                 $pagenb++;
                 $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
                 $this->pdfTabTitles($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter - $heightforfreetext, $outputlangs, $hidetop); 
                 $curY = $tab_top_newpage + $this->tabTitleHeight; 
            }

            $this->resetAfterColsLinePositionsData($curY, $pdf->getPage());
            $pdf->SetFont('', '', $default_font_size - 1);
            $pdf->SetTextColor(0, 0, 0);

            $imglinesize = array();
            if (!empty($realpatharray[$i])) {
                $imglinesize = pdf_getSizeForImage($realpatharray[$i]);
            }
             if ($this->getColumnStatus('photo') && !empty($this->cols['photo'])) {
                $imageTopMargin = 1;
                if (isset($imglinesize['width']) && isset($imglinesize['height'])) { // Check if image size is valid
                    if (($curY + $imageTopMargin + $imglinesize['height']) > ($this->page_hauteur - $heightforfooter) && $pagenb == $pdf->getNumPages()) {
                        // Image won't fit, add new page. This might be redundant if main page break logic catches it.
                         $pdf->AddPage(); if (!empty($tplidx)) $pdf->useTemplate($tplidx); $pagenb++;
                         $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
                         $this->pdfTabTitles($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter - $heightforfreetext, $outputlangs, $hidetop);
                         $curY = $tab_top_newpage + $this->tabTitleHeight;
                    }
                    $pdf->Image($realpatharray[$i], $this->getColumnContentXStart('photo'), $curY + $imageTopMargin, $imglinesize['width'], $imglinesize['height'], '', '', '', 2, 300);
                    $this->setAfterColsLinePositionsData('photo', $curY + $imageTopMargin + $imglinesize['height'], $pdf->getPage());
                }
            }


            if ($this->getColumnStatus('position')) {
                $this->printStdColumnContent($pdf, $curY, 'position', strval($i + 1));
            }
            if ($this->getColumnStatus('desc')) {
                $desc_content = !empty($line->product_label) ? $line->product_label : (!empty($line->description) ? $line->description : (!empty($line->desc) ? $line->desc : ''));
                $colKey = 'desc'; $line_height_cell = 4;
                $x_start_cell = $this->marge_gauche; 
                $col_width_cell = $this->cols[$colKey]['content']['width'] ?? $this->cols[$colKey]['width'];
                $align_cell = $this->cols[$colKey]['content']['align'] ?? 'L';
                $padding_cell = $this->cols[$colKey]['content']['padding'] ?? array(0.5, 0.5, 0.5, 0.5);

                $pdf->SetXY($x_start_cell + $padding_cell[3], $curY + $padding_cell[0]);
                $pdf->MultiCell($col_width_cell - $padding_cell[1] - $padding_cell[3], $line_height_cell, $outputlangs->convToOutputCharset($desc_content), 0, $align_cell, 0, 1, '', '', true, 0, false, true, $line_height_cell, 'M');
                $this->setAfterColsLinePositionsData($colKey, $pdf->GetY(), $pdf->getPage());
            }
            if ($this->getColumnStatus('qty')) {
                $qty_to_show = isset($line->qty_shipped) ? $line->qty_shipped : (isset($line->qty) ? $line->qty : 0);
                $this->printStdColumnContent($pdf, $curY, 'qty', $qty_to_show);
            }
            if ($this->getColumnStatus('serialnumber')) {
                $sn_to_show = $this->getLineSerialNumber($object, $i);
                if (isset($line->fk_product) && $line->fk_product == 483 && !empty($current_line_mo_ref_for_serial)) { 
                    $sn_to_show = $current_line_mo_ref_for_serial;
                }
                $this->printStdColumnContent($pdf, $curY, 'serialnumber', $sn_to_show);
            }
            if ($this->getColumnStatus('garantie')) {
                $this->printStdColumnContent($pdf, $curY, 'garantie', $this->getLineGarantie($object, $i));
            }
            if (isset($line->array_options) && is_array($line->array_options) && !empty($line->array_options)) {
                foreach ($line->array_options as $extrafieldColKey => $extrafieldValue) {
                    if ($this->getColumnStatus($extrafieldColKey)) {
                        $extrafieldValueContent = $this->getExtrafieldContent($line, $extrafieldColKey, $outputlangs);
                        $this->printStdColumnContent($pdf, $curY, $extrafieldColKey, $extrafieldValueContent);
                        $this->setAfterColsLinePositionsData('options_' . $extrafieldColKey, $pdf->GetY(), $pdf->getPage());
                    }
                }
            }

            $bom_components_y_start = $this->getMaxAfterColsLinePositionsData()['y'] + 1; 
            if (isset($line->fk_product) && $line->fk_product == 483 && !empty($target_bom_id)) { // Check $target_bom_id directly
                $line_height_bom_content = 3; // Smaller line height for components
                
                $pdf->SetFont('', 'B', $default_font_size - 2); // Smaller bold for title
                $pdf->SetXY($this->marge_gauche + 2, $bom_components_y_start); // Indent title slightly
                $pdf->MultiCell(0, $line_height_bom_content +1, $outputlangs->transnoentities("MOBOMComponentsTitle", "Composants de l'OF " . $extracted_mo_ref . ":"), 0, 'L');
                $bom_components_y_start += $line_height_bom_content + 2; 
                $pdf->SetFont('', '', $default_font_size - 2); // Smaller normal for components

                $bom_components_from_bom_table = array();
                $sql_get_bom_lines = "SELECT p.rowid as fk_product, p.ref, p.label, bbl.qty";
                $sql_get_bom_lines .= " FROM ".MAIN_DB_PREFIX."bom_bomline as bbl";
                $sql_get_bom_lines .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = bbl.fk_product";
                $sql_get_bom_lines .= " WHERE bbl.fk_bom = ".(int)$target_bom_id;
                $sql_get_bom_lines .= " ORDER BY bbl.position ASC";

                $resql_bom_lines = $this->db->query($sql_get_bom_lines);
                if ($resql_bom_lines) {
                    while ($bom_line_row = $this->db->fetch_object($resql_bom_lines)) {
                        $bom_components_from_bom_table[] = $bom_line_row;
                    }
                    $this->db->free($resql_bom_lines);
                } else {
                     dol_syslog(get_class($this).": DB error fetching BOM lines for BOM ID: " . $target_bom_id . " - " . $this->db->lasterror(), LOG_ERR);
                }

                if (!empty($bom_components_from_bom_table)) {
                    foreach ($bom_components_from_bom_table as $bom_component) {
                        if ($bom_components_y_start + $line_height_bom_content > ($this->page_hauteur - $heightforfooter - 8)) { 
                            $pdf->AddPage(); if (!empty($tplidx)) $pdf->useTemplate($tplidx); $pagenb++;
                            $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
                            $this->pdfTabTitles($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter - $heightforfreetext, $outputlangs, $hidetop);
                            $bom_components_y_start = $tab_top_newpage + $this->tabTitleHeight + 1;
                            $pdf->SetFont('', 'B', $default_font_size - 2);
                            $pdf->SetXY($this->marge_gauche + 2, $bom_components_y_start);
                            $pdf->MultiCell(0, $line_height_bom_content+1, $outputlangs->transnoentities("MOBOMComponentsTitle", "Composants de l'OF " . $extracted_mo_ref . ":"), 0, 'L');
                            $bom_components_y_start += $line_height_bom_content + 2;
                            $pdf->SetFont('', '', $default_font_size - 2);
                        }
                        $component_desc_text = "- " . $bom_component->ref . " - " . dol_trunc($bom_component->label,35) . " (x" . (isset($bom_component->qty) ? round((float)$bom_component->qty, 2) : 0) . ")";
                        $pdf->SetXY($this->marge_gauche + 5, $bom_components_y_start); 
                        $pdf->MultiCell($this->cols['desc']['width'] - 10, $line_height_bom_content, $outputlangs->convToOutputCharset($component_desc_text), 0, 'L');
                        $bom_components_y_start += $line_height_bom_content;
                    }
                    $this->setAfterColsLinePositionsData('bom_components_list', $bom_components_y_start, $pdf->getPage());
                }
            }

            $afterPosData = $this->getMaxAfterColsLinePositionsData();
            $parameters_line = array('object' => $object, 'i' => $i, 'pdf' => &$pdf, 'curY' => &$curY, 'nexY' => &$afterPosData['y'], 'outputlangs' => $outputlangs, 'hidedetails' => $hidedetails);
            if(is_object($hookmanager)) $reshook_line = $hookmanager->executeHooks('printPDFline', $parameters_line, $this); 

            $pdf->setPage($afterPosData['page']);
            $nexY = $afterPosData['y'];

            if (getDolGlobalString('MAIN_PDF_DASH_BETWEEN_LINES') && $i < ($nblines - 1) && $nexY < $this->page_hauteur - $heightforfooter - 5) {
                $pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(80, 80, 80)));
                $pdf->line($this->marge_gauche, $nexY + 0.5, $this->page_largeur - $this->marge_droite, $nexY + 0.5); // Adjusted Y for dash
                $pdf->SetLineStyle(array('dash' => 0));
            }
            $nexY += 1; // Space before next line
        } 


        $afterPosDataFinal = $this->getMaxAfterColsLinePositionsData();
        if (isset($afterPosDataFinal['y']) && $afterPosDataFinal['y'] > $this->page_hauteur - ($heightforfooter + $heightforfreetext + 5)) { 
            $pdf->AddPage(); if (!empty($tplidx)) $pdf->useTemplate($tplidx); $pagenb++;
            $pdf->setPage($pagenb); // Ensure current page is the new one
             if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) { // Redraw head if needed and not repeating
                $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
            }
            // Redraw titles on new page if table continues
            $this->pdfTabTitles($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter - $heightforfreetext, $outputlangs, $hidetop);
        }

        $drawTabNumbPage = $pdf->getNumPages();
        for ($k = $pageposbeforeprintlines; $k <= $drawTabNumbPage; $k++) {
            $pdf->setPage($k);
            // $pdf->setPageOrientation('', 0, 0); // Set before _tableau, but _tableau might reset margins

            $drawTabHideTopCurrentPage = $hidetop;
            $currentDrawTabTop = ($k == $pageposbeforeprintlines) ? $tab_top : $tab_top_newpage;
            
            // If not first page of lines and titles are repeated, ensure tab_top_newpage includes title height
            if ($k > $pageposbeforeprintlines && !$hidetop && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
                 // $currentDrawTabTop is already $tab_top_newpage which should include title height
            } else if ($k > $pageposbeforeprintlines) { // Not first page, and either hidetop or not repeating titles
                 $drawTabHideTopCurrentPage = 1; // Force hide titles if not repeating or explicitly hidden
            }


            $currentDrawTabBottom = $this->page_hauteur - $heightforfooter;
            if ($k == $pdf->getNumPages()) { 
                $currentDrawTabBottom -= $heightforfreetext; 
            }
            $currentDrawTabHeight = $currentDrawTabBottom - $currentDrawTabTop;
            
            // Draw the table frame and titles for the current page segment
            $this->_tableau($pdf, $currentDrawTabTop, $currentDrawTabHeight, 0, $outputlangs, $drawTabHideTopCurrentPage, 0, $conf->currency, $outputlangsbis);

            $hideFreeTextForPage = ($k != $pdf->getNumPages()) ? 1 : 0; 
            $this->_pagefoot($pdf, $object, $outputlangs, $hideFreeTextForPage);

            $pdf->setPage($k); // Ensure we are on the correct page for header/template
            // $pdf->setPageOrientation('', 1, 0); // Potentially restore for header/template
            if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') && $k > $pageposbeforeprintlines ) { // Check k > pageposbeforeprintlines
                $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
            }
            if (!empty($tplidx)) $pdf->useTemplate($tplidx); 
        }

        $pdf->SetTextColor(0,0,0);
        if ($pdf->getPage() != $drawTabNumbPage) $pdf->setPage($drawTabNumbPage); // Ensure on last page

        if (method_exists($pdf, 'AliasNbPages')) $pdf->AliasNbPages();
        $pdf->Close();
        $pdf->Output($file, 'F');

        if(is_object($hookmanager)) {
            $hookmanager->initHooks(array('pdfgeneration')); 
            $parameters_after = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
            $reshook_after = $hookmanager->executeHooks('afterPDFCreation', $parameters_after, $object, $action);
            if ($reshook_after < 0) { $this->error = $hookmanager->error; $this->errors = $hookmanager->errors; }
        }

        dolChmod($file);
        $this->result = array('fullpath' => $file);

        if (!empty($this->update_main_doc_field) && method_exists($this, 'record_generated_document')) {
            $this->record_generated_document($object, $outputlangs, $file);
        }
        return 1;

    } else { 
        $this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
        return 0;
    }

/**
 *   	Show table for lines (Copied from pdf_bon_garantie)
 *
 *   	@param		TCPDF		$pdf     		Object PDF
 *   	@param		float|int	$tab_top		Top position of table
 *   	@param		float|int	$tab_height		Height of table (rectangle)
 *   	@param		int			$nexY			Y (not used in this version of _tableau)
 *   	@param		Translate	$outputlangs	Langs object
 *   	@param		int			$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
 *   	@param		int			$hidebottom		Hide bottom bar of array
 *   	@param		string		$currency		Currency code
 *   	@param		Translate	$outputlangsbis	Langs object bis
 *   	@return	void
 */
protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '', $outputlangsbis = null)
{
    global $conf;

    $hidebottom = 0;
    if ($hidetop && $hidetop !== -1) { // if hidetop is 1 (true), treat as -1 for bon_garantie logic
        $hidetop = -1; 
    }


    $currency = !empty($currency) ? $currency : $conf->currency;
    $default_font_size = pdf_getPDFFontSize($outputlangs);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('', '', $default_font_size - 2);
    if (empty($hidetop)) { 
        // Amount in currency text - commented out as not relevant for warranty slip
        /*
        $titreAmountIn = $outputlangs->transnoentities("AmountInCurrency", $outputlangs->transnoentitiesnoconv("Currency".$currency));
        if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
            $titreAmountIn .= ' - '.$outputlangsbis->transnoentities("AmountInCurrency", $outputlangsbis->transnoentitiesnoconv("Currency".$currency));
        }
        $pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titreAmountIn) + 3), $tab_top - 4);
        $pdf->MultiCell(($pdf->GetStringWidth($titreAmountIn) + 3), 2, $titreAmountIn);
        */

        if (getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')) {
            $pdf->RoundedRect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, $this->corner_radius, '1001', 'F', null, explode(',', getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')));
        }
    }

    $pdf->SetDrawColor(128, 128, 128); 
    $pdf->SetFont('', '', $default_font_size - 1); 

    $this->printRoundedRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $this->corner_radius, ($hidetop == -1 ? 0 : $hidetop), $hidebottom, 'D'); // if hidetop is -1, we still want the top line of the rect

    // Draw titles (column headers)
    // If hidetop is -1, pdfTabTitles should still draw titles but without the background fill (handled by RoundedRect above)
    $this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);


    if (empty($hidetop) || $hidetop == -1) { // Draw line under titles if titles are visible or only text is hidden
        $pdf->line($this->marge_gauche, $tab_top + $this->tabTitleHeight, $this->page_largeur - $this->marge_droite, $tab_top + $this->tabTitleHeight);
    }

    /**
     * Build array this->cols with all parameters (position, width, title, content)
     *
     * @return void
     */
    protected function buildColumnField()
    {
        $this->cols = array_filter($this->cols, function($col) { return !empty($col['status']); }); // Remove disabled cols
        uasort($this->cols, function($a, $b) { return $a['rank'] <=> $b['rank']; }); // Sort cols by rank

        $totalWidth = 0;
        foreach ($this->cols as $key => $col) {
            if (!empty($col['status'])) {
                $totalWidth += $col['width'];
            }
        }

        $pageWidthWithoutMargins = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
        $descColKey = null;
        $descColWidth = 0;

        // Find desc column to make it flexible
        foreach ($this->cols as $key => $col) {
            if ($key == 'desc' || (isset($col['isdesc']) && $col['isdesc'])) { // 'desc' or a column marked as description
                $descColKey = $key;
                $descColWidth = $col['width'];
                break;
            }
        }

        if ($descColKey && $totalWidth != $pageWidthWithoutMargins) {
            $newDescWidth = $descColWidth + ($pageWidthWithoutMargins - $totalWidth);
            if ($newDescWidth > 0) {
                $this->cols[$descColKey]['width'] = $newDescWidth;
            }
        }

        // Set Position X for each column
        $currentX = $this->marge_gauche;
        foreach ($this->cols as $key => &$col) { // Use reference to modify directly
            if (empty($col['status'])) continue;

            $col['posX'] = $currentX; // Set the starting X position for the column
            $currentX += $col['width'];

            // Set default title properties if not defined
            if (!isset($col['title']['textkey']) && !isset($col['title']['label'])) {
                $col['title']['label'] = ''; // Default empty label if no key or label
            }
            if (!isset($col['title']['align'])) {
                $col['title']['align'] = $this->defaultTitlesFieldsStyle['align'] ?? 'C';
            }
            if (!isset($col['title']['padding'])) {
                $col['title']['padding'] = $this->defaultTitlesFieldsStyle['padding'] ?? array(0.5,0,0.5,0);
            }

            // Set default content properties if not defined
            if (!isset($col['content']['align'])) {
                $col['content']['align'] = $this->defaultContentsFieldsStyle['align'] ?? 'L'; // Default L for content
            }
            if (!isset($col['content']['padding'])) {
                $col['content']['padding'] = $this->defaultContentsFieldsStyle['padding'] ?? array(1,0.5,1,0.5);
            }
        }
        unset($col); // Unset reference
    }
}
}


/**
 *   	Show table for lines (Copied from pdf_bon_garantie)
 *
 *   	@param		TCPDF		$pdf     		Object PDF
 *   	@param		float|int	$tab_top		Top position of table
 *   	@param		float|int	$tab_height		Height of table (rectangle)
 *   	@param		int			$nexY			Y (not used in this version of _tableau)
 *   	@param		Translate	$outputlangs	Langs object
 *   	@param		int			$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
 *   	@param		int			$hidebottom		Hide bottom bar of array
 *   	@param		string		$currency		Currency code
 *   	@param		Translate	$outputlangsbis	Langs object bis
 *   	@return	void
 */
protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '', $outputlangsbis = null)
{
    global $conf;

    $hidebottom = 0;
    if ($hidetop && $hidetop !== -1) { // if hidetop is 1 (true), treat as -1 for bon_garantie logic
        $hidetop = -1; 
    }


    $currency = !empty($currency) ? $currency : $conf->currency;
    $default_font_size = pdf_getPDFFontSize($outputlangs);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('', '', $default_font_size - 2);
    if (empty($hidetop)) { 
        // Amount in currency text - commented out as not relevant for warranty slip
        /*
        $titreAmountIn = $outputlangs->transnoentities("AmountInCurrency", $outputlangs->transnoentitiesnoconv("Currency".$currency));
        if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
            $titreAmountIn .= ' - '.$outputlangsbis->transnoentities("AmountInCurrency", $outputlangsbis->transnoentitiesnoconv("Currency".$currency));
        }
        $pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titreAmountIn) + 3), $tab_top - 4);
        $pdf->MultiCell(($pdf->GetStringWidth($titreAmountIn) + 3), 2, $titreAmountIn);
        */

        if (getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')) {
            $pdf->RoundedRect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, $this->corner_radius, '1001', 'F', null, explode(',', getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')));
        }
    }

    $pdf->SetDrawColor(128, 128, 128); 
    $pdf->SetFont('', '', $default_font_size - 1); 

    $this->printRoundedRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $this->corner_radius, ($hidetop == -1 ? 0 : $hidetop), $hidebottom, 'D'); // if hidetop is -1, we still want the top line of the rect

    // Draw titles (column headers)
    // If hidetop is -1, pdfTabTitles should still draw titles but without the background fill (handled by RoundedRect above)
    $this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);


    if (empty($hidetop) || $hidetop == -1) { // Draw line under titles if titles are visible or only text is hidden
        $pdf->line($this->marge_gauche, $tab_top + $this->tabTitleHeight, $this->page_largeur - $this->marge_droite, $tab_top + $this->tabTitleHeight);
    }
}
/**
 * Show top header of page.
 * Adapted from pdf_bon_garantie.modules.php
 *
 * @param TCPDF       $pdf            Object PDF
 * @param Expedition  $object         Object to show (Note: Type is Expedition here)
 * @param int         $showaddress    0=no, 1=yes
 * @param Translate   $outputlangs    Object lang for output
 * @param Translate   $outputlangsbis Object lang for output bis
 * @param string      $titlekey       Translation key to show as title of document
 * @return array<string, int|float>   top shift of linked object lines
 */
protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null, $titlekey = "WarrantySlip") // Changed titlekey
{
    global $conf, $langs, $hookmanager, $mysoc;

    $ltrdirection = 'L';
    if ($outputlangs->trans("DIRECTION") == 'rtl') {
        $ltrdirection = 'R';
    }

    // Load translation files required by page
    $outputlangs->loadLangs(array("main", "bills", "sendings", "orders", "companies")); // Adapted for sendings

    $default_font_size = pdf_getPDFFontSize($outputlangs);

    pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

    $pdf->SetTextColor(0, 0, 60);
    $pdf->SetFont('', 'B', $default_font_size + 3);

    $posy = $this->marge_haute;
    $posx = $this->marge_gauche;
    $page_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

    // ===== LEFT COLUMN =====

    // Company Logo Section (aligned left)
    if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
        if ($this->emetteur->logo) {
            $logodir = $conf->mycompany->dir_output;
            if (!empty(getMultidirOutput($mysoc, 'mycompany'))) {
                $logodir = getMultidirOutput($mysoc, 'mycompany');
            }
            // Use large logo path from pdf_bon_garantie
            $logo = !getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') ? $logodir . '/logos/thumbs/' . $this->emetteur->logo_small : $logodir . '/logos/' . $this->emetteur->logo;
            if (is_readable($logo)) {
                $height = pdf_getHeightForLogo($logo);
                $logo_width = 70; // Increased size for bigger logo from bon_garantie
                $pdf->Image($logo, $this->marge_gauche, $posy, $logo_width, $height); // Aligned left
                $posy += $height + 8; // Added more spacing after logo
            } else {
                $pdf->SetTextColor(200, 0, 0);
                $pdf->SetFont('', 'B', $default_font_size - 2);
                $pdf->SetXY($this->marge_gauche, $posy);
                $pdf->MultiCell($page_width / 2, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
                $pdf->MultiCell($page_width / 2, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L'); // Corrected this line
                $posy += 15; // Increased spacing
            }
        } else {
            // Company name if no logo (from bon_garantie)
            $pdf->SetFont('', 'B', $default_font_size + 2);
            $pdf->SetXY($this->marge_gauche, $posy);
            $pdf->MultiCell($page_width / 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
            $posy += 8;
        }
    }


    // Company Information (from bon_garantie)
    $pdf->SetFont('', 'B', $default_font_size);
    $pdf->SetXY($this->marge_gauche, $posy);
    $pdf->MultiCell($page_width / 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
    $posy += 5;

    // Website URL (from bon_garantie)
    $pdf->SetFont('', '', $default_font_size - 1);
    $pdf->SetXY($this->marge_gauche, $posy);
    $pdf->MultiCell($page_width / 2, 4, 'www.informatics-dz.com', 0, 'L'); // Hardcoded as in bon_garantie
    $posy += 5;


    // Add full-width box with the new text (from bon_garantie)
    $pdf->SetFillColor(230, 230, 230); // Light gray background
    $pdf->Rect($this->marge_gauche, $posy, $page_width, 10, 'F'); // Full width box

    $pdf->SetFont('aealarabiya', '', $default_font_size + 2);
    $pdf->SetXY($this->marge_gauche, $posy + 2);
    $pdf->MultiCell($page_width, 5, $outputlangs->convToOutputCharset('CERTIFICAT DE GARANTIE                     الضمان شهادة'), 0, 'C');
    $posy += 15; // Space after title box

    // ===== RIGHT COLUMN =====
    $w = 100; // Width for right column content
    $posx_right = $this->page_largeur - $this->marge_droite - $w; // Start X for right column
    $right_posy = $this->marge_haute; // Initial Y for right column

    // Document title and reference
    $pdf->SetFont('', 'B', $default_font_size + 3);
    $pdf->SetXY($posx_right, $right_posy);
    $pdf->SetTextColor(0, 0, 60);
    // Use $titlekey passed, or default "WarrantySlip"
    $title = $outputlangs->transnoentities($titlekey);
    if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
        $title .= ' - ';
        $title .= $outputlangsbis->transnoentities($titlekey);
    }
    $title .= ' ' . $outputlangs->convToOutputCharset($object->ref);

    // For Expedition, status might be different. Assuming no draft status watermark needed here unless $object->statut is checked.
    // if ($object->statut == $object::STATUS_DRAFT) { ... } // Adapt if shipments have draft status

    $pdf->MultiCell($w, 3, $title, '', 'R');
    $right_posy = $pdf->getY() + 5;

    // Reference info
    $pdf->SetFont('', '', $default_font_size - 1);
    $pdf->SetTextColor(0, 0, 60);

    // Use $object->tracking_number or other relevant fields for Expedition
    if ($object->tracking_number) {
        $pdf->SetXY($posx_right, $right_posy);
        $pdf->MultiCell($w, 3, $outputlangs->transnoentities("SendingMethod") . " : " . $outputlangs->convToOutputCharset($object->tracking_number), '', 'R'); // Example
        $right_posy += 4;
    }

    // Date (use expedition date, e.g., date_delivery or date_expedition)
    $date_to_print = !empty($object->date_delivery) ? $object->date_delivery : $object->date_expedition;
    if (empty($date_to_print)) $date_to_print = $object->date_creation; // Fallback

    $pdf->SetXY($posx_right, $right_posy);
    $titleDate = $outputlangs->transnoentities("Date"); // Generic "Date"
    if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
        $titleDate .= ' - ' . $outputlangsbis->transnoentities("Date");
    }
    $pdf->MultiCell($w, 3, $titleDate . " : " . dol_print_date($date_to_print, "day", false, $outputlangs, true), '', 'R');
    $right_posy += 4;


    // Customer codes if enabled (from thirdparty object)
    if (is_object($object->thirdparty)) { // Ensure thirdparty is loaded
        if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($object->thirdparty->code_client)) {
            $pdf->SetXY($posx_right, $right_posy);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities("CustomerCode") . " : " . $outputlangs->transnoentities($object->thirdparty->code_client), '', 'R');
            $right_posy += 4;
        }
        if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_ACCOUNTING_CODE') && !empty($object->thirdparty->code_compta_client)) {
            $pdf->SetXY($posx_right, $right_posy);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities("CustomerAccountancyCode") . " : " . $outputlangs->transnoentities($object->thirdparty->code_compta_client), '', 'R');
            $right_posy += 4;
        }
    }
    
    // Sales Representative - Adapt if relevant for Expedition
    // $arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL'); ...

    // ===== LINKED OBJECTS ===== (Adapted from bon_garantie)
    $right_posy += 2;
    $current_y_before_linked = $pdf->getY(); // Use current Y
    // pdf_writeLinkedObjects expects an object that has ->linked_objects array. Expedition object might need fetching these differently.
    // For now, we assume $object might have this or it needs to be populated.
    // $object->fetchSalesOrder(); // Example: if you need to link to sales order
    // $posy_ref = pdf_writeLinkedObjects($pdf, $object, $outputlangs, $posx_right, $right_posy, $w, 3, 'R', $default_font_size);
    // $top_shift_linked = $pdf->getY() - $current_y_before_linked;
    $top_shift = 0; // Placeholder, actual top_shift calculation depends on content

    // ===== ADDRESS FRAMES ===== (Adapted from bon_garantie)
    if ($showaddress) {
        $address_start_y = max($posy, $right_posy) + 15; // Start Y for address blocks

        // Sender properties
        $carac_emetteur = pdf_build_address($outputlangs, $this->emetteur, (is_object($object->thirdparty)?$object->thirdparty:null), '', 0, 'source', $object);

        $posx_sender = $this->marge_gauche;
        if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
            $posx_sender = $this->page_largeur - $this->marge_droite - 80; // Approx width
        }
        $hautcadre = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40; // Height of sender frame
        $widthrecbox_sender = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;


        if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
            $pdf->SetTextColor(0,0,0);
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY($posx_sender, $address_start_y - 5);
            $pdf->MultiCell($widthrecbox_sender, 5, $outputlangs->transnoentities("BillFrom"),0,$ltrdirection); // "Expeditor" or "From" might be more suitable
            $pdf->SetXY($posx_sender, $address_start_y);
            $pdf->SetFillColor(230,230,230);
            $pdf->RoundedRect($posx_sender, $address_start_y, $widthrecbox_sender, $hautcadre, $this->corner_radius, '1234', 'F');
            $pdf->SetTextColor(0,0,60);
        }

        if (!getDolGlobalString('MAIN_PDF_NO_SENDER_NAME')) {
            $pdf->SetXY($posx_sender + 2, $address_start_y + 3);
            $pdf->SetFont('', 'B', $default_font_size);
            $pdf->MultiCell($widthrecbox_sender - 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name),0,$ltrdirection);
            $current_y_sender = $pdf->GetY();
        } else {
            $current_y_sender = $address_start_y +3;
        }
        
        $pdf->SetXY($posx_sender + 2, $current_y_sender);
        $pdf->SetFont('', '', $default_font_size - 1);
        $pdf->MultiCell($widthrecbox_sender - 2, 4, $carac_emetteur,0,$ltrdirection);


        // Recipient (Thirdparty)
        $posx_recipient = $this->page_largeur - $this->marge_droite - (getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100);
         if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) $posx_recipient = $this->marge_gauche;
        $widthrecbox_recipient = (getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100);
        if ($this->page_largeur < 210) $widthrecbox_recipient = 84;


        $recipient_frame_y_start = $address_start_y;
        
        if (is_object($object->thirdparty)) {
            $carac_client_name = pdfBuildThirdpartyName($object->thirdparty, $outputlangs);
            // For expedition, the delivery address is often key. $object->thirdparty_address might hold it.
            // Or use $object->fk_address_livraison and fetch address details
            $delivery_address_string = '';
            if (!empty($object->fk_address_livraison)) {
                 $delivery_address = new Address($this->db);
                 if ($delivery_address->fetch($object->fk_address_livraison) > 0) {
                    $delivery_address_string = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, $delivery_address, 1, 'target', $object, 'shipping');
                 }
            }
            if (empty($delivery_address_string)) { // Fallback to thirdparty main address
                $delivery_address_string = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'target', $object);
            }
            $carac_client = $delivery_address_string;


            if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
                 $pdf->SetTextColor(0,0,0);
                 $pdf->SetFont('', '', $default_font_size - 2);
                 $pdf->SetXY($posx_recipient + 2, $recipient_frame_y_start - 5);
                 $pdf->MultiCell($widthrecbox_recipient, 5, $outputlangs->transnoentities("ShipTo"),0,$ltrdirection); // "ShipTo" or "To"
            }

            $y_text_content_start_recipient = $recipient_frame_y_start + 3;
            $pdf->SetXY($posx_recipient + 2, $y_text_content_start_recipient);
            $pdf->SetFont('', 'B', $default_font_size);
            $pdf->MultiCell($widthrecbox_recipient-2, 2, $carac_client_name,0,$ltrdirection); // Use -2 for padding
            $current_y_recipient = $pdf->GetY();

            $pdf->SetFont('', '', $default_font_size-1);
            $pdf->SetXY($posx_recipient + 2, $current_y_recipient);
            $pdf->MultiCell($widthrecbox_recipient-2, 4, $carac_client,0,$ltrdirection); // Use -2 for padding
            $current_y_recipient = $pdf->getY();

            // Add Client Phone & Email from bon_garantie
            if (!empty($object->thirdparty->phone)) {
                $current_y_recipient +=1;
                $pdf->SetFont('', '', $default_font_size - 1);
                $pdf->SetXY($posx_recipient + 2, $current_y_recipient);
                $pdf->MultiCell($widthrecbox_recipient-2, 4, $outputlangs->transnoentities("PhonePro").": ".$outputlangs->convToOutputCharset($object->thirdparty->phone), 0, $ltrdirection);
                $current_y_recipient = $pdf->GetY();
            }
            if (!empty($object->thirdparty->email)) {
                $current_y_recipient +=1;
                $pdf->SetFont('', '', $default_font_size - 1);
                $pdf->SetXY($posx_recipient + 2, $current_y_recipient);
                $pdf->MultiCell($widthrecbox_recipient-2, 4, $outputlangs->transnoentities("Email").": ".$outputlangs->convToOutputCharset($object->thirdparty->email), 0, $ltrdirection);
                $current_y_recipient = $pdf->GetY();
            }


            if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
                 // Use sender's box height ($hautcadre) for recipient box, as in bon_garantie
                 $pdf->RoundedRect($posx_recipient, $recipient_frame_y_start, $widthrecbox_recipient, $hautcadre, $this->corner_radius, '1234', 'D');
            }
            $posy = $recipient_frame_y_start + $hautcadre; // Update posy to bottom of recipient box
        } else { // No thirdparty object
             $posy = $address_start_y; // Fallback if no thirdparty
        }


        // Shipping address block - In expedition, the main recipient IS the shipping address.
        // The logic from bon_garantie for a separate shipping address might be redundant here.
        // For now, we'll skip the explicit separate shipping address block from bon_garantie
        // as the recipient block above should already represent the shipping destination.
        $shipp_shift = 0; // No separate shipping block shift needed.
        $top_shift = max($top_shift, $posy - ($this->marge_haute + 40)); // Basic top_shift calc
        
    } else { // if !$showaddress
        $shipp_shift = 0;
        $top_shift = 0;
    }


    $pdf->SetTextColor(0,0,0);

    // Ensure $posy is updated to reflect the bottom of the header elements before table starts
    // $pagehead_end_y = $pdf->GetY(); // Or max of all Y positions in header
    // This $posy will be used by write_file to position the table.
    // $this->pagehead_end_y = $pagehead_end_y; // Store it if needed by other methods

    return array('top_shift' => $top_shift, 'shipp_shift' => $shipp_shift, 'pagehead_end_y' => $pdf->GetY());
}

/**
 * Show footer of page. Need this->emetteur object
 * Adapted from pdf_bon_garantie.modules.php
 *
 * @param TCPDF       $pdf            PDF
 * @param Expedition  $object         Object to show
 * @param Translate   $outputlangs    Object lang for output
 * @param int         $hidefreetext   1=Hide free text (parameter from bon_garantie, may not be used here)
 * @return int                        Return height of bottom margin including footer text
 */
protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
{
    global $conf;

    $showdetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
    $default_font_size = pdf_getPDFFontSize($outputlangs);
    $width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

    // Standard footer content (from bon_garantie, adapted for Expedition context if needed)
    // The 'ORDER_FREE_TEXT' from bon_garantie is specific to orders.
    // For expeditions, there might not be an equivalent standard free text.
    // We can pass an empty string or a generic expedition related free text key if one exists.
    $height = pdf_pagefoot($pdf, $outputlangs, '', $this->emetteur, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, $showdetails, $hidefreetext, $this->page_largeur, $this->watermark);

    // Add warranty conditions only on the last page (from bon_garantie)
    if ($pdf->getPage() == $pdf->getNumPages()) {
        $posy = $this->page_hauteur - $this->marge_basse - 40; // Reserve space for warranty conditions
        // Full warranty conditions text from pdf_bon_garantie.modules.php
        $warranty_conditions = "شروط الضمان:\n";
        $warranty_conditions .= "1- تضمن الشركة للزبون العتاد المباع، ضد كل عيوب التصنيع والعمالة ضمن المدة المحددة ابتداء من تاريخ الشراء.\n";
        $warranty_conditions .= "2- نظام التشغيل والبرامج + نضائد الكمبيوتر المحمول ولوحات المفاتيح وكذا مقود اللعب، الفارة، مكبرات الصوت الفلاشديسك والمستهلكات مضمونة فقط عند أول تشغيل.\n";
        $warranty_conditions .= "5- تثبيت البرمجيات غير مضمون.\n"; // Note: Original bon_garantie has 3 and 4 missing, then 5. Replicating that.
        $warranty_conditions .= "7- لا تضمن الشركة أن هذا العتاد سيشتغل بصفة غير منقطعة أو دون خطأ في هذا العتاد.\n"; // Note: Original bon_garantie has 6 missing, then 7. Replicating that.
        $warranty_conditions .= "8- الضمان لا يشمل إرجاع المنتوج أو استبداله، تمنح الشركة مدة 3 أيام من تاريخ استلام المنتوج كأقصى حد لإرجاعه يتم فيها مراجعة المنتوج وتطبيق مستحقات قدرها 5% من سعر المنتوج -(لا تشمل مستحقات التوصيل)-.\n";
        $warranty_conditions .= "9- على الزبون الحفاظ على التغليف خلال مدة ضمان.\n";
        $warranty_conditions .= "10- الضمان لا يشمل: القيام بكسر السرعة OVER CLOCK / الصيانة سيئة / تغيير أو استعمال غير مرخصين / استعمال بطاقة امتداد غير معتمدة / حالات نقل سيئة. وفي حالة خلل في الجهاز يجب على الزبون إرجاعه للشركة خلال فترة الضمان في تغليفه الأصلي.\n";
        $warranty_conditions .= "11- الضمان على الطابعة يشمل إشتغالها فقط، ولا يشمل أخطاء الطباعة أو سوء ملء الخزان الخاص بها.";

        $pdf->SetFont('aealarabiya', '', $default_font_size - 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($this->marge_gauche, $posy);
        $pdf->SetFillColor(255, 255, 255); // White background for the text box
        $pdf->RoundedRect($this->marge_gauche, $posy, $width, 40, $this->corner_radius, '1234', 'F'); // Draw a filled rectangle first
        $pdf->SetXY($this->marge_gauche + 2, $posy + 2); // Position text inside the rectangle
        $pdf->MultiCell($width - 4, 3, $outputlangs->convToOutputCharset($warranty_conditions), 0, 'R'); // Reduced line height to 3 for more text
        $height += 40; // Add height of warranty conditions
    }

    return $height;
}

/**
 * Define Array Column Field. Adapted from pdf_bon_garantie.
 *
 * @param Expedition  $object        Common object (Expedition type)
 * @param Translate   $outputlangs   Langs object
 * @param int         $hidedetails   Do not show line details
 * @param int         $hidedesc      Do not show desc
 * @param int         $hideref       Do not show ref
 * @return void
 */
public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
{
    global $hookmanager, $conf; // Added conf for currency

    // Default field style for content (from bon_garantie)
    $this->defaultContentsFieldsStyle = array(
        'align' => 'R', // R,C,L
        'padding' => array(1, 0.5, 1, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
    );

    // Default field style for titles (from bon_garantie)
    $this->defaultTitlesFieldsStyle = array(
        'align' => 'C', // R,C,L
        'padding' => array(0.5, 0, 0.5, 0), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
    );

    $rank = 0;
    $this->cols['position'] = array( // Added Position column
        'rank' => $rank,
        'width' => 10,
        'status' => (getDolGlobalInt('PDF_ADD_POSITION') ? true : false), // Standard Dolibarr setting
        'title' => array(
            'textkey' => '#',
            'align' => 'C',
            'padding' => array(0.5, 0.5, 0.5, 0.5),
        ),
        'content' => array(
            'align' => 'C',
            'padding' => array(1, 0.5, 1, 1.5),
        ),
    );

    $rank = 5;
    // Reference column (replaces 'desc')
    $this->cols['desc'] = array(
        'rank' => $rank,
        'width' => 60, 
        'status' => true, 
        'title' => array(
            'textkey' => 'Reference', 
        ),
        'content' => array(
            'align' => 'L',
            'padding' => array(1, 0.5, 1, 0.5),
        ),
    );

    $rank += 10;
     // U.P. (Unit Price) column ('subprice') - Disabled for warranty slip
    $this->cols['subprice'] = array(
        'rank' => $rank,
        'width' => 19,
        'status' => false, // Disabled as per requirement
        'title' => array(
            'textkey' => 'PriceUHT' // Dolibarr key for Unit Price HT
        ),
        'content' => array(
            'align' => 'C', // Centered content
        ),
        'border-left' => true,
    );

    $rank += 10;
    // QTY column
    $this->cols['qty'] = array(
        'rank' => $rank,
        'width' => 16,
        'status' => true, // Enabled
        'title' => array(
            'textkey' => 'Qty'
        ),
        'content' => array(
            'align' => 'C',
        ),
        'border-left' => true,
    );

    $rank += 10;
    // Lot/Serial column ('serialnumber')
    $this->cols['serialnumber'] = array(
        'rank' => $rank,
        'width' => 30,
        'status' => false, // Will be dynamically enabled if serial numbers exist
        'title' => array(
            'textkey' => 'LotSerial', 
        ),
        'content' => array(
            'align' => 'L',
            'padding' => array(1, 0.5, 1, 0.5),
        ),
        'border-left' => true,
    );

    $rank += 10;
    // Warranty column ('garantie')
    $this->cols['garantie'] = array(
        'rank' => $rank,
        'width' => 15, 
        'status' => false, // Will be dynamically enabled if warranty info exists
        'title' => array(
            'textkey' => 'Warranty', 
        ),
        'content' => array(
            'align' => 'L',
            'padding' => array(1, 0.5, 1, 0.5),
        ),
        'border-left' => true,
    );
    
    $rank += 10; // Next available rank for other columns if any (e.g. photo, unit etc from original)
    $this->cols['photo'] = array( 
        'rank' => $rank,
        'width' => (!getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH') ? 20 : getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH')),
        'status' => false, 
        'title' => array(
            'textkey' => 'Photo',
            'label' => ' '
        ),
        'content' => array(
            'padding' => array(0, 0, 0, 0),
        ),
        'border-left' => false, 
    );
    
    $rank += 10;
    $this->cols['unit'] = array( 
        'rank' => $rank,
        'width' => 11,
        'status' => false, 
        'title' => array(
            'textkey' => 'Unit'
        ),
        'border-left' => true,
    );
    
    // VAT, Discount, TotalInclTax are kept structurally from bon_garantie but disabled.
    $rank += 10;
    $this->cols['vat'] = array( 
        'rank' => $rank,
        'status' => false, 
        'width' => 16,
        'title' => array(
            'textkey' => 'VAT'
        ),
        'border-left' => true,
    );

    $rank += 10;
    $this->cols['discount'] = array( 
        'rank' => $rank,
        'width' => 13,
        'status' => false, 
        'title' => array(
            'textkey' => 'ReductionShort'
        ),
        'border-left' => true,
    );

    $rank += 1000; // Large jump as in bon_garantie
    // Total column ('totalexcltax') - Disabled for warranty slip
    $this->cols['totalexcltax'] = array(
        'rank' => $rank,
        'width' => 26,
        'status' => false, // Disabled as per requirement
        'title' => array(
            'textkey' => 'TotalHTShort' // Dolibarr key for Total HT
        ),
        'content' => array(
            'align' => 'C',
        ),
        'border-left' => true,
    );

    $rank += 10; 
    $this->cols['totalincltax'] = array( 
        'rank' => $rank,
        'width' => 26,
        'status' => false, 
        'title' => array(
            'textkey' => 'TotalTTCShort'
        ),
        'border-left' => true,
    );

    // Add extrafields cols (copied from bon_garantie, may need adaptation for Expedition lines)
    if (!empty($object->lines)) {
        $line = reset($object->lines); // Get the first line to check for extrafields structure
        if (is_object($line)) { // Ensure $line is an object
             $this->defineColumnExtrafield($line, $outputlangs, $hidedetails);
        }
    }

    // Check if any line has a serial number or garantie to enable columns
    // This logic is adapted from bon_garantie
    $atleastoneserialnumber = false;
    $atleastonegarantie = false;

    if (is_array($object->lines)) { // Ensure lines is an array
        foreach ($object->lines as $line) {
            if (!is_object($line) || empty($line->fk_product)) continue;

            // Check for serial numbers (using the existing simpler getLineSerialNumber as a proxy)
            // Note: getLineSerialNumber in this file is specific to expeditiondet_batch
            $temp_sn = $this->getLineSerialNumber($object, array_search($line, $object->lines));
            if (!empty($temp_sn)) {
                $atleastoneserialnumber = true;
            }

            // Check for garantie
            $sql_garantie = "SELECT garantie FROM ".MAIN_DB_PREFIX."product_extrafields";
            $sql_garantie .= " WHERE fk_object = ".((int) $line->fk_product);
            $resql_garantie = $this->db->query($sql_garantie);
            if ($resql_garantie) {
                $obj_garantie = $this->db->fetch_object($resql_garantie);
                if ($obj_garantie && !empty($obj_garantie->garantie)) {
                    $atleastonegarantie = true;
                }
                $this->db->free($resql_garantie);
            }
            if ($atleastoneserialnumber && $atleastonegarantie) break; // Optimization
        }
    }

    if (isset($this->cols['serialnumber'])) {
        $this->cols['serialnumber']['status'] = $atleastoneserialnumber;
    }
    if (isset($this->cols['garantie'])) {
        $this->cols['garantie']['status'] = $atleastonegarantie;
    }


    $parameters = array(
        'object' => $object,
        'outputlangs' => $outputlangs,
        'hidedetails' => $hidedetails,
        'hidedesc' => $hidedesc,
        'hideref' => $hideref
    );

    $reshook = $hookmanager->executeHooks('defineColumnField', $parameters, $this);
    if ($reshook < 0) {
        setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
    } elseif (empty($reshook)) {
        // $this->cols = array_replace($this->cols, $hookmanager->resArray); // Merging with hook results
        if (isset($hookmanager->resArray) && is_array($hookmanager->resArray)) {
            $this->cols = array_replace($this->cols, $hookmanager->resArray);
        }
    } else {
         if (isset($hookmanager->resArray) && is_array($hookmanager->resArray)) {
            $this->cols = $hookmanager->resArray; // Replacing with hook results
        }
    }
    // rebuild internal positions after potential hook modification
    $this->buildColumnField();
}

    /**
     * Get the serial number(s) for an expedition line.
     * This method is kept from the original pdf_custom_shipping_slip.modules.php
     * as it's more direct for fetching serials from expedition details.
     *
     * @param object $object The expedition object
     * @param int $i The line index (original index in $object->lines array)
     * @return string The serial numbers (comma-separated) or empty string if not found
     */
    protected function getLineSerialNumber($object, $i)
    {
        $sn=[];
        // Ensure $object->lines[$i] exists and is an object with an 'id' property
        if (empty($object->lines[$i]) || !is_object($object->lines[$i]) || empty($object->lines[$i]->id)) {
            return '';
        }
        // $object->lines[$i]->id corresponds to expeditiondet.rowid
        $sql="SELECT batch FROM ".MAIN_DB_PREFIX."expeditiondet_batch WHERE fk_expeditiondet=".(int)$object->lines[$i]->id;
        $res=$this->db->query($sql);
        if ($res) {
            while ($o=$this->db->fetch_object($res)) {
                if (!empty($o->batch)) $sn[]=$o->batch;
            }
            $this->db->free($res);
        }
        return join(', ', $sn);
    }


    /**
     * Get the warranty period label for an expedition line.
     * Mapping updated to match pdf_bon_garantie.modules.php.
     *
     * @param object $object The expedition object
     * @param int $i The line index
     * @return string The warranty period label or empty string if not found
     */
    protected function getLineGarantie($object, $i)
    {
        // Mapping from pdf_bon_garantie.modules.php
        $garantieMap = [
            '1' => '0 MOIS -3 jours-', // Matched from bon_garantie
            '2' => '1 MOIS',
            '3' => '3 MOIS',
            '4' => '6 MOIS',
            '5' => '12 MOIS'
        ];

        if (!empty($object->lines[$i]->fk_product)) {
            $sql="SELECT garantie FROM ".MAIN_DB_PREFIX."product_extrafields WHERE fk_object=".(int)$object->lines[$i]->fk_product;
            $res=$this->db->query($sql);
            if ($res) {
                $g=$this->db->fetch_object($res);
                if ($g && !empty($g->garantie) && isset($garantieMap[$g->garantie])) {
                    $this->db->free($res);
                    return $garantieMap[$g->garantie];
                }
                $this->db->free($res);
            } else {
                 dol_syslog("getLineGarantie: SQL Error=".$this->db->lasterror(), LOG_ERR);
            }
        }
        return '';
    }
}


