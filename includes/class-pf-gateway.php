<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Gateway {

    public function __construct() {
        add_action( 'woocommerce_order_status_processing', [ $this, 'schedule_order_sending' ], 10, 1 );
        add_action( 'woocommerce_payment_complete', [ $this, 'schedule_order_sending' ] );
        add_action( 'pf_async_send_order_event', [ $this, 'send_order_to_pf_now' ], 10, 1 );
    }

    public function schedule_order_sending( $order_id ) {
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            if ( as_next_scheduled_action( 'pf_async_send_order_event', [ 'order_id' => $order_id ] ) ) return;
        }
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), 'pf_async_send_order_event', [ 'order_id' => $order_id ] );
            $order = wc_get_order( $order_id );
            $order->add_order_note( 'Invio a PF Concept programmato in background.' );
        }
    }

    public function send_order_to_pf_now( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_meta( '_pf_order_sent' ) === 'yes' ) return;

        // Configurazione
        $env = get_option( 'pf_environment', 'test' );
        $base_url = ($env === 'live') ? 'https://wsa.pfconcept.com/RestGateway/rest/RestGatewayService/order' : 'https://wsa.pfconcept.com/test/RestGateway/rest/RestGatewayService/order';
        $username = get_option( 'pf_api_username' );
        $password = get_option( 'pf_api_password' );
        $sender_id = get_option( 'pf_sender_id' );

        if ( empty( $username ) || empty( $password ) ) return;

        $header = [
            "messageId" => wp_generate_uuid4(),
            "timestamp" => gmdate("Y-m-d\TH:i:s.v\Z"),
            "isTest"    => ($env === 'test'),
            "senderId"  => $sender_id,
            "receiverId"=> "PF"
        ];

        $shipment_ref_id = "0";
        // Spedizione (Semplificata)
        $shipping = $order->get_address( 'shipping' ) ?: $order->get_address( 'billing' );
        $shipments = [[
            "shipmentReferenceID" => $shipment_ref_id,
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

        // --- GESTIONE PRODOTTI E STAMPA ---
        $skus = [];
        $decorations_collected = [];
        $artworks_collected = [];
        
        // Contatori per gli ID di riferimento (Devono essere univoci nel JSON)
        $deco_ref_id_counter = 0; 
        $art_ref_id_counter = 0;

        $order_type = "BLANK"; 
        $sku_ref_counter = 0;


        foreach ( $order->get_items() as $item_id => $item ) {

            $sku_ref_id = (string) $sku_ref_counter++;

            $product = $item->get_product();
            $sku_pf = $product->get_sku();
            if ( empty($sku_pf) ) {
                $order->add_order_note("❌ PF: SKUID (SKU Woo) vuoto per item {$item_id}");
                $order->update_status('on-hold');
                return;
}

            $unit_price = (float) $product->get_meta('_pf_net_price') ?: 0.01;

            // Prepariamo l'oggetto SKU base
            $sku_data = [
                "skuReferenceID" => $sku_ref_id,
                "isItemProof"    => false,
                "SKUID"          => $sku_pf,
                "Quantity"       => (int) $item->get_quantity(),
                "UnitPrice"      => (float) $unit_price
            ];


            // Recuperiamo i dati di personalizzazione salvati nel carrello
            // (Assumiamo che il frontend salvi: pf_customization[print_code], pf_customization[colors], pf_customization[logo_url])
            $custom_data = $item->get_meta( 'pf_customization' ); // Array salvato dal frontend
            $logo_url = $item->get_meta( '_pf_uploaded_file_url' ); // URL del file caricato (se gestito a parte)

            if ( ! empty( $custom_data ) && is_array($custom_data) ) {
                $order_type = "DECORATED";
                
                // Generiamo ID univoci per collegare SKU -> Decorazione -> Artwork
                $current_deco_id = (string) $deco_ref_id_counter++;
                $current_art_id  = (string) $art_ref_id_counter++;

                $sku_data['DecorationReferenceIDs'] = [ $current_deco_id ];


                $real_config_id = ! empty($custom_data['config_id'])
                ? $custom_data['config_id']
                : '1-front';


                // 2. Crea Oggetto Decorazione
                $decorations_collected[] = [
                    "decorationReferenceID" => $current_deco_id,
                    "allowArtSizeToMax" => true,
                    "ConfigurationID" => $real_config_id,
                    "NumberOfColors" => (int) ($custom_data['colors'] ?? 1),
                    "PMSColors" => [ "Black" ], // Placeholder: Dovresti chiedere il PMS all'utente
                    "ArtworkReferenceIDs" => [ $current_art_id ],
                    "ArtWidth" => 50, // Dimensioni stimate mm
                    "ArtHeight" => 50
                ];

                // 3. Crea Oggetto Artwork (Il File)
                // PF preferisce "VIA URL"[cite: 131]. Il file deve essere pubblico su internet.
                $art_url = $logo_url ? $logo_url : 'https://tuosito.com/wp-content/uploads/placeholder_logo.jpg';
                
                $artworks_collected[] = [
                    "artworkReferenceID" => $current_art_id,
                    "ArtworkFileName" => $sender_id . "_" . basename($art_url), // Nome univoco richiesto
                    "UrlArtFile" => $art_url,
                    "ArtworkType" => "RAW" // O "PROOF"
                ];
            }

            $skus[] = $sku_data;
        }

        // Costruzione Body Finale
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

        // Se è DECORATED, aggiungiamo i nodi extra
        if ( $order_type === "DECORATED" ) {
            if(!empty($decorations_collected)) $body['Decorations'] = $decorations_collected;
            if(!empty($artworks_collected)) $body['Artworks'] = $artworks_collected;
        }

        // Invio
        $response = wp_remote_post( $base_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "$username:$password" ),
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode( $body ),
            'timeout' => 45
        ]);

            $http_code = wp_remote_retrieve_response_code( $response );
            $raw_body  = wp_remote_retrieve_body( $response );

            $order->add_order_note( "PF HTTP: {$http_code}. Body: {$raw_body}" );

            $resp_body = json_decode( $raw_body, true );

            // Accettato dal gateway = 202 (anche se “processing not completed”)
            if ( $http_code == 202 && isset($resp_body['status']) && $resp_body['status'] === 'success' ) {
                $order->add_order_note( "✅ PF Gateway ACCEPTED (TEST=" . (($env==='test')?'yes':'no') . ") messageId=" . ($header['messageId'] ?? '') );
                $order->update_meta_data( '_pf_order_sent', 'yes' );
                $order->update_meta_data( '_pf_gateway_http', (string)$http_code );
                $order->update_meta_data( '_pf_gateway_messageId', $header['messageId'] ?? '' );
                $order->save();
            } else {
                $order->add_order_note( "❌ PF NOT accepted. HTTP {$http_code}. Msg: " . ($resp_body['message'] ?? 'n/a') );
                $order->update_meta_data( '_pf_gateway_http', (string)$http_code );
                $order->update_meta_data( '_pf_gateway_messageId', $header['messageId'] ?? '' );
                $order->save();
            }



        // Gestione Risposta (Logica uguale a prima...)
        if ( is_wp_error( $response ) ) {
            $order->add_order_note( "Errore PF: " . $response->get_error_message() );
        } else {
            $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $resp_body['status'] ) && $resp_body['status'] === 'success' ) {
                $order->add_order_note( "✅ Ordine inviato! ID PF: " . ($resp_body['yourMessageId'] ?? '') );
                $order->update_meta_data( '_pf_order_sent', 'yes' );
                $order->save();
            } else {
                $order->add_order_note( "❌ Rifiutato da PF: " . ($resp_body['message'] ?? 'Errore') );
                $order->update_status( 'on-hold' );
            }
        }
    }
}