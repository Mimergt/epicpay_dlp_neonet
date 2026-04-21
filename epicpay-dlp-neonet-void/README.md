# EpicPay - DLP Neonet VOID on Cancel

Plugin independiente para WooCommerce que intenta anular (VOID) la transaccion al cancelar un pedido, sin modificar el plugin oficial de Cybersource.

## Estado
Version inicial de Paso 1 (base funcional):
- Escucha cancelaciones de pedidos.
- Evalua elegibilidad por metodo de pago.
- Busca referencias de transaccion en transaction_id y metadatos configurables.
- Intenta reversal via gateway usando la capa estandar del gateway (`process_refund`), que en gateways SkyVerge/Cybersource realiza VOID cuando la transaccion aun es anulable.
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

## Metadatos usados por el plugin
- `_epicpay_void_status` (`success`, `failed`, `skipped`)
- `_epicpay_void_attempted_at` (ISO-8601 UTC)
- `_epicpay_void_reference`
- `_epicpay_void_message`

## Nota tecnica importante
Para mantener independencia del plugin oficial, esta version usa el gateway activo del pedido y llama `process_refund()` para delegar al comportamiento nativo (VOID o refund segun elegibilidad/settlement).

Si necesitas forzar un flujo REST de VOID 100% directo, puedes extender con el filtro:
- `epicpay_dlp_neonet_void_custom_attempt`

Firma esperada del filtro:
- Entrada: `null, WC_Order $order, WC_Payment_Gateway $gateway, string $transaction_reference`
- Salida: array con `success`, `code`, `message`, opcional `transaction_reference`

## Siguiente paso recomendado
En un ambiente de staging con Cybersource activo, identificar y confirmar los metakeys reales de transaccion/request_id para tu instalacion y ajustar los defaults en settings.
