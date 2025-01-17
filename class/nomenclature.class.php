<?php

if (!class_exists('TObjetStd'))
{
	define('INC_FROM_DOLIBARR', true);
	$res = require_once dirname(__FILE__).'/../config.php';
}

dol_include_once('/workstation/class/workstation.class.php');

class TNomenclature extends TObjetStd
{

    function __construct()
    {
        global $conf;

        $this->set_table(MAIN_DB_PREFIX.'nomenclature');
        $this->add_champs('title');
        $this->add_champs('fk_object,fk_nomenclature_parent',array('type'=>'integer', 'index'=>true));
        $this->add_champs('is_default',array('type'=>'integer', 'index'=>true));
        $this->add_champs('qty_reference',array('type'=>'float','index'=>true));

		$this->add_champs('totalPRCMO_PMP,totalPRCMO_OF,totalPRCMO',array('type'=>'float'));

        $this->add_champs('object_type',array('type'=>'string', 'index'=>true));
        $this->add_champs('note_private',array('type'=>'text'));

        $this->_init_vars();

        $this->start();

        $this->setChild('TNomenclatureDet', 'fk_nomenclature');
        $this->setChild('TNomenclatureFeedback', 'fk_nomenclature');


        if($conf->workstation->enabled) $this->setChild('TNomenclatureWorkstation', 'fk_nomenclature');

        $this->qty_reference = 1;
        $this->object_type = 'product';

        $this->TNomenclatureDet = $this->TNomenclatureDetOriginal = array();
        $this->TNomenclatureWorkstation = $this->TNomenclatureWorkstationOriginal = array();
        $this->TNomenclatureAll = array();

        $this->iExist = false;
    }

    function reinit() {
        $this->{OBJETSTD_MASTERKEY} = 0; // le champ id est toujours def
        $this->{OBJETSTD_DATECREATE}=time(); // ces champs dates aussi
        $this->{OBJETSTD_DATEUPDATE}=time();

        foreach($this->TNomenclatureDet as &$det) {
            $det->reinit();
        }

        foreach($this->TNomenclatureWorkstation as &$det) {
            $det->reinit();
        }
    }

	function cloneObject(&$PDOdb, $fk_object=0)
	{
		if ($this->object_type !== 'product' && $fk_object > 0)
		{
			$this->fk_object = $fk_object; // On conserve le type de l'objet (propal) mais pas le fk_
		}

		return parent::cloneObject($PDOdb);
	}

	/**
	 * Renvoi le prix d'achat de la nomenclature
	 *
	 * @param	int		$qty_ref	permet de renvoyer le cout "unitaire" (depuis une ligne de document, cette qty est celle de la ligne de document, mais depuis l'onglet "Ouvrage" c'est normalement qty_reference de la nomenclature même)
	 * @return type
	 */
	function getBuyPrice($qty_ref=1)
	{
		global $conf;

		if (empty($conf->global->NOMENCLATURE_USE_FLAT_COST_AS_BUYING_PRICE)) $price_buy =  price2num($this->totalMO + $this->totalPRC, 'MT');
		else $price_buy =  price2num($this->totalMO + $this->totalPR, 'MT');

		return $price_buy / $qty_ref;
	}

	/**
	 * Renvoi le prix de vente de la nomenclature
	 *
	 * @param	int		$qty_line	permet de renvoyer le prix de vente "unitaire" (depuis une ligne de document, cette qty est celle de la ligne de document, mais depuis l'onglet "Ouvrage" c'est normalement qty_reference de la nomenclature même)
	 * @return type
	 */
	function getSellPrice($qty_ref=1)
	{
		return price2num($this->totalPV / $qty_ref, 'MT');
	}

	/**
	 * Doit calculer au global et non unitairement
	 *
	 * @param TPDOdb $PDOdb
	 * @param float $qty_ref		qty de référence pour calculer un coefficient avec l'attribut qty_reference de la nomenclature (devrait s'appeler autrement du genre qty_produite ou qty_de_production)
	 * @param int $fk_object		not used
	 * @param string $object_type	string : "product", "propal", "commande"
	 * @param int $fk_origin		rowid propal ou commande
	 * @return float
	 */
	function setPrice(&$PDOdb, $qty_ref, $fk_object, $object_type,$fk_origin = 0,$fk_product = 0) {

		global $db,$langs,$conf;

		if(empty($this->nested_price_level)) $this->nested_price_level = 0;

		$max_level = empty($conf->global->NOMENCLATURE_MAX_NESTED_LEVEL) ? 50 : $conf->global->NOMENCLATURE_MAX_NESTED_LEVEL;
		if($this->nested_price_level>$max_level){
			setEventMessage($langs->trans('SetPriceInfiniteLoop'), 'errors');

			return false;
		}

		if (empty($qty_ref)) $qty_ref = $this->qty_reference; // si vide alors le save provient de l'onglet "Ouvrage" depuis un produit

		$coef_qty_price = $qty_ref / $this->qty_reference; // $this->qty_reference = qty produite pour une unité de nomenclature (c'est une qté de production)

	    switch ($object_type)
        {
           case 'propal':
               	require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
               	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

				$object = new Propal($db);
	  		 	$object->fetch($fk_origin);
				$object->fetch_thirdparty();

				break;
		   case 'commande':
			   require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
			   require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';

			   $commande = new Commande($db);
			   if ($commande->fetch($fk_origin) > 0)
			   {
				   $commande->fetchObjectLinked();
				   if (!empty($commande->linkedObjects['propal']))
				   {
					   // Récupération de la propal d'origine pour récupérer ses coef
					   $object = current($commande->linkedObjects['propal']);
					   $object_type = 'propal'; // Je bascule sur type "propal" car je veux le loadCoefObject de l'objet d'origine
				   }
			   }
			   else
			   {
				   dol_print_error($db);
			   }

			   break;

			  // TODO le cas "facture" semble exister sur un déclanchement de trigger LINEBILL_INSERT, il faudrait potentiellement remonter à la commande d'origin puis à la propal d'origin pour récup les coef custom
        }

		$this->TCoefStandard = TNomenclatureCoef::loadCoef($PDOdb);
		if(!empty($object->id)) $this->TCoefObject = TNomenclatureCoefObject::loadCoefObject($PDOdb, $object, $object_type);

		// vérifier l'éxistance de coef produit : non prioritaire au coef de l'objet
		$this->CoefProduct = array();
		if(!empty($fk_product)){
		    $product = new Product($db);
		    $product->fetch($fk_product);
		    $this->CoefProduct = TNomenclatureCoefObject::loadCoefObject($PDOdb, $product, 'product'); //Coef du produit
		}


		$totalPR = $totalPRC = $totalPR_PMP = $totalPRC_PMP = $totalPR_OF = $totalPRC_OF = 0;
		foreach($this->TNomenclatureDet as &$det ) {

			$det->nested_price_level = $this->nested_price_level;

			$perso_price = $det->price;

			if(!empty($conf->global->NOMENCLATURE_PERSO_PRICE_HAS_TO_BE_CHARGED) && !empty($perso_price)) {
				if(!empty($conf->global->NOMENCLATURE_PERSO_PRICE_APPLY_QTY)) {
					$det->calculate_price = $perso_price * $det->qty * $coef_qty_price;
				}
				else{
					$det->calculate_price = $perso_price * $coef_qty_price;
				}

				$perso_price = 0;
			}
			else{
				if(!empty($conf->global->NOMENCLATURE_USE_CUSTOM_BUYPRICE) && !empty($det->buying_price)) $det->calculate_price = $det->buying_price * $det->qty * $coef_qty_price;
			    elseif(!empty($conf->global->NOMENCLATURE_COST_TYPE) && $conf->global->NOMENCLATURE_COST_TYPE == '1') {
			        // sélectionne le meilleur prix fournisseur
			        $det->calculate_price = $det->getSupplierPrice($PDOdb, $det->qty * $coef_qty_price,true,true,false,true) * $det->qty * $coef_qty_price;
			    }
			    elseif(!empty($conf->global->NOMENCLATURE_COST_TYPE) && $conf->global->NOMENCLATURE_COST_TYPE == 'pmp'){
			        //sélectionne le pmp si renseigné
			        $det->calculate_price = $det->getPMPPrice() * $det->qty * $coef_qty_price;
			        if(empty($det->calculate_price)) $det->calculate_price = $det->getSupplierPrice($PDOdb, $det->qty * $coef_qty_price,true,true,false,true) * $det->qty * $coef_qty_price;
			    }
			    elseif(!empty($conf->global->NOMENCLATURE_COST_TYPE) && $conf->global->NOMENCLATURE_COST_TYPE == 'costprice'){
			        // sélectionne le prix de revient renseigné sur la fiche produit
			        $det->calculate_price = $det->getCostPrice() * $det->qty * $coef_qty_price;
			        if(empty($det->calculate_price)) $det->calculate_price = $det->getPMPPrice() * $det->qty * $coef_qty_price;
			        if(empty($det->calculate_price)) $det->calculate_price = $det->getSupplierPrice($PDOdb, $det->qty * $coef_qty_price,true,true,false,true) * $det->qty * $coef_qty_price;
			    }
			    else { //comportement initial en cas de non-configuration (on prend le premier prix fournisseur qui vient...)
			        $det->calculate_price = $det->getSupplierPrice($PDOdb, $det->qty * $coef_qty_price,true) * $det->qty * $coef_qty_price;
			    }

			}

			$totalPR+= $det->calculate_price ;

			// Premier cas : taux renseigné manuellement utilisé en priorité (si aucun taux spécifique sur la propal)
			if(!empty($conf->global->NOMENCLATURE_ALLOW_USE_MANUAL_COEF) && !empty($det->tx_custom) && $det->tx_custom != $this->TCoefStandard[$det->code_type]->tx && empty($this->TCoefObject[$det->code_type]->rowid)) $coef = $det->tx_custom;
			elseif (!empty($this->TCoefObject[$det->code_type])) $coef = $this->TCoefObject[$det->code_type]->tx_object;
			elseif (!empty($this->TCoefProduct[$det->code_type])) $coef = $this->TCoefProduct[$det->code_type]->tx_object;
			elseif (!empty($this->TCoefStandard[$det->code_type])) $coef = $this->TCoefStandard[$det->code_type]->tx;
			else $coef = 1;

			// Coefficient appliqué sur le coût de revient (coeff de marge par ligne)
			$coef2 = 1;
			if(!empty($conf->global->NOMENCLATURE_USE_COEF_ON_COUT_REVIENT)) {
				if(empty($conf->global->NOMENCLATURE_ALLOW_USE_MANUAL_COEF)) $coef2 = $this->TCoefStandard[$det->code_type2]->tx;
				else $coef2 = empty($det->tx_custom2) ? $this->TCoefStandard[$det->code_type2]->tx : $det->tx_custom2;
			}

			$det->charged_price = empty($perso_price) ? $det->calculate_price * $coef : $perso_price * $coef_qty_price;
			$det->pv = empty($perso_price) ? $det->charged_price * $coef2 : $perso_price * $coef_qty_price;

			$totalPRC+= $det->charged_price;
			$totalPV += round($det->pv, 2);

			if(!empty($conf->global->NOMENCLATURE_ACTIVATE_DETAILS_COSTS)) {
				$det->calculate_price_pmp = $det->getPrice($PDOdb, $det->qty * $coef_qty_price,'PMP') * $det->qty * $coef_qty_price;
				$totalPR_PMP+= $det->calculate_price_pmp ;
				$det->charged_price_pmp = empty($perso_price) ? $det->calculate_price_pmp * $coef : $perso_price * $coef_qty_price;
				$det->pv_pmp = empty($perso_price) ? $det->charged_price_pmp * $coef2 : $perso_price * $coef_qty_price;

				$totalPRC_PMP+= $det->charged_price_pmp;
				$totalPV_PMP+= $det->pv_pmp;

				if(!empty($conf->of->enabled)) {
					$det->calculate_price_of = $det->getPrice($PDOdb, $det->qty * $coef_qty_price,'OF') * $det->qty * $coef_qty_price;
					$totalPR_OF+= $det->calculate_price_of ;
					$det->charged_price_of = empty($perso_price) ? $det->calculate_price_of * $coef : $perso_price * $coef_qty_price;
					$det->pv_of = empty($perso_price) ? $det->charged_price_of * $coef2 : $perso_price * $coef_qty_price;

					$totalPRC_OF += $det->charged_price_of;
					$totalPV_OF += $det->pv_of;
				}


			}

		}

		$this->totalPR = $totalPR;
		$this->totalPRC = $totalPRC;

		$this->totalPR_PMP = $totalPR_PMP;
		$this->totalPRC_PMP = $totalPRC_PMP;

		$this->totalPR_OF = $totalPR_OF;
		$this->totalPRC_OF = $totalPRC_OF;


		$total_mo = $total_mo_of = 0;
		foreach($this->TNomenclatureWorkstation as &$ws) {
			if (!empty($this->TCoefObject[$ws->code_type])) $coef = $this->TCoefObject[$ws->code_type]->tx_object;
			elseif (!empty($this->TCoefStandard[$ws->code_type])) $coef = $this->TCoefStandard[$ws->code_type]->tx;
			else $coef = 1;

			list($ws->nb_hour_calculate, $ws->calculate_price) = $ws->getPrice($PDOdb, $coef_qty_price, '', $coef);

			$total_mo+=empty($ws->price) ? $ws->calculate_price : $ws->price;

			if(!empty($conf->global->NOMENCLATURE_ACTIVATE_DETAILS_COSTS) && !empty($conf->of->enabled)) {
			 	list($ws->nb_hour_calculate_of, $ws->calculate_price_of) = $ws->getPrice($PDOdb, $coef_qty_price, 'OF'); // [FIXME] - dois je prendre en compte le coef dans ce cas pour être appliqué ?
				$total_mo_of+=empty($ws->price) ? $ws->calculate_price_of : $ws->price;
			}



		}
		$this->totalMO = $total_mo;
		$this->totalMO_OF = $total_mo_of;

		$marge = TNomenclatureCoefObject::getMarge($PDOdb, $object, $object_type);
		$this->marge_object = $marge;
		$this->marge = $marge->tx_object;

		$this->totalPRCMO = $this->totalMO + $this->totalPRC;
		$this->totalPV = ($this->totalMO + $totalPV);

		if(!empty($conf->global->NOMENCLATURE_ACTIVATE_DETAILS_COSTS)) {
			$this->totalPRCMO_PMP = $this->totalMO + $this->totalPRC_PMP;
			$this->totalPRCMO_OF = $this->totalMO_OF + $this->totalPRC_OF;

			$this->totalPV_PMP = ($this->totalMO + $totalPV_PMP) * $marge->tx_object;
			$this->totalPV_OF = ($this->totalMO_OF + $totalPV_OF) * $marge->tx_object;

		}

		return $coef_qty_price;
	}

	function load_original(&$PDOdb, $fk_product=0, $qty=1) {

        if(empty($fk_product)) return false;

        if($this->fk_nomenclature_parent == 0) {
            $n = TNomenclature::getDefaultNomenclature($PDOdb, $fk_product, $qty);
            if($n === false) return false;
            $this->fk_nomenclature_parent = $n->getId();
        }
		else if($this->fk_nomenclature_parent>0) {
            $n = new TNomenclature;
            if(!$n->load($PDOdb, $this->fk_nomenclature_parent)) {
            	return false;
            }
        }
//var_dump($n);
        $this->TNomenclatureDetOriginal = $n->TNomenclatureDet;
        $this->TNomenclatureWorkstationOriginal = $n->TNomenclatureWorkstation;

        if( (count($this->TNomenclatureDet)+count($this->TNomenclatureWorkstation) )==0 && (count($this->TNomenclatureDetOriginal) + count($this->TNomenclatureWorkstationOriginal))>0)
		{
      	    $this->qty_reference = $n->qty_reference ;

            foreach($this->TNomenclatureDetOriginal as $k => &$det) {
                $this->TNomenclatureDet[$k] = new TNomenclatureDet;
                $this->TNomenclatureDet[$k]->set_values((array)$det);
            }
            foreach($this->TNomenclatureWorkstationOriginal as $k => &$det) {
                $this->TNomenclatureWorkstation[$k] = new TNomenclatureWorkstation;
                $this->TNomenclatureWorkstation[$k]->set_values((array)$det);
                $this->TNomenclatureWorkstation[$k]->workstation = $det->workstation;
            }
        }
        else{
            $this->iExist = true;
        }

        return true;
    }

	function __toString() {
		global $langs;

		return '('.$this->getId().') '. ($this->title ? $this->title : $langs->trans('Nomenclature') ) .' : '.$this->qty_reference;

	}

	function load(&$PDOdb, $id, $loadProductWSifEmpty = false, $fk_product= 0 , $qty = 1, $object_type='', $fk_object_parent=0) {
		global $conf;

		$res = parent::load($PDOdb, $id);
		if($res) {
			$this->iExist = true;
		}

		if($loadProductWSifEmpty && $conf->workstation->enabled && empty($this->TNomenclatureWorkstation)) {
			$this->load_product_ws($PDOdb);
		}

		$this->loadThmObject($PDOdb, $object_type, $fk_object_parent);

		usort($this->TNomenclatureWorkstation, array('TNomenclature', 'sortTNomenclatureWorkstation'));
		usort($this->TNomenclatureDet, array('TNomenclature', 'sortTNomenclatureWorkstation'));

		return $res;

	}

	private function setAll() {
		$this->TNomenclatureAll = array_merge($this->TNomenclatureDet,$this->TNomenclatureWorkstation);
		usort($this->TNomenclatureAll, array('TNomenclature', 'sortTNomenclatureAll'));
	}

	function addProduct($PDOdb, $fk_new_product) {


		$k = $this->addChild($PDOdb, 'TNomenclatureDet');
        $det = &$this->TNomenclatureDet[$k];
        $det->rang = $k;
        $det->fk_product = $fk_new_product;

		$this->save($PDOdb);

		if(!$this->infinitLoop($PDOdb)) {
			return true;
		}
		else {

			global $langs;

			setEventMessage($langs->trans('CantAddProductBecauseOfAnInfiniteLoop', 'errors'));

			$det->to_delete = true;
			$this->save($PDOdb);

			return false;
		}
	}

	function infinitLoop(&$PDOdb, $level = 1) {
		global $conf;

		$max_level = empty($conf->global->NOMENCLATURE_MAX_NESTED_LEVEL) ? 50 : $conf->global->NOMENCLATURE_MAX_NESTED_LEVEL;
		if($level > $max_level) return true;

		foreach($this->TNomenclatureDet as &$det) {

			$fk_product = $det->fk_product;

			$n=new TNomenclature;
			$n->loadByObjectId($PDOdb, $fk_product, 'product');

			$res = $n->infinitLoop($PDOdb, $level + 1 );

			if($res) return true;

		}

		return false;
	}

	function sortTNomenclatureWorkstation(&$objA, &$objB)
	{
		$r = $objA->rang > $objB->rang;

		if ($r == 1) return 1;
		else return -1;
	}

	function sortTNomenclatureAll(&$objA, &$objB)
	{
		$r = $objA->unifyRang > $objB->unifyRang;

		if ($r == 1) return 1;
		else return -1;
	}

	private function setCombinedArray() {

		$this->TNomenclatureDetCombined = $this->TNomenclatureWorkstationCombined = array();

		foreach($this->TNomenclatureDet as $det) {
			if($this->TNomenclatureDetCombined[$det->fk_product]) {
				$this->TNomenclatureDetCombined[$det->fk_product]->qty+=$det->qty;
			}
			else{
				$this->TNomenclatureDetCombined[$det->fk_product] = $det;
			}
		}

		foreach($this->TNomenclatureWorkstation as $ws) {
			if($this->TNomenclatureWorkstationCombined[$ws->fk_workstation]) {
				$this->TNomenclatureWorkstationCombined[$ws->fk_workstation]->nb_hour+=$ws->nb_hour;
				$this->TNomenclatureWorkstationCombined[$ws->fk_workstation]->nb_hour_prepare+=$ws->nb_hour_prepare;
				$this->TNomenclatureWorkstationCombined[$ws->fk_workstation]->nb_hour_manufacture+=$ws->nb_hour_manufacture;
			}
			else{
				$this->TNomenclatureWorkstationCombined[$ws->fk_workstation] = $ws;
			}
		}


	}

	function fetchCombinedDetails(&$PDOdb) {

		$this->setCombinedArray();

		foreach($this->TNomenclatureDet as &$det) {

			$n=new TNomenclature;
			$n->loadByObjectId($PDOdb, $det->fk_product, 'product',true,$det->fk_product,$det->qty);
			$n->setCombinedArray();

			foreach($n->TNomenclatureDetCombined as &$n_det) {

				if($this->TNomenclatureDetCombined[$n_det->fk_product]) {
					$this->TNomenclatureDetCombined[$n_det->fk_product]->qty+=$n_det->qty * $det->qty;
				}
				else{
					$this->TNomenclatureDetCombined[$n_det->fk_product] = $n_det;
					$this->TNomenclatureDetCombined[$n_det->fk_product]->qty *= $det->qty;
				}

			}


			foreach($n->TNomenclatureWorkstationCombined as &$n_ws) {
				if($this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation]) {
					$this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation]->nb_hour+=$n_ws->nb_hour* $det->qty;
					$this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation]->nb_hour_prepare+=$n_ws->nb_hour_prepare* $det->qty;
					$this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation]->nb_hour_manufacture+=$n_ws->nb_hour_manufacture* $det->qty;
				}
				else{
					$this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation] = $n_ws;
					$this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation]->nb_hour *= $det->qty;
					$this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation]->nb_hour_prepare *= $det->qty;
					$this->TNomenclatureWorkstationCombined[$n_ws->fk_workstation]->nb_hour_manufacture *= $det->qty;
				}
			}

		}



	}

	function loadByObjectId(&$PDOdb, $fk_object, $object_type, $loadProductWSifEmpty = false, $fk_product = 0, $qty = 1, $fk_origin=0) {
	    $sql = "SELECT rowid FROM ".$this->get_table()."
            WHERE fk_object=".(int)$fk_object." AND object_type='".$object_type."'";

        $PDOdb->Execute($sql);

        $res = false;
        if($obj = $PDOdb->Get_line()) {
            $res = $this->load($PDOdb, $obj->rowid, $loadProductWSifEmpty, 0, 1, $object_type, $fk_origin);
        }

        $this->load_original($PDOdb, $fk_product, $qty);
		$this->setAll();

		$this->loadThmObject($PDOdb, $object_type, $fk_origin);

        return $res;

    }

	function loadThmObject(&$PDOdb, $object_type, $fk_object_parent)
	{
		global $db,$conf,$TNomenclatureWorkstationThmObject;

		if (!empty($conf->global->NOMENCLATURE_USE_CUSTOM_THM_FOR_WS) && $fk_object_parent > 0 && $object_type == 'propal')
		{
			// 1 : on charge le coef custom (si existant) des TNomenclatureWorkstation
			foreach ($this->TNomenclatureWorkstation as &$nomenclatureWs)
			{
				if (empty($nomenclatureWs->thmobjectloaded))
				{
					if (empty($TNomenclatureWorkstationThmObject[$nomenclatureWs->fk_workstation]))
					{
						$workstationThmObject = new TNomenclatureWorkstationThmObject;
						$workstationThmObject->loadByFkWorkstationByFkObjectByType($PDOdb, $nomenclatureWs->fk_workstation, $fk_object_parent, $object_type);
					}
					else
					{
						$workstationThmObject = $TNomenclatureWorkstationThmObject[$nomenclatureWs->fk_workstation];
					}

					if ($workstationThmObject->getId() > 0)
					{
						$TNomenclatureWorkstationThmObject[$nomenclatureWs->fk_workstation] = $workstationThmObject;
						$nomenclatureWs->workstation->thm = $workstationThmObject->thm_object;
					}

					$nomenclatureWs->thmobjectloaded = true;
				}
			}

			// 2 : on charge les coef custom des TNomenclatureDet qui possède une nomenclature (récusrive)
			require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
			foreach ($this->TNomenclatureDet as &$det)
			{
				$product = new Product($db);
				if ($det->fk_product>0 && $product->fetch($det->fk_product)>0)
				{
					$n = new TNomenclature;
					$res = $n->loadByObjectId($PDOdb, $product->id, 'product', false);
					if ($res) $n->loadThmObject($PDOdb, $object_type, $fk_object_parent, true);
				}
			}

		}
	}

	function deleteChildrenNotImported(&$PDOdb)
	{
		foreach ($this->TNomenclatureDet as $k => &$det)
		{
			if ($det->is_imported > 0) $det->delete($PDOdb);
		}
	}

	function load_product_ws(&$PDOdb) {
		$this->TNomenclatureWorkstation=array();

		$sql = "SELECT fk_workstation, nb_hour,nb_hour_prepare,nb_hour_manufacture";
		$sql.= " FROM ".MAIN_DB_PREFIX."workstation_product";
		$sql.= " WHERE fk_product = ".$this->fk_object;
		$PDOdb->Execute($sql);

		while($res = $PDOdb->Get_line())
		{
			$ws = new TWorkstation;
			$ws->load($PDOdb, $res->fk_workstation);
			$k = $this->addChild($PDOdb, 'TNomenclatureWorkstation');

			$this->TNomenclatureWorkstation[$k]->fk_workstation = $ws->getId();
			$this->TNomenclatureWorkstation[$k]->nb_hour = $res->nb_hour;
			$this->TNomenclatureWorkstation[$k]->nb_hour_prepare = $res->nb_hour_prepare;
			$this->TNomenclatureWorkstation[$k]->nb_hour_manufacture = $res->nb_hour_manufacture;
			$this->TNomenclatureWorkstation[$k]->workstation = $ws;

		}
	}

    function getDetails($qty_ref = 1) {

        $Tab = array();

        $PDOdb = new TPDOdb;

        $coef = 1;
        if($qty_ref != $this->qty_reference) {
            $coef = $qty_ref / $this->qty_reference;
        }

        foreach($this->TNomenclatureDet as &$d)
        {
            $qty = $d->qty * $coef;
            $fk_product = $d->fk_product;

            $childs = array();

			$nomenclature = TNomenclatureDet::getArboNomenclatureDet($PDOdb, $d, $qty);

			if(!empty($nomenclature)) $childs = $nomenclature->getDetails($qty);

            $Tab[]=array(
                0=>$fk_product
                ,1=>$qty
                ,'fk_product'=>$fk_product
                ,'qty'=>$qty
				,'childs'=>$childs
                ,'note_private'=>$d->note_private
            	,'workstations'=>$d->workstations
            	,'rowid'=>$d->rowid
            	,'fk_unit'=>$d->fk_unit
            );

        }

        return $Tab;

    }
    static function get(&$PDOdb, $fk_object, $forCombo=false, $object_type= 'product')
    {
    	global $langs;

        $Tab = $PDOdb->ExecuteAsArray("SELECT rowid FROM ".MAIN_DB_PREFIX."nomenclature
		 WHERE fk_object=".(int) $fk_object." AND object_type='".$object_type."' ORDER BY is_default DESC, rowid ASC ");
        $TNom=array();

        foreach($Tab as $row) {

            $n=new TNomenclature;
            $n->load($PDOdb, $row->rowid);

			if($forCombo) {
				 $TNom[$n->getId()] = '('.$n->getId().') '. ($n->title ? $n->title : $langs->trans('NoTitle') ) .' : '.$n->qty_reference;
			}
			else{
				 $TNom[] = $n;
			}


        }

        return $TNom;
    }

	/*
	 * Return the default TNomenclature object of product or the first of list if not default or false
	 */
	static function getDefaultNomenclature(&$PDOdb, $fk_product, $qty_ref = 0)
	{
		$TNomenclature = new TNomenclature;

		$PDOdb->Execute('SELECT rowid FROM '.MAIN_DB_PREFIX.'nomenclature
		          WHERE (fk_object='.(int)$fk_product.' AND object_type=\'product\')
		          AND qty_reference<='.$qty_ref.'
		          ORDER BY is_default DESC, qty_reference DESC
		          LIMIT 1');
	        $res = $PDOdb->Get_line();

		if ($res)
		{
			$TNomenclature->load($PDOdb, $res->rowid);

			return $TNomenclature;
		}
		else
		{
			$Tab = self::get($PDOdb, $fk_product);

			if (count($Tab) > 0)
			{
				return $Tab[0];
			}
		}

		return false;
	}

    static function resetDefaultNomenclature(&$PDOdb, $fk_product)
	{
		return $PDOdb->Execute('UPDATE '.MAIN_DB_PREFIX.'nomenclature SET is_default = 0 WHERE fk_product = '.(int) $fk_product);
	}

	/**
	 * @return array : retourne un tableau contenant en clef le fk_product et en valeur le type de ce produit dans la nomenclature
	 */
	function getArrayTypesProducts() {
		$PDOdb = new TPDOdb;

		$TTypesProducts = array();
		$types = TNomenclatureDet::getTType($PDOdb);

		foreach ($this->TNomenclatureDet as $key => $value) {
			$TTypesProducts[$value->fk_product] = $types[$value->code_type];
		}

		return $TTypesProducts;

	}

	function isWorkstationAssociated($fk_new_workstation) {

		global $langs;

		if(empty($this->TNomenclatureWorkstation)) return false;

		foreach ($this->TNomenclatureWorkstation as $ws) {
			if($ws->fk_workstation == $fk_new_workstation) {
				setEventMessage($langs->trans('WorkstationAlreadyAssociated'), 'errors');
				return true;
			}
		}

		return false;

	}
	/*
	 * Fonction pour savoir si un produit d'un certain type n'a pas d'autres enfants du même type
	 * $details = result of getDetails
	 * $type = product_type
	 */
	public static function noProductOfThisType($details,$type){
		global $db;

		foreach ($details as &$lineNomen)
		{
			//Conversion du tableau en objet
			$product = new Product($db);
			$product->fetch($lineNomen['fk_product']);

			if($product->type==$type){
				return false;
			}else if(!empty($lineNomen['childs']) && !TNomenclature::noProductOfThisType($lineNomen['childs'],$type)){

				return false;
			}

		}
		return true;
	}

	/**
	 * Méthode qui ce charge de faire les mouvements de stock du produit final ainsi que des composants
	 * @param type $qty
	 * @param type $fk_warehouse_to_make
	 * @param type $fk_warehouse_needed
	 * @return int
	 */
	function addMvtStock($qty, $fk_warehouse_to_make, $fk_warehouse_needed)
	{
		global $db,$langs,$user,$conf;

		if (empty($conf->stock->enabled)) return 1;

		require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

		$error = 0;

		if (empty($qty)) { $error++; $this->errors[] = $langs->trans('NomenclatureErrorEmptyQtyToStock'); }
		if (empty($fk_warehouse_to_make) | $fk_warehouse_to_make < 0) { $error++; $this->errors[] = $langs->trans('NomenclatureErrorNoWarehouseSelectedToMake'); }
		if (empty($fk_warehouse_needed) | $fk_warehouse_needed < 0) { $error++; $this->errors[] = $langs->trans('NomenclatureErrorNoWarehouseSelectedNeeded'); }
		if ($this->object_type != 'product') { $error++; $this->errors[] = $langs->trans('NomenclatureErrorInvalidNomenclatureType'); }

		if (empty($error))
		{
			$action = 'destockNeeded';
			if ($qty < 0) $action = 'stockNeeded';

			$qty_abs = abs($qty); // Qté du produit final à déplacer
			$coef = $qty_abs / $this->qty_reference; // Coef pour les composants (l'attribut qty des lignes équivaut à la fabrication de qty_reference de la nomenclature)

			$mouvS = new MouvementStock($db);
			$mouvS->origin = new stdClass();
			$mouvS->origin->element = 'product';
			$mouvS->origin->id = $this->fk_object;

			$db->begin();
			if($action === 'destockNeeded')
			{
				// DESTOCK components (needed)
				foreach ($this->TNomenclatureDet as &$det)
				{
				    $val = $det->qty*$coef;
					if(empty($val)) continue;
					$result=$mouvS->livraison($user, $det->fk_product, $fk_warehouse_needed, $det->qty*$coef, 0, $langs->trans('NomenclatureDestockProductFrom', $this->getId()));
					if ($result < 0 || ($result == 0 && empty($det->fk_product))) $error++;
				}

				// Then STOCK the parent (to_make)
				$result=$mouvS->reception($user, $this->fk_object, $fk_warehouse_to_make, $qty_abs, $this->totalPRCMO, $langs->trans('NomenclatureStockProductFrom', $this->getId()));
				if ($result <= 0) $error++;
			}
			else
			{
				// TODO STOCK components (needed)
				foreach ($this->TNomenclatureDet as &$det)
				{
					$result=$mouvS->reception($user, $det->fk_product, $fk_warehouse_needed, $det->qty*$coef, 0, $langs->trans('NomenclatureDestockProductFrom', $this->getId()));
					if ($result <= 0) $error++;
				}

				// Then DESTOCK the parent (to_make)
				$result=$mouvS->livraison($user, $this->fk_object, $fk_warehouse_to_make, $qty_abs, $this->totalPRCMO, $langs->trans('NomenclatureDestockProductFrom', $this->getId()));
				if ($result <= 0) $error++;
			}

			if (empty($error))
			{
				$db->commit();
				return 1;
			}
			else
			{
				$db->rollback();
				return -2;
			}
		}

		return -1;
	}

	/**
	 * Renvoi la quantité potentiellement fabricable du produit final par rapport au stock théorique ou reel des composants
	 *
	 * @param string $attr_stock	attribut d'un objet Product (stock_theorique | stock_reel)
	 * @return float
	 */
	public function getQtyManufacturable($attr_stock='stock_theorique')
	{
		global $db;
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$coef = 1 / $this->qty_reference;

		$qty_theo = null;
		foreach ($this->TNomenclatureDet as &$det)
		{
			$product = new Product($db);
			if ($product->fetch($det->fk_product) > 0)
			{
				$product->load_stock();
				$qty = $product->{$attr_stock} / $det->qty * $coef;

				if ($qty < $qty_theo || is_null($qty_theo)) $qty_theo = $qty;
			}
		}

		return $qty_theo;
	}
}


class TNomenclatureDet extends TObjetStd
{
	/**
	 * product_type == fk_coef (rowid de la table nomenclature_coef)
	 */
    function __construct()
    {
    	global $conf;

        $this->set_table(MAIN_DB_PREFIX.'nomenclaturedet');
		$this->add_champs('title'); //Pour ligne libre
        $this->add_champs('fk_product,fk_nomenclature,is_imported,rang,unifyRang,fk_unit',array('type'=>'integer', 'index'=>true));
        $this->add_champs('code_type,code_type2,fk_fournprice',array('type'=>'varchar', 'length' => 30)); // Got : Je mets fk_fournprice en chaîne car fk_fournprice peut contenir un id ou "costprice" ou "pmpprice"
		$this->add_champs('workstations',array('type'=>'varchar', 'length' => 255));
        $this->add_champs('qty,qty_base,price,tx_custom,tx_custom2,loss_percent,buying_price',array('type'=>'float'));
        $this->add_champs('note_private',array('type'=>'text'));

        $this->_init_vars();

        $this->start();

		$this->calculate_price = 0;

        $this->qty=1;
        $this->code_type = TNomenclatureCoef::getFirstCodeType();
        if(!empty($conf->global->NOMENCLATURE_USE_COEF_ON_COUT_REVIENT)) $this->code_type2 = $this->code_type;

    }

    function save(&$PDOdb) {

    	global $db, $conf;

    	// Enregistrement de l'unité du produit dans la ligne de nomclature
    	if(!empty($conf->global->PRODUCT_USE_UNITS) && empty($this->fk_unit) && !empty($this->fk_product)) {
    		require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
    		$prod = new Product($db);
    		if($prod->fetch($this->fk_product) > 0) {
    			$this->fk_unit = $prod->fk_unit;
    		}
    	}

    	return parent::save($PDOdb);

    }

    function reinit() {
    	$this->fk_origin = $this->rowid;
        $this->{OBJETSTD_MASTERKEY} = 0; // le champ id est toujours def
        $this->{OBJETSTD_DATECREATE}=time(); // ces champs dates aussi
        $this->{OBJETSTD_DATEUPDATE}=time();

    }

	function getPrice(&$PDOdb, $qty, $type='') {

		if($type == 'PMP') {
			return $this->getPMPPrice();
		}
		else if($type == 'OF') {
			return $this->getOFPrice($PDOdb);
		}
		else{
			return $this->getSupplierPrice($PDOdb, $qty, true);
		}

	}

	function getOFPrice(&$PDOdb) {
		global $conf;
		if(empty($conf->of->enabled)) return 0;


		$PDOdb->Execute("SELECT AVG(pmp) as pmp
                FROM ".MAIN_DB_PREFIX."assetOf_line
                WHERE type='NEEDED' AND fk_product=".$this->fk_product." AND date_maj>=DATE_SUB(NOW(), INTERVAL 6 MONTH) AND pmp>0");

		if($obj = $PDOdb->Get_line()) {
			return (float)$obj->pmp;

		}

		return 0;

	}

	function getPMPPrice() {
		global $db,$conf,$user,$langs;

		if (empty($this->fk_product)) return 0;

		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$p=new Product($db);
		$p->fetch($this->fk_product);

		return $p->pmp;

	}

	function getCostPrice() {
	    global $db,$conf,$user,$langs;

		if (empty($this->fk_product)) return 0;

		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	    $p=new Product($db);
	    $p->fetch($this->fk_product);

	    return $p->cost_price;

	}

	/*
	 * Retourne le prix unitaire en fonction de la quantité commandé
	 */
    function getSupplierPrice(&$PDOdb, $qty = 1, $searchforhigherqtyifnone=false, $search_child_price=true, $force_cost_price=false, $best_one = false) {
        global $db,$conf;

        if (!empty($conf->global->NOMENCLATURE_USE_QTYREF_TO_ONE)) {
        	$qty=1;
        }

		$price_supplier = $child_price = 0;

		if (!$force_cost_price)
		{
		    if(!$best_one){
		        $PDOdb->Execute("SELECT rowid, price, quantity, remise_percent FROM ".MAIN_DB_PREFIX."product_fournisseur_price
					WHERE fk_product = ". $this->fk_product." AND quantity<=".$qty." ORDER BY quantity DESC LIMIT 1 ");
		    } else {
		        $PDOdb->Execute("SELECT rowid, price, quantity, remise_percent, ((price / quantity) * (1 -  remise_percent / 100 )) as unitfinalprice  FROM ".MAIN_DB_PREFIX."product_fournisseur_price
					WHERE fk_product = ". $this->fk_product." AND quantity<=".$qty." ORDER BY unitfinalprice ASC LIMIT 1 ");
		    }



			if($obj = $PDOdb->Get_line()) {
				$price_supplier = $obj->price / $obj->quantity;
                $price_supplier = $price_supplier * (1 - $obj->remise_percent / 100);
			}

			if($searchforhigherqtyifnone && empty($price_supplier)) {
			    if(!$best_one){
			        $PDOdb->Execute("SELECT rowid, price, quantity, remise_percent FROM ".MAIN_DB_PREFIX."product_fournisseur_price
						WHERE fk_product = ". $this->fk_product." AND quantity>".$qty." ORDER BY quantity ASC LIMIT 1 ");
			    } else {
			        $PDOdb->Execute("SELECT rowid, price, quantity, remise_percent, ((price / quantity) * (1 -  remise_percent / 100 )) as unitfinalprice FROM ".MAIN_DB_PREFIX."product_fournisseur_price
						WHERE fk_product = ". $this->fk_product." AND quantity>".$qty." ORDER BY unitfinalprice ASC LIMIT 1 ");
			    }

				if($obj = $PDOdb->Get_line()) {
					$price_supplier = $obj->price / $obj->quantity;
                    $price_supplier = $price_supplier * (1 - $obj->remise_percent / 100);
				}
			}
		}


		// Si aucun prix fournisseur de disponible
		if ((empty($price_supplier) && (double) DOL_VERSION >= 3.9) || $force_cost_price)
		{
			$PDOdb->Execute('SELECT cost_price FROM '.MAIN_DB_PREFIX.'product WHERE rowid = '.$this->fk_product);
			if($obj = $PDOdb->Get_line()) $price_supplier = $obj->cost_price; // Si une quantité de conditionnement existe alors il faut l'utiliser comme diviseur [v4.0 : n'existe pas encore]
		}

		if (!$force_cost_price)
		{
			if($search_child_price && (empty($price_supplier) || !empty($conf->global->NOMENCLATURE_TAKE_PRICE_FROM_CHILD_FIRST))) {

				$n = self::getArboNomenclatureDet($PDOdb, $this,$this->qty,false);
				if($n!==false) {
					$n->nested_price_level = $this->nested_price_level + 1;

					$n->setPrice($PDOdb, $qty, $this->fk_product, 'product');

					$child_price = $n->totalPRCMO / $qty;
					//var_dump($child_price,$n);exit;
				}
			}
		}


		if(empty($conf->global->NOMENCLATURE_TAKE_PRICE_FROM_CHILD_FIRST)) return empty($price_supplier) ? $child_price : $price_supplier;
		else  return empty($child_price) ? $price_supplier : $child_price;

    }

	/**
	 * renvoi la nomenclature par defaut du produit de la ligne
	 * TODO c'est une métode useless !!!
	 *
	 * @param      $PDOdb
	 * @param      $nomenclatureDet
	 * @param      $qty_to_make
	 * @param bool $recursive
	 * @return bool|mixed|TNomenclature
	 * @deprecated
	 */
	static function getArboNomenclatureDet(&$PDOdb, &$nomenclatureDet, $qty_to_make, $recursive = false)
	{
		//$defaultNomenclature = self::getDefaultNomenclature($PDOdb, $nomenclatureDet->fk_product, $qty_to_make);
		return TNomenclature::getDefaultNomenclature($PDOdb, $nomenclatureDet->fk_product, $qty_to_make);
	}


    static function getTType(&$PDOdb, $blankRow = false, $type='nomenclature')
	{
		global $conf;

		$res = array();
		if ($blankRow) $res = array('' => '');

		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'nomenclature_coef WHERE entity IN ('.$conf->entity.',0) AND type = "'.$type.'" ORDER BY rowid';
		$resql = $PDOdb->Execute($sql);

		if ($resql && $PDOdb->Get_Recordcount() > 0)
		{
			while ($row = $PDOdb->Get_line())
			{
				$res[$row->code_type] = $row->label;
			}
		}

		return $res;
	}

	// Récupération des différents tarifs (tarifs fourn, PMP) de la même manière que Dolibarr, puis adaptationp our le cas nomenclature
	function printSelectProductFournisseurPrice($k, $nomenclature_id=0, $nomenclature_type='product') {

		global $langs, $conf;

		?>
		<script type="text/javascript">

		$.post('<?php echo DOL_URL_ROOT; ?>/fourn/ajax/getSupplierPrices.php?bestpricefirst=1', { 'idprod': <?php echo $this->fk_product; ?> }, function(data) {
    	    	if (data && data.length > 0)
    	    	{
    	    		var options = '<option value="0" price=""></option>'; // Valeur vide
        	  		var defaultkey = '';
        	  		var defaultprice = '';
    	      		var bestpricefound = 0;

    	      		var bestpriceid = 0; var bestpricevalue = 0;
    	      		var pmppriceid = 0; var pmppricevalue = 0;
    	      		var costpriceid = 0; var costpricevalue = 0;

    				/* setup of margin calculation */
    	      		var defaultbuyprice = '<?php

    	      		if (!empty($conf->global->NOMENCLATURE_COST_TYPE))
    	      		{
    	      		    if ($conf->global->NOMENCLATURE_COST_TYPE == '1')   print 'bestsupplierprice';
    	      		    if ($conf->global->NOMENCLATURE_COST_TYPE == 'pmp') print 'pmp';
    	      		    if ($conf->global->NOMENCLATURE_COST_TYPE == 'costprice') print 'costprice';
    	      		}
    	      		elseif (isset($conf->global->MARGIN_TYPE))
    	      		{
    	      		    if ($conf->global->MARGIN_TYPE == '1')   print 'bestsupplierprice';
    	      		    if ($conf->global->MARGIN_TYPE == 'pmp') print 'pmp';
    	      		    if ($conf->global->MARGIN_TYPE == 'costprice') print 'costprice';
    	      		} ?>';
    	      		console.log("we will set the field for margin. defaultbuyprice="+defaultbuyprice);

    	      		var i = 0;
    	      		$(data).each(function() {
    	      			if (this.id != 'pmpprice' && this.id != 'costprice')
    		      		{
    		        		i++;
                            this.price = parseFloat(this.price); // to fix when this.price >0
    			      		// If margin is calculated on best supplier price, we set it by defaut (but only if value is not 0)
    			      		//console.log("id="+this.id+"-price="+this.price+"-"+(this.price > 0));
    		      			if (bestpricefound == 0 && this.price > 0) { defaultkey = this.id; defaultprice = this.price; bestpriceid = this.id; bestpricevalue = this.price; bestpricefound=1; }	// bestpricefound is used to take the first price > 0
    		      		}
    	      			if (this.id == 'pmpprice')
    	      			{
    	      				// If margin is calculated on PMP, we set it by defaut (but only if value is not 0)
    			      		//console.log("id="+this.id+"-price="+this.price);
    			      		if ('pmp' == defaultbuyprice || 'costprice' == defaultbuyprice)
    			      		{
    			      			if (this.price > 0) {
    				      			defaultkey = this.id; defaultprice = this.price; pmppriceid = this.id; pmppricevalue = this.price;
    			      				console.log("pmppricevalue="+pmppricevalue);
    			      			}
    			      		}
    	      			}
    	      			if (this.id == 'costprice')
    	      			{
    	      				// If margin is calculated on Cost price, we set it by defaut (but only if value is not 0)
    			      		//console.log("id="+this.id+"-price="+this.price+"-pmppricevalue="+pmppricevalue);
    			      		if ('costprice' == defaultbuyprice)
    			      		{
    		      				if (this.price > 0) { defaultkey = this.id; defaultprice = this.price; costpriceid = this.id; costpricevalue = this.price; }
    		      				else if (pmppricevalue > 0) { defaultkey = pmppriceid; defaultprice = pmppricevalue; }
    			      		}
    	      			}

    	      			if(this.price == ''){
			      			this.price = 0;
			      		}
    	        		options += '<option value="'+this.id+'" price="'+this.price+'">'+this.label+'</option>';
    	      		});

    	      		console.log("finally selected defaultkey="+defaultkey+" defaultprice="+defaultprice);

    	      		<?php if(empty($nomenclature_id) || $nomenclature_type !== 'product') { ?>

    	      			var select_fournprice = $('select[name=TNomenclature\\[<?php echo $k; ?>\\]\\[fk_fournprice\\]]');
					<?php } else { ?>
						var select_fournprice = $('div#nomenclature<?php echo $nomenclature_id; ?> select[name=TNomenclature\\[<?php echo $k; ?>\\]\\[fk_fournprice\\]]');
					<?php } ?>

    	      		select_fournprice.html(options);

    	      		// Pour l'instant on laisee l'utilisateur choisir à la main le prix d'achat
    	      		/*if (defaultkey != '')
    				{
    		      		$("#fournprice_predef_line_<?php echo $this->rowid; ?>").val(defaultkey);
    		      	}*/

    	      		// Préselection de la liste avec la valeur en base si existante
    	      		<?php if(!empty($this->fk_fournprice)) { ?>
    	      			select_fournprice.val('<?php echo $this->fk_fournprice; ?>');
		      		<?php }else{ ?>
		      			select_fournprice.val(defaultbuyprice);
		      		<?php } ?>
    	      		/* At loading, no product are yet selected, so we hide field of buying_price */
    	      		//$("#buying_price").hide();

    	      		if(select_fournprice.closest('tr').find('input[name*="buying_price"]').val() == '')
    	      		{
    	      			console.log("init fournprice_predef");
    	        		var pricevalue = select_fournprice.find('option:selected').attr("price");
    	      			select_fournprice.closest('tr').find('input[name*="buying_price"]').attr('placeholder',pricevalue);
    	      		}

    			    select_fournprice.change(function() {
    		      		console.log("change on fournprice_predef");
    	      			var linevalue=$(this).find('option:selected').val();
    	        		var pricevalue = $(this).find('option:selected').attr("price");
    	        		$(this).closest('tr').find('input[name*="buying_price"]').val(pricevalue);
    	        		$(this).closest('tr').find('input[name*="buying_price"]').attr('placeholder','');
    				});
    	    	}
    	  	},
    	  	'json');

		</script>

		<?php

	}

}


class TNomenclatureWorkstation extends TObjetStd
{

    function __construct()
    {
        $this->set_table(MAIN_DB_PREFIX.'nomenclature_workstation');
        $this->add_champs('fk_workstation,fk_nomenclature,rang,unifyRang',array('type'=>'integer', 'index'=>true));
        $this->add_champs('nb_hour,nb_hour_prepare,nb_hour_manufacture,nb_days_before_beginning',array('type'=>'float'));
        $this->add_champs('note_private',array('type'=>'text'));
		$this->add_champs('code_type',array('type'=>'varchar', 'length' => 30));

        $this->_init_vars();

        $this->start();

        $this->qty=1;
        $this->product_type=1;
    }

    function reinit()
    {
        $this->{OBJETSTD_MASTERKEY} = 0; // le champ id est toujours def
        $this->{OBJETSTD_DATECREATE}=time(); // ces champs dates aussi
        $this->{OBJETSTD_DATEUPDATE}=time();

    }

	function getPrice(&$PDOdb, $coef_qty_price = 1, $type ='', $coef=1) {
		global $conf;

		$nb_hour = 0;
		$price = 0;

		$nb_hour = $this->nb_hour_prepare + ($this->nb_hour_manufacture * $coef_qty_price);

		if($type == 'OF' && !empty($conf->of->enabled)) {

			$PDOdb->Execute("SELECT SUM(thm * nb_hour) / SUM(nb_hour) as thm
	                FROM ".MAIN_DB_PREFIX."asset_workstation_of
	                WHERE fk_asset_workstation=".$this->fk_workstation." AND date_maj>=DATE_SUB(NOW(), INTERVAL 6 MONTH) AND thm>0");

			if($obj = $PDOdb->Get_line()) {
				$price = $obj->thm * $nb_hour * $coef;
			}

		}
		else{
			$price = ($this->workstation->thm + $this->workstation->thm_machine) * $nb_hour * $coef;
		}

		return array( $nb_hour , $price );
	}

    function load(&$PDOdb, $id, $annexe = true)
    {
        parent::load($PDOdb, $id);

        if($annexe) {
            $this->workstation = new TWorkstation;
            $this->workstation->load($PDOdb, $this->fk_workstation);
        }
    }

    function save(&$PDOdb)
    {
        $this->nb_hour  = $this->nb_hour_prepare+$this->nb_hour_manufacture;
        parent::save($PDOdb);
    }

}

class TNomenclatureCoef extends TObjetStd
{
    function __construct()
    {
        $this->set_table(MAIN_DB_PREFIX.'nomenclature_coef');
        $this->add_champs('label,description',array('type'=>'varchar', 'length'=>255));
		$this->add_champs('code_type,type',array('type'=>'varchar', 'length'=>30, 'index'=>true)); // type = nomenclature ou workstation
        $this->add_champs('tx',array('type'=>'float'));
        $this->add_champs('entity',array('type'=>'int', 'index'=>true, 'default'=>1));

        $this->_init_vars();

        $this->start();
    }

	function load(&$PDOdb, $id, $loadChild = true)
	{
		parent::load($PDOdb, $id);
		$this->tx_object = $this->tx;
	}

	static function loadCoef(&$PDOdb, $type='nomenclature')
	{
		global $conf;

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'nomenclature_coef WHERE entity IN ('.$conf->entity.',0) AND type = "'.$type.'" ORDER BY rowid';
		$TRes = $PDOdb->ExecuteAsArray($sql);
		$TResult = array();

		foreach ($TRes as $res)
		{
			$o=new TNomenclatureCoef;
			$o->load($PDOdb, $res->rowid);

			$TResult[$o->code_type] = $o;
		}

		return $TResult;
	}

	static function getFirstCodeType(&$PDOdb = false)
	{
		global $conf,$cacheFirstCodeType;

		if(isset($cacheFirstCodeType))return $cacheFirstCodeType;

		if (!$PDOdb) $PDOdb = new TPDOdb;

		$resql = $PDOdb->Execute('SELECT MIN(rowid) AS rowid, code_type FROM '.MAIN_DB_PREFIX.'nomenclature_coef WHERE entity IN ('.$conf->entity.',0)');
		if ($resql && $PDOdb->Get_Recordcount() > 0)
		{
			$row = $PDOdb->Get_line();
			$cacheFirstCodeType = $row->code_type;
			return $row->code_type;
		}

		return null;
	}

    function save(&$PDOdb)
    {
    	global $conf;

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'nomenclature_coef WHERE entity IN ('.$conf->entity.',0) AND code_type = '.$PDOdb->quote($this->code_type).' AND rowid <> '.(int)$this->getId();
		$res = $PDOdb->Execute($sql);

		if ($res && $PDOdb->Get_Recordcount() > 0)
		{
			return 0;
		}


		$rowid = parent::save($PDOdb);
		return $rowid;
    }

	function delete(&$PDOdb)
	{
		if ($this->code_type == 'coef_marge') return false;

		//Vérification que le coef ne soit pas utilisé - si utilisé alors on interdit la suppression
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'nomenclaturedet WHERE code_type = '.$this->code_type.'
				UNION
				SELECT rowid FROM '.MAIN_DB_PREFIX.'nomenclature_coef_object WHERE code_type = '.$this->code_type;

		$res = $PDOdb->ExecuteAsArray($sql);

		if (count($res) > 0)
		{
			return 0;
		}

		parent::delete($PDOdb);
		return 1;
	}

}

class TNomenclatureCoefObject extends TObjetStd
{
	function __construct()
    {
        $this->set_table(MAIN_DB_PREFIX.'nomenclature_coef_object');

		$this->add_champs('fk_object',array('type'=>'integer', 'index'=>true)); /*,entity*/
        $this->add_champs('type_object',array('type'=>'varchar', 'length'=>50, 'index'=>true));
		$this->add_champs('code_type,type',array('type'=>'vachar', 'length'=>30, 'index'=>true));
        $this->add_champs('tx_object',array('type'=>'float'));

        $this->_init_vars();

        $this->start();
		$this->type = 'nomenclature';
    }

	function loadByTypeByCoef(&$PDOdb, $code_type, $fk_object, $type_object)
	{
		if(empty($fk_object) || empty($type_object) )return false;

		$PDOdb->Execute("SELECT rowid FROM ".$this->get_table()." WHERE code_type='".$code_type."' AND fk_object=".$fk_object." AND type_object='".$type_object."'");

		if($obj = $PDOdb->Get_line())
		{
			return $this->load($PDOdb, $obj->rowid);
		}

		return false;
	}

	static function getMarge(&$PDOdb, $object, $type_object)
	{
		$TCoef = self::loadCoefObject($PDOdb, $object, $type_object);
		$marge = $TCoef['coef_marge'];

		if($marge > 5) $marge = 1+($marge/100);

		return $marge;
	}

	static function deleteCoefsObject(&$PDOdb, $fk_object, $type_object) {

		$Tab = $PDOdb->ExecuteAsArray("SELECT rowid
				FROM ".MAIN_DB_PREFIX."nomenclature_coef_object
				WHERE type_object='".$type_object."' AND fk_object=".(int)$fk_object."
				");

		foreach($Tab as &$row) {

			$c = new TNomenclatureCoefObject;
			$c->load($PDOdb, $row->rowid);
			$c->delete($PDOdb);

		}

	}

	static function loadCoefObject(&$PDOdb, &$object, $type_object, $fk_origin=0)
	{
		$Tab = array();
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'nomenclature_coef_object
				WHERE fk_object = '.(int)$object->id.'
				AND type_object = "'.$type_object.'"';
/*				AND entity IN('.getEntity('nomenclature').')';*/
//var_dump($sql);exit;
		$PDOdb->Execute($sql);
		$TRes = $PDOdb->Get_All();

		switch ($type_object) {
			case 'propal':
				$TCoef = self::loadCoefObject($PDOdb, $object->thirdparty, 'tiers', $object->id); // Récup des coefs du parent (exemple avec propal -> je charge les coef du tiers associé)
				break;
			default:
				$TCoef = TNomenclatureCoef::loadCoef($PDOdb);
				$TCoef += TNomenclatureCoef::loadCoef($PDOdb, 'workstation');
//				uasort($TCoef, function($a, $b) {
//					if ($a->type == 'nomenclature' && $b->type == 'workstation') return -1;
//					else if ($a->type == 'workstation' && $b->type == 'nomenclature') return 1;
//					else return 0;
//				});
				break;
		}

		foreach ($TRes as $row)
		{
			$o = new TNomenclatureCoefObject;
			$o->load($PDOdb, $row->rowid);
			$o->fk_origin = $fk_origin;
			$o->tx = $o->tx_object;

			$Tab[$o->code_type] = $o;
		}

		foreach($TCoef as $k=> &$coef) {

			$coef->rowid = 0;
			$coef->tx_object = $coef->tx;

			if(!isset($Tab[$k])) $Tab[$k] = $coef;
			else {
				$Tab[$k]->label = $coef->label;
				$Tab[$k]->description = $coef->description;

		 	}
		}

		ksort($Tab);
		return $Tab;
	}
}




class TNomenclatureWorkstationThmObject extends TObjetStd
{
	function __construct()
    {
        $this->set_table(MAIN_DB_PREFIX.'nomenclature_workstation_thm_object');

		$this->add_champs('fk_workstation',array('type'=>'integer'));
		$this->add_champs('fk_object',array('type'=>'integer', 'index'=>true));
        $this->add_champs('type_object',array('type'=>'varchar', 'length'=>50, 'index'=>true));
        $this->add_champs('thm_object',array('type'=>'float'));

        $this->_init_vars();

        $this->start();
		$this->label = '';
    }

	function loadByFkWorkstationByFkObjectByType(&$PDOdb, $fk_workstation, $fk_object_parent, $type)
	{
		$PDOdb->Execute('SELECT rowid FROM '.$this->get_table().' WHERE fk_object = '.$fk_object_parent.' AND fk_workstation = '.$fk_workstation.' AND type_object = "'.$type.'"');

		if($obj = $PDOdb->Get_line())
		{
			return $this->load($PDOdb, $obj->rowid);
		}

		return false;
	}

	/**
	 * Méthode pour supprimer tous les THM custom associés à la propal
	 *
	 * @param type $PDOdb
	 * @param type $fk_object
	 * @param type $type_object
	 */
	static function deleteAllThmObject(&$PDOdb, $fk_object, $type_object)
	{
		$Tab = $PDOdb->ExecuteAsArray("SELECT rowid
				FROM ".MAIN_DB_PREFIX."nomenclature_workstation_thm_object
				WHERE type_object='".$type_object."' AND fk_object=".(int)$fk_object."
				");

		foreach($Tab as &$row)
		{
			$c = new TNomenclatureWorkstationThmObject;
			$c->load($PDOdb, $row->rowid);
			$c->delete($PDOdb);
		}
	}

	/**
	 * Methode pour récupérer le tableau des THM custom associés à la propal
	 *
	 * @param type $PDOdb
	 * @param type $object
	 * @param type $type_object
	 * @return array of TNomenclatureWorkstationThmObject
	 */
	static function loadAllThmObject(&$PDOdb, &$object, $type_object)
	{
		$TThmObject = array();

		// 1 : on récupère tous les Poste de travail existants
		dol_include_once('/workstation/class/workstation.class.php');
		$TWorkstation = TWorkstation::getAllWorkstationObject($PDOdb);

		// 2 : on va chercher les coef custom
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'nomenclature_workstation_thm_object
				WHERE fk_object = '.(int)$object->id.'
				AND type_object = "'.$type_object.'"';
/*				AND entity IN('.getEntity('nomenclature').')';*/

		$PDOdb->Execute($sql);
		$Tab = $PDOdb->Get_All();

		foreach ($Tab as $row)
		{
			$o = new TNomenclatureWorkstationThmObject;
			$o->load($PDOdb, $row->rowid);

			$o->label = $TWorkstation[$o->fk_workstation]->name;
			$TThmObject[$o->fk_workstation] = $o;
		}

		// 3 : je merge mon tableau de workstations avec mes thm custom si encore non défini
		foreach ($TWorkstation as &$workstation)
		{
			if (empty($TThmObject[$workstation->getId()]))
			{
				$o = new TNomenclatureWorkstationThmObject;
				$o->rowid = 0;
				$o->fk_workstation = $workstation->getId();
				$o->fk_object = $object->id;
				$o->type_object = $object->element;

				$o->label = $workstation->name;
				$o->thm_object = $workstation->thm;

				$TThmObject[$o->fk_workstation] = $o;
			}

		}

		ksort($TThmObject);
		return $TThmObject;
	}

	/**
	 * Methode pour mettre à jour les THM liés à l'objet (n'applique pas le nouveau THM sur les lignes du document)
	 *
	 * @param type $PDOdb
	 * @param type $object
	 * @param type $TNomenclatureWorkstationThmObject
	 */
	static function updateAllThmObject(&$PDOdb, &$object, $TNomenclatureWorkstationThmObject)
	{
		global $langs;

		if (!empty($TNomenclatureWorkstationThmObject))
		{
			foreach ($TNomenclatureWorkstationThmObject as $fk_workstation => &$thm)
			{
				// TODO loadByFkWorkstation
				$o = new TNomenclatureWorkstationThmObject;
				$o->loadByFkWorkstationByFkObjectByType($PDOdb, $fk_workstation, $object->id, $object->element);

				$o->fk_object = $object->id;
				$o->type_object = $object->element;
				$o->fk_workstation = $fk_workstation;
				$o->thm_object = $thm;

				$o->save($PDOdb);
			}
		}

		setEventMessages($langs->trans('workstationThmUpdated'), null);
	}

}

class TNomenclatureFeedback extends TObjetStd
{

    function __construct()
    {
        $this->element          = 'nomenclaturefeedback';

        $this->set_table(MAIN_DB_PREFIX.'nomenclature_feedback');
        $this->add_champs('fk_origin,fk_nomenclature,fk_product',array('type'=>'integer', 'index'=>true));
        $this->add_champs('fk_warehouse',array('type'=>'integer'));
        $this->add_champs('origin' , array('type'=>'string'));
        $this->add_champs('stockAllowed,qtyUsed' , array('type'=>'float'));
        $this->add_champs('note', array('type'=>'text'));

        $this->_init_vars();

        $this->start();

        $this->origin           = '';
        $this->fk_origin        = 0;
        $this->qtyUsed          = 0;
        $this->stockAllowed     = 0;
        $this->fk_nomenclature  = 0;
        $this->fk_warehouse     = 0;
        $this->fk_product       = 0;
        $this->note             = '';
    }

    function reinit()
    {
        $this->{OBJETSTD_MASTERKEY}  = 0; // le champ id est toujours def
        $this->{OBJETSTD_DATECREATE} = time(); // ces champs dates aussi
        $this->{OBJETSTD_DATEUPDATE} = time();

    }

    function loadByProduct(&$db, $origin, $fk_origin, $fk_product, $fk_nomenclature) {
        $sql = "SELECT ".OBJETSTD_MASTERKEY." FROM ".$this->get_table()." WHERE fk_nomenclature=".intval($fk_nomenclature)." AND fk_product=".intval($fk_product)." AND fk_origin=".intval($fk_origin)." AND origin=".$db->quote($origin)." LIMIT 1";

        $db->Execute($sql);


        if($db->Get_line()) {
            return $this->load($db, $db->Get_field(OBJETSTD_MASTERKEY), false);
        }
        else {
            return false;
        }
    }

}
