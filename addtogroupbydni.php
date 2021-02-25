<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

Class Addtogroupbydni extends Module {

    public function __construct(){

        $this->name = 'addtogroupbydni';
        $this->version = '1.0.0';
        $this->author = 'Daniel Soto';
        $this->displayName = $this->trans('Add to group by DNI');
        $this->description = $this->trans('Permite subir un CSV con los DNIs Y EMAILs de clientes a los cuales queremos agregar a un grupo de clientes de PrestaShop');
        $this->controllers = array('default');
        $this->bootstrap = 1; 

        $this->_html = '';
        parent::__construct();
    }

    // Función que nos permite instalar nuestro módulo y registrar los hooks
    public function install(){

        if (!parent::install()
                OR !$this->installDb()
                OR !$this->registerHook('actionCustomerAccountAdd')
                //OR !$this->registerHook('actionCustomerAccountUpdate')
                OR !$this->registerHook('actionValidateCustomerAddressForm')
                OR !$this->registerHook('actionObjectDeleteBefore')
            )
            return false;
        return true;
    }

    // Creamos tabla en DB para almacenar los socios VIP y el grupo al que corresponden
    public function installDb() {
        // Añado la columna tipo = 1=>dni, 2=>email
        if (Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_. $this->name. '_vip_miembros` (
            `dni` VARCHAR(50) NOT NULL,
            `grupo` INT(11) UNSIGNED NOT NULL,
            `tipo` INT UNSIGNED NOT NULL,
            PRIMARY KEY ( `dni`, `grupo` )
            ) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;')){
                return true;
        }
        return false;
    }


    // Función que nos permite desinstalar nuestro módulo y desregistrar los hooks
    public function uninstall() {

        if (!parent::uninstall() 
                OR !$this->unregisterHook('actionCustomerAccountAdd')
                //OR !$this->unregisterHook('actionCustomerAccountUpdate')
                OR !$this->unregisterHook('actionValidateCustomerAddressForm')
                OR !$this->unregisterHook('actionObjectDeleteBefore')
            )
            return false;
        return true;
    }
    
 
    // Procesamos el submit del BO, recogiendo el grupo VIP selecionado y procesando el fichero csv
    public function postProcess() {

        $registros_csv = array();

        // Aquí introduciremos nuestro código para darle funcionalidad al botón "Exportar Clientes en CSV" 
        if ( Tools::isSubmit('export_to_csv') ) {

            // EXPORTAR FICHERO CSV:

            // Obtenemos el grupo seleccionado en el BO y el guardado en la configuración 
             $id_selected_group = (int)(Tools::getValue('groups_name_export'));
             $id_old_group = (int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP_EXPORT');
             // Comparamos el grupo guardado en la cofiguración con el seleccionado en el BO, si son distintos guardamos el nuevo en la configuración
             if ($id_selected_group != $id_old_group) {
                 (int)Configuration::updateValue('SOY_'.strtoupper($this->name).'_SELECTED_GROUP_EXPORT', $id_selected_group);
             }

            // Obtiene el id cliente, el id grupo, el nombre traducido del grupo, el correo, el nombre y el apellido del grupo de cliente seleccionado:
            /*
                SELECT c.id_customer, email, firstname, lastname, c.id_default_group, cg.id_group, gl.name
                FROM ps_customer AS c
                INNER JOIN ps_customer_group AS cg ON c.id_customer = cg.id_customer
                INNER JOIN ps_group_lang AS gl ON cg.id_group = gl.id_group
                WHERE cg.id_group = 3 AND gl.id_lang = 1
            */
            $query = "SELECT c.id_customer, email, firstname, lastname, c.id_default_group, cg.id_group, gl.name ".
            "FROM "._DB_PREFIX_."customer AS c ".
            "INNER JOIN "._DB_PREFIX_."customer_group AS cg ON c.id_customer = cg.id_customer ".
            "INNER JOIN "._DB_PREFIX_."group_lang AS gl ON cg.id_group = gl.id_group ".
            "WHERE cg.id_group = ". $id_selected_group ." AND gl.id_lang = ". $this->context->language->id;
            $customers_db = Db::getInstance()->executeS($query);

            // Obtiene el nombre del grupo de clientes seleccionado:
            $query = "SELECT name FROM "._DB_PREFIX_."group_lang WHERE id_group = ".$id_selected_group. " AND id_lang = ". $this->context->language->id;
            // Crea el nombre del fichero a partir del nombre del grupo seleccionado, en minúsculas y reemplaza espacios (" ") por guiones ("_"):
            $file_name = str_replace(' ', '_', strtolower(Db::getInstance()->getValue($query)));

            // Ruta + Nombre del fichero csv a crear:
            $path_file = '..'._MODULE_DIR_.$this->name.'/'.$file_name.'.csv';   // Ruta relativa: "../modules/addtogroupbydni/cliente_vip.csv"
            //$path_file = _PS_MODULE_DIR_.$this->name.'/'.$file_name.'.csv';      // Ruta absoluta: "/home/admin/web/gonzalvez7422.tk/public_html/modules/addtogroupbydni/cliente_vip.csv"

            // Crea el fichero csv y si tiene éxito:
            if (($file = fopen($path_file, 'w')) !== FALSE) {             
                // Escribe la secuencia de caracteres BOM para arreglar el problema con UTF-8 en Excel
                fputs($file, $bom = chr(0xEF) . chr(0xBB) . chr(0xBF) );
                // Escribe cada fila en el fichero csv, separado por coma ",":
                foreach ($customers_db as $customer) {
                    fputcsv($file, $customer, ',', "'");
                }
                // Cierra el fichero
                fclose($file);

                // Descarga el fichero csv:
                if (file_exists($path_file)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="'.basename($path_file).'"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($path_file));

                    readfile($path_file);
                    exit;   
                }  

            //  Si no puede abrir/crear el fichero CSV:
            } else {
                $this->_html .= $this->displayError($this->trans('Ha ocurrido un error al crear el fichero CSV.'));
                return $this->_html . $this->renderForm() . $this->display(__FILE__, 'views/templates/admin/addtogroupbydni.tpl'); 
            }

        }

        // Submit de la importaciín del csv
        if ( Tools::isSubmit('csv_form') ) {

            // Obtenemos el grupo seleccionado en el BO y el guardado en la configuración 
            $id_selected_group = (int)(Tools::getValue('groups_name'));
            $id_old_group = (int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP');
            // Comparamos el grupo guardado en la cofiguración con el seleccionado en el BO, si son distintos guardamos el nuevo en la configuración
            if ($id_selected_group != $id_old_group) {
                (int)Configuration::updateValue('SOY_'.strtoupper($this->name).'_SELECTED_GROUP', $id_selected_group);
            }

            // Comprueba si se ha marcado el check para eliminar los datos del grupo
           
            if ((bool)(Tools::getValue('check_group_delete_0'))) {

                // ELIMINAR LOS DATOS DEL GRUPO SELECCIONADO:

                // Obtiene el nombre del grupo de clientes seleccionado
                $query = "SELECT name FROM "._DB_PREFIX_."group_lang WHERE id_group = ".$id_selected_group." AND id_lang = ". $this->context->language->id;
                $group_name = Db::getInstance()->getValue($query);

                // Borramos TODOS los registros de la BBDD del Grupo de Clientes Seleccionado, después los insertaremos:
                $query = 'DELETE FROM '._DB_PREFIX_.$this->name.'_vip_miembros WHERE grupo="' . $id_selected_group .'"';
                if ($result = Db::getInstance()->execute($query)) {   // Ejecuta el borrado
                    // Si ha tenido éxito:
                    $this->_html .= $this->displayConfirmation($this->trans('Se han eliminado correctamente los datos del grupo '.$group_name));
                } else {
                    $this->_html .= $this->displayError($this->trans('Ha ocurrido un error al intentar borrar los datos del grupo '.$group_name));
                    return $this->_html . $this->renderForm();
                }
                return $this->_html . $this->renderForm() . $this->display(__FILE__, 'views/templates/admin/addtogroupbydni.tpl');
            
            } else {

                // IMPORTAR FICHERO CSV:

                // Tratamos el fichero csv adjuntado en el BO
                $files = $_FILES;
                foreach ($files as $item => $value)
                    $file = $value;

                if ($file['error'] === UPLOAD_ERR_OK){
                    // Obtenemos un array con los dni y los emails validados
                    $registros_csv = $this->csvToArrayValidated($file['tmp_name'], ','); 
                    if ( $registros_csv == FALSE ){
                        $this->_html .= $this->displayError($this->trans('Los registros del CSV insertado no son correctos. Recuerda utilizar la "," como delimitador.'));
                        return $this->_html . $this->renderForm();
                    }
                    // Crea un archivo log con la información del fichero csv subido correctamente y validado
                    $this->createCSVUploadedLog($file, $registros_csv);
                    // Inserta el cliente en la base de datos
                    $this->insertVipMembersOnDB($registros_csv);
                    $this->_html .= $this->displayConfirmation($this->trans('El fichero CSV se ha cargado correctamente.'));
                } 
                else {
                    $this->_html .= $this->displayError($this->trans('Fichero CSV vacío.'));
                }

                return $this->_html . $this->renderForm() . $this->display(__FILE__, 'views/templates/admin/addtogroupbydni.tpl');
            }
            
        }

        return $this->renderForm() . $this->display(__FILE__, 'views/templates/admin/addtogroupbydni.tpl');

    }

    // Funcion getContent, nos permite configurar nuestro módulo
    public function getContent() {
        return $this->postProcess();
    }

     // Creamos formulario para poder seleccionar cual será nuestro grupo VIP y además nos permite subir un archivo csv
    public function renderForm() {

        $id_lang = $this->context->language->id;
        $query = "SELECT * FROM "._DB_PREFIX_."group_lang WHERE id_lang=". (int)$id_lang;
        $get_groups = Db::getInstance()->executeS($query); 
        $get_groups_export = Db::getInstance()->executeS($query);

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->languages = $this->context->controller->getLanguages();
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $this->context->controller->default_form_language;
        $helper->allow_employee_form_lang = $this->context->controller->allow_employee_form_lang;
        $helper->title = $this->displayName;
        $helper->submit_action = $this->name;

        $selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));
        $selected_group_export = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP_EXPORT'));

        // Asignamos al select el valor del grupo seleccionado para Exportar
        $helper->fields_value['groups_name_export'] = $selected_group_export;
        // Asignamos al select el valor del grupo seleccionado para Importar
        $helper->fields_value['groups_name'] = $selected_group; 
       
    
        // Formulario que nos permite importar el csv con los clientes VIP
        $this->form[0] = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('CSV IMPORT')
                ),
                'input' => array(
                    array( 
                        'type' => 'select',
                        'label' => $this->trans('Grupo de Clientes'),
                        'name' => 'groups_name',
                        'options' => array(
                            'query' => $get_groups,
                            'name' => 'name',
                            'id' => 'id_group'
                        ),
                    ),
                    array(
                        'type' => 'checkbox',
                        'label' => $this->trans('Eliminar'),
                        'desc' => "",
                        'name' => 'check_group_delete',
                        'values' => array(
                            'query' => $eliminar = array(
                                array(
                                    'check_id' => '0',
                                    'name' => $this->trans('Borrar los datos del grupo de clientes seleccionado.'),
                                )
                            ),
                            'id' => 'check_id',
                            'name' => 'name',
                            'desc' => $this->trans('Seleccione para eliminar los datos dele grupo de clientes.')
                        )
                    ),
                    array(
                        'type' => 'file',
                        'label' => $this->trans('CSV file'),
                        'name' => 'CSVIMPORT_CSV_FILE',
                        'desc' => $this->trans('Seleccione el archivo CSV que desea importar.')
                    )
                      
                ),
                'submit' => array(
                    'title' => $this->trans('Importar CSV'),
                    'name' => 'csv_form'
                    )
            )
        );

        $this->form[1] = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('CSV EXPORT')
                ),
                'input' => array(
                    array( 
                        'type' => 'select',
                        'label' => $this->trans('Grupo de Clientes'),
                        'name' => 'groups_name_export',
                        'options' => array(
                            'query' => $get_groups_export,
                            'name' => 'name',
                            'id' => 'id_group'
                        )
                    )     
                ),
                'submit' => array(
                    'title' => $this->trans('Exportar CSV'),
                    'name' => 'export_to_csv'
                )
            )
        );
        
        return $helper->generateForm($this->form);

    }

    /**
     * Insertamos en la BBDD el cliente según si se encuentra en el grupo que le pasamos como parámetro
     *
     * @param [int] $group
     */
    public function insertCustomerByGroup($group) {
        $id_customer = (int)$this->context->customer->id;
        $query = "SELECT id_group FROM "._DB_PREFIX_."customer_group WHERE id_group=". (int)$group ." AND id_customer=". (int)$id_customer;
        $results = Db::getInstance()->getValue($query);
        
        // Insertamos el cliente en la BBDD
        if ( $group != $results ) {
            Db::getInstance()->insert('customer_group', array(
                'id_customer' => (int)$id_customer,
                'id_group' => $group,
            ));
        }
    }


    /**
     * Crea un fichero log en la carpeta /logs con los datos cargados del csv
     *
     * @param [array[]] $file_uploaded
     * @param [array[]] $file_data_array
     */
    public function createCSVUploadedLog($file_uploaded, $file_data_array) {
        // Obtiene la fecha actual
        $date = new DateTime();
        // Obtiene el nombre del grupo de clientes seleccionado:
        $group_id = (int)(Tools::getValue('groups_name'));
        $query = "SELECT name FROM "._DB_PREFIX_."group_lang WHERE id_group = ".$group_id." AND id_lang = ". $this->context->language->id;
        $group_name = Db::getInstance()->getValue($query);
        
        // Cadena con información del Admin: fecha, ip, email y nombre
        $log_data = $date->format('d/m/Y H:i:s').
            "\n\n".'[ADMIN]:'.
            "\n".'IP: '.$_SERVER['REMOTE_ADDR'].
            "\n".'Account: '.$this->context->employee->email.
            "\n".'Name: '.$this->context->employee->firstname.' '.$this->context->employee->lastname;    
        
        // Añade el Grupo de Clientes del csv subido
        $log_data .= "\n\n".'[CUSTOMER GROUP]:'.
            "\n".'ID Group: '.$group_id.
            "\n".'Group: '.$group_name;

        // Añade información del fichero csv: nombre, nombre temporal, tipo y tamaño (bytes)
        $log_data .= "\n\n".'[CSV FILE]:'.
            "\n".'Filename: '.$file_uploaded["name"].
            "\n".'Temp Name: '.$file_uploaded["tmp_name"].
            "\n".'Type: '.$file_uploaded["type"].
            "\n".'Size: '.$file_uploaded["size"].' bytes.';

        // Añade los datos del fichero csv:
        $log_data .= "\n\n".'[CSV DATA]:'."\n";
        $log_data .= implode(",", $file_data_array);

        // Guarda el fichero en /logs con el nombre: addtogroupbydni_csv_uploaded_YYY_MM_DD_HHMMSS.log
        $path_file = '..'._MODULE_DIR_.$this->name.'/logs/'.$this->name.'_csv_uploaded_'.$date->format('Y_m_d_His').'.log';   // Ruta relativa: "../modules/addtogroupbydni/logs/addtogroupbydni_csv_uploaded_2021_02_24_105002.log"
        //$path_file = _PS_MODULE_DIR_.$this->name.'/logs/'.$this->name.'_csv_uploaded_'.$date->format('Y_m_d_His').'.log';   // Ruta absoluta: "/home/admin/web/gonzalvez7422.tk/public_html/modules/addtogroupbydni/logs/addtogroupbydni_csv_uploaded_2021_02_24_105002.log"
        file_put_contents($path_file, $log_data);
    }


    /**
     * Insertamos en la BBDD los clientes obtenidos mediante un archivo csv
     *
     * @param [array[]] $array_registros_csv
     */
    public function insertVipMembersOnDB($array_registros_csv) {
        // Obtenemos el grupo seleccionado en el Backend
        //$selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));
        $selected_group = (int)(Tools::getValue('groups_name'));
        if (empty($selected_group) || !$selected_group)
            return $this->displayError($this->trans('Debes seleccionar un grupo'));

        // Obtenemos los registros cel CSV:
        $dnisYemails_csv = $this->getDniYEmailArrayMultiple($array_registros_csv);
        
        // Obtenemos todos los dnis y emails de la base de datos:
        $query = "SELECT dni, tipo FROM "._DB_PREFIX_.$this->name."_vip_miembros";
        $dni_db = Db::getInstance()->executeS($query);


        
        // NOTA IMPORTANTE: SI QUEREMOS PERMITIR QUE UN CLIENTE PUEDA PERTENECER A VARIOS GRUPOS DE CLIENTES,
        // BASTARÍA CON COMENTAR ESTE BLOQUE HASTA: //FIN NOTA IMPORTANTE.
        // /*
        // Array que contendrá los dnis y emails coincidentes en la BBDD y en el CSV, para eliminar de la BBDD:
        $db_delete = Array();
        // Iteramos los 2 arrays CSV y DB para comparar sus valores y encontrar los registros coincidentes.
        foreach ( $dni_db as $indice => $variable ) {
            $array_csv = Array();
            // Comprueba el tipo del campo en la BBDD (1=>DNI, 2=>EMAIL)
            // Y seleccionar el array a iterar:
            switch ($variable['tipo']) {
                case 1:     // DNI
                    $array_csv = $dnisYemails_csv["dni"];
                    $variable['dni'] = str_replace('-', '', strtoupper(trim($variable['dni'])));
                    break;
                case 2:     // EMAIL
                    $variable['dni'] = strtoupper(trim($variable['dni']));
                    $array_csv = $dnisYemails_csv["email"];
                    break;
            }
            // Recorre todos los campos del CSV:
            foreach ($array_csv as $index => $registro ) {
                // Comprueba el tipo del campo en la BBDD (1=>DNI, 2=>EMAIL)
                switch ($variable['tipo']) {
                    case 1:     // DNI
                        $registro = str_replace('-', '', strtoupper(trim($registro)));
                        break;
                    case 2:     // EMAIL
                        $registro = strtoupper(trim($registro));
                        break;
                }
                // Si los registros coinciden, lo guardamos en el array para borrar de la BBDD:
                if ($variable['dni'] == $registro ) {
                    array_push($db_delete, $registro);
                }  
            }
        }
        // Borramos los registros de la BBDD, del Grupo de Clientes que sea y que estén tanto en la BBDD como en el CSV: 
        foreach ( $db_delete as $row ) {
            $row = htmlspecialchars(str_replace(';', ',', strtoupper(trim($row))));
            $query = 'DELETE FROM '._DB_PREFIX_.$this->name.'_vip_miembros WHERE dni="' . pSQL($row) .'"';
            Db::getInstance()->execute($query);
        }
        // */
        // FIN NOTA IMPORTANTE.



        // Borramos TODOS los registros de la BBDD del Grupo de Clientes Seleccionado, después los insertaremos:
        $query = 'DELETE FROM '._DB_PREFIX_.$this->name.'_vip_miembros WHERE grupo="' . $selected_group .'"';
        Db::getInstance()->execute($query);
       
        // Insertamos los registros de la BBDD, para actualizarla con los miembros incluidos en el csv adjuntado
        foreach ($dnisYemails_csv as $clave => $array_datos ) {
            foreach($array_datos as $dato) {
                $tipo = 0; // Si se guarda 0 finalmente en la BBDD sería un error
                switch ($clave) {
                    case "dni":     // DNI
                        $dato = htmlspecialchars(str_replace(';', ',', strtoupper(trim($dato))));
                        $tipo = 1;
                        break;
                    case "email":   // EMAIL
                        $dato = htmlspecialchars(str_replace(';', ',', trim($dato)));
                        $tipo = 2;
                        break;
                }

                Db::getInstance()->insert($this->name.'_vip_miembros', array(
                    'dni' => pSQL($dato),
                    'grupo' => (int)$selected_group,
                    'tipo' => (int)$tipo 
                ));
            }
            
        }
    }
      
    /**
     * Comprobamos si un array no tiene elementos duplicados
     *
     * @param array $customer_dnis
     * @return bool
     */
    public function noDuplicado(array $customer_dnis, string $address_dni, string $name) {
        $count = count($customer_dnis); 
        foreach ( $customer_dnis as $indice => $row){
            for ($i = $indice + 1; $i < $count; $i++) {
                if($customer_dnis[$i][$name] == $customer_dnis[$indice][$name]) {
                    if ($customer_dnis[$i][$name] == $address_dni) {
                        return false;    
                    }
                }    
            }   
        }
        return true;
    }

     /**
     * Obtenemos un array con los dnis y los emails del csv validados
     *
     * @param [aray()] $array_csv
     */
    public function csvToArrayValidated($file, $delimiter) {
        $dnisYemails = array();

        //  Obtenemos un array con los campos del fichero csv
        if (($handle = fopen($file, "r")) !== FALSE) {
            $i = 0;
            while (($lineArray = fgetcsv($handle, 4000, $delimiter)) !== FALSE) {
                for ($j=0; $j<count($lineArray); $j++) {
                    $dataArray[$i][$j] = $lineArray[$j];
                } 
                $i++;
            }
            fclose($handle);
        }else   
            return false;

        // Iteramos el array obtenido, comprobando que sean dnis o emails:
        foreach ($dataArray as $csv) {
            foreach ($csv as $registro) {  
                // Si es un DNI válido:
                if ($this->validateDni(trim($registro))) {
                    $registro = strtoupper(str_replace('-', '', $registro));    // Elimina el guión de la letra, en el caso de que sea un DNI
                    array_push($dnisYemails, trim($registro));
                }
                // Si es un Email válido:
                else if ($this->validateEmail(trim($registro))) {
                    array_push($dnisYemails, trim($registro));                  // Si es un correo lo guarda tal cual
                }
            }
        }

        return array_unique($dnisYemails);
    }

    /**
     * Obtenemos un array multiple separando los dnis y los emails
     *
     * @param [aray()] $array_csv
     */
    public function getDniYEmailArrayMultiple($array_csv) {
        $dnis = array();
        $emails = array();
        $keys = array(
            "dni" => array(),
            "email" => array()
        );

        // Iteramos el array  y separamos los campos que son dnis y emails:
        foreach ($array_csv as $registro) {  
            if ($this->validateDni(trim($registro))) {
                //$registro = strtoupper(str_replace('-', '', $registro)); 
                array_push($dnis, trim($registro));
            }
            else if ($this->validateEmail(trim($registro))) {
                array_push($emails, trim($registro));
            }
        }

        // Hacer únicos:
        $keys["dni"] = array_unique($dnis);
        $keys["email"] = array_unique($emails);
        
        return $keys;
    }

    // Función que valida un DNI español
    public function validateDni($dni){
        $letra = substr($dni, -1);
        $numeros = substr($dni, 0, -1);
        $valido=false;

        if (!is_numeric($numeros))
            return false;

        if ( (substr("TRWAGMYFPDXBNJZSQVHLCKE", $numeros%23, 1) == $letra && strlen($letra) == 1 && strlen ($numeros) == 8 ))
            $valido=true;
        else
            $valido=false;

        return $valido;
    }

    // Función que valida un correo electrónico
    public function validateEmail($email){
        $valido=false;

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valido=true;
        } else {
            $valido=false;
        }

        return $valido;
    }

    public function hookActionCustomerAccountAdd($params) {
        // Cogemos todos los emails vip de la base de datos 
        $query = "SELECT dni, grupo FROM "._DB_PREFIX_.$this->name."_vip_miembros WHERE tipo=2";
        $emails_vip_db = Db::getInstance()->executeS($query);

        // Obtenemos el email del cliente tras registrar/actualizar su cuenta:
        $query = "SELECT email FROM "._DB_PREFIX_."customer WHERE id_customer=".$this->context->customer->id;
        $customer_email = Db::getInstance()->getValue($query);

        // Obtenemos el grupo seleccionado en el Backend
        //$selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));
        foreach ( $emails_vip_db as $row ) {
            if ( $customer_email == $row['dni']) {
                $this->insertCustomerByGroup($row['grupo']);
            }         
        }

    }

    /*public function hookActionCustomerAccountUpdate($params) {
        // Cogemos todos los emails vip de la base de datos 
        $query = "SELECT dni, grupo FROM "._DB_PREFIX_.$this->name."_vip_miembros WHERE tipo=2";
        $emails_vip_db = Db::getInstance()->executeS($query);

        // Obtenemos el email del cliente tras registrar/actualizar su cuenta:
        $query = "SELECT email FROM "._DB_PREFIX_."customer WHERE id_customer=".$this->context->customer->id;
        $customer_email = Db::getInstance()->getValue($query);

        // Obtenemos el grupo seleccionado en el Backend
        //$selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));
        foreach ( $emails_vip_db as $row ) {
            if ( $customer_email == $row['dni']) {
                $this->insertCustomerByGroup($row['grupo']);
            }         
        }
    }*/


    /**
     * Tratramiento del hook -> ActionValidateCustomerAddressForm
     *
     * @param [array[]] $params
     * @return void
     */
    public function hookActionValidateCustomerAddressForm($params){

        // Cogemos todos los dnis vip de la base de datos 
        $query = "SELECT dni, grupo FROM "._DB_PREFIX_.$this->name."_vip_miembros WHERE tipo=1";
        $dnis_vip_db = Db::getInstance()->executeS($query);
        
        // Cogemos el dni del formulario tras validarlo por el hook
        $form = $params['form'];
        $dni_form = trim(strtoupper(str_replace('-', '', $form->getField('dni')->getValue())));

        // Obtenemos el grupo seleccionado en el Backend
        //$selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));
        
        foreach ( $dnis_vip_db as $row ) {
            if ( $dni_form == $row['dni']) {
                //$this->insertCustomerByGroup($selected_group);
                $this->insertCustomerByGroup($row['grupo']);
            }        
        }
    }

    /**
     * Tratramiento del hook -> ActionObjectDeleteBefore
     *
     * @param [array[]] $params
     */
    public function hookActionObjectDeleteBefore($params){

        // Obtenemos el grupo seleccionado en el Backend
        //$selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));
        $selected_group = (int)(Tools::getValue('groups_name'));

        // Obtenemos el id del consumidor logueado
        $id_customer = (int)$this->context->customer->id;
        $email_customer = $this->context->customer->email;

        // Cogemos todos los dnis vip de la base de datos 
        $query = "SELECT dni, grupo FROM "._DB_PREFIX_.$this->name."_vip_miembros WHERE tipo=1";
        $dnis_vip_db = Db::getInstance()->executeS($query);

        // Obtenemos el id_address correspondiente a la dirección que va a borrar el cliente
        $id_address = (int)Tools::getValue('id_address');

        // Obtenemos las direcciones correspondientes al id del consumidor logueado
        $query = "SELECT id_address FROM "._DB_PREFIX_."address WHERE id_customer=" . $id_customer;
        $customer_addresses = Db::getInstance()->executeS($query);

        // Recorremos las direcciones del cliente logueado
        if ($id_customer > 0){
            $cont = 0;
            foreach($customer_addresses as $row){
                if($row['id_address'] == $id_address){  // Si el la dirección que se va a borrar:
                    // Obtenemos el dni correspondiente a la dirección borrada por el cliente
                    $sql = 'SELECT dni FROM '._DB_PREFIX_.'address WHERE id_address=' . $id_address;
                    $dni_by_address = Db::getInstance()->getValue($sql);
                 
                    // Los dni de todas las direcciones del cliente
                    $query = 'SELECT dni FROM '._DB_PREFIX_.'address WHERE id_customer=' . $id_customer;
                    $dnis_by_customer = Db::getInstance()->executeS($query);
                   
                    // Obtiene un array con los dni de todas las direcciones del cliente
                    $dnis_cliente = array();
                    foreach ($dnis_by_customer as $dni_cliente) {
                        array_push($dnis_cliente, $dni_cliente['dni']);
                    }
                    
                    // Borra los dni duplicados, los que se repiten en otras direcciones
                    $dnis_cliente = array_unique($dnis_cliente);

                    // Cuenta el número de dnis del cliente que hay en los vips:
                    foreach ($dnis_vip_db as $row_dni)  {
                        if(in_array($row_dni['dni'], $dnis_cliente)) {
                            $cont++;
                        }
                    } 

                    // Borramos del grupo VIP al cliente que ha borrado la dirección con el dni VIP
                    // Sólo lo borra si es la única dirección del cliente que tiene un DNI VIP y en el grupo a borrar no tiene un email VIP.
                    // Es decir, si el dni de la dirección a borrar es VIP y también está en otra dirección, no lo borrará del grupo.
                    if ($this->noDuplicado($dnis_by_customer, $dni_by_address, 'dni')) {
                        foreach ($dnis_vip_db as $row_dni){
                            if ($row_dni['dni'] == $dni_by_address ){
                                // Comprueba si el cliente es VIP porque tiene un email VIP, en ese caso, no lo borrará dee grupo
                                $query = "SELECT count(*) FROM "._DB_PREFIX_.$this->name."_vip_miembros WHERE tipo=2 AND dni='".$email_customer."' AND grupo=".$row_dni['grupo'];
                                $cont_emails = (int)(Db::getInstance()->getValue($query));
                                // Si es la única dirección que tiene el DNI VIP:
                                if($cont == 1 && $cont_emails == 0){     
                                    // Borra del grupo al cliente:
                                    $query = 'DELETE FROM '._DB_PREFIX_.'customer_group WHERE id_group='. $row_dni['grupo'] .' AND id_customer='. $id_customer;
                                    Db::getInstance()->execute($query);
                                }             
                            }
                        }
                    } 
                 }
            }
        }
    }

}


