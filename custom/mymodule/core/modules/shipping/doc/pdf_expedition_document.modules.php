<?php
/* Copyright (C) 2024 Your Name / Company <you@example.com>
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
 * You should have received a copy of unbelievab.ly along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       custom/mymodule/core/modules/expedition/doc/pdf_expedition_document.modules.php
 *  \ingroup    expedition
 *  \brief      File of Class to generate PDF shipment documents.
 */

// CHANGE_ME: Adjust the path to the correct base PDF module class
// It might be 'modules_commande.php' if shipments are closely related to orders,
// or a more generic one if it exists, e.g., 'core/modules/doc/modules_doc.php'
// Attempt to use a more generic document model if available, or stick to a relevant base.
// Dolibarr's standard shipment (expedition) module has its PDF models (e.g., 'rouget')
// often extending 'ModelePDFExpeditions' or similar, which itself might extend a generic doc model.
// For now, let's assume we might need to create/use 'ModelePDFExpeditions'.
// As a placeholder, we'll keep ModelePDFCommandes to ensure class structure,
// but ideally, this should be 'ModelePDFExpeditions' or a custom base class for shipment PDFs.
// Changing to ModelePDFDocument as ModelePDFExpeditions is likely not standard in v20.
require_once DOL_DOCUMENT_ROOT.'/core/modules/doc/modules_doc.php';       // This should provide ModelePDFDocument
require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/modules_expedition.php'; // Base for expedition related things
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

/**
 *  Class to generate PDF for Shipments
 *  CHANGE_ME: The class name `pdf_expedition_document` should be unique.
 *  CHANGE_ME: The base class `ModelePDFCommandes` is a placeholder and likely needs to be changed
 *             to a more appropriate one for shipments (e.g., a generic document model or a new base for shipments).
 */
class pdf_expedition_document extends ModelePDFDocument // Changed base class to ModelePDFDocument
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
     * @var int     Save the name of generated file as the main doc when generating a doc with this template
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
    public $version = 'dolibarr';

    /**
     * @var array<string,array{rank:int,width:float|int,status:bool,title:array{textkey:string,label:string,align:string,padding:array{0:float,1:float,2:float,3:float}},content:array{align:string,padding:array{0:float,1:float,2:float,3:float}}}>    Array of document table columns
     */
    public $cols;


    /**
     *  Constructor
     *
     *  @param      DoliDB      $db      Database handler
     */
    public function __construct(DoliDB $db)
    {
        global $conf, $langs, $mysoc;

        // Translations
        $langs->loadLangs(array("main", "bills", "products", "deliveries", "sendings", "expedition", "orders")); // Added expedition, orders

        $this->db = $db;
        $this->name = "expedition_document"; // Technical name for this PDF model
        $this->description = $langs->trans("PdfPackingSlipTitle"); // User-friendly description (e.g., "Packing Slip")
        // We need to add "PdfPackingSlipTitle" to language files, e.g. en_US/expedition.lang
        // For now, if not found, it will show "PdfPackingSlipTitle"
        if ($this->description == "PdfPackingSlipTitle") $this->description = "Packing Slip / Delivery Note"; // Fallback
        $this->update_main_doc_field = 1;

        // Document type, version
        $this->type = 'pdf'; // Keep as PDF
        $this->version = 'dolibarr'; // Standard

        // Page dimensions
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

        // Options: Adjust these as needed for shipment documents
        $this->option_tva = 0; // Typically shipments don't show detailed VAT like invoices
        $this->option_modereg = 0; // Payment mode not usually relevant on shipment docs
        $this->option_condreg = 0; // Payment conditions not usually relevant
        $this->option_multilang = 1;
        $this->option_escompte = 0;
        $this->option_credit_note = 0;
        $this->option_freetext = 1;
        $this->option_draft_watermark = 1;
        $this->watermark = '';

        if ($mysoc === null) {
            dol_syslog(get_class($this).'::__construct() Global $mysoc should not be null.'. getCallerInfoString(), LOG_ERR);
            return;
        }

        $this->emetteur = $mysoc;
        if (empty($this->emetteur->country_code)) {
            $this->emetteur->country_code = substr($langs->defaultlang, -2);
        }

        $this->posxdesc = $this->marge_gauche + 1;
        $this->tabTitleHeight = 5; // default height

        // Initialize totals arrays (may or may not be needed depending on shipment content)
        $this->tva = array();
        $this->tva_array = array();
        $this->localtax1 = array();
        $this->localtax2 = array();
        $this->atleastoneratenotnull = 0;
        $this->atleastonediscount = 0; // Discounts might be relevant if shown on packing slips
    }

    /**
     *  Function to build pdf onto disk
     *
     *  @param      CommonObject    $object             Object to generate (e.g., Shipment object)
     *  @param      Translate       $outputlangs        Lang output object
     *  @param      string          $srctemplatepath    Full path of source filename for generator using a template file
     *  @param      int<0,1>        $hidedetails        Do not show line details
     *  @param      int<0,1>        $hidedesc           Do not show desc
     *  @param      int<0,1>        $hideref            Do not show ref
     *  @return     int<-1,1>                           1 if OK, <=0 if KO
     */
    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $user, $langs, $conf, $mysoc, $db, $hookmanager, $nblines;

        dol_syslog("write_file (pdf_expedition_document) for object ref ".$object->ref." with outputlangs->defaultlang=".(is_object($outputlangs) ? $outputlangs->defaultlang : 'null'));

        if (!is_object($outputlangs)) {
            $outputlangs = $langs;
        }
        if (getDolGlobalInt('MAIN_USE_FPDF')) {
            $outputlangs->charset_output = 'ISO-8859-1';
        }

        // Load language files
        $outputlangs->loadLangs(array("main", "dict", "companies", "products", "orders", "deliveries", "sendings", "expedition"));

        // Show Draft Watermark for shipment
        // Standard Dolibarr Expedition object has $object->statut and Expedition::STATUS_DRAFT
        if (property_exists($object, 'statut') && $object->statut == Expedition::STATUS_DRAFT && getDolGlobalString('EXPEDITION_DRAFT_WATERMARK')) {
            $this->watermark = getDolGlobalString('EXPEDITION_DRAFT_WATERMARK');
        }

        global $outputlangsbis;
        $outputlangsbis = null;
        if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE')) {
            $outputlangsbis = new Translate('', $conf);
            $outputlangsbis->setDefaultLang(getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE'));
            // Load same langs for the bis language
            $outputlangsbis->loadLangs(array("main", "dict", "companies", "products", "orders", "deliveries", "sendings", "expedition"));
        }

        // $nblines: Number of lines in the shipment (items)
        // Standard Dolibarr Expedition object has $object->lines
        $nblines = (is_array($object->lines) ? count($object->lines) : 0);

        $hidetop = getDolGlobalInt('MAIN_PDF_DISABLE_COL_HEAD_TITLE');

        // Product pictures (if configured and relevant for packing slips)
        $this->atleastonephoto = false;
        if (getDolGlobalInt('MAIN_GENERATE_SHIPMENT_WITH_PICTURE')) { // Using a new global for shipment pictures
            // Logic to check for photos, similar to the original, adapted for $object->lines
            // This assumes lines have fk_product and Product class can fetch photos.
            // This part can be extensive and depends on how product photos are stored and accessed.
            // For brevity, we'll assume this logic is similar to the original if needed.
            // If not needed, this whole block can be removed.
        }


        // Output directory and filename for the PDF
        // getMultidirOutput should work if $object has ->element and ->table_element set correctly by its class
        // For Expedition, ->element is 'shipping' or 'sending'.
        if (empty($object->element)) $object->element = 'shipping'; // Ensure element is set for getMultidirOutput
        if (empty($object->table_element)) $object->table_element = 'expedition'; // Ensure table_element is set

        if (getMultidirOutput($object)) {
            $object->fetch_thirdparty(); // Fetch recipient thirdparty data

            // Definition of $dir and $file
            if ($object->specimen) {
                $dir = getMultidirOutput($object);
                $file = $dir."/SPECIMEN.pdf"; // Standard specimen name
            } else {
                $objectref = dol_sanitizeFileName($object->ref);
                $dir = getMultidirOutput($object)."/".$objectref;
                // Use a model-specific suffix for the PDF file if multiple models exist for shipments
                $file = $dir."/".$objectref."_".$this->name.".pdf";
            }

            if (! file_exists($dir)) {
                if (dol_mkdir($dir) < 0) {
                    $this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
                    return 0;
                }
            }

            if (file_exists($dir)) {
                // Add pdfgeneration hook
                // ... (hookmanager initialization - can be kept similar)
                $parameters = array('file' => $file, 'object' => $object, 'outputlangs' => $outputlangs);
                global $action;
                $reshook = $hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);

                $nblines = (is_array($object->lines) ? count($object->lines) : 0); // Re-count after hook

                $pdf = pdf_getInstance($this->format);
                $default_font_size = pdf_getPDFFontSize($outputlangs);
                $pdf->SetAutoPageBreak(1, 0);

                // Heights for various sections (adjust as needed)
                $heightforinfotot = 20; // May not need as much space as invoices for totals
                $heightforfreetext = getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5);
                $heightforfooter = $this->marge_basse + 8;
                if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS')) {
                    $heightforfooter += 6;
                }

                // ... (TCPDF specific setup, SetFont, PDF Background - can be kept similar)

                $pdf->Open();
                $pagenb = 0;
                $pdf->SetDrawColor(128, 128, 128);

                // CHANGE_ME: Adjust PDF metadata for shipments
                $pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
                $pdf->SetSubject($outputlangs->transnoentities("PdfShipmentTitle")); // New lang key
                $pdf->SetCreator("Dolibarr ".DOL_VERSION);
                $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
                $pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("PdfPackingSlipTitle")." ".$outputlangs->convToOutputCharset(isset($object->thirdparty->name) ? $object->thirdparty->name : ''));
                if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
                    $pdf->SetCompression(false);
                }

                $pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);

                // Discounts are generally not shown on packing slips, so $this->atleastonediscount can be ignored or set to 0.
                $this->atleastonediscount = 0;

                // New page
                $pdf->AddPage();
                if (!empty($tplidx)) $pdf->useTemplate($tplidx);
                $pagenb++;
                $pagehead = $this->_pagehead($pdf, $object, 1, $outputlangs, $outputlangsbis, "PdfPackingSlipTitle"); // Pass the title key
                $top_shift = $pagehead['top_shift'];
                $shipp_shift = $pagehead['shipp_shift'];
                $pdf->SetFont('', '', $default_font_size - 1);
                $pdf->MultiCell(0, 3, '');
                $pdf->SetTextColor(0, 0, 0);

                // Tab positions and heights
                // CHANGE_ME: These will likely change based on what _pagehead for shipments outputs
                $tab_top = 70 + $top_shift + $shipp_shift; // Adjusted starting point, review after _pagehead
                $tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? 42 + $top_shift : 10);
                if (!$hidetop && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
                    $tab_top_newpage += $this->tabTitleHeight;
                }
                $tab_height = $this->page_hauteur - $tab_top - $heightforfooter - $heightforfreetext - $heightforinfotot;

                $nexY = $tab_top -1; // Initial Y for content

                // Incoterm (if relevant for shipments)
                // ... (code for incoterms - can be kept or removed)

                // Display notes (e.g., shipping instructions)
                // Display notes (e.g., shipping instructions from $object->note_public)
                $notetoshow = '';
                if (!empty($object->note_public)) {
                    $notetoshow = $object->note_public;
                }
                // Add extrafields to note if any are configured to be shown in notes
                $extranote = $this->getExtrafieldsInHtml($object, $outputlangs); // This helper is from base ModelPDF
                if (!empty($extranote)) {
                    $notetoshow = dol_concatdesc($notetoshow, $extranote);
                }

                $pagenb_before_note = $pdf->getPage();
                if ($notetoshow) {
                    $tab_top_note = $tab_top - 2; // Y position for note box
                    // Logic for printing notes, handling page breaks, and drawing rounded rect around notes
                    // This is similar to the original pdf_bon_livraison, adapted slightly
                    $tab_width_note = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

                    $substitutionarray = pdf_getSubstitutionArray($outputlangs, null, $object); // For variable substitution in notes
                    complete_substitutions_array($substitutionarray, $outputlangs, $object);
                    $notetoshow_substituted = make_substitutions($notetoshow, $substitutionarray, $outputlangs);
                    $notetoshow_substituted = convertBackOfficeMediasLinksToPublicLinks($notetoshow_substituted);

                    $pdf->startTransaction();
                    $pdf->SetFont('', '', $default_font_size - 1);
                    $pdf->writeHTMLCell($tab_width_note, 3, $this->posxdesc -1, $tab_top_note, dol_htmlentitiesbr($notetoshow_substituted), 0, 1);
                    $pageposafternote = $pdf->getPage();
                    $posyafternote = $pdf->GetY();

                    if ($pageposafternote > $pagenb_before_note) { // Note caused page break
                        $pdf->rollbackTransaction(true);
                        // ... (complex multi-page note handling - for now, let's assume notes are shorter or simplify)
                        // For simplicity in this step, we'll just redraw note on the new page if it breaks.
                        // A full robust solution would replicate the complex logic from parent if needed.
                        $pdf->AddPage(); $pagenb++; if(!empty($tplidx)) $pdf->useTemplate($tplidx);
                        if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis, "PdfPackingSlipTitle");
                        $pdf->setTopMargin($tab_top_newpage);
                        $pdf->setPageOrientation('',1,$heightforfooter + $heightforfreetext);
                        $pdf->writeHTMLCell($tab_width_note, 3, $this->posxdesc-1, $tab_top_newpage, dol_htmlentitiesbr($notetoshow_substituted), 0, 1);
                        $posyafternote = $pdf->GetY();
                        // Draw frame for note on new page
                        $pdf->RoundedRect($this->marge_gauche, $tab_top_newpage -1, $tab_width_note, $posyafternote - $tab_top_newpage +1, $this->corner_radius, '1234', 'D');

                    } else { // Note fits on current page
                        $pdf->commitTransaction();
                         // Draw frame for note
                        $height_note = $posyafternote - $tab_top_note;
                        $pdf->RoundedRect($this->marge_gauche, $tab_top_note -1, $tab_width_note, $height_note +1, $this->corner_radius, '1234', 'D');
                    }
                    $tab_top = $posyafternote + 6; // Adjust $tab_top for the content table to be below the note
                }


                // Prepare columns for the lines table
                $this->prepareArrayColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref); // Uses our overridden defineColumnField

                // Table simulation to know the height of the title line (if titles are shown)
                if (!$hidetop) {
                    $pdf->startTransaction();
                    $this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);
                    $pdf->rollbackTransaction(true);
                    $nexY = $tab_top + $this->tabTitleHeight;
                } else {
                    $nexY = $tab_top;
                }


                // Loop on each lines (e.g., items in the shipment)
                // This whole loop needs to be adapted to what a "line" means for a shipment
                // and what data needs to be displayed for each shipped item.
                for ($i = 0; $i < $nblines; $i++) {
                    // ... (page break logic, ResetAfterColsLinePositionsData - can be kept similar)
                    // ... (SetFont, SetTextColor - can be kept similar)
                    // ... (Image handling if product images are shown - adapt $realpatharray[$i])

                    // Description of product line (or shipped item)
                    // CHANGE_ME: `printCustomDescContent` might need to be adapted or a new one created
                    if ($this->getColumnStatus('desc')) {
                        // This will use the `printCustomDescContent` from the base class or this class if overridden
                        $this->printCustomDescContent($pdf, $curY, 'desc', $object, $i, $outputlangs, $hideref, $hidedesc);
                        $this->setAfterColsLinePositionsData('desc', $pdf->GetY(), $pdf->getPage());
                    }

                    // Other columns like Quantity, Product Ref, Serial Numbers, Weight, Dimensions etc.
                    // These will be defined in `defineColumnField` and printed here.
                    // Example:
                    $curY = $nexY;
                    $pageposbefore = $pdf->getPage();

                    // Page break if defined on line
                    if (isset($object->lines[$i]->pagebreak) && $object->lines[$i]->pagebreak) {
                        $pdf->AddPage(); $pagenb++; if(!empty($tplidx)) $pdf->useTemplate($tplidx);
                        $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis, "PdfPackingSlipTitle");
                        $pdf->setTopMargin($tab_top_newpage);
                        // Draw table titles again on new page
                        if (!$hidetop) $this->pdfTabTitles($pdf, $tab_top_newpage, $tab_height, $outputlangs, $hidetop);
                        $curY = $tab_top_newpage + ((!$hidetop && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) ? $this->tabTitleHeight : 0);
                    }

                    $this->resetAfterColsLinePositionsData($curY, $pdf->getPage());
                    $pdf->SetFont('', '', $default_font_size - 1);
                    $pdf->SetTextColor(0, 0, 0);

                    // Product image (if enabled and $this->atleastonephoto is true)
                    // ... (Logic for image display, similar to original, if needed)

                    // Description of product line
                    if ($this->getColumnStatus('desc')) {
                        // Using the overridden printCustomDescContent from this class (if defined) or from ModelePDFExpeditions/ModelePDFDocuments
                        $this->printCustomDescContent($pdf, $curY, 'desc', $object, $i, $outputlangs, $hideref, $hidedesc);
                        $this->setAfterColsLinePositionsData('desc', $pdf->GetY(), $pdf->getPage());
                    }

                    $afterPosDataDesc = $this->getMaxAfterColsLinePositionsData();
                    $pdf->setPage($pageposbefore); // Go back to page before desc printing to print other cols at same Y
                    $pdf->setTopMargin($this->marge_haute); // reset
                    $pdf->setPageOrientation('',0,$heightforfooter);

                    if ($afterPosDataDesc['page'] > $pageposbefore ) { // Description caused a page break
                         $pdf->setPage($afterPosDataDesc['page']);
                         $curY = $tab_top_newpage + ((!$hidetop && getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) ? $this->tabTitleHeight : 0);
                    }


                    // Position
                    if ($this->getColumnStatus('position')) {
                        $this->printStdColumnContent($pdf, $curY, 'position', strval($i + 1));
                    }

                    // Product Ref
                    if ($this->getColumnStatus('product_ref')) {
                        $product_ref = $this->getLineProductRef($object, $i, $outputlangs);
                        $this->printStdColumnContent($pdf, $curY, 'product_ref', $product_ref);
                    }

                    // Quantity Shipped
                    if ($this->getColumnStatus('qty_shipped')) {
                        $qty_shipped = $this->getLineQtyShipped($object, $i, $outputlangs);
                        $this->printStdColumnContent($pdf, $curY, 'qty_shipped', $qty_shipped);
                    }

                    // Serial Numbers (if column is active and method exists)
                    if ($this->getColumnStatus('serialnumber') && method_exists($this, 'getLineSerialNumbers')) {
                       $serial_numbers = $this->getLineSerialNumbers($object, $i, $outputlangs);
                       $this->printStdColumnContent($pdf, $curY, 'serialnumber', $serial_numbers);
                       $this->setAfterColsLinePositionsData('serialnumber', $pdf->GetY(), $pdf->getPage());
                    }

                    // Weight (if column is active and method exists)
                    if ($this->getColumnStatus('weight') && method_exists($this, 'getLineWeight')) {
                       $weight = $this->getLineWeight($object, $i, $outputlangs);
                       $this->printStdColumnContent($pdf, $curY, 'weight', $weight);
                    }

                    // Extrafields for lines
                    if (!empty($object->lines[$i]->array_options)) {
                        foreach ($object->lines[$i]->array_options as $extrafieldColKey => $extrafieldValue) {
                            if ($this->getColumnStatus($extrafieldColKey)) {
                                $extrafieldFormattedValue = $this->getExtrafieldContent($object->lines[$i], $extrafieldColKey, $outputlangs);
                                $this->printStdColumnContent($pdf, $curY, $extrafieldColKey, $extrafieldFormattedValue);
                                $this->setAfterColsLinePositionsData('options_'.$extrafieldColKey, $pdf->GetY(), $pdf->getPage());
                            }
                        }
                    }

                    // Hook for modules to add more line data
                    $parameters_line = array('object' => $object, 'i' => $i, 'pdf' => $pdf, 'curY' => $curY, 'outputlangs' => $outputlangs);
                    $reshook_line = $hookmanager->executeHooks('printPDFLineExpedition', $parameters_line, $this);


                    // Accumulate any necessary totals (e.g., total weight, total packages - if not done by a hook)
                    // Example: if (property_exists($object->lines[$i], 'weight')) $this->total_weight += ($object->lines[$i]->weight * $object->lines[$i]->qty);


                    $afterPosData = $this->getMaxAfterColsLinePositionsData();
                    $pdf->setPage($afterPosData['page']);
                    $nexY = $afterPosData['y'];

                    // Draw line separator
                    if (getDolGlobalString('MAIN_PDF_DASH_BETWEEN_LINES') && $i < ($nblines - 1) && $nexY < $this->page_hauteur - $heightforfooter - 5) {
                        $pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(80, 80, 80)));
                        $pdf->line($this->marge_gauche, $nexY + 1, $this->page_largeur - $this->marge_droite, $nexY + 1);
                        $pdf->SetLineStyle(array('dash' => 0));
                    }
                    $nexY += 2; // Space between lines
                } // End of loop on lines


                // After lines loop: Check if new page needed for footer/totals
                $afterPosData = $this->getMaxAfterColsLinePositionsData();
                if (isset($afterPosData['y']) && $afterPosData['y'] > $this->page_hauteur - ($heightforfooter + $heightforfreetext + $heightforinfotot) ) {
                    $pdf->AddPage(); $pagenb++; if(!empty($tplidx)) $pdf->useTemplate($tplidx);
                    // No need to repeat full head, just ensure margins are okay for footer content
                    $pdf->setPage($pagenb);
                }

                // Draw table frames and column borders for all pages with lines
                $drawTabNumbPage = $pdf->getNumPages();
                for ($p=$pagenb_before_note; $p<=$drawTabNumbPage; $p++) { // Start from page where table content began
                    $pdf->setPage($p);
                    $pdf->setPageOrientation('',0,0);

                    $drawTabHideTopCurrentPage = $hidetop;
                    $drawTabTopCurrentPage = $tab_top_newpage;
                    $drawTabBottomCurrentPage = $this->page_hauteur - $heightforfooter;

                    if ($p == $pagenb_before_note) { // First page of table content
                        $drawTabTopCurrentPage = $tab_top; // Use $tab_top which is after notes
                    } elseif (!$drawTabHideTopCurrentPage) {
                        if (!getDolGlobalInt('MAIN_PDF_ENABLE_COL_HEAD_TITLE_REPEAT')) {
                             $drawTabHideTopCurrentPage = 1; // Hide titles if not repeating
                        }
                    }

                    // Adjust bottom for last page to make space for info/total area
                    if ($p == $pdf->getNumPages()) {
                        $drawTabBottomCurrentPage -= ($heightforfreetext + $heightforinfotot);
                    }

                    $drawTabHeightCurrentPage = $drawTabBottomCurrentPage - $drawTabTopCurrentPage;
                    if ($drawTabHeightCurrentPage > 0) { // Only draw if there's actual height
                        $this->_tableau($pdf, $drawTabTopCurrentPage, $drawTabHeightCurrentPage, 0, $outputlangs, $drawTabHideTopCurrentPage, 0, $conf->currency, $outputlangsbis);
                    }

                    $hideFreeTextCurrentPage = ($p != $pdf->getNumPages());
                    $this->_pagefoot($pdf, $object, $outputlangs, $hideFreeTextCurrentPage);

                    $pdf->setPage($p); // Restore page context
                    $pdf->setPageOrientation('',1,0);
                    if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') && $p != $pagenb_before_note) { // Don't repeat head on first page of table if already drawn by note logic
                        $this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis, "PdfPackingSlipTitle");
                    }
                    if (!empty($tplidx)) $pdf->useTemplate($tplidx);
                }
                $pdf->SetTextColor(0,0,0);
                $pdf->setPage($pdf->getNumPages()); // Ensure we are on the last page

                // Position for Info/Total tables (bottom of the page, above free text and footer)
                $bottom_of_last_table_lines = $this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;

                // Display infos area (e.g., shipping method, tracking number)
                // This will call our new drawShipmentInfoTable method
                $posy_after_info = $this->drawShipmentInfoTable($pdf, $object, $bottom_of_last_table_lines, $outputlangs);

                // Display total zone (e.g., total weight, total packages)
                // This will call our new drawShipmentTotalTable method
                // $posy_after_total = $this->drawShipmentTotalTable($pdf, $object, $bottom_of_last_table_lines, $outputlangs);
                // For now, we'll skip custom totals table, as it's not defined yet.
                // If drawInfoTable is sufficient, this might not be needed or can be part of it.

                // Finalize PDF (AliasNbPages, Terms of Sale if any, Close, Output)
                if (method_exists($pdf,'AliasNbPages')) $pdf->AliasNbPages();
                // Potentially add terms of sale if relevant for shipments (e.g. GENCOND_SHIPMENT_PATH)
                // ...
                $pdf->Close();
                $pdf->Output($file, 'F');
                // ... (afterPDFCreation hook, chmod, result array - can be kept similar)

                $this->result = array('fullpath' => $file);
                return 1; // No error
            } else {
                $this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
                return 0;
            }
        } else {
            // CHANGE_ME: Use a shipment-specific constant for output directory if it exists
            $this->error = $langs->transnoentities("ErrorConstantNotDefined", "EXPEDITION_OUTPUTDIR"); // Or SHIPMENT_OUTPUTDIR
            return 0;
        }
    }

    /**
     *  Show top header of page.
     *  CHANGE_ME: This method needs to be overridden and heavily adapted for shipment documents.
     *             It should display shipment-specific information.
     *
     *  @param      TCPDF           $pdf            Object PDF
     *  @param      CommonObject    $object         Object to show (Shipment object)
     *  @param      int             $showaddress    0=no, 1=yes (shipping address primarily)
     *  @param      Translate       $outputlangs    Object lang for output
     *  @param      Translate       $outputlangsbis Object lang for output bis
     *  @param      string          $titlekey       Translation key for document title
     *  @return     array<string, int|float>    top shift of linked object lines
     */
    protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null, $titlekey = "PdfPackingSlipTitle")
    {
        global $conf, $langs, $hookmanager, $mysoc;

        $outputlangs->loadLangs(array("main", "deliveries", "sendings", "companies", "expedition", "orders", "propal", "bills"));

        $default_font_size = pdf_getPDFFontSize($outputlangs);

        // Standard page header (logo, company name/address from $mysoc)
        pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

        // START Title and Ref block (top right)
        $posx = $this->page_largeur - $this->marge_droite - 100; // X position for the right block
        $posy = $this->marge_haute;

        // Document Title (e.g., "PACKING SLIP")
        $pdf->SetXY($posx, $posy);
        $pdf->SetFont('', 'B', $default_font_size + 3);
        $pdf->SetTextColor(0, 0, 60);
        $doctitle = $outputlangs->transnoentities($titlekey);
        if (is_object($outputlangsbis)) $doctitle .= ' / '.$outputlangsbis->transnoentities($titlekey);
        $pdf->MultiCell(100, 4, $doctitle, 0, 'R');
        $posy += 5;

        // Shipment Reference
        $pdf->SetXY($posx, $posy);
        $pdf->SetFont('', 'B', $default_font_size + 1);
        $shipref_title = $outputlangs->transnoentities("RefShipment");
        if (is_object($outputlangsbis)) $shipref_title .= ' / '.$outputlangsbis->transnoentities("RefShipment");
        $shipref_title .= " : ".$outputlangs->convToOutputCharset($object->ref);
        if ($object->statut == Expedition::STATUS_DRAFT) {
             $pdf->SetTextColor(128,0,0);
             $shipref_title .= ' ('.$outputlangs->transnoentities("Draft").')';
        }
        $pdf->MultiCell(100, 4, $shipref_title, 0, 'R');
        $posy += 4;
        $pdf->SetTextColor(0,0,60); // Reset color

        // Shipment Date
        $pdf->SetXY($posx, $posy);
        $pdf->SetFont('', '', $default_font_size);
        $shipdate_title = $outputlangs->transnoentities("DateShipment");
        if (is_object($outputlangsbis)) $shipdate_title .= ' / '.$outputlangsbis->transnoentities("DateShipment");
        // Use $object->date_expedition if available, otherwise $object->date_valid or $object->date_creation
        $date_to_print = $object->date_expedition ? $object->date_expedition : ($object->date_valid ? $object->date_valid : $object->date_creation);
        $shipdate_title .= " : ".dol_print_date($date_to_print, "daytext", false, $outputlangs, true);
        $pdf->MultiCell(100, 4, $shipdate_title, 0, 'R');
        $posy += 4;

        // Order Reference(s)
        // An expedition can be linked to one or more orders.
        $order_refs = array();
        if (!empty($object->origin) && $object->origin == 'commande') { // Standard link
            $order = new Commande($this->db);
            if ($order->fetch($object->origin_id) > 0) {
                $order_refs[] = $order->ref;
            }
        } else {
            // Check linked elements if more complex linking is used (e.g. via element_element table)
            // This might require fetching linked objects of type 'commande'
            // For now, we assume a simple link if present or use a specific method if the object provides it.
            if (method_exists($object, 'getLinkedObjects')) {
                $linked_orders = $object->getLinkedObjects('commande');
                if (is_array($linked_orders)) {
                    foreach ($linked_orders as $order_id => $order_data) {
                        $order_refs[] = $order_data['ref'];
                    }
                }
            }
        }
        if (!empty($order_refs)) {
            $pdf->SetXY($posx, $posy);
            $orderref_title = $outputlangs->transnoentities(count($order_refs) > 1 ? "RefOrders" : "RefOrder");
            if (is_object($outputlangsbis)) $orderref_title .= ' / '.$outputlangsbis->transnoentities(count($order_refs) > 1 ? "RefOrders" : "RefOrder");
            $orderref_title .= " : ".implode(', ', $order_refs);
            $pdf->MultiCell(100, 4, $outputlangs->convToOutputCharset($orderref_title), 0, 'R');
            $posy += 4;
        }

        // Tracking Number
        if (!empty($object->tracking_number)) {
            $pdf->SetXY($posx, $posy);
            $track_title = $outputlangs->transnoentities("TrackingNumber");
            if (is_object($outputlangsbis)) $track_title .= ' / '.$outputlangsbis->transnoentities("TrackingNumber");
            $track_title .= " : ".$outputlangs->convToOutputCharset($object->tracking_number);
            $pdf->MultiCell(100, 4, $track_title, 0, 'R');
            $posy += 4;
        }

        // Shipping Method
        if (!empty($object->fk_shipping_method_id)) {
            $shipping_method = new ShippingMethod($this->db);
            if ($shipping_method->fetch($object->fk_shipping_method_id) > 0) {
                $pdf->SetXY($posx, $posy);
                $shipmethod_title = $outputlangs->transnoentities("ShippingMethod");
                if (is_object($outputlangsbis)) $shipmethod_title .= ' / '.$outputlangsbis->transnoentities("ShippingMethod");
                $shipmethod_title .= " : ".$outputlangs->convToOutputCharset($shipping_method->libelle);
                $pdf->MultiCell(100, 4, $shipmethod_title, 0, 'R');
                $posy += 4;
            }
        }
        // END Title and Ref block

        $current_y_after_right_block = $posy; // Save Y position after the right block

        // Addresses
        $top_shift = 0;     // Y shift from elements above address boxes (e.g. linked objects list if any)
        $shipp_shift = 0;   // Height of the shipping address box, to correctly position elements below it

        if ($showaddress) {
            // Determine recipient for shipping
            $recipient = $object->thirdparty; // Already fetched in write_file
            $contact_id_shipping = $object->getIdContact('external', 'SHIPPING'); // Get specific shipping contact if any
            $contact_shipping_obj = null;
            if (!empty($contact_id_shipping[0])) {
                $contact_shipping_obj = new Contact($this->db);
                $contact_shipping_obj->fetch($contact_id_shipping[0]);
            }

            // Build Shipping Address (Recipient)
            $carac_shipping_to_name = pdfBuildThirdpartyName($recipient, $outputlangs, ($contact_shipping_obj && $contact_shipping_obj->id ? $contact_shipping_obj : null));
            // The pdf_build_address function needs the 'shipping address index' if multiple are available for the thirdparty.
            // Standard Expedition object might have $object->fk_address for this, or it's determined by the contact.
            // If $object->fk_address (or similar like $object->delivery_address_id) exists, use it.
            // Otherwise, if $contact_shipping_obj exists and has an address, it might be used.
            // For now, we assume default address or contact's if available.
            $mode = 'shipping'; // Or 'target'
            $carac_shipping_to_address = pdf_build_address(
                $outputlangs,
                $this->emetteur, // Sender (mysoc)
                $recipient,      // Recipient company
                ($contact_shipping_obj && $contact_shipping_obj->id ? $contact_shipping_obj : ''), // Contact object
                ($contact_shipping_obj && $contact_shipping_obj->id ? 1 : 0),    // Use contact info
                $mode,           // Mode: 'shipping' or 'target'
                $object,         // Source object (expedition)
                (isset($object->delivery_address_id) ? $object->delivery_address_id : null) // delivery_address_id if available
            );

            // Shipping Address Box
            $width_addr_box = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
            if ($this->page_largeur < 210) $width_addr_box -= 10; // Adjust for smaller paper

            $posx_shipping_addr = $this->marge_gauche; // Default on left
            if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT') || getDolGlobalInt('MAIN_PDF_SHIPMENT_ADDRESS_ON_RIGHT') ) { // Option to put shipping address on right
                 $posx_shipping_addr = $this->page_largeur - $this->marge_droite - $width_addr_box;
            }

            $y_shipping_addr_frame = (getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 40 : 42) + $top_shift;

            // Ensure shipping address box doesn't overlap with the right block info if address is on left
            if ($posx_shipping_addr == $this->marge_gauche && ($y_shipping_addr_frame + 30) < $current_y_after_right_block) { // 30 is an estimated height for addr box
                 // If right block is taller, push address box down.
                 // This might need more dynamic height calculation for the right block.
                 // $y_shipping_addr_frame = $current_y_after_right_block + 5;
            }


            // "Ship To" Title
            $pdf->SetFont('', '', $default_font_size - 2);
            $pdf->SetXY($posx_shipping_addr + 1, $y_shipping_addr_frame - 5);
            $pdf->MultiCell($width_addr_box -2, 3, $outputlangs->transnoentities("ShipTo"), 0, 'L');

            // Name in Address Box
            $pdf->SetFont('', 'B', $default_font_size);
            $pdf->SetXY($posx_shipping_addr + 2, $y_shipping_addr_frame + 2);
            $pdf->MultiCell($width_addr_box -4, 3, $carac_shipping_to_name, 0, 'L');
            $current_y_in_addr = $pdf->GetY() +1;

            // Address details
            $pdf->SetFont('', '', $default_font_size -1);
            $pdf->SetXY($posx_shipping_addr + 2, $current_y_in_addr);
            $pdf->MultiCell($width_addr_box -4, 3, $carac_shipping_to_address, 0, 'L');
            $y_after_addr_text = $pdf->GetY();

            // Add phone/email from recipient thirdparty to shipping address block
            if (!empty($recipient->phone)) {
                 $pdf->SetXY($posx_shipping_addr + 2, $y_after_addr_text + 1);
                 $pdf->MultiCell($width_addr_box - 4, 3, $outputlangs->transnoentities("Phone").": ".$outputlangs->convToOutputCharset($recipient->phone), 0, 'L');
                 $y_after_addr_text = $pdf->GetY();
            }
            if (!empty($recipient->email)) {
                 $pdf->SetXY($posx_shipping_addr + 2, $y_after_addr_text + 1);
                 $pdf->MultiCell($width_addr_box - 4, 3, $outputlangs->transnoentities("Email").": ".$outputlangs->convToOutputCharset($recipient->email), 0, 'L');
                 $y_after_addr_text = $pdf->GetY();
            }


            $addr_content_height = $y_after_addr_text - ($y_shipping_addr_frame +2); // Height of text content
            $addr_box_padding = 5; // Total padding (top+bottom)
            $dynamic_addr_box_height = $addr_content_height + $addr_box_padding;
            if ($dynamic_addr_box_height < 25) $dynamic_addr_box_height = 25; // Min height

            // Draw Frame for Shipping Address
            if (!getDolGlobalString('MAIN_PDF_NO_ADDRESS_FRAME')) {
                $pdf->RoundedRect($posx_shipping_addr, $y_shipping_addr_frame, $width_addr_box, $dynamic_addr_box_height, $this->corner_radius, '1234', 'D');
            }
            $shipp_shift = $y_shipping_addr_frame + $dynamic_addr_box_height; // Y position after the shipping address box


            // Sender Address Box (if MAIN_INVERT_SENDER_RECIPIENT is true)
            if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
                // Position sender address opposite to recipient
                $posx_sender_addr = $this->page_largeur - $this->marge_droite - $width_addr_box;
                $y_sender_addr_frame = $y_shipping_addr_frame; // Align vertically with shipping address

                $carac_sender_name = pdfBuildThirdpartyName($this->emetteur, $outputlangs);
                $carac_sender_address = pdf_build_address($outputlangs, $this->emetteur, $this->emetteur, '', 0, 'sender', $object);

                $pdf->SetFont('', '', $default_font_size - 2);
                $pdf->SetXY($posx_sender_addr + 1, $y_sender_addr_frame - 5);
                $pdf->MultiCell($width_addr_box-2, 3, $outputlangs->transnoentities("Sender"),0,'L');

                $pdf->SetFont('', 'B', $default_font_size);
                $pdf->SetXY($posx_sender_addr + 2, $y_sender_addr_frame + 2);
                $pdf->MultiCell($width_addr_box-4, 3, $carac_sender_name,0,'L');
                $current_y_in_sender_addr = $pdf->GetY() +1;

                $pdf->SetFont('', '', $default_font_size-1);
                $pdf->SetXY($posx_sender_addr + 2, $current_y_in_sender_addr);
                $pdf->MultiCell($width_addr_box-4, 3, $carac_sender_address,0,'L');

                if (!getDolGlobalString('MAIN_PDF_NO_ADDRESS_FRAME')) {
                    $pdf->RoundedRect($posx_sender_addr, $y_sender_addr_frame, $width_addr_box, $dynamic_addr_box_height, $this->corner_radius, '1234', 'D');
                }
            }
            // Use the greater Y position from right block or address block to determine next content start
            $top_shift = max($current_y_after_right_block, $shipp_shift) - $this->marge_haute;

        } else { // if $showaddress is false
             $top_shift = $current_y_after_right_block - $this->marge_haute;
             $shipp_shift = 0; // No address box height to consider
        }


        $pdf->SetTextColor(0, 0, 0); // Reset text color
        // The returned 'shipp_shift' here is actually the Y coordinate marking the end of the address block(s).
        // The 'top_shift' is the total vertical space consumed by all header elements above the main content table.
        // The original meaning of shipp_shift was the height of the shipping box itself, to be added to a base Y.
        // Let's make shipp_shift represent the total height taken by address block area from its start Y.
        // And top_shift be the Y where the content table should start.

        $pagehead_end_y = max($current_y_after_right_block, ($showaddress ? $y_shipping_addr_frame + $dynamic_addr_box_height : $this->marge_haute));

        return array('top_shift' => $pagehead_end_y, 'shipp_shift' => 0); // shipp_shift is not used in the same way as original, top_shift is now the critical Y value.
    }


    /**
     *  Show footer of page.
     *  CHANGE_ME: Adjust free text key if necessary (e.g., 'SHIPMENT_FREE_TEXT')
     *
     *  @param      TCPDF           $pdf            PDF
     *  @param      CommonObject    $object         Object to show
     *  @param      Translate       $outputlangs    Object lang for output
     *  @param      int             $hidefreetext   1=Hide free text
     *  @return     int                             Return height of bottom margin including footer text
     */
    protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
    {
        $showdetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
        // CHANGE_ME: Use a shipment-specific free text key if different from orders.
        return pdf_pagefoot($pdf, $outputlangs, 'EXPEDITION_FREE_TEXT', $this->emetteur, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, $showdetails, $hidefreetext, $this->page_largeur, $this->watermark);
    }

    /**
     *   Define Array Column Field for shipment items.
     *   CHANGE_ME: This method needs to be completely redefined for shipment documents.
     *              Columns will be different (e.g., Product Ref, Description, Qty Shipped, Serial/Lot, Weight).
     *
     *   @param      CommonObject    $object         common object (Shipment object)
     *   @param      Translate       $outputlangs    langs
     *   @param      int             $hidedetails    Do not show line details
     *   @param      int             $hidedesc       Do not show desc
     *   @param      int             $hideref        Do not show ref
     *   @return     void
     */
    public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $hookmanager;

        $this->cols = array(); // Reset columns from parent

        // Default styles (can be kept or adjusted)
        $this->defaultContentsFieldsStyle = array('align' => 'L', 'padding' => array(1, 0.5, 1, 1.5));
        $this->defaultTitlesFieldsStyle = array('align' => 'C', 'padding' => array(0.5, 0, 0.5, 0));

        $rank = 0;

        // Example Columns for a Shipment Document:
        // Item No. / Position
        $this->cols['position'] = array(
            'rank' => $rank += 10, 'width' => 10, 'status' => true, //getDolGlobalInt('PDF_SHIPMENT_ADD_POSITION')
            'title' => array('textkey' => '#', 'align' => 'C'),
            'content' => array('align' => 'C')
        );

        // Product Reference
        $this->cols['product_ref'] = array(
            'rank' => $rank += 10, 'width' => 30, 'status' => true,
            'title' => array('textkey' => 'Ref.', 'label' => $outputlangs->transnoentities("Ref."), 'align' => 'L'),
            'content' => array('align' => 'L')
        );

        // Description (Product Label)
        // The 'width' => false makes this column take remaining space.
        $this->cols['desc'] = array(
            'rank' => $rank += 10, 'width' => false, 'status' => true,
            'title' => array('textkey' => 'Description', 'label' => $outputlangs->transnoentities("Description"), 'align' => 'L'),
            'content' => array('align' => 'L')
        );

        // Quantity Shipped
        $this->cols['qty_shipped'] = array(
            'rank' => $rank += 10, 'width' => 15, 'status' => true,
            'title' => array('textkey' => 'QtyShipped', 'label' => $outputlangs->transnoentities("QtyShipped"), 'align' => 'C'), // New lang key
            'content' => array('align' => 'C')
        );

        // Serial Numbers / Lot (if applicable)
        // $this->cols['serialnumber'] = array(
        //     'rank' => $rank += 10, 'width' => 30, 'status' => getDolGlobalInt('SHIPMENT_SHOW_SERIALNUMBERS'), // Example global conf
        //     'title' => array('textkey' => 'LotSerial', 'align' => 'L'),
        //     'content' => array('align' => 'L')
        // );

        // Weight (if applicable)
        // $this->cols['weight'] = array(
        //     'rank' => $rank += 10, 'width' => 20, 'status' => getDolGlobalInt('SHIPMENT_SHOW_WEIGHT'),
        //     'title' => array('textkey' => 'Weight', 'align' => 'R'),
        //     'content' => array('align' => 'R')
        // );


        // Remove VAT/Price related columns inherited if not needed
        // unset($this->cols['vat']);
        // unset($this->cols['subprice']);
        // unset($this->cols['discount']);
        // unset($this->cols['totalexcltax']);
        // unset($this->cols['totalincltax']);


        // Add extrafields cols for shipment lines (if any)
        if (!empty($object->lines)) {
            $line = reset($object->lines); // Get a sample line
            if (is_object($line)) { // Ensure $line is an object
                 $this->defineColumnExtrafield($line, $outputlangs, $hidedetails);
            }
        }

        // Hook for other modules to modify columns
        $parameters = array('object' => $object, 'outputlangs' => $outputlangs, 'hidedetails' => $hidedetails, 'hidedesc' => $hidedesc, 'hideref' => $hideref);
        $reshook = $hookmanager->executeHooks('defineColumnField', $parameters, $this); // Context 'this' refers to pdf_expedition_document instance
        if ($reshook < 0) { setEventMessages($hookmanager->error, $hookmanager->errors, 'errors'); }
        elseif (empty($reshook)) { $this->cols = array_replace($this->cols, $hookmanager->resArray); }
        else { $this->cols = $hookmanager->resArray; }
    }

    /**
     * Overload default printCustomDescContent to ensure it fits shipment needs
     * or to fetch description from different fields if necessary.
     * For now, it will use the parent's logic which is:
     * $desc = $object->lines[$i]->desc ? $object->lines[$i]->desc : $object->lines[$i]->libelle;
     * This might be sufficient if $object->lines[$i] for shipments have these properties.
     */
    // protected function printCustomDescContent($pdf, $curY, $colKey, $object, $i, $outputlangs, $hideref = 0, $hidedesc = 0)
    // {
    //    // Custom logic for shipment item description if needed
    //    parent::printCustomDescContent($pdf, $curY, $colKey, $object, $i, $outputlangs, $hideref, $hidedesc);
    // }


    // Placeholder for new methods that might be needed for shipment specific data
    /**
     * Get the product reference for a shipment line.
     * @param object    $object         Shipment object
     * @param int       $i              Line index
     * @param Translate $outputlangs    Language object
     * @return string Product reference
     */
    protected function getLineProductRef($object, $i, $outputlangs)
    {
        if (empty($object->lines[$i]->fk_product)) { // Service or free text line
            return !empty($object->lines[$i]->product_ref) ? $object->lines[$i]->product_ref : '';
        }

        // Product line
        $product_static = new Product($this->db);
        $product_static->fetch($object->lines[$i]->fk_product);
        return $outputlangs->convToOutputCharset($product_static->ref);
    }

    /**
     * Get the quantity shipped for a shipment line.
     * @param object    $object         Shipment object
     * @param int       $i              Line index
     * @param Translate $outputlangs    Language object
     * @return string Quantity shipped
     */
    protected function getLineQtyShipped($object, $i, $outputlangs)
    {
        // Standard ExpeditionLigne object has ->qty
        return $outputlangs->convToOutputCharset($object->lines[$i]->qty);
    }

    /**
     * Get serial numbers for a line. Placeholder, needs actual implementation if used.
     */
    // protected function getLineSerialNumbers($object, $i, $outputlangs) { return "SN123, SN456"; }

    /**
     * Get weight for a line. Placeholder, needs actual implementation if used.
     */
    // protected function getLineWeight($object, $i, $outputlangs) { return ($object->lines[$i]->weight * $object->lines[$i]->qty) . ' kg'; }


    /**
     * Show miscellaneous shipment information (shipping method, tracking, dates etc.)
     * This replaces the payment-related drawInfoTable from orders/invoices.
     *
     * @param TCPDF     $pdf            Object PDF
     * @param object    $object         Object to show (Shipment object)
     * @param int       $posy           Y position to start drawing
     * @param Translate $outputlangs    Langs object
     * @return int      New Y position after drawing
     */
    protected function drawShipmentInfoTable(&$pdf, $object, $posy, $outputlangs)
    {
        global $conf, $mysoc;
        $default_font_size = pdf_getPDFFontSize($outputlangs);
        $pdf->SetFont('', '', $default_font_size - 1);

        $curY = $posy;
        $posx = $this->marge_gauche; // Start from left margin
        $cellheight = 4;
        $label_width = 50; // Width for labels
        $value_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite - $label_width - 5; // Remaining width for values

        // Example: Shipping Method
        if (!empty($object->fk_shipping_method_id)) {
            $shipping_method = new ShippingMethod($this->db);
            if ($shipping_method->fetch($object->fk_shipping_method_id) > 0) {
                $pdf->SetFont('', 'B', $default_font_size -1);
                $pdf->SetXY($posx, $curY);
                $pdf->MultiCell($label_width, $cellheight, $outputlangs->transnoentities("ShippingMethod").":", 0, 'L');
                $pdf->SetFont('', '', $default_font_size -1);
                $pdf->SetXY($posx + $label_width, $curY);
                $pdf->MultiCell($value_width, $cellheight, $outputlangs->convToOutputCharset($shipping_method->libelle), 0, 'L');
                $curY = $pdf->GetY() +1; // Space for next line
            }
        }

        // Example: Tracking Number
        if (!empty($object->tracking_number)) {
            $pdf->SetFont('', 'B', $default_font_size -1);
            $pdf->SetXY($posx, $curY);
            $pdf->MultiCell($label_width, $cellheight, $outputlangs->transnoentities("TrackingNumber").":", 0, 'L');
            $pdf->SetFont('', '', $default_font_size -1);
            $pdf->SetXY($posx + $label_width, $curY);
            $pdf->MultiCell($value_width, $cellheight, $outputlangs->convToOutputCharset($object->tracking_number), 0, 'L');
            $curY = $pdf->GetY() +1;
        }

        // Example: Date Shipped (if different from main date in header)
        // if ($object->date_shipping_effective) { ... }

        // Example: Total Weight (if calculated and stored on $object, e.g., $object->weight)
        if (isset($object->weight) && $object->weight > 0 && !empty($object->weight_units_label)) {
             $pdf->SetFont('', 'B', $default_font_size -1);
             $pdf->SetXY($posx, $curY);
             $pdf->MultiCell($label_width, $cellheight, $outputlangs->transnoentities("TotalWeight").":", 0, 'L');
             $pdf->SetFont('', '', $default_font_size -1);
             $pdf->SetXY($posx + $label_width, $curY);
             $pdf->MultiCell($value_width, $cellheight, price($object->weight) . " " . $outputlangs->transnoentities($object->weight_units_label), 0, 'L');
             $curY = $pdf->GetY() +1;
        }

        // Add more info as needed...
        // - Incoterms (if $object->incoterms exists)
        // - Number of packages (if $object->nb_packages exists)

        // Hook for other modules to add to info table
        $parameters_infotable = array('pdf' => $pdf, 'object' => $object, 'posy' => $curY, 'outputlangs' => $outputlangs, 'label_width' => $label_width, 'value_width' => $value_width, 'posx' => $posx);
        $reshook_infotable = $hookmanager->executeHooks('pdfShipmentInfoTable', $parameters_infotable, $this);
        if ($reshook_infotable > 0) {
            $curY = $parameters_infotable['posy']; // Hook might have updated posy
        }


        return $curY; // Return the Y position after drawing this table
    }

    /**
     * This function is called by write_file to display totals.
     * For shipments, financial totals are usually not relevant.
     * We might show total weight, number of packages, etc.
     * This can be combined with drawShipmentInfoTable or be separate.
     * For now, let's make it empty or minimal as drawShipmentInfoTable handles some of this.
     */
    protected function drawTotalTable(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis = null)
    {
        // Most financial totals from ModelePDFCommandes are not applicable here.
        // We can leave this empty, or add non-financial totals if not covered by drawShipmentInfoTable.
        // Example: if we need a distinct "Totals" box.

        // For now, let's ensure it doesn't error and returns a Y position.
        return $posy;
    }

    /**
     * Overload drawInfoTable from parent (ModelePDFDocuments or ModelePDFCommandes)
     * to call our shipment-specific version.
     */
    protected function drawInfoTable(&$pdf, $object, $posy, $outputlangs)
    {
        return $this->drawShipmentInfoTable($pdf, $object, $posy, $outputlangs);
    }


}
?>
