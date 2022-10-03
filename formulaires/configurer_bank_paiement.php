<?php
/*
 * Paiement Bancaire
 * module de paiement bancaire multi prestataires
 * stockage des transactions
 *
 * Auteurs :
 * Cedric Morin, Nursit.com
 * (c) 2012-2019 - Distribue sous licence GNU/GPL
 *
 */
if (!defined('_ECRIRE_INC_VERSION')){
	return;
}

include_spip('inc/bank');


function formulaires_configurer_bank_paiement_verifier_dist(){
	$erreurs = array();
	if ($e = _request('email_ticket_admin') AND !email_valide($e)){
		$erreurs['email_ticket_admin'] = _T('form_prop_indiquer_email');
	}


	if (!count($erreurs)){
		if ($dels = _request('action_del') AND count($dels)){
			set_request('action_del');
			foreach ($dels as $del => $v){
				set_request($del, null);
			}
		}
		if ($ups = _request('action_up') AND count($ups)){
			set_request('action_up');
			foreach ($ups as $up => $v){
				bank_deplacer_config($up, "up");
			}
		}
		if ($downs = _request('action_down') AND count($downs)){
			set_request('action_down');
			foreach ($downs as $down => $v){
				bank_deplacer_config($down, "down");
			}
		}
		if (_request('action_append')
			AND $presta = _request('action_append_presta')
			AND in_array($presta, bank_lister_prestas())){
			set_request('action_append');
			set_request('action_append_presta');
			bank_ajouter_config($presta);
		}
	}
	return $erreurs;
}

/**
 * Ajouter une config pour un presta
 * @param $presta
 */
function bank_ajouter_config($presta){
	include_spip('inc/config');
	$config = lire_config("bank_paiement/");
	$c = array('presta' => $presta, 'actif' => 0, 'type' => 'acte');

	$id = $presta;
	$suff = "";
	$n = "";
	while (isset($config["config_$id$suff"])){
		$n++;
		$suff = "-$n";
	}
	$id = "$id$suff";
	$c['config'] = $id;
	set_request("config_$id", $c);
	ecrire_config("bank_paiement/config_$id", $c);
}


/**
 * Deplacer une config (remonter/descendre) pour configurer l'ordre de presentation
 * @param $nom
 * @param string $sens
 */
function bank_deplacer_config($nom, $sens = "up"){
	include_spip('inc/config');
	$config = lire_config("bank_paiement/");

	$new = array();
	// d'abord on remet les autres configs (pas presta)
	foreach ($config as $k => $v){
		if (strncmp($k, "config_", 7)!==0){
			$new[$k] = $v;
			unset($config[$k]);
		}
	}

	$kp = $vp = null;
	foreach ($config as $k => $v) {
		if ($k === $nom and $kp and $sens === "up") {
			array_pop($new);
			$new[$k] = $v;
			$new[$kp] = $vp;
		}
		elseif ($kp === $nom and $sens === "down") {
			array_pop($new);
			$new[$k] = $v;
			$new[$kp] = $vp;
		}
		else {
			$new[$k] = $v;
		}
		$kp = $k;
		$vp = $v;
	}

	ecrire_config("bank_paiement/", $new);
}

/**
 * Compter si un presta est utiliser plusieurs fois pour permettre de mettre un label dessus
 * @param string $presta
 * @return string
 */
function bank_configure_label_presta($presta){
	static $configs;
	if (is_null($configs)){
		$configs = bank_lister_configs();
	}
	$n = 0;
	foreach ($configs as $c){
		if ($c['presta']==$presta){
			$n++;
		}
		if ($n>1){
			return ' ';
		}
	}
	return '';
}
