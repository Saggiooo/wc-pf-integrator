<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Gateway {

    public function __construct() {
        // 1. Quando il cliente paga, programmiamo l'invio (non lo facciamo subito)
        add_action( 'woocommerce_payment_complete', [ $this, 'schedule_order_sending' ] );
        
        // 2. Questo è l'hook "invisibile" che verrà eseguito in background
        add_action( 'pf_async_send_order_event', [ $this, 'send_order_to_pf_now' ], 10, 1 );
    }

    /**
     * Passo A: Pianifica l'invio (velocissimo per l'utente)
     */
    public function schedule_order_sending( $order_id ) {
        // Verifica se abbiamo già pianificato questo ordine per evitare duplicati
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            if ( as_next_scheduled_action( 'pf_async_send_order_event', [ 'order_id' => $order_id ] ) ) {
                return;
            }
        }

        // Usa Action Scheduler di WooCommerce per eseguire l'invio "il prima possibile"
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), 'pf_async_send_order_event', [ 'order_id' => $order_id ] );
            
            // Nota interna per l'admin
            $order = wc_get_order( $order_id );
            $order->add_order_note( 'Invio a PF Concept programmato in background.' );
        }
    }

    /**
     * Passo B: Esegue l'invio reale (Lento, ma l'utente non aspetta)
     */
    public function send_order_to_pf_now( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Evita invii doppi se l'ordine è già stato segnato come inviato
        if ( $order->get_meta( '_pf_order_sent' ) === 'yes' ) return;

        // --- INIZIO LOGICA INVIO (Uguale a prima) ---
        $env = get_option( 'pf_environment', 'test' );
        $base_url = ($env === 'live') ? 'https://wsa.pfconcept.com/RestGateway/rest/RestGatewayService/order' : 'https://wsa.pfconcept.com/test/RestGateway/rest/RestGatewayService/order';
        
        $username = get_option( 'pf_api_username' );
        $password = get_option( 'pf_api_password' );
        $sender_id = get_option( 'pf_sender_id' );

        if ( empty( $username ) || empty( $password ) ) {
            $order->add_order_note( 'Errore PF: Credenziali mancanti nelle impostazioni.' );
            return;
        }

        // Costruzione Header
        $header = [
            "messageId" => wp_generate_uuid4(),
            "timestamp" => gmdate("Y-m-d\TH:i:s.v\Z"),
            "isTest"    => ($env === 'test'),
            "senderId"  => $sender_id,
            "receiverId"=> "PF"
        ];

        // Costruzione Dati Spedizione
        $shipping = $order->get_address( 'shipping' );
        // Fallback se manca indirizzo spedizione (usa fatturazione)
        if ( empty( $shipping['first_name'] ) ) $shipping = $order->get_address( 'billing' );

        $shipments = [[
            "shipmentReferenceID" => 1,
            "Service" => "STD",
            "shipContact" => [
                "Name" => $shipping['first_name'] . ' ' . $shipping['last_name'],
                "Email" => $order->get_billing_email(),
                "Phone" => $order->get_billing_phone(),
                "shipAddress" => [
                    "Address1" => $shipping['address_1'],
                    "City" => $shipping['city'],
                    "PostalCode" => $shipping['postcode'],
                    "Country" => $shipping['country'] 
                ]
            ]
        ]];

        // Costruzione Prodotti (SKU)
        $skus = [];
        $order_type = "BLANK"; 

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;

            $sku_pf = $product->get_sku(); // SKU deve coincidere con PF!
            
            // Recupera eventuale prezzo netto salvato (se disponibile) o mette 0.01 fittizio
            $unit_price = (float) $product->get_meta('_pf_net_price');
            if ( $unit_price <= 0 ) $unit_price = 0.01;

            // Check personalizzazione (se implementata nel frontend)
            $custom_data = $item->get_meta( 'pf_customization' );
            if ( ! empty( $custom_data ) ) {
                $order_type = "DECORATED";
            }

            $skus[] = [
                "skuReferenceID" => $item_id,
                "isItemProof" => false,
                "SKUID" => $sku_pf,
                "Quantity" => $item->get_quantity(),
                "UnitPrice" => $unit_price 
            ];
        }

        if ( empty( $skus ) ) {
            $order->add_order_note( 'Errore PF: Nessuno SKU valido trovato nell\'ordine.' );
            return;
        }

        $body = [
            "Header" => $header,
            "OrderType" => $order_type,
            "PurchaseOrderNumber" => (string) $order_id,
            "Currency" => $order->get_currency(),
            "PurchaseOrderTotal" => (float) $order->get_total(),
            "ProcessingPriority" => "STANDARD",
            "Shipments" => $shipments,
            "SKUs" => $skus
        ];

        // Chiamata API
        $response = wp_remote_post( $base_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "$username:$password" ),
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode( $body ),
            'timeout' => 45 // Timeout lungo perché siamo in background!
        ]);

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( "Errore Connessione PF: " . $response->get_error_message() );
            // Opzionale: Qui potresti ri-schedulare l'azione tra 1 ora in caso di errore server
        } else {
            $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $http_code = wp_remote_retrieve_response_code( $response );

            if ( isset( $resp_body['status'] ) && $resp_body['status'] === 'success' ) {
                $order->add_order_note( "✅ Ordine inviato a PF Concept!\nID Gateway: " . ($resp_body['yourMessageId'] ?? 'N/A') );
                $order->update_meta_data( '_pf_order_sent', 'yes' );
                $order->save();
            } else {
                $error_msg = $resp_body['message'] ?? 'Errore sconosciuto';
                // Aggiungi dettagli se ci sono errori specifici nel JSON
                if ( ! empty( $resp_body['errorMessage'] ) ) $error_msg .= " - " . $resp_body['errorMessage'];
                
                $order->add_order_note( "❌ PF Gateway ha rifiutato l'ordine (Codice $http_code): " . $error_msg );
                // Importante: Cambia stato ordine in 'On Hold' o notifica l'admin
                $order->update_status( 'on-hold', 'Errore invio PF Concept - Verificare manualmente' );
            }
        }
    }
}