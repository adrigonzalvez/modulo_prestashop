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
        $this->description = $this->trans('Permite subir un CSV con los DNIs de clientes a los cuales queremos agregar a un grupo de clientes de PrestaShop');
        $this->controllers = array('default');
        $this->bootstrap = 1; 

        $this->_html = '';
        parent::__construct();
    }

    // Función que nos permite instalar nuestro módulo y registrar los hooks
    public function install(){

        if (!parent::install()
                OR !$this->installDb()
                OR !$this->registerHook('actionValidateCustomerAddressForm')
                OR !$this->registerHook('actionObjectDeleteBefore')
            )
            return false;
        return true;
    }

    // Creamos tabla en DB para almacenar los socios VIP y el equipo al que corresponden
    public function installDb() {

        if (Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_. $this->name. '_vip_miembros` (
            `dni` VARCHAR(9) NOT NULL,
            `equipo` INT(11) UNSIGNED NOT NULL,
            PRIMARY KEY ( `dni`, `equipo` )
            ) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;')){
                return true;
        }
        return false;
    }


    // Función que nos permite desinstalar nuestro módulo y desregistrar los hooks
    public function uninstall() {

        if (!parent::uninstall() 
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
        
            // Obtenemos el grupo seleccionado en el Backend
            $selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));

            // Obtiene el id cliente, el id grupo, el nombre traducido del grupo, el correo, el nombre y el apellido del grupo de cliente seleccionado:
            /*
                SELECT c.id_customer, cg.id_group, gl.name, email, firstname, lastname
                FROM ps_customer AS c
                INNER JOIN ps_customer_group AS cg ON c.id_customer = cg.id_customer
                INNER JOIN ps_group_lang AS gl ON cg.id_group = gl.id_group
                WHERE cg.id_group = 3 AND gl.id_lang = 1
            */
            $query = "SELECT c.id_customer, cg.id_group, gl.name, email, firstname, lastname ".
            "FROM "._DB_PREFIX_."customer AS c ".
            "INNER JOIN "._DB_PREFIX_."customer_group AS cg ON c.id_customer = cg.id_customer ".
            "INNER JOIN "._DB_PREFIX_."group_lang AS gl ON cg.id_group = gl.id_group ".
            "WHERE cg.id_group = ". $selected_group ." AND gl.id_lang = ". $this->context->language->id;
            $customers_db = Db::getInstance()->executeS($query);

            // Obtiene el nombre del grupo de clientes seleccionado:
            $query = "SELECT name FROM "._DB_PREFIX_."group_lang WHERE id_group = ".$selected_group. " AND id_lang = ". $this->context->language->id;
            // Crea el nombre del fichero a partir del nombre del grupo seleccionado, en minúsculas y reemplaza espacios (" ") por guiones ("_"):
            $file_name = str_replace(' ', '_', strtolower(Db::getInstance()->getValue($query)));

            // Ruta + Nombre del fichero csv a crear:
            $path_file = '..'._MODULE_DIR_.$this->name.'/logs/'.$file_name.'.csv';   // Ruta relativa: "../modules/addtogroupbydni/logs/cliente_vip.csv"
            //$path_file = _PS_MODULE_DIR_.$this->name.'/logs/'.$file_name.'.csv';      // Ruta absoluta: "/home/admin/web/gonzalvez7422.tk/public_html/modules/addtogroupbydni/logs/cliente_vip.csv"

            // Crea el fichero csv y si tiene éxito:
            if (($file = fopen($path_file, 'w')) !== FALSE) {             
                // Escribe la secuencia de caracteres BOM para arreglar el problema con UTF-8 en Excel
                fputs($file, $bom = chr(0xEF) . chr(0xBB) . chr(0xBF) );
                // Escribe cada fila en el fichero csv, separado por coma ",":
                foreach ($customers_db as $customer) {
                    fputcsv($file, $customer, ',');
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

        // Submit del grupo VIP seleccionado
        if ( Tools::isSubmit('group_form') ) {

            // Obtenemos el grupo seleccionado en el BO y el guardado en la configuración 
            $id_selected_group = (int)(Tools::getValue('groups_name'));
            $id_old_group = (int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP');

            // Comparamos el grupo guardado en la cofiguración con el seleccionado en el BO, si son distintos guardamos el nuevo en la configuración
            if ($id_selected_group != $id_old_group) {
                (int)Configuration::updateValue('SOY_'.strtoupper($this->name).'_SELECTED_GROUP', $id_selected_group);
                $this->_html .= $this->displayConfirmation( $this->trans('VIP Group changed successfully '));
            }

            return $this->_html . $this->renderForm() . $this->display(__FILE__, 'views/templates/admin/addtogroupbydni.tpl');
        }

        // Submit de la importaciín del csv
        if ( Tools::isSubmit('csv_form') ) {

            // Tratamos el fichero csv adjuntado en el BO
            $files = $_FILES;
            foreach ($files as $item => $value)
                $file = $value;

            if ($file['error'] === UPLOAD_ERR_OK){
                $registros_csv = $this->getDniArrayFromCsv($file['tmp_name'], ',');    
                if ( $registros_csv == FALSE ){
                    $this->_html .= $this->displayError($this->trans('Los registros del CSV insertado no son correctos. Recuerda utilizar la "," como delimitador.'));
                    return $this->_html . $this->renderForm();
                }
                $this->insertVipMembersOnDB($registros_csv);
                $this->_html .= $this->displayConfirmation($this->trans('CSV file uploaded successfully.'));
            } 
            else {
                $this->_html .= $this->displayError($this->trans('Empty CSV file.'));
            }

            return $this->_html . $this->renderForm() . $this->display(__FILE__, 'views/templates/admin/addtogroupbydni.tpl');
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

        // Asignamos al select el valor del grupo seleccionado como VIP
        $helper->fields_value['groups_name'] = $selected_group;  
    
        // Formulario donde seleccionamos nuestro grupo VIP
        $this->form[0] = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('CHOOSING VIP GROUP')
                    ),
                'input' => array(
                    array( 
                        'type' => 'select',
                        'label' => $this->trans('Select your VIP Group'),
                        'name' => 'groups_name',
                        'options' => array(
                            'query' => $get_groups,
                            'name' => 'name',
                            'id' => 'id_group'
                        ),
                    ),      
                ),
                
                'submit' => array(
                    'title' => $this->trans('Save'),
                    'name' => 'group_form'
                    )
                )
            );

        // Formulario que nos permite importar el csv con los clientes VIP
        $this->form[1] = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('CSV IMPORT')
                    ),
                'input' => array(
                    array(
                        'type' => 'file',
                        'label' => $this->trans('CSV file'),
                        'name' => 'CSVIMPORT_CSV_FILE',
                        'desc' => $this->trans('Select file you wish to import.')
                    ),         
                ),
                
                'submit' => array(
                    'title' => $this->trans('Save'),
                    'name' => 'csv_form'
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
        if ( !$group == $results ) {
            Db::getInstance()->insert('customer_group', array(
                'id_customer' => (int)$id_customer,
                'id_group' => $group,
            ));
        }
    }

    /**
     * Insertamos en la BBDD los clientes obtenidos mediante un archivo csv
     *
     * @param [array[]] $array_dni
     */
    public function insertVipMembersOnDB($array_dni) {

        // Obtenemos todos los dnis de la base de datos
        $query = "SELECT dni FROM "._DB_PREFIX_.$this->name."_vip_miembros";
        $dni_db = Db::getInstance()->executeS($query);

        // Obtenemos la fecha actual
        $date = new DateTime();
        $date = $date->format("y_m_d_H_i_s");

        // Iteramos los 2 arrays para comparar sus valores
        foreach ( $dni_db as $indice => $variable ) {
            foreach ( $array_dni as $index => $registro ) {

                $variable['dni'] = str_replace('-', '', strtoupper(trim($variable['dni'])));
                $registro = str_replace('-', '', strtoupper(trim($registro)));
                // Comparamos para obtener 2 arrays y eliminar los valores que coinciden en ambos
                if ( $variable['dni'] == $registro ) {
                    unset($dni_db[$indice]);
                    unset($array_dni[$index]);
                }  
            }
        }

        // Borramos los registros de la BBDD, para actualizarla con los miembros incluidos en el csv adjuntado
        foreach ( $dni_db as $row ) {
            $row['dni'] = htmlspecialchars(str_replace(';', ',', strtoupper(trim($row['dni']))));
            $query = 'DELETE FROM '._DB_PREFIX_.$this->name.'_vip_miembros WHERE dni="' . pSQL($row['dni']) .'"';
            Db::getInstance()->execute($query);
        } 

        // Obtenemos el grupo seleccionado en el Backend
        $selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));

        if (empty($selected_group) || !$selected_group)
            return $this->displayError($this->trans('Debes seleccionar un grupo'));

        // Insertamos los registros de la BBDD, para actualizarla con los miembros incluidos en el csv adjuntado
        foreach ( $array_dni as $dni ) {
            $dni = htmlspecialchars(str_replace(';', ',', strtoupper(trim($dni))));
            Db::getInstance()->insert($this->name.'_vip_miembros', array(
                'dni' => pSQL($dni),
                'equipo' => (int)$selected_group,
            ));
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
     * Obtenemos un array a partir del fichero csv leido mediante el BO
     *
     * @param [string] $file
     * @param [string] $delimiter
     */
    public function getDniArrayFromCsv($file,$delimiter) {

        $dnis = array();

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

        // Iteramos el array obtenido y creamos otro solo con los campos que sean dni's
        foreach ($dataArray as $csv) {
            foreach ($csv as $registro) {  
                $registro = strtoupper(str_replace('-', '', $registro)); 
                if ($this->validateDni(trim($registro))) {
                    array_push($dnis, $registro);
                }
            }
        }
        
        return array_unique($dnis);
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

    /**
     * Tratramiento del hook -> ActionValidateCustomerAddressForm
     *
     * @param [array[]] $params
     * @return void
     */
    public function hookActionValidateCustomerAddressForm($params){

        // Cogemos todos los dnis vip de la base de datos 
        $query = "SELECT dni FROM "._DB_PREFIX_.$this->name."_vip_miembros";
        $dni_db = Db::getInstance()->executeS($query);
        
        // Cogemos el dni del formulario tras validarlo por el hook
        $form = $params['form'];
        $dni_form = trim(strtoupper(str_replace('-', '', $form->getField('dni')->getValue())));

        // Obtenemos el grupo seleccionado en el Backend
        $selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));
        
        foreach ( $dni_db as $row ) {
            foreach ( $row as $item ) { 
                if ( $dni_form == $item)
                    $this->insertCustomerByGroup($selected_group);
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
        $selected_group = ((int)Configuration::get('SOY_'.strtoupper($this->name).'_SELECTED_GROUP'));

        // Obtenemos el id del consumidor logueado
        $id_customer = (int)$this->context->customer->id;

        // Cogemos todos los dnis vip de la base de datos 
        $query = "SELECT dni FROM "._DB_PREFIX_.$this->name."_vip_miembros";
        $dni_db = Db::getInstance()->executeS($query);

        // Obtenemos el id_address correspondiente a la dirección que va a borrar el cliente
        $id_address = (int)Tools::getValue('id_address');

        // Obtenemos las direcciones correspondientes al id del consumidor logueado
        $query = "SELECT id_address FROM "._DB_PREFIX_."address WHERE id_customer=" . $id_customer;
        $customer_addresses = Db::getInstance()->executeS($query);

        // Recorremos las direcciones del cliente loguead
        if ($id_customer > 0){
            $cont = 0;
            foreach($customer_addresses as $row){
                if($row['id_address'] == $id_address){
                    // Obtenemos el dni correspondiente a la dirección borrada por el cliente
                    $sql = 'SELECT dni FROM '._DB_PREFIX_.'address WHERE id_address=' . $id_address;
                    $dni_by_address = Db::getInstance()->getValue($sql);

                    $query = 'SELECT dni FROM '._DB_PREFIX_.'address WHERE id_customer=' . $id_customer;
                    $dni_by_customer = Db::getInstance()->executeS($query);

                    $dnis_cliente = array();
                    foreach ($dni_by_customer as $dni_cliente)
                        array_push($dnis_cliente, $dni_cliente['dni']);
                    
                    array_unique($dnis_cliente);

                    foreach ($dni_db as $row_dni)
                        if(in_array($row_dni['dni'], $dnis_cliente))
                                $cont++;

                    // Borramos del grupo VIP al cliente que ha borrado la dirección con el dni VIP
                    if ($this->noDuplicado($dni_by_customer, $dni_by_address, 'dni')) {
                        foreach ($dni_db as $row_dni){
                            if ($row_dni['dni'] == $dni_by_address ){
                                if($cont == 1){
                                    $query = 'DELETE FROM '._DB_PREFIX_.'customer_group WHERE id_group='. $selected_group .' AND id_customer='. $id_customer;
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


