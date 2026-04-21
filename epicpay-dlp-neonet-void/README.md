# EpicPay - DLP Neonet VOID on Cancel

Plugin independiente para WooCommerce que intenta anular (VOID) la transaccion al cancelar un pedido, sin modificar el plugin oficial de Cybersource.

## Estado
Version 0.2.0:
- Escucha cancelaciones de pedidos.
- Evalua elegibilidad por metodo de pago.
- Busca referencias de transaccion en transaction_id y metadatos configurables.
- Intenta VOID directo via Cybersource REST API (`/pts/v2/payments/{id}/reversals`) con firma HTTP.
- Soporta tomar credenciales desde el gateway de Cybersource o de forma manual.
- Mantiene fallback opcional a flujo refund del gateway (apagado por defecto).
- Guarda trazabilidad en metadatos y notas del pedido.

## Instalacion
1. Copiar la carpeta `epicpay-dlp-neonet-void` dentro de `wp-content/plugins/`.
2. Activar el plugin en WordPress.
3. Ir a WooCommerce > EpicPay VOID.
4. Configurar:
   - Enable auto VOID
   - Allowed payment method IDs
   - Transaction meta keys
   - Request ID meta keys
   - Use CyberSource gateway credentials
   - Cybersource environment
   - (Opcional) credenciales manuales
   - Allow refund fallback (recomendado apagado)

## Metadatos usados por el plugin
- `_epicpay_void_status` (`success`, `failed`, `skipped`)
- `_epicpay_void_attempted_at` (ISO-8601 UTC)
- `_epicpay_void_code`
- `_epicpay_void_reference`
- `_epicpay_void_message`

## Nota tecnica importante
El flujo principal de esta version es VOID directo por API REST de Cybersource. El fallback a refund se ejecuta solo si se habilita explicitamente en configuracion.

Si necesitas forzar un flujo REST de VOID 100% directo, puedes extender con el filtro:
- `epicpay_dlp_neonet_void_custom_attempt`

Firma esperada del filtro:
- Entrada: `null, WC_Order $order, WC_Payment_Gateway $gateway, string $transaction_reference`
- Salida: array con `success`, `code`, `message`, opcional `transaction_reference`

## Siguiente paso recomendado
En un ambiente de staging con Cybersource activo, identificar y confirmar los metakeys reales de transaccion/request_id para tu instalacion y ajustar los defaults en settings.
