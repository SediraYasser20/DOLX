<?php
/* Copyright (C) 2024 Custom Development
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \file    htdocs/core/triggers/interface_99_modCustom_ShipmentAutoEmail.class.php
 * \ingroup core
 * \brief   Trigger file for automatic shipment email notifications
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfaceShipmentAutoEmail
 */
class InterfaceShipmentAutoEmail extends DolibarrTriggers
{
    /**
     * @var string Name of trigger file
     */
    public $name = 'InterfaceShipmentAutoEmail';

    /**
     * @var string Description of trigger file
     */
    public $description = "Trigger for automatic shipment email notifications";

    /**
     * @var string Version
     */
    public $version = self::VERSION_DOLIBARR;

    /**
     * @var string Image
     */
    public $picto = 'technic';

    /**
     * Function called when a Dolibarr business event occurs.
     *
     * @param string        $action     Event action code
     * @param CommonObject  $object     Object
     * @param User          $user       Object user
     * @param Translate     $langs      Object langs
     * @param Conf          $conf       Object conf
     * @return int                      Return integer <0 if KO, 0 if no triggered ran, >0 if OK
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (empty($conf->global->MAIN_MODULE_TRIGGERS_ENABLED)) {
            return 0;
        }

        // Only process SHIPPING_VALIDATE event
        if ($action !== 'SHIPPING_VALIDATE') {
            return 0;
        }

        dol_syslog("Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". id=".$object->id);

        try {
            return $this->sendShipmentEmail($object, $user, $langs, $conf);
        } catch (Exception $e) {
            dol_syslog("Error in shipment email trigger: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Send shipment email to customer
     *
     * @param  Expedition   $shipment   Shipment object
     * @param  User         $user       User object
     * @param  Translate    $langs      Language object
     * @param  Conf         $conf       Configuration object
     * @return int                      Return integer <0 if KO, >0 if OK
     */
    private function sendShipmentEmail($shipment, $user, $langs, $conf)
    {
        global $db;

        // Load required classes
        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
        require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
        require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

        // Check if shipment email is enabled in configuration
        if (empty($conf->global->SHIPMENT_AUTO_EMAIL_ENABLED)) {
            dol_syslog("Shipment auto email is disabled", LOG_INFO);
            return 0;
        }

        // Load the customer (thirdparty)
        $societe = new Societe($db);
        if ($societe->fetch($shipment->socid) <= 0) {
            dol_syslog("Failed to load customer for shipment " . $shipment->id, LOG_ERR);
            return -1;
        }

        // Get customer email
        $customer_email = $societe->email;
        if (empty($customer_email)) {
            dol_syslog("No email found for customer " . $societe->name . " (ID: " . $societe->id . ")", LOG_WARNING);
            return 0;
        }

        // Get sender email
        $sender_email = $conf->global->MAIN_MAIL_EMAIL_FROM;
        $sender_name = $conf->global->MAIN_MAIL_EMAIL_FROM_NAME ?: $conf->global->MAIN_INFO_SOCIETE_NOM;

        if (empty($sender_email)) {
            dol_syslog("No sender email configured", LOG_ERR);
            return -1;
        }

        // Prepare email subject and message
        $subject = $this->getEmailSubject($shipment, $societe, $langs);
        $message = $this->getEmailMessage($shipment, $societe, $user, $langs);

        // Generate and attach shipment PDF
        $attachments = array();
        $pdf_path = $this->generateShipmentPDF($shipment, $conf);
        
        if ($pdf_path && file_exists($pdf_path)) {
            $attachments[] = array(
                'name' => basename($pdf_path),
                'content' => file_get_contents($pdf_path),
                'mimetype' => 'application/pdf'
            );
        }

        // Create and send email
        $mailfile = new CMailFile(
            $subject,
            $customer_email,
            $sender_email,
            $message,
            $attachments,
            array(), // CC
            array(), // BCC
            '', // Delivery receipt
            '', // Message ID
            0, // Priority
            -1, // Charset
            $sender_name
        );

        $result = $mailfile->sendfile();

        if ($result) {
            dol_syslog("Shipment email sent successfully to " . $customer_email . " for shipment " . $shipment->ref, LOG_INFO);
            
            // Log the action in Dolibarr's action log
            $this->logEmailAction($shipment, $customer_email, $user, $db);
            
            return 1;
        } else {
            dol_syslog("Failed to send shipment email to " . $customer_email . " for shipment " . $shipment->ref . ". Error: " . $mailfile->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Generate email subject
     *
     * @param  Expedition   $shipment   Shipment object
     * @param  Societe      $societe    Customer object
     * @param  Translate    $langs      Language object
     * @return string                   Email subject
     */
    private function getEmailSubject($shipment, $societe, $langs)
    {
        global $conf;

        // Check if custom subject is configured
        if (!empty($conf->global->SHIPMENT_EMAIL_SUBJECT_TEMPLATE)) {
            $subject = $conf->global->SHIPMENT_EMAIL_SUBJECT_TEMPLATE;
        } else {
            $subject = $langs->trans("ShipmentValidatedSubject");
            if ($subject == "ShipmentValidatedSubject") {
                $subject = "Your shipment [SHIPMENT_REF] has been validated";
            }
        }

        // Replace placeholders
        $subject = str_replace('[SHIPMENT_REF]', $shipment->ref, $subject);
        $subject = str_replace('[CUSTOMER_NAME]', $societe->name, $subject);
        $subject = str_replace('[COMPANY_NAME]', $conf->global->MAIN_INFO_SOCIETE_NOM, $subject);

        return $subject;
    }

    /**
     * Generate email message body
     *
     * @param  Expedition   $shipment   Shipment object
     * @param  Societe      $societe    Customer object
     * @param  User         $user       User object
     * @param  Translate    $langs      Language object
     * @return string                   Email message
     */
    private function getEmailMessage($shipment, $societe, $user, $langs)
    {
        global $conf;

        // Check if custom message template is configured
        if (!empty($conf->global->SHIPMENT_EMAIL_MESSAGE_TEMPLATE)) {
            $message = $conf->global->SHIPMENT_EMAIL_MESSAGE_TEMPLATE;
        } else {
            $message = $langs->trans("ShipmentValidatedMessage");
            if ($message == "ShipmentValidatedMessage") {
                $message = "Dear [CUSTOMER_NAME],\n\n";
                $message .= "Your shipment [SHIPMENT_REF] has been validated and is ready for delivery.\n\n";
                $message .= "Please find the shipment details attached to this email.\n\n";
                $message .= "Best regards,\n";
                $message .= "[COMPANY_NAME]";
            }
        }

        // Replace placeholders
        $message = str_replace('[SHIPMENT_REF]', $shipment->ref, $message);
        $message = str_replace('[CUSTOMER_NAME]', $societe->name, $message);
        $message = str_replace('[COMPANY_NAME]', $conf->global->MAIN_INFO_SOCIETE_NOM, $message);
        $message = str_replace('[USER_NAME]', $user->getFullName($langs), $message);
        $message = str_replace('[SHIPMENT_DATE]', dol_print_date($shipment->date_valid, 'day'), $message);

        return $message;
    }

    /**
     * Generate shipment PDF
     *
     * @param  Expedition   $shipment   Shipment object
     * @param  Conf         $conf       Configuration object
     * @return string|false             PDF file path or false on error
     */
    private function generateShipmentPDF($shipment, $conf)
    {
        global $db, $langs;

        try {
            // Get the configured PDF model for shipments
            $model = $conf->global->EXPEDITION_ADDON_PDF ?: 'rouget';
            
            // Load the PDF generation class
            $classname = 'pdf_'.$model;
            $dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
            
            foreach ($dirmodels as $reldir) {
                $file = dol_buildpath($reldir."core/modules/expedition/doc/".$model.".modules.php", 0);
                if (file_exists($file)) {
                    require_once $file;
                    break;
                }
            }

            if (!class_exists($classname)) {
                dol_syslog("PDF model class $classname not found", LOG_ERR);
                return false;
            }

            // Create PDF generator instance
            $obj = new $classname($db);

            // Generate PDF
            $outputlangs = $langs;
            $result = $obj->write_file($shipment, $outputlangs);

            if ($result > 0) {
                $pdf_path = $conf->expedition->multidir_output[$shipment->entity] . "/" . $shipment->ref . "/" . $shipment->ref . ".pdf";
                return $pdf_path;
            }

        } catch (Exception $e) {
            dol_syslog("Error generating shipment PDF: " . $e->getMessage(), LOG_ERR);
        }

        return false;
    }

    /**
     * Log email action in Dolibarr
     *
     * @param  Expedition   $shipment       Shipment object
     * @param  string       $customer_email Customer email
     * @param  User         $user          User object
     * @param  DoliDB       $db            Database object
     * @return void
     */
    private function logEmailAction($shipment, $customer_email, $user, $db)
    {
        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

        $actioncomm = new ActionComm($db);
        $actioncomm->type_code = 'AC_EMAIL';
        $actioncomm->code = 'AC_EMAIL_AUTO';
        $actioncomm->label = 'Automatic shipment email sent';
        $actioncomm->note_private = 'Automatic email sent to customer (' . $customer_email . ') for shipment validation ' . $shipment->ref;
        $actioncomm->fk_soc = $shipment->socid;
        $actioncomm->fk_element = $shipment->id;
        $actioncomm->elementtype = 'shipping';
        $actioncomm->datep = dol_now();
        $actioncomm->datef = dol_now();
        $actioncomm->userownerid = $user->id;
        $actioncomm->percentage = 100;

        $actioncomm->create($user);
    }
}
