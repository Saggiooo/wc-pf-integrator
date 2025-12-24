<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PF_Webhook {

    public function __construct() {
        // Registra l'endpoint REST: https://iltuosito.com/wp-json/pf-integrator/v1/notifications
        add_action( 'rest_api_init', [ $this, 'register_webhook_routes' ] );
    }

    public function register_webhook_routes() {
        register_rest_route( 'pf-integrator/v1', '/notifications', [
            'methods'  => 'POST',
            'callback' => [ $this, 'handle_incoming_notification' ],
            'permission_callback' => '__return_true' // PF non ha login WP, autentichiamo tramite IP o logica interna se necessario
        ]);
    }

    public function handle_incoming_notification( $request ) {
        $data = $request->get_json_params();
        
        // Log per debug (utile all'inizio per vedere cosa arriva)
        error_log( 'PF Webhook ricevuto: ' . print_r( $data, true ) );

        // 1. GESTIONE ASN (Tracking Spedizione)
        if ( isset( $data['ShipmentNotification'] ) ) {
            return $this->process_asn( $data['ShipmentNotification'] );
        }

        // 2. GESTIONE STATUS CHANGE (Ordine in lavorazione, ecc.)
        if ( isset( $data['StatusChangedNotification'] ) ) {
            return $this->process_status_change( $data['StatusChangedNotification'] );
        }

        return new WP_REST_Response( 'Messaggio ignorato: Tipo sconosciuto', 200 );
    }

    /**
     * Elabora la notifica di spedizione (ASN)
     */
    private function process_asn( $asn ) {
        // Struttura JSON ASN basata su manuale pag. 30-33
        if ( empty( $asn['deliveries'] ) ) return new WP_REST_Response( 'No deliveries data', 200 );

        foreach ( $asn['deliveries'] as $delivery ) {
            $pf_data = $delivery['deliveryLocation'] ?? [];
            
            // Il CustomerPONumber è il NOSTRO ID Ordine WooCommerce (es. 12345)
            $order_id = $pf_data['customerPONumber'] ?? 0;
            
            // Pulizia ID (a volte arrivano come "PO-12345")
            $order_id = preg_replace( '/[^0-9]/', '', $order_id );

            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;

            // Recupera Tracking
            $tracking_code = '';
            $carrier_name = $pf_data['carrier'] ?? 'Corriere';
            
            if ( ! empty( $pf_data['trackAndTrace'] ) ) {
                // Prende il primo tracking disponibile
                $tracking_node = $pf_data['trackAndTrace'][0] ?? [];
                $tracking_code = $tracking_node['trackingId'] ?? '';
                $tracking_url  = $tracking_node['trackingURL'] ?? '';
            }

            // Aggiorna Ordine WooCommerce
            // Nota: Se usi plugin tipo "Shipment Tracking", usa le loro funzioni qui.
            // Altrimenti lo salviamo nelle note e completiamo l'ordine.
            
            $note = "Spedito con $carrier_name. Tracking: $tracking_code";
            
            // Evita di completare due volte
            if ( $order->get_status() !== 'completed' ) {
                $order->add_order_note( $note );
                $order->update_status( 'completed', 'Spedizione confermata da PF Concept.' );
                
                // Salviamo tracking nei meta per usi futuri
                if($tracking_code) update_post_meta( $order_id, '_pf_tracking_code', $tracking_code );
                if($tracking_url)  update_post_meta( $order_id, '_pf_tracking_url', $tracking_url );
            }
        }

        return new WP_REST_Response( 'ASN Processed', 200 );
    }

    /**
     * Elabora il cambio stato (es. In Lavorazione)
     */
    private function process_status_change( $status_data ) {
        $order_id = preg_replace( '/[^0-9]/', '', $status_data['poNumber'] ?? 0 );
        $order = wc_get_order( $order_id );

        if ( $order ) {
            $pf_status = $status_data['statusCode'] ?? '';
            $msg = "PF Update: Ordine è ora " . $pf_status;
            
            // Mappa stati PF -> Stati WC
            // PF Stati: Stalled, Processing, Partial shipped, Shipped, Completed, Cancelled [cite: 161-167]
            switch ( strtoupper( $pf_status ) ) {
                case 'PROCESSING':
                    // Non facciamo nulla se è già in lavorazione
                    break;
                case 'CANCELLED':
                    $order->update_status( 'cancelled', 'Annullato da PF Concept.' );
                    break;
                case 'SHIPPED':
                case 'COMPLETED':
                    // Gestito meglio dall'ASN, qui mettiamo solo una nota
                    $order->add_order_note( $msg );
                    break;
                default:
                    $order->add_order_note( $msg );
            }
        }
        return new WP_REST_Response( 'Status Processed', 200 );
    }
}