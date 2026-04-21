# Plan de ejecucion - Plugin EpicPay DLP Neonet (VOID en cancelacion)

Fecha base: 21 de abril de 2026.

## Objetivo
Crear un plugin independiente del plugin oficial de Cybersource para WooCommerce que, al cancelar un pedido, intente ejecutar un VOID contra Cybersource para transacciones autorizadas/capturadas que aun sean anulables.

## Restricciones y criterios
- Debe ser un plugin aparte (no modificar el plugin oficial de Cybersource).
- Debe ser compatible con la integracion API usada por WooCommerce Cybersource Payment Gateway (Verge).
- Debe registrar logs claros para auditoria y soporte.
- Debe evitar dobles VOID y ser idempotente.

## Fase 1: Descubrimiento tecnico
1. Verificar como guarda metadatos el plugin de Cybersource en el pedido:
   - transaction_id
   - request_id
   - estado de autorizacion/captura
   - campos/meta de referencia para reversal/void
2. Identificar hooks de WooCommerce para cancelacion:
   - woocommerce_order_status_cancelled
   - woocommerce_order_status_changed (si aplica para mayor control)
3. Confirmar endpoint API de VOID/reversal para la modalidad de Cybersource usada en GT.
4. Definir matriz de elegibilidad VOID:
   - Pedido cancelado
   - Metodo de pago Cybersource
   - Existe referencia transaccional
   - No existe marca previa de VOID exitoso

## Fase 2: Estructura del plugin
1. Crear plugin nuevo, por ejemplo:
   - Carpeta: epicpay-dlp-neonet-void/
   - Archivo principal: epicpay-dlp-neonet-void.php
2. Definir clases base:
   - Bootstrap del plugin
   - Listener de estado de pedido
   - Servicio Cybersource VOID
   - Logger/registro de eventos
3. Agregar ajustes en WP Admin:
   - Activar/desactivar auto-VOID al cancelar
   - Modo sandbox/produccion (si no se reutiliza del gateway)
   - Nivel de log

## Fase 3: Implementacion funcional
1. Escuchar cancelacion del pedido.
2. Validar condiciones de elegibilidad.
3. Construir solicitud VOID con identificadores correctos.
4. Enviar request al API.
5. Persistir resultado en metadatos del pedido:
   - _epicpay_void_attempted_at
   - _epicpay_void_status
   - _epicpay_void_reference
   - _epicpay_void_response_raw (opcional, sanitizado)
6. Agregar nota de pedido con resultado (exito o error controlado).
7. Manejar reintentos manuales (accion de admin opcional).

## Fase 4: Seguridad y robustez
1. Validar credenciales y entorno antes de enviar.
2. Sanitizar datos sensibles en logs.
3. Implementar guardas anti-duplicado (idempotencia).
4. Manejar errores de red/API con mensajes claros.

## Fase 5: QA y pruebas
1. Casos de prueba minimos:
   - Cancelacion con transaccion valida -> VOID exitoso.
   - Cancelacion sin referencia -> no intenta VOID y registra motivo.
   - Doble cancelacion -> no duplica VOID.
   - Error API -> deja trazabilidad y estado consistente.
2. Probar en sandbox Cybersource GT.
3. Validar compatibilidad con versiones de WooCommerce/WordPress objetivo.

## Fase 6: Documentacion y release
1. README del plugin:
   - Instalacion
   - Configuracion
   - Flujo funcional
   - Solucion de problemas
2. Changelog inicial.
3. Versionado semantico (ejemplo: 0.1.0).

## Flujo Git recomendado (obligatorio)
Siempre publicar cambios con push a git al terminar cada bloque funcional estable.

Secuencia sugerida:
1. git checkout -b feat/void-on-order-cancel
2. Implementar bloque pequeño + pruebas
3. git add .
4. git commit -m "feat: add order cancellation listener for cybersource void"
5. git push -u origin feat/void-on-order-cancel
6. Repetir por bloque funcional
7. Abrir PR y merge a rama principal
8. git push origin main (tras merge)

## Entregables tecnicos esperados
- Plugin WordPress independiente para auto-VOID al cancelar pedido.
- Registro auditable por pedido.
- Controles anti-duplicado e idempotencia.
- Documentacion de operacion y soporte.
