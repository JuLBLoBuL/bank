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
include_spip('inc/filtres');
include_spip('inc/filtres_mini'); // url_absolue
/**
 * Liste des cartes moyens de paiement possibles selon la config
 * @param $config
 * @return array
 */
function stripe_available_cards($config){

	$mode = $config['presta'];
	// https://stripe.com/docs/api/payment_methods/object#payment_method_object-type

	$cartes_possibles = array(
		'card' => "CARD.gif",
		'bancontact' => "BANCONTACT.gif",
		'ideal' => "IDEAL.gif",
		// a tester
		//'sepa_debit' => "SEPA_SDD.gif",
	);

	// pas de bancontact ni de ideal en abonnement
	if ($config['type']=='abo'){
		unset($cartes_possibles['bancontact']);
		unset($cartes_possibles['ideal']);
	}

	return $cartes_possibles;
}

/**
 * Choix de la langue : on utilise celle du site si connue de stripe, sinon auto
 * @param string $lang
 * @return string
 */
function stripe_locale($lang) {
	if (in_array($lang, [
		"bg", "cs", "da", "de", "el", "en", "en-GB", "es", "es-419", "et", "fi", "fil", "fr", "fr-CA", "hr", "hu", "id", "it", "ja", "ko", "lt", "lv", "ms", "mt", "nb", "nl", "pl", "pt", "pt-BR", "ro", "ru", "sk", "sl", "sv", "th", "tr", "vi", "zh", "zh-HK", "zh-TW"
	])) {
		return $lang;
	}

	return "auto";
}

/**
 * Initialiser l'API Stripe : chargement de la lib et inits des static
 * @param $config
 */
function stripe_init_api($config){

	include_spip('presta/stripe/lib/stripe-php-7/init');

	// Set secret key
	// See keys here: https://dashboard.stripe.com/account/apikeys
	$key = ($config['mode_test'] ? $config['SECRET_KEY_test'] : $config['SECRET_KEY']);
	\Stripe\Stripe::setApiKey($key);

	// debug : pas de verif des certificats
	\Stripe\Stripe::$verifySslCerts = false;

	// s'annoncer fierement : SPIP + bank vx
	\Stripe\Stripe::$appInfo = bank_annonce_version_plugin('array');

}

/**
 * Creer/updater un webhook pour ce site
 * @param $config
 * @throws \Stripe\Error\Api
 */
function stripe_set_webhook($config){
	stripe_init_api($config);
	$mode = $config['presta'];
	$key_webhook_secret = (($config['mode_test']) ? 'WEBHOOK_SECRET_KEY_test' : 'WEBHOOK_SECRET_KEY');
	$has_secret = ((isset($config[$key_webhook_secret]) and $config[$key_webhook_secret]) ? true : false);

	$url_endpoint = bank_url_api_retour($config, "autoresponse");
	$event_endpoint = ["*"];
	$p = strpos($url_endpoint, '.');
	$p = strpos($url_endpoint, '/', $p);
	$base_endpoint = substr($url_endpoint, 0, $p+1);
	spip_log("stripe_set_webhook: endpoint $url_endpoint base $base_endpoint", $mode);

	// verifier que le endpoint n'est pas un localhost, sinon on laisse tomber
	$parts = parse_url($base_endpoint);
	if ($parts['host'] === 'localhost') {
		spip_log("stripe_set_webhook: Impossible de creer un endpoint sur localhost -- request Ignoree", $mode . _LOG_ERREUR);
		return;
	}


	$existing_endpoint_id = null;
	try {
		$list = \Stripe\WebhookEndpoint::all(["limit" => 100]);
	} catch (Exception $e) {
		spip_log("stripe_set_webhook: Impossible de lister les endpoints :: " . $e->getMessage(), $mode . _LOG_ERREUR);
		$list = [];
		// si secret connu, on presume qu'on a deja un endpoint configure
		if ($has_secret){
			return;
		}
	}

	foreach ($list->data as $endpoint){
		if ($endpoint->status=='enabled'){
			if (strpos($endpoint->url, $GLOBALS['meta']['adresse_site'] . '/')===0
				OR strpos($endpoint->url, $base_endpoint)===0){
				// si on ne connait pas le secret du webhook on le disabled et on en cree un nouveau
				if ($has_secret
					and $endpoint->url===$url_endpoint
					and is_array($endpoint->enabled_events)
					and (!array_diff($endpoint->enabled_events, $event_endpoint) or in_array('*', $endpoint->enabled_events))){
					// endpoint OK, rien a faire
					spip_log("stripe_set_webhook: OK endpoint " . $endpoint->id, $mode);
					return;
				} else {
					if ($has_secret){
						// Update endpoint
						$new_events = (is_array($endpoint->enabled_events) ? array_merge($event_endpoint, $endpoint->enabled_events) : $event_endpoint);
						// Stripe: * should be alone in the array
						if (in_array("*", $new_events)){
							$new_events = ["*"];
						}
						$set = ['url' => $url_endpoint, 'enabled_events' => $new_events];
					} else {
						$set = ['disabled' => true];
					}
					try {
						\Stripe\WebhookEndpoint::update($endpoint->id, $set);
						spip_log("stripe_set_webhook: UPDATED endpoint " . $endpoint->id . " " . json_encode($set), $mode);
					} catch (Exception $e) {
						spip_log("stripe_set_webhook: Impossible de modifier le endpoint " . $endpoint->id . " " . json_encode($set) . ' :: ' . $e->getMessage(), $mode . _LOG_ERREUR);
					}
					if ($has_secret){
						return;
					}
				}
			}
		}
	}

	try {
		// aucun endpoint valide, on en ajoute un
		$set = [
			"url" => $url_endpoint,
			"enabled_events" => $event_endpoint
		];
		$endpoint = \Stripe\WebhookEndpoint::create($set);
		spip_log("stripe_set_webhook: ADDED endpoint " . $endpoint->id . " " . json_encode($set), $mode);
		$secret = $endpoint->secret;

		$config_meta = lire_config("bank_paiement/", array());
		$key_ref = (($config['mode_test']) ? 'SECRET_KEY_test' : 'SECRET_KEY');
		if (is_array($config_meta)){
			foreach ($config_meta as $k => $v){
				if (strncmp($k, "config_", 7)==0){
					if ($v['presta']==='stripe'
						and $v['mode_test']==$config['mode_test']
						and $v[$key_ref]===$config[$key_ref]){
						ecrire_config("bank_paiement/$k/$key_webhook_secret", $secret);
					}
				}
			}
		}

	} catch (Exception $e) {
		spip_log("stripe_set_webhook: Impossible de creer un endpoint :: " . $e->getMessage(), $mode . _LOG_ERREUR);
	}

}

/**
 * Gerer la reponse du POST JS sur paiement/abonnement
 * @param array $config
 * @param array $response
 * @return array
 */
function stripe_traite_reponse_transaction($config, &$response){

	$mode = $config['presta'];
	if (isset($config['mode_test']) AND $config['mode_test']){
		$mode .= "_test";
	}
	$config_id = bank_config_id($config);
	$is_abo = (isset($response['abo']) and $response['abo']);

	if (!isset($response['id_transaction']) OR !isset($response['transaction_hash'])){
		return bank_transaction_invalide(0,
			array(
				'mode' => $mode,
				'erreur' => "transaction inconnue",
				'log' => var_export($response, true),
			)
		);
	}
	if ((!isset($response['payment_id']) OR !$response['payment_id'])){
		return bank_transaction_invalide(0,
			array(
				'mode' => $mode,
				'erreur' => "payment_id absent dans la reponse",
				'log' => var_export($response, true),
			)
		);
	}

	$id_transaction = $response['id_transaction'];
	$transaction_hash = $response['transaction_hash'];

	if (!$row = sql_fetsel('*', 'spip_transactions', 'id_transaction=' . intval($id_transaction))){
		return bank_transaction_invalide($id_transaction,
			array(
				'mode' => $mode,
				'erreur' => "transaction non trouvee",
				'log' => var_export($response, true),
			)
		);
	}
	if ($transaction_hash!=$row['transaction_hash']){
		return bank_transaction_invalide($id_transaction,
			array(
				'mode' => $mode,
				'erreur' => "hash $transaction_hash non conforme",
				'log' => var_export($response, true),
			)
		);
	}

	// On peut maintenant connaître la devise et ses infos
	$devise_fraction = bank_devise_info($row['devise'], 'fraction');

	// ok, on traite le reglement
	$date = $_SERVER['REQUEST_TIME'];
	$date_paiement = date('Y-m-d H:i:s', $date);

	$erreur = "";
	$erreur_code = 0;

	// charger l'API Stripe avec la cle
	stripe_init_api($config);


	try {
		$payment = \Stripe\PaymentIntent::retrieve($response['payment_id']);
	} catch (Exception $e) {
		if ($body = $e->getJsonBody()){
			$err = $body['error'];
			list($erreur_code, $erreur) = stripe_error_code($err);
		} else {
			$erreur = $e->getMessage();
			$erreur_code = 'error';
		}
	}

	if (!$payment or !in_array($payment->status, array(/*'processing', */ 'succeeded'))){
		// regarder si l'annulation n'arrive pas apres un reglement (internaute qui a ouvert 2 fenetres de paiement)
		if ($row['reglee']=='oui'){
			return array($id_transaction, true);
		}
		// sinon enregistrer l'absence de paiement et l'erreur
		return bank_transaction_echec($id_transaction,
			array(
				'mode' => $mode,
				'config_id' => $config_id,
				'date_paiement' => $date_paiement,
				'erreur' => ($payment ? "Status PaymentIntent=" . $payment->status : "PaymentIntent " . $response['payment_id'] . " non valide") . ($erreur ? "\n$erreur" : ""),
				'code_erreur' => $erreur_code,
				'log' => var_export($response, true),
			)
		);
	}

	// essayer de retrouver ou creer un customer pour l'id_auteur
	if (empty($response['pay_id']) and $customer_id = $payment->customer) {
		$response['pay_id'] = $customer_id;
	}

	// Ouf, le reglement a ete accepte

	// on verifie que le montant est bon !
	$montant_regle = $payment->amount_received / (10**$devise_fraction);

	if ($montant_regle!=$row['montant']){
		spip_log($t = "call_response : id_transaction $id_transaction, montant regle $montant_regle!=" . $row['montant'] . ":" . var_export($payment, true), $mode);
		// on log ca dans un journal dedie
		spip_log($t, $mode . '_reglements_partiels');
	}

	$authorisation_id = $payment->id;
	$transaction = "";
	$charge = null;
	if ($payment->charges
		and $payment->charges->data
		and $charge = end($payment->charges->data)){
		$transaction = $charge['balance_transaction'];
		$date_paiement = date('Y-m-d H:i:s', $charge['created']);
	}

	$set = array(
		"autorisation_id" => "$transaction/$authorisation_id",
		"mode" => "$mode/$config_id",
		"montant_regle" => $montant_regle,
		"date_paiement" => $date_paiement,
		"statut" => 'ok',
		"reglee" => 'oui'
	);

	if (isset($response['pay_id'])){
		$set['pay_id'] = $response['pay_id'];
	}
	if (isset($response['abo_uid'])){
		$set['abo_uid'] = $response['abo_uid'];
	}

	// type et numero de carte ?
	$card = null;
	if ($charge
		and isset($charge['payment_method_details'])
		and $charge['payment_method_details']['type']=='card'){
		$card = $charge['payment_method_details']['card'];
	}
	if (!$card
		and $charge
		and isset($charge['source'])
		and $charge['source']['object']=='card'){
		$card = $charge['source'];
	}
	if (!$card){
		// TODO utiliser $payment->payment_method
	}

	if ($card){
		// par defaut on note carte et BIN6 dans refcb
		$set['refcb'] = '';
		if (isset($card['brand'])){
			$set['refcb'] .= $card['brand'];
		}

		if (isset($card['last4']) and $card['last4']){
			$set['refcb'] .= ' ****' . $card['last4'];
		}

		$set['refcb'] = trim($set['refcb']);
		// validite de carte ?
		if (isset($card['exp_month']) AND isset($card['exp_year'])){
			$set['validite'] = $card['exp_year'] . "-" . str_pad($card['exp_month'], 2, '0', STR_PAD_LEFT);
		}
	}

	$response = array_merge($response, $set);

	sql_updateq("spip_transactions", $set, "id_transaction=" . intval($id_transaction));
	spip_log("call_response : id_transaction $id_transaction, reglee", $mode);

	// faire les updates chez Stripe, *apres* avoir mis la transaction a jour
	// pour faire au plus vite et mieux gerer les concurrences
	if (!empty($response['pay_id'])){
		try {
			$customer = \Stripe\Customer::retrieve($response['pay_id']);

			// si customer retrouve, on ajoute la source et la transaction
			if ($customer){
				$metadata = $customer->metadata;
				if (!$metadata){
					$metadata = array();
				}
				if (isset($metadata['id_transaction'])){
					$metadata['id_transaction'] .= ',' . $id_transaction;
				} else {
					$metadata['id_transaction'] = $id_transaction;
				}

				// limiter la longueur de id_transaction sinon Stripe fait une erreur
				// on ne garde que les plus recentes
				while (strlen($metadata['id_transaction']) > 256) {
					$t = explode(',', $metadata['id_transaction']);
					array_shift($t);
					$metadata['id_transaction'] = implode(',', $t);
				}

				if ($row['id_auteur']>0){
					$metadata['id_auteur'] = $row['id_auteur'];
					$customer->metadata = $metadata;
					$customer->description = sql_getfetsel('nom', 'spip_auteurs', 'id_auteur=' . intval($row['id_auteur']));
				}
				$customer->save();
			}
		} catch (Exception $e) {
			if ($body = $e->getJsonBody()){
				$err = $body['error'];
				list($erreur_code, $erreur) = stripe_error_code($err);
			} else {
				$erreur = $e->getMessage();
			}
			spip_log("Echec recherche/update customer transaction #$id_transaction $erreur", $mode . _LOG_ERREUR);
		}
	}

	if ($charge and $payment){
		try {
			\Stripe\Charge::update($charge->id, ['description' => $payment->description,]);
		} catch (Exception $e) {
			spip_log('call_response: erreur lors de la modification de la charge ' . $charge->id . ' :: ' . $e->getMessage(), $mode . _LOG_ERREUR);
		}
	}


	$options = array('row_prec' => $row);
	if (!empty($response['lang'])) {
		$options['lang'] = $response['lang'];
	}
	$regler_transaction = charger_fonction('regler_transaction', 'bank');
	$regler_transaction($id_transaction, $options);

	// update payment informations for Stripe Dashboard
	// after billing
	try {
        /*AJOUT BLOBUL*/        
        //IL SEMBLE QUE CETTE PARTIE RESET LE DESCRIPTIF DE LA TRANSACTION
/*		$description = bank_description_transaction($id_transaction);
		$description = array_filter([$description['libelle'], $description['description']]);
		$description = implode(" | ", $description);
		$description = str_replace("\n", " ", $description);
		$description = str_replace("\r", " ", $description);
		$nom_site = bank_nom_site();
		$description .= " [$nom_site]";*/
        
        
        //CODE PERSO 03/01/2023
                
    //PREPARATION DES INFOS LIES AUX ADHESIONS
    if ($query_asso_comptes = sql_fetsel("id_categorie,id_auteur,reinscription", "spip_asso_comptes", "id_transaction=" . intval($id_transaction))){
        $id_categorie = $query_asso_comptes['id_categorie'];   
        $id_auteur = $query_asso_comptes['id_auteur'];   
        $query_categorie = sql_fetsel("*", "spip_asso_categories_adherents", "id_categorie=" . intval($id_categorie));        
        $titre_categorie = $query_categorie['valeur'];   
        
        $reinscription = ($query_asso_comptes['reinscription'] == 'inscription') ? 'Adhésion à l\'association' : 'Réadhésion à l\'association';
        $query_auteur = sql_fetsel("nom_famille,prenom,email", "spip_auteurs", "id_auteur=" . intval($id_auteur));     
        $description_acheteur = $query_auteur['nom_famille'] . ' ' . $query_auteur['prenom']; 
        
        
        $libelle_transaction = 'ADH' . $id_auteur .'TRA'. $id_transaction . ' - ' . $reinscription;
        $description_transaction = $description_acheteur . ' - ' . $titre_categorie ;      
    }   
    //PREPARATION DES INFOS LIES AUX ACTIVITES
    if ($query_activite = sql_fetsel("id_activite,id_evenement,email_inscrit,nom_inscrit,prenom_inscrit", "spip_asso_activites", "id_transaction=" . intval($id_transaction))){
        $id_activite = $query_activite['id_activite'];   
        $query_evenement = sql_fetsel("*", "spip_evenements", "id_evenement=" . intval($query_activite['id_evenement']));        
        $titre_evenement = supprimer_numero($query_evenement['titre']) . ' - ' . affdate($query_evenement['date_debut'],"d/m/Y");        
        
        $description_acheteur = $query_activite['nom_inscrit'] . ' ' . $query_activite['prenom_inscrit']; 
        
        $libelle_transaction = 'ACT' . $id_activite . 'TRA' . $id_transaction . ' - Inscription à un événement' ;      
        $description_transaction = $description_acheteur . ' - ' . $titre_evenement;      
        
        
    }
    $contexte['description'] = _T('bank:titre_transaction') . '#' . $id_transaction;
	$contexte['image'] = find_in_path('img/logo-paiement-stripe.png');
	$description = bank_description_transaction($id_transaction, $row);
    
    $description_transaction = !empty($description_transaction) ? $description_transaction : $description['description'];
        
    $description = $description_transaction;
        
        /*FIN DU CODE PERSO*/
        
		$payment->description = $description;
		$metadata = $payment->metadata;
		if (!$metadata){
			$metadata = array();
		}
		$metadata['id_transaction'] = $id_transaction;
		$metadata['id_auteur'] = $row['id_auteur'];
		$metadata['nom_site'] = $nom_site;
		$metadata['url_site'] = $GLOBALS['meta']['adresse_site'];
		$payment->save();
	} catch (Exception $e) {
		if ($body = $e->getJsonBody()){
			$err = $body['error'];
			list($erreur_code, $erreur) = stripe_error_code($err);
		} else {
			$erreur = $e->getMessage();
			$erreur_code = 'error';
		}
		spip_log("Echec update payment metadata/description transaction #$id_transaction $erreur", $mode . _LOG_ERREUR);
	}

	return array($id_transaction, true);

}


function stripe_error_code($err){
	$message = $err['message'];
	$code = $err['type'];
	if ($code==='card_error'){
		$code = $err['code'];
	}

	return array($code, $message);
}
