<?php

/**
 * Permet de calculer la difficulté de chaque question à partir du taux de réussite.
 * 
 * La difficulté sera comprise entre 0 et 1, où 0 représente une question 
 * facile (toutes les réponses sont correctes) et 1 représente une question 
 * difficile (aucune réponse correcte).
 * 
 * @* @param array const les réponses correctes
 * @* @param array const les réponses de l'étudiant
 */
function prob_difficultes($reponses_corrects, $reponses_candidats) {
	
	$nbr_questions = count($reponses_corrects);
	$nbr_candidat = count($reponses_candidats);

	// $nbr_reponse_choisit = nbr de fois où une réponse correct a été choisit par question
	// $difficultes = difficulté de chaque question
	// initialisation à 0
	$difficultes = $nbr_reponse_choisit = array_fill(0, $nbr_questions, 0);

	for ($i = 0; $i < $nbr_candidat; $i++) {	// Parcours chaque candidat

		for ($j = 0; $j < $nbr_questions; $j++) {

			// On compte le nombre de fois où une question 
			// a été correctement répondu parle candidat $i
			// candidats ont donné la réponse correcte
			if ($reponses_corrects[$j] == $reponses_candidats[$i][$j])
				$nbr_reponse_choisit[$j]++;
		}
	}

	// On calcul la difficulté de chaque question
	for ($i = 0; $i < $nbr_questions; $i++) {

		// difficulte = 1 -  taux de réussite
		$difficultes[$i] = 1 - ($nbr_reponse_choisit[$i] / $nbr_candidat);
	}

	return $difficultes;
}


/**
 * Le modèle de réseaux bayésien uttilisé pour calculer la note d'un étudiant 
 * en fonction de ses connaissances à priori, ses réponses et la difficulté des questions.
 * Sur cette évaluation, les questions sont indépendantes.
 * 
 * @* @param array const les réponses correctes
 * @* @param array var les difficultés de chaque question
 * @* @param array var les réponses de l'étudiant
 */
function bayesian_eval($reponses_correctes, $difficultes, $reponses_eleve){
	
	
	// Elle doit normalement être mise à jour pour chaque question (quiz à questions indépendantes)
	// par une IA qui étudiera les réponses des candidats à cette question lors des anciennes questions.



	/* la probabilité a priori que l'élève réponde correctement à une question sans
		tenir compte de ses réponses précédentes. C'est une valeur que l'on fixe de façon arbitraire.  
		Par exemple, si l'on estime que les élèves ont de bonnes connaissances et ont plus de chances
		de répondre correctement (par exemple, 70% de chances de réussite a priori), 
		alors on peut définir "$prior_prob" à 0.7. 

		Pour ce test, on fixera cette valeur à 0.5 car nous partons sur la base de la probabilité du
		choix d'une réponse. Ici nos questions aurons 2 réponses, donc probabilité == 1/2.
	*/
	$prior_prob = 0.5;

	$note_totale = $note_partielle = 0;

	// On boucle sur chaque question
	for ($i = 0; $i < count($reponses_correctes); $i++) {
		// On récupère la difficulté de la question
		$difficulte = $difficultes[$i];

		// Dépendamment de la difficulté de la question
		if($reponses_eleve[$i] == $reponses_correctes[$i] && (1 - $difficulte) <= 0) { // Si la difficulté était élevé on fait un bonus
			$p_ba = 1 + $difficulte; 
		} 
		else if($reponses_eleve[$i] == $reponses_correctes[$i]) {
			$p_ba = 1; 
		} 
		// si il a raté une question difficile on lui donne quand même une partie de la note
		else if($reponses_eleve[$i] != $reponses_correctes[$i] && (1 - $difficulte) <= 0) {
			$p_ba = (1 - $difficulte);
		} 
		else { // Sinon il a raté une question facile
			$p_ba = 0;
		}

		$p_ba = $reponses_eleve[$i] == $reponses_correctes[$i] ? 5: 5;

		$p_b = $p_ba * $prior_prob + (1 - $p_ba) * (1 - $prior_prob);
		$p_a_b = ($p_ba * $prior_prob) / $p_b;

		// On calcule la note partielle de l'élève pour cette question en utilisant la difficulté de la question
		$note_partielle = $p_a_b; // On multiplie la note partielle par la difficulté de la question

		// On ajoute la note partielle à la note totale de l'élève
		$note_totale += $note_partielle;

		$note_partielle = 0;
	}

	$note_finale = $note_totale / count($reponses_correctes) * 20; // On suppose que la note finale est sur 20

	return $note_finale;

}
//-------------------------------------------------------------------------------------------------------------


// On définit les réponses correctes pour chaque question sous forme d'un tableau
$reponses_correctes = [2,1,3,4,2,1,3,4,2,1];

// On récupère les réponses de l'élève sous forme d'un tableau
$reponses_eleves =  [[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1],
					[2,1,3,4,2,1,3,4,2,1]];

// On définit l'échelle de difficulté pour chaque question
$difficultes = prob_difficultes($reponses_correctes, $reponses_eleves);

// On affiche la note finale de l'étudiant
$note_finale = bayesian_eval($reponses_correctes, $difficultes, $reponses_eleves[0]);
echo "La note finale de l'élève_0 est de : " . $note_finale;

echo "<br/>";echo "<br/>";

$note_finale = bayesian_eval($reponses_correctes, $difficultes, $reponses_eleves[1]);
echo "La note finale de l'élève_1 est de : " . $note_finale;
