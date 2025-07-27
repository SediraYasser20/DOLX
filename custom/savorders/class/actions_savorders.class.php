<?php

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/orderline.class.php'; // Added for OrderLine updates
dol_include_once('/savorders/class/savorders.class.php');

/**
 * Class Actionssavorders
 */
class Actionssavorders
{
    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
     */
    public $results = array();

    /**
     * @var string String displayed by executeHook() immediately after return
     */
    public $resprints;

    /**
     * @var array Errors
     */
    public $errors = array();

    /**
     * Constructor
     */
    public function __construct()
    {
    }

   
public function doActions($parameters, &$object, &$action, $hookmanager) 
{
    global $langs, $db, $user, $conf;

    $langs->loadLangs(array('stocks'));
    $langs->load('savorders@savorders');

    $savorders = new savorders($db);

    $tmparray = ['receiptofproduct_valid', 'createdelivery_valid', 'deliveredtosupplier_valid', 'receivedfromsupplier_valid'];

    $ngtmpdebug = GETPOST('ngtmpdebug', 'int');
    if ($ngtmpdebug) {
        echo '<pre>';
        print_r($parameters);
        echo '</pre>';
        
        ini_set('display_startup_errors', 1);
        ini_set('display_errors', 1);
        error_reporting(-1);
    }

    if ($object && (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) && in_array($action, $tmparray)) {

        $error = 0;
        $now = dol_now();

        $savorders_date = '';
        global $savorders_date;

        $tmpdate = dol_mktime(0, 0, 0, GETPOST('savorders_datemonth', 'int'), GETPOST('savorders_dateday', 'int'), GETPOST('savorders_dateyear', 'int'));
        $savorders_date = dol_print_date($tmpdate, 'day');

        $cancel = GETPOST('cancel', 'alpha');
        $novalidaction = str_replace("_valid", "", $action);
        $s = GETPOST('savorders_data', 'array');

        $savorders_sav = $object->array_options["options_savorders_sav"];
        $savorders_status = $object->array_options["options_savorders_status"];

        if (!$savorders_sav || $cancel) return 0;

        // Force warehouse ID to 2 for all SAV operations
        $idwarehouse = 2;

        // This check is no longer needed as $idwarehouse is always 2
        // if (($novalidaction == 'receiptofproduct' || $novalidaction == 'deliveredtosupplier') && $idwarehouse <= 0) {
        //     $error++;
        //     $action = $novalidaction;
        // }

        $commande = $object;
        $nblines = count($commande->lines);

        if ($object->element == 'order_supplier') {
            $labelmouve = ($novalidaction == 'deliveredtosupplier') ? $langs->trans('ProductDeliveredToSupplier') : $langs->trans('ProductReceivedFromSupplier');
        } else {
            $labelmouve = ($novalidaction == 'receiptofproduct') ? $langs->trans('ProductReceivedFromCustomer') : $langs->trans('ProductDeliveredToCustomer');
        }

        $origin_element = '';
        $origin_id = null;

        $mouvement = 0; // 0: Add, 1: Delete
        if ($object->element == 'order_supplier') {
            $mouvement = ($novalidaction == 'deliveredtosupplier') ? 1 : 0;
        } else {
            $mouvement = ($novalidaction == 'receiptofproduct') ? 0 : 1;
        }

        $texttoadd = '';
        if (isset($object->array_options["options_savorders_history"])) {
            $texttoadd = $object->array_options["options_savorders_history"];
        }

        if ($novalidaction == 'createdelivery' || $novalidaction == 'receivedfromsupplier') {
            $texttoadd .= '<br>';
        }

        $oneadded = 0;

        if (!$error) {
            for ($i = 0; $i < $nblines; $i++) {
                if (empty($commande->lines[$i]->fk_product)) {
                    continue;
                }

                // Determine the correct extrafields table based on the object type
                $extrafields_table_name = '';
                if ($commande->element == 'order_supplier') {
                    $extrafields_table_name = MAIN_DB_PREFIX . "commande_fournisseur_extrafields";
                } else {
                    $extrafields_table_name = MAIN_DB_PREFIX . "commande_extrafields";
                }

                $objprod = new Product($db);
                $objprod->fetch($commande->lines[$i]->fk_product);

                if ($objprod->type != Product::TYPE_PRODUCT) continue;

                $tmid = $commande->lines[$i]->fk_product;

                // Force warehouse ID to 2 for all SAV operations
                $warehouse = 2;

                // Initial qty from form or line qty
                $qty = ($s && isset($s[$tmid]) && isset($s[$tmid]['qty'])) ? $s[$tmid]['qty'] : $commande->lines[$i]->qty;

                $line_id = $commande->lines[$i]->id; // Get line_id for potential use

                // Over-delivery prevention for 'createdelivery' (customer order delivery) action
                if ($commande->element != 'order_supplier' && $novalidaction == 'createdelivery') {
                    $sql = "SELECT savorders_received_qty, savorders_delivered_qty FROM " . $extrafields_table_name . " WHERE fk_object = " . (int)$line_id;
                    $resql = $db->query($sql);

                    $line_sav_received_qty = 0;
                    $line_sav_delivered_qty = 0;

                    if ($resql && $db->num_rows($resql) > 0) {
                        $obj = $db->fetch_object($resql);
                        $line_sav_received_qty = (float)$obj->savorders_received_qty;
                        $line_sav_delivered_qty = (float)$obj->savorders_delivered_qty;
                    }

                    dol_syslog("SAVORDERS Debug: Product " . $objprod->ref . " - Received: " . $line_sav_received_qty . ", Delivered: " . $line_sav_delivered_qty, LOG_DEBUG);

                    $qty_remaining_for_sav = max(0, $line_sav_received_qty - $line_sav_delivered_qty);

                    if ($qty > $qty_remaining_for_sav) {
                        $warning_message = "Product " . $objprod->ref . ": Trying to deliver " . $qty . " but only " . $qty_remaining_for_sav . " remaining (Received: " . $line_sav_received_qty . ", Already delivered: " . $line_sav_delivered_qty . ")";
                        if (method_exists($langs, 'trans') && $langs->trans("WarningSAVQtyAdjusted") != "WarningSAVQtyAdjusted") {
                            $warning_message = $langs->trans("WarningSAVQtyAdjusted", $objprod->ref, $qty_remaining_for_sav);
                        }
                        setEventMessages($warning_message, null, 'warnings');
                        dol_syslog("SAVORDERS Info: Delivery qty for product " . $objprod->ref . " (line " . $line_id . ") changed from " . $qty . " to " . $qty_remaining_for_sav . " to prevent over-delivery.", LOG_INFO);
                        $qty = $qty_remaining_for_sav;
                    }
                    if ($qty < 0) $qty = 0; // Ensure qty not negative
                }
                // Constraint for receiving items from supplier for SAV ('deliveredtosupplier' action)
                elseif ($commande->element == 'order_supplier' && $novalidaction == 'deliveredtosupplier') {
                    $original_order_qty = $commande->lines[$i]->qty;
                    
                    $sql_check_received = "SELECT savorders_received_qty FROM " . $extrafields_table_name . " WHERE fk_object = " . (int)$line_id;
                    $resql_check_received = $db->query($sql_check_received);
                    $current_sav_received_qty = 0;
                    if ($resql_check_received && $db->num_rows($resql_check_received) > 0) {
                        $obj_received = $db->fetch_object($resql_check_received);
                        $current_sav_received_qty = (float)$obj_received->savorders_received_qty;
                    }

                    $max_receivable_for_sav = max(0, $original_order_qty - $current_sav_received_qty);

                    if ($qty > $max_receivable_for_sav) {
                        $warning_message = "Product " . $objprod->ref . ": Trying to receive " . $qty . " for SAV, but this would exceed original order qty. Max allowed: " . $max_receivable_for_sav . " (Original order: " . $original_order_qty . ", Already received for SAV: " . $current_sav_received_qty . ")";
                        if (method_exists($langs, 'trans') && $langs->trans("WarningSAVQtyAdjustedOriginalOrderLimit") != "WarningSAVQtyAdjustedOriginalOrderLimit") {
                            $warning_message = $langs->trans("WarningSAVQtyAdjustedOriginalOrderLimit", $objprod->ref, $max_receivable_for_sav, $original_order_qty, $current_sav_received_qty);
                        }
                        setEventMessages($warning_message, null, 'warnings');
                        dol_syslog("SAVORDERS Info (Supplier SAV Reception): Reception qty for product " . $objprod->ref . " (line " . $line_id . ") changed from " . $qty . " to " . $max_receivable_for_sav . " due to original order qty limit.", LOG_INFO);
                        $qty = $max_receivable_for_sav;
                    }
                    if ($qty < 0) $qty = 0; // Ensure qty not negative
                }
                // Constraint for returning items to supplier ('receivedfromsupplier' action)
                elseif ($commande->element == 'order_supplier' && $novalidaction == 'receivedfromsupplier') {
                    $sql_supplier_sav = "SELECT savorders_received_qty, savorders_delivered_qty FROM " . $extrafields_table_name . " WHERE fk_object = " . (int)$line_id;
                    $resql_supplier_sav = $db->query($sql_supplier_sav);

                    $supplier_sav_received_qty = 0;
                    $supplier_sav_delivered_qty = 0;

                    if ($resql_supplier_sav && $db->num_rows($resql_supplier_sav) > 0) {
                        $obj_supplier_sav = $db->fetch_object($resql_supplier_sav);
                        $supplier_sav_received_qty = (float)$obj_supplier_sav->savorders_received_qty; // Total received from supplier for SAV
                        $supplier_sav_delivered_qty = (float)$obj_supplier_sav->savorders_delivered_qty; // Total already returned to supplier
                    }

                    dol_syslog("SAVORDERS Debug (Supplier Return): Product " . $objprod->ref . " - SAV Received: " . $supplier_sav_received_qty . ", SAV Returned: " . $supplier_sav_delivered_qty, LOG_DEBUG);

                    $qty_returnable_to_supplier = max(0, $supplier_sav_received_qty - $supplier_sav_delivered_qty);

                    if ($qty > $qty_returnable_to_supplier) {
                        $warning_message = "Product " . $objprod->ref . ": Trying to return " . $qty . " to supplier, but only " . $qty_returnable_to_supplier . " are pending return from SAV (Received for SAV: " . $supplier_sav_received_qty . ", Already returned: " . $supplier_sav_delivered_qty . ")";
                        if (method_exists($langs, 'trans') && $langs->trans("WarningSAVQtyAdjustedSupplierReturn") != "WarningSAVQtyAdjustedSupplierReturn") {
                            $warning_message = $langs->trans("WarningSAVQtyAdjustedSupplierReturn", $objprod->ref, $qty_returnable_to_supplier);
                        }
                        setEventMessages($warning_message, null, 'warnings');
                        dol_syslog("SAVORDERS Info (Supplier Return): Return qty for product " . $objprod->ref . " (line " . $line_id . ") changed from " . $qty . " to " . $qty_returnable_to_supplier, LOG_INFO);
                        $qty = $qty_returnable_to_supplier;
                    }
                    if ($qty < 0) $qty = 0; // Ensure qty not negative
                }


                if ($novalidaction == 'receiptofproduct' || $novalidaction == 'deliveredtosupplier') {
                    $warehouse = $idwarehouse;
                }

                // This check is no longer needed as $warehouse is always 2
                // if (($novalidaction == 'createdelivery') && $warehouse <= 0) {
                //     setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Warehouse")), null, 'errors');
                //     $error++;
                // }

                $txlabelmovement = '(SAV) ' . $objprod->ref . ': ' . $labelmouve;

                // Determine price to use: cost price for product 483, PMP otherwise
                $price_to_use = ($objprod->id == 483) ? $objprod->cost_price : $objprod->pmp;

                if ($objprod->hasbatch()) {

                    $qty = ($qty > $commande->lines[$i]->qty) ? $commande->lines[$i]->qty : $qty;

                    if ($qty) {
                        for ($z = 0; $z < $qty; $z++) {
                            $batch = ($s && isset($s[$tmid]) && isset($s[$tmid]['batch'][$z])) ? $s[$tmid]['batch'][$z] : '';

                            if (!$batch && $z == 0 && $qty > 0) {
                                setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("batch_number")), null, 'errors');
                                $error++;
                                break;
                            }

                            if (!$error && ($batch || $qty == 0)) { // If $qty is 0, we still might record history, but no stock move
                                // Validation for customer receiving product (receiptofproduct action)
                                if ($commande->element != 'order_supplier' && $novalidaction == 'receiptofproduct' && $qty > 0 && $batch) {
                                    $lot = new ProductLot($db);
                                    $res = $lot->fetch(0, $objprod->id, $batch);
                                    if ($res <= 0) {
                                        setEventMessages($langs->trans("BatchDoesNotExist", $batch), null, 'errors');
                                        $error++;
                                    }
                                }

                                if (!$error && $batch && $novalidaction == 'createdelivery' && $qty > 0) {
                                    $lot = new ProductLot($db);
                                    $res_lot_fetch = $lot->fetch(0, $objprod->id, $batch);
                                    if ($res_lot_fetch <= 0) {
                                        setEventMessages($langs->trans("SerialNumberNotForProduct", $batch, $objprod->ref), null, 'errors');
                                        $error++;
                                    }

                                    if (!$error) {
                                        $sql_stock_check = "SELECT SUM(pb.qty) as total_qty FROM " . MAIN_DB_PREFIX . "product_batch pb";
                                        $sql_stock_check .= " INNER JOIN " . MAIN_DB_PREFIX . "product_stock ps ON pb.fk_product_stock = ps.rowid";
                                        $sql_stock_check .= " WHERE ps.fk_product = " . (int)$objprod->id;
                                        $sql_stock_check .= " AND ps.fk_entrepot = " . (int)$warehouse;
                                        $sql_stock_check .= " AND pb.batch = '" . $db->escape($batch) . "';";

                                        $resql_stock_check = $db->query($sql_stock_check);
                                        if ($resql_stock_check) {
                                            $obj_stock = $db->fetch_object($resql_stock_check);
                                            if (!$obj_stock || $obj_stock->total_qty <= 0) {
                                                $warehouse_obj = new Entrepot($db);
                                                $warehouse_ref = $warehouse;
                                                if ($warehouse_obj->fetch($warehouse) > 0) {
                                                    $warehouse_ref = $warehouse_obj->ref;
                                                }
                                                setEventMessages($langs->trans("SerialNumberNotInStockOrZeroQty", $batch, $warehouse_ref), null, 'errors');
                                                $error++;
                                            }
                                        } else {
                                            dol_syslog("SAVORDERS Error checking stock for batch: " . $db->error(), LOG_ERR);
                                            setEventMessages($langs->trans("ErrorCheckingSerialNumberStock", $batch), null, 'errors');
                                            $error++;
                                        }
                                    }
                                }
                                
                                // End of specific serial validations for this batch ($z)

                                if ($error) break; // If any validation for this batch failed (error was incremented), break from processing further batches for this line item $i.

                                if (!$error && $qty > 0) { // This $qty is the outer loop qty (number of batches for the line)
                                                             // The actual stock move is for 1 unit of this batch
                                    $result = $objprod->correct_stock_batch(
                                        $user,
                                        $warehouse,
                                        1,
                                        $mouvement,
                                        $txlabelmovement,
                                        $price_to_use,
                                        '', '', // eatby, sellby
                                        $batch,
                                        '',
                                        $origin_element,
                                        $origin_id,
                                        0
                                    );

                                    if ($result > 0) {
                                        $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod, $batch);
                                        $oneadded++;

                                        $line_id = $commande->lines[$i]->id;

                                        // Robust SAV quantity update logic
                                        $qty_to_add = 1;
                                        $sql_check = "SELECT savorders_received_qty, savorders_delivered_qty FROM " . $extrafields_table_name . " WHERE fk_object = " . (int)$line_id;
                                        $resql_check = $db->query($sql_check);

                                        if ($resql_check && $db->num_rows($resql_check) > 0) {
                                            $obj_check = $db->fetch_object($resql_check);
                                            $new_received_qty = (float)$obj_check->savorders_received_qty;
                                            $new_delivered_qty = (float)$obj_check->savorders_delivered_qty;

                                            if ($commande->element == 'order_supplier') {
                                                if ($novalidaction == 'deliveredtosupplier') { // Received from supplier for SAV
                                                    $new_received_qty += $qty_to_add;
                                                    $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_received_qty = " . $new_received_qty . " WHERE fk_object = " . (int)$line_id;
                                                } elseif ($novalidaction == 'receivedfromsupplier') { // Returned to supplier after SAV
                                                    $new_delivered_qty += $qty_to_add;
                                                    $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_delivered_qty = " . $new_delivered_qty . " WHERE fk_object = " . (int)$line_id;
                                                }
                                            } else { // Customer Order
                                                if ($novalidaction == 'receiptofproduct') {
                                                    $new_received_qty += $qty_to_add;
                                                    $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_received_qty = " . $new_received_qty . " WHERE fk_object = " . (int)$line_id;
                                                } elseif ($novalidaction == 'createdelivery') {
                                                    $new_delivered_qty += $qty_to_add;
                                                    $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_delivered_qty = " . $new_delivered_qty . " WHERE fk_object = " . (int)$line_id;
                                                }
                                            }
                                            
                                            if (isset($sql_update) && !$db->query($sql_update)) {
                                                dol_syslog("SAVORDERS Error updating SAV quantities: " . $db->error(), LOG_ERR);
                                                setEventMessages("Error updating SAV quantities", null, 'errors');
                                                $error++;
                                            }
                                        } else {
                                            // Insert new row
                                            $received_qty = 0;
                                            $delivered_qty = 0;

                                            if ($commande->element == 'order_supplier') {
                                                if ($novalidaction == 'deliveredtosupplier') { // Received from supplier for SAV
                                                    $received_qty = $qty_to_add;
                                                } elseif ($novalidaction == 'receivedfromsupplier') { // Returned to supplier after SAV
                                                    $delivered_qty = $qty_to_add;
                                                }
                                                $sql_insert = "INSERT INTO " . $extrafields_table_name . " (fk_object, savorders_received_qty, savorders_delivered_qty, marketing_) VALUES (" . (int)$line_id . ", " . $received_qty . ", " . $delivered_qty . ", 0)";
                                            } else { // Customer Order
                                                if ($novalidaction == 'receiptofproduct') {
                                                    $received_qty = $qty_to_add;
                                                } elseif ($novalidaction == 'createdelivery') {
                                                    $delivered_qty = $qty_to_add;
                                                }
                                                // Customer orders do not get 'marketing_' field in this custom module's insert logic
                                                $sql_insert = "INSERT INTO " . $extrafields_table_name . " (fk_object, savorders_received_qty, savorders_delivered_qty) VALUES (" . (int)$line_id . ", " . $received_qty . ", " . $delivered_qty . ")";
                                            }
                                            
                                            if (!$db->query($sql_insert)) {
                                                dol_syslog("SAVORDERS Error inserting SAV quantities into ".$extrafields_table_name.": " . $db->error(), LOG_ERR);
                                                setEventMessages("Error inserting SAV quantities", null, 'errors');
                                                $error++;
                                            }
                                        }
                                    } else {
                                        $error++;
                                    }
                                }
                            }
                        }
                    }
                } else { // Non-batch product
                    if (!$error && $qty >= 0) {
                        $line_processed_non_batch = false;

                        if ($qty > 0) {
                            $result = $objprod->correct_stock(
                                $user,
                                $warehouse,
                                $qty,
                                $mouvement,
                                $txlabelmovement,
                                $price_to_use,
                                '',
                                $origin_element,
                                $origin_id,
                                0
                            );

                            if ($result > 0) {
                                $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod);
                                $oneadded++;
                                $line_processed_non_batch = true;
                            } else {
                                $error++;
                            }
                        } else { // qty == 0
                            $this->addLineHistoryToSavCommande($texttoadd, $novalidaction, $objprod);
                            $oneadded++;
                            $line_processed_non_batch = true;
                        }

                        if ($line_processed_non_batch && !$error) {
                            $line_id = $commande->lines[$i]->id;

                            $qty_to_add = $qty;

                            $sql_check = "SELECT savorders_received_qty, savorders_delivered_qty FROM " . $extrafields_table_name . " WHERE fk_object = " . (int)$line_id;
                            $resql_check = $db->query($sql_check);

                            if ($resql_check && $db->num_rows($resql_check) > 0) {
                                $obj_check = $db->fetch_object($resql_check);
                                $new_received_qty = (float)$obj_check->savorders_received_qty;
                                $new_delivered_qty = (float)$obj_check->savorders_delivered_qty;

                                if ($commande->element == 'order_supplier') {
                                    if ($novalidaction == 'deliveredtosupplier') { // Received from supplier for SAV
                                        $new_received_qty += $qty_to_add;
                                        $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_received_qty = " . $new_received_qty . " WHERE fk_object = " . (int)$line_id;
                                    } elseif ($novalidaction == 'receivedfromsupplier') { // Returned to supplier after SAV
                                        $new_delivered_qty += $qty_to_add;
                                        $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_delivered_qty = " . $new_delivered_qty . " WHERE fk_object = " . (int)$line_id;
                                    }
                                } else { // Customer Order
                                    if ($novalidaction == 'receiptofproduct') {
                                        $new_received_qty += $qty_to_add;
                                        $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_received_qty = " . $new_received_qty . " WHERE fk_object = " . (int)$line_id;
                                    } elseif ($novalidaction == 'createdelivery') {
                                        $new_delivered_qty += $qty_to_add;
                                        $sql_update = "UPDATE " . $extrafields_table_name . " SET savorders_delivered_qty = " . $new_delivered_qty . " WHERE fk_object = " . (int)$line_id;
                                    }
                                }

                                if (isset($sql_update) && !$db->query($sql_update)) {
                                    dol_syslog("SAVORDERS Error updating SAV quantities: " . $db->error(), LOG_ERR);
                                    setEventMessages("Error updating SAV quantities", null, 'errors');
                                    $error++;
                                }
                            } else {
                                // Insert new row
                                $received_qty = 0;
                                $delivered_qty = 0;

                                if ($commande->element == 'order_supplier') {
                                    if ($novalidaction == 'deliveredtosupplier') { // Received from supplier for SAV
                                        $received_qty = $qty_to_add;
                                    } elseif ($novalidaction == 'receivedfromsupplier') { // Returned to supplier after SAV
                                        $delivered_qty = $qty_to_add;
                                    }
                                    $sql_insert = "INSERT INTO " . $extrafields_table_name . " (fk_object, savorders_received_qty, savorders_delivered_qty, marketing_) VALUES (" . (int)$line_id . ", " . $received_qty . ", " . $delivered_qty . ", 0)";
                                } else { // Customer Order
                                    if ($novalidaction == 'receiptofproduct') {
                                        $received_qty = $qty_to_add;
                                    } elseif ($novalidaction == 'createdelivery') {
                                        $delivered_qty = $qty_to_add;
                                    }
                                    $sql_insert = "INSERT INTO " . $extrafields_table_name . " (fk_object, savorders_received_qty, savorders_delivered_qty) VALUES (" . (int)$line_id . ", " . $received_qty . ", " . $delivered_qty . ")";
                                }
                                
                                if (!$db->query($sql_insert)) {
                                    dol_syslog("SAVORDERS Error inserting SAV quantities into ".$extrafields_table_name.": " . $db->error(), LOG_ERR);
                                    setEventMessages("Error inserting SAV quantities", null, 'errors');
                                    $error++;
                                }
                            }
                        }
                    }
                }

                if ($error) break;
            }
        }

        if (!$error && $oneadded) {
            // Update SAV status accordingly
            if ($object->element == 'order_supplier') {
                if ($novalidaction == 'deliveredtosupplier') { // Items received from supplier for SAV
                    $savorders_status = $savorders::DELIVERED_SUPPLIER;
                } elseif ($novalidaction == 'receivedfromsupplier') { // Items returned to supplier after SAV
                    $all_sav_items_returned_to_supplier = true;
                    foreach ($commande->lines as $line) {
                        if (empty($line->fk_product)) continue;

                        $status_extrafields_table_name = MAIN_DB_PREFIX . "commande_fournisseur_extrafields"; // Explicit for supplier
                        
                        $sql_stat = "SELECT savorders_received_qty, savorders_delivered_qty FROM " . $status_extrafields_table_name . " WHERE fk_object = " . (int)$line->id;
                        $resql_stat = $db->query($sql_stat);
                        $line_sav_received_supp = 0;
                        $line_sav_delivered_supp = 0;
                        if ($resql_stat && $db->num_rows($resql_stat) > 0) {
                            $obj_stat = $db->fetch_object($resql_stat);
                            $line_sav_received_supp = (float)$obj_stat->savorders_received_qty;
                            $line_sav_delivered_supp = (float)$obj_stat->savorders_delivered_qty;
                        }

                        if ($line_sav_received_supp > 0 && $line_sav_delivered_supp < $line_sav_received_supp) {
                            $all_sav_items_returned_to_supplier = false;
                            break;
                        }
                    }
                    if ($all_sav_items_returned_to_supplier) {
                        // Only set to RECEIVED_SUPPLIER if all items taken for SAV have been returned
                        // And if there was at least one item received for SAV initially.
                        $any_item_received_for_sav = false;
                        foreach($commande->lines as $line) {
                             if (empty($line->fk_product)) continue;
                             $sql_check_initial_reception = "SELECT savorders_received_qty FROM " . MAIN_DB_PREFIX . "commande_fournisseur_extrafields WHERE fk_object = " . (int)$line->id . " AND savorders_received_qty > 0";
                             $resql_check_initial_reception = $db->query($sql_check_initial_reception);
                             if($resql_check_initial_reception && $db->num_rows($resql_check_initial_reception) > 0) {
                                 $any_item_received_for_sav = true;
                                 break;
                             }
                        }
                        if ($any_item_received_for_sav) {
                             $savorders_status = $savorders::RECEIVED_SUPPLIER;
                        } else {
                            // If no items were ever marked as received from supplier for SAV, keep current status or DELIVERED_SUPPLIER
                            // This case might need further thought if it's common, but for now, it defaults to DELIVERED_SUPPLIER if no prior status
                            $savorders_status = !empty($object->array_options["options_savorders_status"]) ? $object->array_options["options_savorders_status"] : $savorders::DELIVERED_SUPPLIER;
                        }

                    } else {
                        // Not all items returned yet, so SAV with supplier is still active
                        $savorders_status = $savorders::DELIVERED_SUPPLIER;
                    }
                }
                // If action is neither, keep existing status (should not happen with current buttons)
                // else {
                //     $savorders_status = $object->array_options["options_savorders_status"];
                // }
            } else { // Customer order
                if ($novalidaction == 'process_reimbursement') {
                    $savorders_status = $savorders::REIMBURSED;
                } elseif ($novalidaction == 'receiptofproduct') {
                    $savorders_status = $savorders::RECIEVED_CUSTOMER;
                } elseif ($novalidaction == 'createdelivery') {
                    $all_sav_items_delivered = true;

                    foreach ($commande->lines as $line) {
                        if (empty($line->fk_product)) continue;

                        // Determine the correct extrafields table based on the object type for status calculation
                        $status_extrafields_table_name = '';
                        if ($commande->element == 'order_supplier') {
                            $status_extrafields_table_name = MAIN_DB_PREFIX . "commande_fournisseur_extrafields";
                        } else {
                            $status_extrafields_table_name = MAIN_DB_PREFIX . "commande_extrafields";
                        }

                        $sql = "SELECT savorders_received_qty FROM " . $status_extrafields_table_name . " WHERE fk_object = " . (int)$line->id;
                        $resql = $db->query($sql);
                        $line_sav_received = 0;
                        if ($resql && $db->num_rows($resql) > 0) {
                            $obj = $db->fetch_object($resql);
                            $line_sav_received = (float)$obj->savorders_received_qty;
                        }

                        $sql = "SELECT savorders_delivered_qty FROM " . $status_extrafields_table_name . " WHERE fk_object = " . (int)$line->id;
                        $resql = $db->query($sql);
                        $line_sav_delivered = 0;
                        if ($resql && $db->num_rows($resql) > 0) {
                            $obj = $db->fetch_object($resql);
                            $line_sav_delivered = (float)$obj->savorders_delivered_qty;
                        }

                        if ($line_sav_received > 0 && $line_sav_delivered < $line_sav_received) {
                            $all_sav_items_delivered = false;
                            break;
                        }
                    }

                    $savorders_status = $all_sav_items_delivered ? $savorders::DELIVERED_CUSTOMER : $savorders::RECIEVED_CUSTOMER;
                } else {
                    $savorders_status = $object->array_options["options_savorders_status"];
                }
            }

            $texttoadd = str_replace(['<span class="savorders_history_td">', '</span>'], ' ', $texttoadd);

            $extrafieldtxt = '<span class="savorders_history_td">' . $texttoadd . '</span>';

            $object->array_options["options_savorders_history"] = $extrafieldtxt;
            $object->array_options["options_savorders_status"] = $savorders_status;

            if ($novalidaction == 'process_reimbursement') {
                $object->array_options['options_facture_sav'] = GETPOST('facture_sav', 'int');
            }

            $result = $object->insertExtraFields();
            if (!$result) $error++;
        }

        if ($error) {
            setEventMessages($objprod->errors, $object->errors, 'errors');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=' . $novalidaction);
        } else {
            if ($oneadded) setEventMessages($langs->trans("RecordCreatedSuccessfully"), null, 'mesgs');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit();
        }
    }
}


  public function addLineHistoryToSavCommande(&$texttoadd, $novalidaction, $objprod = '', $batch = '')
    {
        global $langs, $savorders_date;

        $contenu = '- '.$savorders_date.': ';

        if($novalidaction == 'receiptofproduct' || $novalidaction == 'receivedfromsupplier') {
            $contenu .= $langs->trans("OrderSavRecieveProduct");
        }
        elseif($novalidaction == 'createdelivery' || $novalidaction == 'deliveredtosupplier') {
            $contenu .= $langs->trans("OrderSavDeliveryProduct");
        }
        elseif($novalidaction == 'process_reimbursement') {
            $contenu .= $langs->trans("OrderSavReimbursementProcessed");
        }

        $contenu .= ' <a target="_blank" href="'.dol_buildpath('/product/card.php?id='.$objprod->id, 1).'">';
        $contenu .= '<b>'.$objprod->ref.'</b>';
        $contenu .= '</a>';

        if($batch) {
            $contenu .=  ' NÂ° <b>'.$batch.'</b>';
        }

        $texttoadd .=  '<div class="savorders_history_txt " title="'.strip_tags($contenu).'">';
        $texttoadd .= $contenu;
        $texttoadd .=  '</div>';
    }

    public function addMoreActionsButtons($parameters, &$object, &$action = '')
    {
        global $db, $conf, $langs, $confirm, $user;

        $langs->load('admin');
        $langs->load('savorders@savorders');

    $allowed = $user->admin;

    if (!$allowed) {
        $sql = "
            SELECT 1 
            FROM ".MAIN_DB_PREFIX."usergroup_user u
            WHERE u.fk_user = ".(int)$user->id."
              AND u.fk_usergroup = 5
              AND u.entity = ".(int)$conf->entity;
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $allowed = true;
        }
    }

    if (! $allowed) {
        return 0;
    }

        $form = new Form($db);

        $ngtmpdebug = GETPOST('ngtmpdebug', 'int');
        if($ngtmpdebug) {
            echo '<pre>';
            print_r($parameters);
            echo '</pre>';

            ini_set('display_startup_errors', 1);
            ini_set('display_errors', 1);
            error_reporting(-1);
        }
		
        if (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) {

            $s = GETPOST('savorders_data', 'array');
            $linktogo = $_SERVER["PHP_SELF"].'?id=' . $object->id;
            $tmparray = ['receiptofproduct', 'createdelivery', 'deliveredtosupplier', 'receivedfromsupplier', 'process_reimbursement'];

            if(in_array($action, $tmparray)) {
                ?>
                <script type="text/javascript">
                    $(document).ready(function() {
                        $('html, body').animate({
                            scrollTop: ($("#savorders_formconfirm").offset().top - 80)
                        }, 800);

                        function toggleBatchInput(qtyInput) {
                            var qty = parseInt($(qtyInput).val());
                            var batchContainer = $(qtyInput).closest('tr').find('.batch-input-container');
                            if (qty === 0) {
                                batchContainer.hide();
                            } else {
                                batchContainer.show();
                            }
                        }

                        $('input[name^="savorders_data"][name$="[qty]"]').each(function() {
                            toggleBatchInput(this);
                        });

                        $('input[name^="savorders_data"][name$="[qty]"]').on('input change', function() {
                            toggleBatchInput(this);
                        });
                    });
                </script>
                <?php

                if($object->element == 'order_supplier') {
                    $title = ($action == 'deliveredtosupplier') ? $langs->trans('ProductDeliveredToSupplier') : $langs->trans('ProductReceivedFromSupplier');
                } else {
                    if ($action == 'process_reimbursement') {
                        $title = $langs->trans('ProcessReimbursement');
                    } else {
                        $title = ($action == 'receiptofproduct') ? $langs->trans('ProductReceivedFromCustomer') : $langs->trans('ProductDeliveredToCustomer');
                    }
                }

                $formproduct = new FormProduct($db);
                $nblines = count($object->lines);
                
                print '<div class="tagtable paddingtopbottomonly centpercent noborderspacing savorders_formconfirm" id="savorders_formconfirm">';
                print_fiche_titre($title, '', $object->picto);

                // Force warehouse ID to 2 for all SAV operations
                $idwarehouse = 2;

                if($action == 'receiptofproduct' && $idwarehouse <= 0) { // This condition will now always be false
                    $link = '<a href="'.dol_buildpath('savorders/admin/admin.php', 1).'" target="_blank">'.img_picto('', 'setup', '').' '.$langs->trans("Configuration").'</a>';
                    setEventMessages($langs->trans("ErrorFieldRequired", $langs->trans('SAV').' '.dol_htmlentitiesbr_decode($langs->trans('Warehouse'))).' '.$link, null, 'errors');
                    $error++;
                }

                print '<div class="tagtable paddingtopbottomonly centpercent noborderspacing savorders_formconfirm" id="savorders_formconfirm">';
                print '<form method="POST" action="'.$linktogo.'" class="notoptoleftroright">'."\n";
                print '<input type="hidden" name="action" value="'.$action.'_valid">'."\n";
                print '<input type="hidden" name="token" value="'.(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '').'">'."\n";

                $now = dol_now();

                print '<table class="valid centpercent">';
                    
                    print '<tr>';
                    if ($action == 'process_reimbursement') {
                        print '<tr><td colspan="2">'.$langs->trans("SelectInvoiceForReimbursement").'</td></tr>';
                        print '<tr>';
                        print '<td class="fieldrequired">'.$langs->trans("Invoice").'</td>';
                        print '<td>';
                        $factures = array(); 
                        if (method_exists($object, 'fetch_thirdparty')) {
                            $object->fetch_thirdparty();
                            $sql_invoices = "SELECT rowid, facnumber FROM ".MAIN_DB_PREFIX."facture WHERE fk_soc = ".$object->fk_soc." AND fk_statut > 0 ORDER BY facnumber DESC";
                            $resql_invoices = $db->query($sql_invoices);
                            if ($resql_invoices) {
                                while ($obj_inv = $db->fetch_object($resql_invoices)) {
                                    $factures[$obj_inv->rowid] = $obj_inv->facnumber;
                                }
                            }
                        }
                        print $form->selectarray('facture_sav', $factures, GETPOST('facture_sav', 'int'), 1);
                        print '</td>';
                        print '</tr>';
                    } else {
                        print '<tr>';
                        print '<td class="left"><b>'.$langs->trans("Product").'</b></td>';
                        print '<td class="left"><b>'.$langs->trans("batch_number").'</b></td>';
                        print '<td class="left"><b>'.$langs->trans("Qty").'</b></td>';

                        if($action == 'createdelivery' || $action == 'receivedfromsupplier') {
                            print '<td class="left">'.$langs->trans("Warehouse").'</td>';
                        }
                        print '</tr>';

                        for ($i = 0; $i < $nblines; $i++) {
                            if (empty($object->lines[$i]->fk_product)) {
                                continue;
                            }

                            $objprod = new Product($db);
                            $objprod->fetch($object->lines[$i]->fk_product);

                            if($objprod->type != Product::TYPE_PRODUCT) continue;

                            $hasbatch = $objprod->hasbatch();
                            $tmid = $object->lines[$i]->fk_product;
                            $warehouse  = $s && isset($s[$tmid]) && isset($s[$tmid]['warehouse']) ? $s[$tmid]['warehouse'] : 0;
                            $qty        = $s && isset($s[$tmid]) && isset($s[$tmid]['qty']) ? $s[$tmid]['qty'] : $object->lines[$i]->qty;

                            print '<tr class="oddeven_">';
                            print '<td class="left width300">'.$objprod->getNomUrl(1).'</td>';

                            print '<td class="left width300 batch-input-container">';
                            if($hasbatch) {
                                for ($z=0; $z < $object->lines[$i]->qty; $z++) { 
                                    $batch = $s && isset($s[$tmid]) && isset($s[$tmid]['batch'][$z]) ? $s[$tmid]['batch'][$z] : '';
                                    $display_batch_input = ($qty > $z) ? '' : 'style="display:none;"';
                                    print '<input type="text" class="flat width200 batch_input_field_'.$tmid.'" name="savorders_data['.$tmid.'][batch]['.$z.']" value="'.$batch.'" '.$display_batch_input.'/>';
                                }
                            } else {
                                print '-';
                            }
                            print '</td>';

                            $maxqty = ($hasbatch) ? 'max="'.$object->lines[$i]->qty.'"' : ''; 

                            print '<td class="left ">';
                            print '<input type="number" class="flat width50 savorder-qty-input" name="savorders_data['.$tmid.'][qty]" value="'.$qty.'" '.$maxqty.' min="0" step="any" data-product-id="'.$tmid.'" />';
                            print '</td>';

                            if($action == 'createdelivery' || $action == 'receivedfromsupplier') {
                                print '<td class="left selectWarehouses">';
                                // $formproduct_sel = new FormProduct($db);
                                // if (!isset($forcecombo)) {
                                //     $forcecombo = 0;  
                                // }
                                // print $formproduct_sel->selectWarehouses($warehouse, 'savorders_data['.$tmid.'][warehouse]', '', 0, 0, 0, '', 0, $forcecombo);
                                print '<input type="hidden" name="savorders_data['.$tmid.'][warehouse]" value="2" />';
                                print $langs->trans("Warehouse").': Entrepôt SAV (ID: 2)'; // Displaying the fixed warehouse
                                print '</td>';
                            }
                            print '</tr>';
                        }
                    }

                    print '<tr><td colspan="'.($action == 'process_reimbursement' ? 2 : 4).'"></td></tr>';
                    print '<tr>';
                        print '<td colspan="'.($action == 'process_reimbursement' ? 2 : 4).'" class="center">';
                        print '<div class="savorders_dateaction">';
                        print '<b>'.$langs->trans('Date').'</b>: ';
                        print $form->selectDate('', 'savorders_date', 0, 0, 0, '', 1, 1);
                        print '</div>';
                        print '</td>';
                    print '</tr>';

                    print '<tr class="valid">';
                    print '<td class="valid center" colspan="'.($action == 'process_reimbursement' ? 2 : 4).'">';
                    print '<input type="submit" class="button valignmiddle" name="validate" value="'.$langs->trans("Validate").'">';
                    print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans("Cancel").'">';
                    print '</td>';
                    print '</tr>'."\n";

                print '</table>';
                print "</form>\n";

                if (!empty($conf->use_javascript_ajax)) {
                    print '<script type="text/javascript">'."\n";
                    print '
                    $(document).ready(function () {
                        $(".confirmvalidatebutton").on("click", function() {
                            $(this).attr("disabled", "disabled");
                            setTimeout(function() { $(".confirmvalidatebutton").removeAttr("disabled"); }, 3000);
                            $(this).closest("form").submit();
                        });
                        $("td.selectWarehouses select").select2();

                        function updateBatchInputs(productId, currentQty) {
                            var originalQty = parseInt($(".savorder-qty-input[data-product-id=\'" + productId + "\']").attr("max")); 
                            $(".batch_input_field_" + productId).each(function(index) {
                                if (index < currentQty) {
                                    $(this).show();
                                } else {
                                    $(this).hide();
                                }
                            });
                        }

                        $(".savorder-qty-input").each(function() {
                            var productId = $(this).data("product-id");
                            var currentQty = parseInt($(this).val());
                            updateBatchInputs(productId, currentQty);
                        });

                        $(".savorder-qty-input").on("input change", function() {
                            var productId = $(this).data("product-id");
                            var currentQty = parseInt($(this).val());
                            if (isNaN(currentQty) || currentQty < 0) currentQty = 0; 

                            var batchContainer = $(this).closest("tr").find(".batch-input-container");
                            if (currentQty === 0) {
                                batchContainer.find("input[type=\'text\']").hide();
                            } else {
                                updateBatchInputs(productId, currentQty);
                            }
                        });
                    });
                    ';
                    print '</script>'."\n";
                }

                print '</div>';
                print '<br>';
                return 1;
            }
        }

        if (in_array('ordercard', explode(':', $parameters['context'])) || in_array('ordersuppliercard', explode(':', $parameters['context']))) {
            if ($object->statut < 1) return 0;

            $nblines = count($object->lines);
            $savorders_sav = $object->array_options["options_savorders_sav"];
            $savorders_status = $object->array_options["options_savorders_status"];

            if($ngtmpdebug) {
                echo 'nblines : '.$nblines.'<br>';
                echo 'savorders_sav : '.$savorders_sav.'<br>';
                echo 'savorders_status : '.$savorders_status.'<br>';
                echo 'object->element : '.$object->element.'<br>';
            }

            if($savorders_sav && $nblines > 0) {
                print '<div class="inline-block divButAction">';
                if($object->element == 'order_supplier') {
                    // Always show both buttons for supplier orders if SAV is active
                    print '<a id="savorders_button_delivered" class="savorders butAction badge-status1" href="'.$linktogo.'&action=deliveredtosupplier&token='.newToken().'">' . $langs->trans('ProductDeliveredToSupplier');
                    print '</a>';
                    print '<a id="savorders_button_received" class="savorders butAction badge-status1" href="'.$linktogo.'&action=receivedfromsupplier&token='.newToken().'">' . $langs->trans('ProductReceivedFromSupplier');
                    print '</a>';
                } else {
                    if(empty($savorders_status)) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=receiptofproduct&token='.newToken().'">' . $langs->trans('ProductReceivedFromCustomer');
                        print '</a>';
                    } 
                    elseif($savorders_status == savorders::RECIEVED_CUSTOMER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status1" href="'.$linktogo.'&action=createdelivery&token='.newToken().'">' . $langs->trans('ProductDeliveredToCustomer');
                        print '</a>';
                    }
                    elseif($savorders_status == savorders::DELIVERED_CUSTOMER) {
                        print '<a id="savorders_button" class="savorders butAction badge-status3" href="'.$linktogo.'&action=process_reimbursement&token='.newToken().'">' . $langs->trans('ProcessReimbursement');
                        print '</a>';
                    }
                }
                print '</div>';
            }
        }
        return 0;
    }

    public function printObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $conf;

        if (! in_array('ordercard', explode(':', $parameters['context']))) {
            return 0;
        }

        if (isset($parameters['optionals']['savorders_status'])) {
            $status = (int) $object->array_options['options_savorders_status'];
            if ($status === savorders::REIMBURSED) {
                $facId = (int) $object->array_options['options_facture_sav'];
                $label = $langs->trans('Reimbursed');  
                if ($facId > 0) {
                    $fac = new Facture($db);
                    if ($fac->fetch($facId) > 0) {
                        $amt = price($fac->total_ttc).' '.$langs->trans("Currency".$conf->currency);
                        $label = $langs->trans('ClientReimbursedAmount', $amt);
                    }
                }
                $parameters['optionals']['savorders_status']['value']
                    = '<span class="badge badge-status4">'.$label.'</span>';
                return 1;
            }
        }
        return 0;
    }
}


