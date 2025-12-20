// Aggiunge menu "PF Integration" nel backend
// Campi da salvare:
// 1. PF Username (API)
// 2. PF Password (API)
// 3. Global Markup % (Es. 1.40 per il 40%)
// 4. Print Markup % (Opzionale, se vuoi guadagnare anche sulla stampa)

// Esempio di utilizzo nel codice importer:
$markup = get_option('pf_global_markup', 1.30); // Default 30%
$final_price = $pf_net_price * $markup;