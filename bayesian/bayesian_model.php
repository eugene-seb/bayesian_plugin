<?php

/**
 * Permet de calculer la probabilité à priori de chaque question à partir 
 * du taux de réussite d'une évaluation précédente du même QCM.
 * 
 * @* @param array const les réponses correctes
 * @* @param array const les réponses de l'étudiant
 */
function les_prop_apriori($reponses_corrects, $reponses_candidats) {
	
	$nbr_questions = count($reponses_corrects);
	$nbr_candidat = count($reponses_candidats);

	// $nbr_reponse_choisit = nbr de fois où une réponse correct a été choisit par question
	// $props_apriori = prop_apriori de chaque question
	// initialisation à 0
	$props_apriori = $nbr_reponse_choisit = array_fill(0, $nbr_questions, 0);

	for ($i = 0; $i < $nbr_candidat; $i++) {	// Parcours chaque candidat

		for ($j = 0; $j < $nbr_questions; $j++) {

			// On compte le nombre de fois où une question 
			// a été correctement répondu parle candidat $i
			// candidats ont donné la réponse correcte
			if ($reponses_corrects[$j] == $reponses_candidats[$i][$j])
				$nbr_reponse_choisit[$j]++;
		}
	}

	// On calcul la probabilité à priori de chaque question en utilisant 
	// la formule du taux de réussite
	for ($i = 0; $i < $nbr_questions; $i++) {

		$props_apriori[$i] = $nbr_reponse_choisit[$i] / $nbr_candidat;
	}

	return $props_apriori;
}


/**
 * Le modèle de réseaux bayésien uttilisé pour calculer la note d'un étudiant 
 * en fonction de ses connaissances à priori, ses réponses et la difficulté des questions.
 * Sur cette évaluation, les questions sont indépendantes.
 * 
 * L'utilisation de la probabilité que l'élève ait les connaissances nécessaires pour
 * répondre correctement à chaque question permet de prendre en compte la difficulté 
 * de chaque question et le niveau de l'élève.
 * 
 * La difficulté de chaque question est estimée par le professeur, qui pourra par 
 * exemple avoir le barême suivant: niveau facile(1) intermédiare(0.5) très_difficile(0) 
 * 
 * @* @param array const les réponses correctes
 * @* @param array var les difficultés de chaque question
 * @* @param array var les réponses de l'étudiant
 */
function bayesian_eval($reponses_correctes, $difficultes, $reponses_eleve, $reponses_prec_eleves){

	/* 
		la probabilité a priori que l'élève réponde correctement à une question sans
		tenir compte de ses réponses précédentes. C'est une valeur que l'on fixe de façon arbitraire.  
		Par exemple, si l'on estime que les élèves ont de bonnes connaissances et ont plus de chances
		de répondre correctement (par exemple, 70% de chances de réussite a priori), 
		alors on peut définir "$prior_prob" à 0.7. 

		Elle doit normalement être mise à jour pour chaque question (quiz à questions indépendantes)
		par une IA qui étudiera les réponses des candidats à cette question lors des anciennes questions.
	
		Pour ce test, on supposera qu'un élève ai 50% de chance de répondre correctement à chaque question.
	*/
	$prior_prob = les_prop_apriori($reponses_correctes, $reponses_prec_eleves);

	$note_totale = $note_partielle = 0;

	$note_finale = array_fill(0, count($reponses_eleve), 0);

	for ($x = 0; $x < count($reponses_eleve); $x++) {

		// On boucle sur chaque question
		for ($i = 0; $i < count($reponses_correctes); $i++) {

			// On récupère la difficulté de la question
			$difficulte = $difficultes[$i];

			// Dépendamment de la difficulté de la question on détermine 
			// que l'élève ait la réponse correcte
			$p_ba = ($reponses_eleve[$x][$i] == $reponses_correctes[$i]) ? (1 - $difficulte) : $difficulte;

			$p_b = $p_ba * $prior_prob[$i] + (1 - $p_ba) * (1 - $prior_prob[$i]);
			$p_ab = ($p_ba * $prior_prob[$i]) / $p_b; 

			// On calcule la note partielle de l'élève pour cette question en utilisant la difficulté de la question
			// On multiplie la probabilité de réussite par le nombre de poids que vaut la question
			$note_partielle = $p_ab * 1; 

			// On ajoute la note partielle à la note totale de l'élève
			$note_totale += $note_partielle;

			$note_partielle = 0;
		}

		$note_finale[$x] = $note_totale / count($reponses_correctes) * 20; // On suppose que la note finale est sur 20
		$note_totale=0;
	}

	return $note_finale;

}

//-------------------------------------------------------------------------------------------------------------

// Les réponse des élèves lors de la session précédente
$reponses_prec_eleves = [[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,5,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,0,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[0,1,2,3,4,5,6,7,8,9],
						[2,1,3,4,2,7,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1]];

// On récupère les réponses de l'élève sous forme d'un tableau
$reponses_eleves =  [[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,5,5,5],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[2,1,3,4,2,1,3,4,2,1],
						[4,4,4,4,4,5,6,7,8,9]];

// la difficulté de chaque question est fixée par le professeur
$difficultes = [0.2, 0.1, 0.3, 0.4, 0.2, 0.1, 0.3, 0.4, 0.2, 0.1];

// On définit les réponses correctes pour chaque question sous forme d'un tableau
$reponses_correctes = [2,1,3,4,2,1,3,4,2,1];

for ($y = 0; $y < count($reponses_eleves); $y++) {
	
	// On affiche la note finale de l'étudiant
	$note_finale = bayesian_eval($reponses_correctes, $difficultes, $reponses_eleves, $reponses_prec_eleves);

	echo "La note finale de l'élève_" . $y . " est de : " . $note_finale[$y];

	echo "<br/>";echo "<br/>";

}
