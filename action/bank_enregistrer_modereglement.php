<?php
/*
 * Paiement Bancaire
 * module de paiement bancaire multi prestataires
 * stockage des transactions
 *
 * Auteurs :
 * Cedric Morin, Nursit.com
 * (c) 2012 - Distribue sous licence GNU/GPL
 *
 */
if (!defined('_ECRIRE_INC_VERSION')) return;

function action_bank_enregistrer_modereglement_dist($arg=null){

	if (is_null($arg)){
		$securiser_action = charger_fonction('securiser_action','inc');
		$arg = $securiser_action();
	}

	var_dump($arg);
	$arg = explode("-",$arg);
	$id_transaction = intval(array_pop($arg));
	$presta = implode("-",$arg);

	if (isset($GLOBALS['meta']['bank_paiement'])
		AND $config = unserialize($GLOBALS['meta']['bank_paiement'])){

		$prestas = (is_array($config['presta'])?$config['presta']:array());
		$prestas = array_filter($prestas);
		if (is_array($config['presta_abo']))
			$prestas = array_merge($prestas,array_filter($config['presta_abo']));

	}

	if (
	  ((isset($prestas[$presta]) AND $prestas[$presta]) OR $presta=='gratuit')
	  AND $id_transaction
		AND $transaction = sql_fetsel('*','spip_transactions','id_transaction='.intval($id_transaction))
	){

		if ($transaction['statut']=='commande'){
			sql_updateq("spip_transactions",array('mode'=>$presta,'autorisation_id'=>date('d/m/Y-H:i:s')."/".$GLOBALS['ip']),'id_transaction='.intval($id_transaction));
			$GLOBALS['redirect'] = _request('redirect');
			$GLOBALS['redirect'] = parametre_url($GLOBALS['redirect'],"attente_mode",$presta,"&");
		}

	}

}